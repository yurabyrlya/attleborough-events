<?php

use App\Enums\EventOrigin;
use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\RepeatPattern;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\ChangeEventStatus;
use App\Publishing\Actions\ChangeOccurrenceStatus;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Data\PublishableEventData;
use App\Publishing\Exceptions\InvalidStatusTransition;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;

/*
 * ChangeEventStatus is the only writer of events.status after publish
 * (ADM-03): admin-only, row-locked, asserted on the fresh status, writes
 * status + audit columns only, never touches occurrences.
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

    $this->change = fn (Event $event, EventStatus $to, ?User $actor = null): Event => app(ChangeEventStatus::class)($event, $to, $actor ?? $this->admin);

    /** @return array<int, array{status: string, starts_at: string}> */
    $this->occurrenceSnapshot = fn (Event $event): array => EventOccurrence::query()
        ->where('event_id', $event->id)
        ->orderBy('id')
        ->get()
        ->mapWithKeys(fn (EventOccurrence $o) => [$o->id => ['status' => $o->status->value, 'starts_at' => $o->starts_at->toIso8601String()]])
        ->all();
});

it('hides a published event, records who and when, and changes nothing else', function () {
    $before = $this->series->fresh();
    $occurrencesBefore = ($this->occurrenceSnapshot)($before);

    $hidden = ($this->change)($before, EventStatus::Hidden);

    expect($hidden->status)->toBe(EventStatus::Hidden)
        ->and($hidden->status_changed_by)->toBe($this->admin->id)
        ->and($hidden->status_changed_at?->equalTo(CarbonImmutable::now()))->toBeTrue();

    foreach (['title', 'slug', 'start_time', 'recurrence_rule', 'approved_by'] as $column) {
        expect($hidden->{$column})->toBe($before->{$column});
    }

    expect($hidden->start_date->equalTo($before->start_date))->toBeTrue()
        ->and($hidden->origin)->toBe($before->origin)
        ->and($hidden->approved_at->equalTo($before->approved_at))->toBeTrue()
        ->and(($this->occurrenceSnapshot)($hidden))->toBe($occurrencesBefore)
        ->and($occurrencesBefore)->toHaveCount(13);
});

it('refuses a non-admin actor and changes nothing', function () {
    expect(fn () => ($this->change)($this->series, EventStatus::Hidden, $this->stranger))
        ->toThrow(AuthorizationException::class);

    $fresh = $this->series->fresh();

    expect($fresh->status)->toBe(EventStatus::Published)
        ->and($fresh->status_changed_at)->toBeNull()
        ->and($fresh->status_changed_by)->toBeNull();
});

it('asserts the transition on the freshly locked status, not on a stale model', function () {
    $stale = Event::query()->findOrFail($this->series->id);

    ($this->change)(Event::query()->findOrFail($this->series->id), EventStatus::Hidden);

    expect($stale->status)->toBe(EventStatus::Published)
        ->and(fn () => ($this->change)($stale, EventStatus::Cancelled))
        ->toThrow(InvalidStatusTransition::class, "Can't change status from hidden to cancelled");

    expect($this->series->fresh()->status)->toBe(EventStatus::Hidden);
});

it('leaves an individually cancelled date cancelled, with its own audit values, when the event is reinstated', function () {
    $occurrence = EventOccurrence::query()->where('event_id', $this->series->id)->where('recurrence_key', '2026-11-06')->firstOrFail();
    $cancelledAt = CarbonImmutable::now();
    app(ChangeOccurrenceStatus::class)($occurrence, OccurrenceStatus::Cancelled, $this->admin);

    $this->travel(1)->hours();
    $cancelled = ($this->change)($this->series, EventStatus::Cancelled);
    $this->travel(1)->hours();
    $reinstated = ($this->change)($cancelled, EventStatus::Published);

    $occurrence->refresh();

    expect($reinstated->status)->toBe(EventStatus::Published)
        ->and($occurrence->status)->toBe(OccurrenceStatus::Cancelled)
        ->and($occurrence->status_changed_by)->toBe($this->admin->id)
        ->and($occurrence->status_changed_at?->equalTo($cancelledAt))->toBeTrue();
});

it('hides, shows, cancels and reinstates an event whose only occurrence expired yesterday', function () {
    $past = makeOccurrence(['title' => 'Harvest Supper'], '2026-10-19 18:00', '2026-10-19 21:00');
    $event = $past->event;

    $event = ($this->change)($event, EventStatus::Hidden);
    $event = ($this->change)($event, EventStatus::Published);
    $event = ($this->change)($event, EventStatus::Cancelled);
    $event = ($this->change)($event, EventStatus::Published);

    expect($event->status)->toBe(EventStatus::Published)
        ->and($past->fresh()->status)->toBe(OccurrenceStatus::Scheduled);
});

it('logs one info entry with ids, from, to and user_id only', function () {
    $logged = collect();
    Log::listen(fn (MessageLogged $message) => $logged->push($message));

    ($this->change)($this->series, EventStatus::Cancelled);

    $entries = $logged->filter(fn (MessageLogged $m) => $m->message === 'event status changed');

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->level)->toBe('info')
        ->and($entries->first()->context)->toBe([
            'event_id' => $this->series->id,
            'from' => 'published',
            'to' => 'cancelled',
            'user_id' => $this->admin->id,
        ]);
});
