<?php

use App\Enums\EventOrigin;
use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Filament\Resources\Events\RelationManagers\OccurrencesRelationManager;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\ChangeEventStatus;
use App\Publishing\Actions\ChangeOccurrenceStatus;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Actions\UpdatePublishedEvent;
use App\Publishing\Data\PublishableEventData;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

/*
 * Admin-set status and its audit trail survive edits and the daily sync
 * (ADM-03, REC-01); the 01-04 orphan rule is pinned; nothing can be hard
 * deleted from the admin; the events list can be filtered by status.
 */
beforeEach(function () {
    freezeLondon('2026-10-20 10:00');

    $this->admin = User::factory()->create(['email' => config('app.admin_email')]);
    actingAs($this->admin);

    $this->series = app(PublishEvent::class)(PublishableEventData::fromForm([
        'title' => 'Repair Café',
        'description' => 'Bring broken things; volunteers help you fix them.',
        'category_id' => Category::factory()->create()->id,
        'venue_id' => Venue::factory()->create()->id,
        'start_date' => '2026-10-23',
        'start_time' => '10:00',
        'repeats' => 'weekly',
        'organiser_contact' => 'organiser@example.test',
    ]), $this->admin, EventOrigin::Manual);

    $this->on = fn (string $key): ?EventOccurrence => EventOccurrence::query()
        ->where('event_id', $this->series->id)
        ->where('recurrence_key', $key)
        ->first();

    $this->edit = fn (array $changes): Event => app(UpdatePublishedEvent::class)(
        $this->series->fresh(),
        PublishableEventData::fromForm(array_merge(PublishableEventData::fromEvent($this->series->fresh()), $changes)),
        $this->admin,
    );

    $this->setOccurrence = fn (string $key, OccurrenceStatus $to): EventOccurrence => app(ChangeOccurrenceStatus::class)(($this->on)($key), $to, $this->admin);
    $this->setEvent = fn (EventStatus $to, ?Event $event = null): Event => app(ChangeEventStatus::class)(($event ?? $this->series)->fresh(), $to, $this->admin);

    /** @return array{0: int, 1: string, 2: int|null, 3: string|null} */
    $this->audit = fn (?EventOccurrence $o): array => [$o?->id, $o?->status->value, $o?->status_changed_by, $o?->status_changed_at?->toIso8601String()];

    // Admin-set statuses on two future dates, at different instants.
    $this->cancelledAudit = ($this->audit)(($this->setOccurrence)('2026-11-06', OccurrenceStatus::Cancelled));
    $this->travel(7)->minutes();
    $this->hiddenAudit = ($this->audit)(($this->setOccurrence)('2026-11-13', OccurrenceStatus::Hidden));
});

it('keeps both admin statuses and their audit values through an edit that changes only the start time', function () {
    ($this->edit)(['start_time' => '11:00']);

    $raw = DB::selectOne(
        "select starts_at at time zone 'UTC' as s from event_occurrences where event_id = ? and recurrence_key = '2026-11-06'",
        [$this->series->id],
    );

    expect(($this->audit)(($this->on)('2026-11-06')))->toBe($this->cancelledAudit)
        ->and(($this->audit)(($this->on)('2026-11-13')))->toBe($this->hiddenAudit)
        ->and($this->cancelledAudit[1])->toBe('cancelled')
        ->and($this->hiddenAudit[1])->toBe('hidden')
        ->and(substr((string) $raw->s, 0, 19))->toBe('2026-11-06 11:00:00');
});

it('keeps both admin statuses and their audit values through the daily sync and adds the new date as scheduled', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-27 03:10', 'Europe/London'));
    artisan('calendar:sync-occurrences')->assertExitCode(0);

    $new = ($this->on)('2027-01-22');

    expect(($this->audit)(($this->on)('2026-11-06')))->toBe($this->cancelledAudit)
        ->and(($this->audit)(($this->on)('2026-11-13')))->toBe($this->hiddenAudit)
        ->and($new)->not->toBeNull()
        ->and($new->status)->toBe(OccurrenceStatus::Scheduled)
        ->and($new->status_changed_at)->toBeNull()
        ->and($new->status_changed_by)->toBeNull();
});

it('still rolls a hidden series forward, and its new date is listed once the event is shown again', function () {
    ($this->setEvent)(EventStatus::Hidden);

    $this->travelTo(CarbonImmutable::parse('2026-10-27 03:10', 'Europe/London'));
    artisan('calendar:sync-occurrences')->assertExitCode(0);

    expect(($this->on)('2027-01-22'))->not->toBeNull();
    get('/')->assertOk()->assertDontSee('Repair Café');

    ($this->setEvent)(EventStatus::Published);

    get('/')->assertOk()->assertSee('Fri 22 Jan, 10am');
});

it("keeps a cancelled event's status and audit values when its title is edited", function () {
    $cancelled = ($this->setEvent)(EventStatus::Cancelled);

    $this->travel(1)->hours();
    $edited = ($this->edit)(['title' => 'Repair Café (Connaught Hall)']);

    expect($edited->title)->toBe('Repair Café (Connaught Hall)')
        ->and($edited->status)->toBe(EventStatus::Cancelled)
        ->and($edited->status_changed_by)->toBe($cancelled->status_changed_by)
        ->and($edited->status_changed_at?->equalTo($cancelled->status_changed_at))->toBeTrue();
});

it('prunes the cancelled Friday when the series moves to Saturdays and leaves the event status as it was', function () {
    $cancelled = ($this->setEvent)(EventStatus::Cancelled);

    $edited = ($this->edit)(['start_date' => '2026-10-24']);

    expect(($this->on)('2026-11-06'))->toBeNull()
        ->and(($this->on)('2026-11-07'))->not->toBeNull()
        ->and($edited->status)->toBe(EventStatus::Cancelled)
        ->and($edited->status_changed_at?->equalTo($cancelled->status_changed_at))->toBeTrue();
});

it('offers no delete or bulk delete action on the events list, the edit page or the Occurrences table', function () {
    livewire(ListEvents::class)
        ->assertActionDoesNotExist(TestAction::make('delete')->table($this->series))
        ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk())
        ->assertActionDoesNotExist(TestAction::make('forceDelete')->table($this->series));

    livewire(EditEvent::class, ['record' => $this->series->getRouteKey()])
        ->assertActionDoesNotExist('delete')
        ->assertActionDoesNotExist('forceDelete');

    livewire(OccurrencesRelationManager::class, ['ownerRecord' => $this->series, 'pageClass' => EditEvent::class])
        ->assertActionDoesNotExist(TestAction::make('delete')->table(($this->on)('2026-10-30')))
        ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());
});

it('filters the events list by status so hidden and cancelled events can be found', function () {
    $hidden = ($this->setEvent)(EventStatus::Hidden);

    $publish = fn (string $title): Event => app(PublishEvent::class)(PublishableEventData::fromForm([
        'title' => $title,
        'description' => 'A one-off.',
        'category_id' => Category::factory()->create()->id,
        'venue_id' => Venue::factory()->create()->id,
        'start_date' => '2026-11-14',
        'start_time' => '19:00',
        'organiser_contact' => 'organiser@example.test',
    ]), $this->admin, EventOrigin::Manual);

    $published = $publish('Quiz Night');
    $cancelled = ($this->setEvent)(EventStatus::Cancelled, $publish('Bonfire Supper'));

    livewire(ListEvents::class)
        ->filterTable('status', EventStatus::Hidden->value)
        ->assertCanSeeTableRecords([$hidden])
        ->assertCanNotSeeTableRecords([$published, $cancelled]);

    livewire(ListEvents::class)
        ->filterTable('status', EventStatus::Cancelled->value)
        ->assertCanSeeTableRecords([$cancelled])
        ->assertCanNotSeeTableRecords([$published, $hidden]);

    livewire(ListEvents::class)
        ->assertCanSeeTableRecords([$hidden, $published, $cancelled]);
});
