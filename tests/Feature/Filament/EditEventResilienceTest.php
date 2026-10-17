<?php

use App\Calendar\OccurrenceGenerator;
use App\Enums\EventOrigin;
use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\RepeatPattern;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\ChangeOccurrenceStatus;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Actions\UpdatePublishedEvent;
use App\Publishing\Data\PublishableEventData;
use Filament\Actions\Testing\TestAction;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

/*
 * WR-09: an event whose stored recurrence_rule ManualRecurrence cannot
 * represent (an importer, SQL or an older rule) must not lock the admin out of
 * hiding or cancelling it. The edit page opens read-only with a warning and
 * cannot save; UpdatePublishedEvent refuses the edit; the events table also
 * offers the status actions.
 */
const UNSUPPORTED_RULE_WARNING = "This event's repeat rule can't be edited here. You can still hide or cancel it.";

beforeEach(function () {
    freezeLondon('2026-10-16 12:00');

    $this->admin = User::factory()->create(['email' => config('app.admin_email')]);
    actingAs($this->admin);

    // Stored directly: no action can write a rule the form cannot represent.
    $this->stored = fn (string $rule, array $overrides = []): Event => Event::factory()->create([
        'title' => 'Imported Yoga',
        'start_date' => '2026-10-23',
        'start_time' => '10:00:00',
        'recurrence_rule' => $rule,
        ...$overrides,
    ]);

    $this->base = [
        'title' => 'Coffee Morning',
        'description' => 'Tea, coffee and cake.',
        'categoryId' => Category::factory()->create()->id,
        'venueId' => Venue::factory()->create()->id,
        'startDate' => '2026-10-23',
        'startTime' => '10:00:00',
        'organiserContact' => 'organiser@example.test',
        'repeats' => RepeatPattern::Weekly,
    ];

    $this->weekly = fn (): Event => app(PublishEvent::class)(new PublishableEventData(...$this->base), $this->admin, EventOrigin::Manual);
});

dataset('unsupported rules', [
    'daily' => 'FREQ=DAILY',
    'count above 999' => 'FREQ=WEEKLY;BYDAY=FR;COUNT=1000',
    'impossible until' => 'FREQ=WEEKLY;BYDAY=FR;UNTIL=20261345',
    // WR-10 'non-round-tripping rules': they parse, but the next save would
    // rewrite them for the Friday start date.
    'weekly Monday on a Friday start' => 'FREQ=WEEKLY;BYDAY=MO',
    'second Tuesday on a Friday start' => 'FREQ=MONTHLY;BYDAY=2TU',
    'last Friday on a fourth-Friday start' => 'FREQ=MONTHLY;BYDAY=-1FR',
    'timed UNTIL at noon UTC' => 'FREQ=WEEKLY;BYDAY=FR;UNTIL=20270101T120000Z',
    'DATE UNTIL on a timed event' => 'FREQ=WEEKLY;BYDAY=FR;UNTIL=20270101',
]);

it('opens the edit page read-only with a warning and no Save action', function (string $rule) {
    $event = ($this->stored)($rule);

    livewire(EditEvent::class, ['record' => $event->getRouteKey()])
        ->assertOk()
        ->assertNotified(UNSUPPORTED_RULE_WARNING)
        ->assertActionDoesNotExist(TestAction::make('save')->schemaComponent('form-actions', 'content'))
        ->assertFormFieldDisabled('title')
        ->assertFormFieldDisabled('repeats');
})->with('unsupported rules');

it('refuses a forced save and leaves the stored event unchanged', function (string $rule) {
    $event = ($this->stored)($rule);

    livewire(EditEvent::class, ['record' => $event->getRouteKey()])
        ->set('data.title', 'Forged Title')
        ->set('data.repeats', RepeatPattern::None->value)
        ->call('save')
        ->assertNotified(UNSUPPORTED_RULE_WARNING);

    $fresh = $event->fresh();

    expect($fresh->title)->toBe('Imported Yoga')
        ->and($fresh->recurrence_rule)->toBe($rule);
})->with('unsupported rules');

it('still hides the event from the edit page header', function (string $rule) {
    $event = ($this->stored)($rule);

    livewire(EditEvent::class, ['record' => $event->getRouteKey()])
        ->callAction('hide')
        ->assertNotified('Event hidden');

    $fresh = $event->fresh();

    expect($fresh->status)->toBe(EventStatus::Hidden)
        ->and($fresh->status_changed_by)->toBe($this->admin->id)
        ->and($fresh->recurrence_rule)->toBe($rule);
})->with('unsupported rules');

it('opens a supported weekly series with Repeats hydrated, Save present and no warning', function () {
    $event = ($this->weekly)();

    livewire(EditEvent::class, ['record' => $event->getRouteKey()])
        ->assertFormSet(['repeats' => RepeatPattern::Weekly->value])
        ->assertActionExists(TestAction::make('save')->schemaComponent('form-actions', 'content'))
        ->assertFormFieldEnabled('title')
        ->assertNotNotified(UNSUPPORTED_RULE_WARNING);
});

it('refuses an edit in UpdatePublishedEvent itself when the stored rule is unsupported', function () {
    $event = ($this->stored)('FREQ=DAILY');

    expect(fn () => app(UpdatePublishedEvent::class)($event, new PublishableEventData(...$this->base), $this->admin))
        ->toThrow(InvalidArgumentException::class, "repeat rule can't be edited here");

    $fresh = $event->fresh();

    expect($fresh->title)->toBe('Imported Yoga')
        ->and($fresh->recurrence_rule)->toBe('FREQ=DAILY');

    $series = ($this->weekly)();
    $updated = app(UpdatePublishedEvent::class)($series, new PublishableEventData(...[...$this->base, 'startTime' => '11:00:00']), $this->admin);

    expect($updated->start_time)->toStartWith('11:00');
});

it('refuses an edit in UpdatePublishedEvent when the stored rule does not round-trip', function (string $rule) {
    $event = ($this->stored)($rule);

    expect(fn () => app(UpdatePublishedEvent::class)($event, new PublishableEventData(...$this->base), $this->admin))
        ->toThrow(InvalidArgumentException::class, UpdatePublishedEvent::UNSUPPORTED_RULE_REFUSED);

    $fresh = $event->fresh();

    expect($fresh->title)->toBe('Imported Yoga')
        ->and($fresh->recurrence_rule)->toBe($rule);
})->with([
    'weekly Monday on a Friday start' => 'FREQ=WEEKLY;BYDAY=MO',
    'second Tuesday on a Friday start' => 'FREQ=MONTHLY;BYDAY=2TU',
]);

it('keeps occurrences, an admin cancellation and the .ics UID when a save of a non-round-tripping rule is refused', function (string $rule, string $scheduledKey, string $cancelledKey) {
    $event = ($this->stored)($rule);
    app(OccurrenceGenerator::class)->sync($event);

    $occurrences = fn () => EventOccurrence::query()
        ->where('event_id', $event->id)
        ->orderBy('recurrence_key')
        ->get()
        ->map(fn (EventOccurrence $o): array => [
            'id' => $o->id,
            'recurrence_key' => $o->recurrence_key,
            'starts_at' => $o->starts_at->toIso8601String(),
            'status' => $o->status->value,
            'status_changed_by' => $o->status_changed_by,
            'status_changed_at' => $o->status_changed_at?->toIso8601String(),
        ])
        ->all();

    $keys = array_column($occurrences(), 'recurrence_key');
    expect($keys)->toContain($scheduledKey)->toContain($cancelledKey);

    $toCancel = EventOccurrence::query()->where('event_id', $event->id)->where('recurrence_key', $cancelledKey)->firstOrFail();
    app(ChangeOccurrenceStatus::class)($toCancel, OccurrenceStatus::Cancelled, $this->admin);

    $uidLine = function () use ($event, $scheduledKey): ?string {
        $body = (string) get("/events/{$event->slug}/{$scheduledKey}.ics")->assertOk()->getContent();
        preg_match('/^UID:.*$/m', $body, $m);

        return isset($m[0]) ? rtrim($m[0], "\r") : null;
    };

    $before = $occurrences();
    $uidBefore = $uidLine();

    expect($uidBefore)->toBe("UID:{$event->id}-{$scheduledKey}@".config('site.domain'))
        ->and(collect($before)->firstWhere('recurrence_key', $cancelledKey))
        ->toMatchArray(['status' => OccurrenceStatus::Cancelled->value, 'status_changed_by' => $this->admin->id])
        ->and(collect($before)->firstWhere('recurrence_key', $cancelledKey)['status_changed_at'])->not->toBeNull();

    // (a) forced page save
    livewire(EditEvent::class, ['record' => $event->getRouteKey()])
        ->set('data.title', 'Forged Title')
        ->call('save')
        ->assertNotified(UNSUPPORTED_RULE_WARNING);

    // (b) direct domain call
    expect(fn () => app(UpdatePublishedEvent::class)($event->fresh(), new PublishableEventData(...$this->base), $this->admin))
        ->toThrow(InvalidArgumentException::class, UpdatePublishedEvent::UNSUPPORTED_RULE_REFUSED);

    $fresh = $event->fresh();

    expect($occurrences())->toBe($before)
        ->and($uidLine())->toBe($uidBefore)
        ->and($fresh->title)->toBe('Imported Yoga')
        ->and($fresh->recurrence_rule)->toBe($rule);
})->with([
    'weekly Monday' => ['FREQ=WEEKLY;BYDAY=MO', '2026-10-26', '2026-11-02'],
    'second Tuesday' => ['FREQ=MONTHLY;BYDAY=2TU', '2026-11-10', '2026-12-08'],
]);

it('hides and cancels events from the events table', function () {
    $toHide = ($this->stored)('FREQ=DAILY', ['title' => 'Imported Yoga A']);
    $toCancel = ($this->stored)('FREQ=DAILY', ['title' => 'Imported Yoga B']);

    livewire(ListEvents::class)
        ->callAction(TestAction::make('hide')->table($toHide))
        ->assertNotified('Event hidden')
        ->callAction(TestAction::make('cancel')->table($toCancel))
        ->assertNotified('Event marked as cancelled');

    expect($toHide->fresh()->status)->toBe(EventStatus::Hidden)
        ->and($toCancel->fresh()->status)->toBe(EventStatus::Cancelled)
        ->and($toCancel->fresh()->status_changed_by)->toBe($this->admin->id);
});

it('offers Show on calendar in the table only for a hidden event', function () {
    $published = ($this->stored)('FREQ=DAILY', ['title' => 'Imported Yoga A']);
    $hidden = ($this->stored)('FREQ=DAILY', ['title' => 'Imported Yoga B', 'status' => EventStatus::Hidden]);

    livewire(ListEvents::class)
        ->assertActionHidden(TestAction::make('unhide')->table($published))
        ->assertActionVisible(TestAction::make('hide')->table($published))
        ->assertActionVisible(TestAction::make('unhide')->table($hidden))
        ->assertActionHidden(TestAction::make('hide')->table($hidden));
});

it('keeps the events list closed to anyone but the admin', function () {
    $intruder = User::factory()->create(['email' => 'someone@example.test']);

    actingAs($intruder);
    get('/admin/events')->assertForbidden();
});
