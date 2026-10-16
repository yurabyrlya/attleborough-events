<?php

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\RelationManagers\OccurrencesRelationManager;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\ChangeOccurrenceStatus;
use App\Publishing\Exceptions\InvalidStatusTransition;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

/*
 * Tracer (ADM-03 + CAL-07): the admin cancels ONE date of a weekly series in
 * the Filament Occurrences table. The change goes through
 * ChangeOccurrenceStatus (enum rule, row locks, audit columns); residents see
 * that card with a "Cancelled" badge until it expires; the daily sync keeps it.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Europe/London'));

    $this->seed(CategorySeeder::class);
    $this->admin = User::factory()->create(['email' => config('app.admin_email')]);
    actingAs($this->admin);

    livewire(CreateEvent::class)
        ->fillForm([
            'title' => 'Repair Café',
            'description' => 'Bring broken things; volunteers help you fix them.',
            'category_id' => Category::where('slug', 'community')->firstOrFail()->id,
            'venue_id' => Venue::factory()->create(['name' => 'Connaught Hall'])->id,
            'start_date' => '2026-10-23',
            'start_time' => '10:00',
            'repeats' => 'weekly',
            'organiser_contact' => 'repair@example.test',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->event = Event::query()->latest('id')->firstOrFail();

    $this->occurrenceOn = fn (string $key): EventOccurrence => EventOccurrence::query()
        ->where('event_id', $this->event->id)
        ->where('recurrence_key', $key)
        ->firstOrFail();

    $this->cancelThirtiethInFilament = function (): EventOccurrence {
        livewire(OccurrencesRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => EditEvent::class])
            ->callAction(TestAction::make('cancel')->table(($this->occurrenceOn)('2026-10-30')))
            ->assertNotified('Date cancelled');

        return ($this->occurrenceOn)('2026-10-30');
    };
});

it('cancels only the Fri 30 Oct row from the Occurrences table and records who and when', function () {
    $cancelled = ($this->cancelThirtiethInFilament)();

    expect($cancelled->status)->toBe(OccurrenceStatus::Cancelled)
        ->and($cancelled->status_changed_by)->toBe($this->admin->id)
        ->and($cancelled->status_changed_at?->equalTo(CarbonImmutable::now()))->toBeTrue();

    $others = EventOccurrence::query()
        ->where('event_id', $this->event->id)
        ->where('recurrence_key', '!=', '2026-10-30')
        ->get();

    expect($others)->toHaveCount(12)
        ->and($others->every(fn (EventOccurrence $o) => $o->status === OccurrenceStatus::Scheduled
            && $o->status_changed_at === null
            && $o->status_changed_by === null))->toBeTrue()
        ->and($this->event->fresh()->status)->toBe(EventStatus::Published);
});

it('shows a Cancelled badge on the Fri 30 Oct card only', function () {
    ($this->cancelThirtiethInFilament)();

    $response = get('/')->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('Fri 30 Oct, 10am')
        ->and(Str::before($html, 'Fri 23 Oct, 10am'))->not->toContain('Cancelled')
        ->and(Str::between($html, 'Fri 23 Oct, 10am', 'Fri 30 Oct, 10am'))->toContain('Cancelled')
        ->and(Str::between($html, 'Fri 30 Oct, 10am', 'Fri 6 Nov, 10am'))->not->toContain('Cancelled');
});

it('keeps the cancellation and its audit values through the daily calendar:sync-occurrences run', function () {
    $cancelled = ($this->cancelThirtiethInFilament)();

    $this->travelTo(CarbonImmutable::parse('2026-10-27 03:10', 'Europe/London'));
    artisan('calendar:sync-occurrences')->assertExitCode(0);

    $after = ($this->occurrenceOn)('2026-10-30');

    expect($after->id)->toBe($cancelled->id)
        ->and($after->status)->toBe(OccurrenceStatus::Cancelled)
        ->and($after->status_changed_by)->toBe($cancelled->status_changed_by)
        ->and($after->status_changed_at?->equalTo($cancelled->status_changed_at))->toBeTrue()
        ->and(($this->occurrenceOn)('2027-01-22')->status)->toBe(OccurrenceStatus::Scheduled);
});

it('lists the cancelled date until 12:59:59 and drops it at its 13:00 expiry', function () {
    ($this->cancelThirtiethInFilament)();

    $this->travelTo(CarbonImmutable::parse('2026-10-30 12:59:59', 'Europe/London'));
    get('/')->assertOk()->assertSee('Fri 30 Oct, 10am')->assertSee('Cancelled');

    $this->travelTo(CarbonImmutable::parse('2026-10-30 13:00:00', 'Europe/London'));
    get('/')->assertOk()->assertDontSee('Fri 30 Oct');
});

it('refuses to cancel an already cancelled date and leaves status_changed_at as it was', function () {
    $cancelled = ($this->cancelThirtiethInFilament)();

    $this->travelTo(CarbonImmutable::parse('2026-10-21 09:00', 'Europe/London'));

    expect(fn () => app(ChangeOccurrenceStatus::class)($cancelled, OccurrenceStatus::Cancelled, $this->admin))
        ->toThrow(InvalidStatusTransition::class, "Can't change status from cancelled to cancelled");

    expect(($this->occurrenceOn)('2026-10-30')->status_changed_at?->equalTo($cancelled->status_changed_at))->toBeTrue();
});
