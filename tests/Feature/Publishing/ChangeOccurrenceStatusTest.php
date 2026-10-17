<?php

use App\Enums\EventOrigin;
use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\RepeatPattern;
use App\Models\Category;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\ChangeOccurrenceStatus;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Data\PublishableEventData;
use App\Publishing\Exceptions\InvalidStatusTransition;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * ChangeOccurrenceStatus is the only writer of event_occurrences.status
 * after insert (ADM-03).
 */
beforeEach(function () {
    freezeLondon('2026-10-20 10:00');

    $this->admin = User::factory()->create(['email' => config('app.admin_email')]);
    $this->stranger = User::factory()->create(['email' => 'someone@example.test']);

    $this->series = app(PublishEvent::class)(new PublishableEventData(
        title: 'Repair Café',
        description: 'Bring broken things; volunteers help you fix them.',
        categoryId: Category::factory()->create()->id,
        venueId: Venue::factory()->create()->id,
        startDate: '2026-10-23',
        startTime: '10:00:00',
        repeats: RepeatPattern::Weekly,
        organiserContact: 'organiser@example.test',
    ), $this->admin, EventOrigin::Manual);

    $this->on = fn (string $key): EventOccurrence => EventOccurrence::query()
        ->where('event_id', $this->series->id)
        ->where('recurrence_key', $key)
        ->firstOrFail();

    $this->change = fn (EventOccurrence $occurrence, OccurrenceStatus $to, ?User $actor = null): EventOccurrence => app(ChangeOccurrenceStatus::class)($occurrence, $to, $actor ?? $this->admin);

    /** @return array<string, string|null> */
    $this->timing = fn (EventOccurrence $o): array => [
        'recurrence_key' => $o->recurrence_key,
        'starts_at' => $o->starts_at->toIso8601String(),
        'ends_at' => $o->ends_at?->toIso8601String(),
        'expires_at' => $o->expires_at->toIso8601String(),
    ];
});

it('hides then restores a scheduled date, back to scheduled', function () {
    $hidden = ($this->change)(($this->on)('2026-10-30'), OccurrenceStatus::Hidden);
    expect($hidden->status)->toBe(OccurrenceStatus::Hidden);

    $restored = ($this->change)($hidden, OccurrenceStatus::Scheduled);

    expect($restored->status)->toBe(OccurrenceStatus::Scheduled)
        ->and($restored->status_changed_by)->toBe($this->admin->id)
        ->and($restored->status_changed_at?->equalTo(CarbonImmutable::now()))->toBeTrue();
});

it('refuses cancelled to hidden and leaves the row as it was', function () {
    $cancelled = ($this->change)(($this->on)('2026-10-30'), OccurrenceStatus::Cancelled);

    expect(fn () => ($this->change)($cancelled, OccurrenceStatus::Hidden))
        ->toThrow(InvalidStatusTransition::class, "Can't change status from cancelled to hidden");

    expect(($this->on)('2026-10-30')->status)->toBe(OccurrenceStatus::Cancelled);
});

it('never touches siblings, times or the recurrence key', function () {
    $target = ($this->on)('2026-11-06');
    $timingBefore = ($this->timing)($target);
    $siblingsBefore = EventOccurrence::query()->where('event_id', $this->series->id)->whereKeyNot($target->id)->orderBy('id')->get()
        ->map(fn (EventOccurrence $o) => [$o->id, $o->status->value, ($this->timing)($o), $o->updated_at->toIso8601String()])->all();

    $this->travel(5)->minutes();
    $changed = ($this->change)($target, OccurrenceStatus::Cancelled);

    $siblingsAfter = EventOccurrence::query()->where('event_id', $this->series->id)->whereKeyNot($target->id)->orderBy('id')->get()
        ->map(fn (EventOccurrence $o) => [$o->id, $o->status->value, ($this->timing)($o), $o->updated_at->toIso8601String()])->all();

    expect(($this->timing)($changed))->toBe($timingBefore)
        ->and($changed->id)->toBe($target->id)
        ->and($siblingsAfter)->toBe($siblingsBefore)
        ->and($this->series->fresh()->status)->toBe(EventStatus::Published);
});

it('raises ModelNotFoundException for a row removed after it was loaded', function () {
    $loaded = ($this->on)('2026-11-13');
    DB::table('event_occurrences')->where('id', $loaded->id)->delete();

    expect(fn () => ($this->change)($loaded, OccurrenceStatus::Cancelled))
        ->toThrow(ModelNotFoundException::class);
});

it('can cancel and hide past occurrences for the record', function () {
    $pastA = makeOccurrence(['title' => 'Harvest Supper'], '2026-10-18 18:00', '2026-10-18 21:00');
    $pastB = makeOccurrence(['title' => 'Quiz Night'], '2026-10-19 19:00', '2026-10-19 22:00');

    expect(($this->change)($pastA, OccurrenceStatus::Cancelled)->status)->toBe(OccurrenceStatus::Cancelled)
        ->and(($this->change)($pastB, OccurrenceStatus::Hidden)->status)->toBe(OccurrenceStatus::Hidden);
});

it('refuses a non-admin actor and changes nothing', function () {
    expect(fn () => ($this->change)(($this->on)('2026-10-30'), OccurrenceStatus::Cancelled, $this->stranger))
        ->toThrow(AuthorizationException::class);

    $row = ($this->on)('2026-10-30');

    expect($row->status)->toBe(OccurrenceStatus::Scheduled)
        ->and($row->status_changed_at)->toBeNull()
        ->and($row->status_changed_by)->toBeNull();
});

it('logs one info entry with ids, from, to and user_id only', function () {
    $target = ($this->on)('2026-10-30');
    $logged = collect();
    Log::listen(fn (MessageLogged $message) => $logged->push($message));

    ($this->change)($target, OccurrenceStatus::Hidden);

    $entries = $logged->filter(fn (MessageLogged $m) => $m->message === 'occurrence status changed');

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->level)->toBe('info')
        ->and($entries->first()->context)->toBe([
            'occurrence_id' => $target->id,
            'event_id' => $this->series->id,
            'from' => 'scheduled',
            'to' => 'hidden',
            'user_id' => $this->admin->id,
        ]);
});
