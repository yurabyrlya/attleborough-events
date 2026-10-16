<?php

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Publishing\Exceptions\InvalidStatusTransition;

/*
 * The status state machines (ARCHITECTURE Pattern 3). Pure enum logic: every
 * one of the 9 pairs per enum is pinned, including same-to-same.
 */

dataset('event status pairs', [
    'published → hidden' => [EventStatus::Published, EventStatus::Hidden, true],
    'published → cancelled' => [EventStatus::Published, EventStatus::Cancelled, true],
    'published → published' => [EventStatus::Published, EventStatus::Published, false],
    'hidden → published' => [EventStatus::Hidden, EventStatus::Published, true],
    'hidden → cancelled' => [EventStatus::Hidden, EventStatus::Cancelled, false],
    'hidden → hidden' => [EventStatus::Hidden, EventStatus::Hidden, false],
    'cancelled → published' => [EventStatus::Cancelled, EventStatus::Published, true],
    'cancelled → hidden' => [EventStatus::Cancelled, EventStatus::Hidden, false],
    'cancelled → cancelled' => [EventStatus::Cancelled, EventStatus::Cancelled, false],
]);

dataset('occurrence status pairs', [
    'scheduled → cancelled' => [OccurrenceStatus::Scheduled, OccurrenceStatus::Cancelled, true],
    'scheduled → hidden' => [OccurrenceStatus::Scheduled, OccurrenceStatus::Hidden, true],
    'scheduled → scheduled' => [OccurrenceStatus::Scheduled, OccurrenceStatus::Scheduled, false],
    'cancelled → scheduled' => [OccurrenceStatus::Cancelled, OccurrenceStatus::Scheduled, true],
    'cancelled → hidden' => [OccurrenceStatus::Cancelled, OccurrenceStatus::Hidden, false],
    'cancelled → cancelled' => [OccurrenceStatus::Cancelled, OccurrenceStatus::Cancelled, false],
    'hidden → scheduled' => [OccurrenceStatus::Hidden, OccurrenceStatus::Scheduled, true],
    'hidden → cancelled' => [OccurrenceStatus::Hidden, OccurrenceStatus::Cancelled, false],
    'hidden → hidden' => [OccurrenceStatus::Hidden, OccurrenceStatus::Hidden, false],
]);

it('allows exactly the event status transitions in the machine table', function (EventStatus $from, EventStatus $to, bool $allowed) {
    expect($from->canTransitionTo($to))->toBe($allowed);

    if ($allowed) {
        $from->assertCanTransitionTo($to);
    } else {
        expect(fn () => $from->assertCanTransitionTo($to))->toThrow(InvalidStatusTransition::class);
    }
})->with('event status pairs');

it('allows exactly the occurrence status transitions in the machine table', function (OccurrenceStatus $from, OccurrenceStatus $to, bool $allowed) {
    expect($from->canTransitionTo($to))->toBe($allowed);

    if ($allowed) {
        $from->assertCanTransitionTo($to);
    } else {
        expect(fn () => $from->assertCanTransitionTo($to))->toThrow(InvalidStatusTransition::class);
    }
})->with('occurrence status pairs');

it('names both statuses in the refused transition and exposes them', function () {
    try {
        EventStatus::Hidden->assertCanTransitionTo(EventStatus::Cancelled);
        $this->fail('Expected InvalidStatusTransition');
    } catch (InvalidStatusTransition $e) {
        expect($e->getMessage())->toBe("Can't change status from hidden to cancelled")
            ->and($e->from)->toBe(EventStatus::Hidden)
            ->and($e->to)->toBe(EventStatus::Cancelled);
    }

    try {
        OccurrenceStatus::Hidden->assertCanTransitionTo(OccurrenceStatus::Cancelled);
        $this->fail('Expected InvalidStatusTransition');
    } catch (InvalidStatusTransition $e) {
        expect($e->getMessage())->toBe("Can't change status from hidden to cancelled")
            ->and($e->from)->toBe(OccurrenceStatus::Hidden)
            ->and($e->to)->toBe(OccurrenceStatus::Cancelled);
    }
});

it('lists published and cancelled events publicly but never hidden ones', function () {
    expect(EventStatus::publiclyListed())->toBe([EventStatus::Published, EventStatus::Cancelled])
        ->and(EventStatus::Published->isPubliclyListed())->toBeTrue()
        ->and(EventStatus::Cancelled->isPubliclyListed())->toBeTrue()
        ->and(EventStatus::Hidden->isPubliclyListed())->toBeFalse();
});

it('lists scheduled and cancelled occurrences publicly but never hidden ones', function () {
    expect(OccurrenceStatus::publiclyListed())->toBe([OccurrenceStatus::Scheduled, OccurrenceStatus::Cancelled])
        ->and(OccurrenceStatus::Scheduled->isPubliclyListed())->toBeTrue()
        ->and(OccurrenceStatus::Cancelled->isPubliclyListed())->toBeTrue()
        ->and(OccurrenceStatus::Hidden->isPubliclyListed())->toBeFalse();
});
