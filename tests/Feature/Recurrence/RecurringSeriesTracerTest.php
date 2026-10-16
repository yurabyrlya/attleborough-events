<?php

use App\Enums\EventOrigin;
use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Models\Category;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Europe/London'));

    $this->seed(CategorySeeder::class);
    $this->admin = User::factory()->create(['email' => 'admin@example.test']);
    actingAs($this->admin);

    $this->repairCafe = [
        'title' => 'Repair Café',
        'description' => 'Bring broken things; volunteers help you fix them.',
        'category_id' => Category::where('slug', 'community')->firstOrFail()->id,
        'venue_id' => Venue::factory()->create(['name' => 'Connaught Hall'])->id,
        'start_date' => '2026-10-23',
        'start_time' => '10:00',
        'repeats' => 'weekly',
        'source_note' => 'Poster in the library window',
        'organiser_contact' => 'repair@example.test',
    ];
});

function createRepairCafeInFilament(array $form): Event
{
    livewire(CreateEvent::class)
        ->fillForm($form)
        ->call('create')
        ->assertHasNoFormErrors();

    return Event::query()->latest('id')->firstOrFail();
}

/**
 * @return array<string, string> recurrence_key => starts_at (UTC, raw)
 */
function rawUtcStarts(Event $event): array
{
    $rows = DB::select(
        "select recurrence_key, starts_at at time zone 'UTC' as s from event_occurrences where event_id = ? order by starts_at",
        [$event->id],
    );

    return collect($rows)->mapWithKeys(fn ($r) => [$r->recurrence_key => substr((string) $r->s, 0, 19)])->all();
}

it('publishes a weekly series created in Filament with origin manual, the admin as approver and a weekly RRULE', function () {
    $event = createRepairCafeInFilament($this->repairCafe);

    expect($event->origin)->toBe(EventOrigin::Manual)
        ->and($event->approved_by)->toBe($this->admin->id)
        ->and($event->recurrence_rule)->toBe('FREQ=WEEKLY;BYDAY=FR')
        ->and($event->source_note)->toBe('Poster in the library window');
});

it('materialises exactly 13 weekly occurrences in UTC across the 25 Oct 2026 DST change', function () {
    $event = createRepairCafeInFilament($this->repairCafe);

    $starts = rawUtcStarts($event);

    expect($starts)->toHaveCount(13)
        ->and(array_key_first($starts))->toBe('2026-10-23')
        ->and(array_key_last($starts))->toBe('2027-01-15')
        ->and($starts['2026-10-23'])->toBe('2026-10-23 09:00:00')
        ->and($starts['2026-10-30'])->toBe('2026-10-30 10:00:00');
});

it('shows one public entry per occurrence in date order and nothing beyond the 90-day horizon', function () {
    createRepairCafeInFilament($this->repairCafe);

    get('/')
        ->assertOk()
        ->assertSeeInOrder(['Fri 23 Oct, 10am', 'Fri 30 Oct, 10am', 'Fri 6 Nov, 10am'])
        ->assertSee('Fri 15 Jan, 10am')
        ->assertDontSee('Fri 22 Jan');
});

it('gives each of two identical published series its own 13 occurrences', function () {
    $first = createRepairCafeInFilament($this->repairCafe);
    $second = createRepairCafeInFilament($this->repairCafe);

    expect($second->id)->not->toBe($first->id)
        ->and(rawUtcStarts($first))->toHaveCount(13)
        ->and(rawUtcStarts($second))->toHaveCount(13);
});
