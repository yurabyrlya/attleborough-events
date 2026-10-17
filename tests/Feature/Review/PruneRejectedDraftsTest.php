<?php

use App\Enums\RejectReason;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;

/**
 * Retention for rejected drafts (D-12, published on /privacy): spam goes
 * 30 days after rejection, anything else after 90. Pending and approved
 * drafts are never pruned.
 *
 * "Now" is Sun 1 Nov 2026, after BST ended on Sun 25 Oct, so the 29/31-day
 * cases straddle the 25-hour day.
 */
beforeEach(function () {
    freezeLondon('2026-11-01 12:00');
    $this->seed(CategorySeeder::class);
});

/**
 * A draft rejected the given whole number of days before now.
 */
function rejectedDaysAgo(?RejectReason $reason, int $days): EventDraft
{
    $draft = EventDraft::factory()->submission()->create();

    $draft->forceFill([
        'review_status' => 'rejected',
        'reject_reason' => $reason,
        'reviewed_at' => CarbonImmutable::now()->utc()->subDays($days),
    ])->save();

    return $draft;
}

function pruneDrafts(): void
{
    test()->artisan('model:prune', ['--model' => [EventDraft::class]])->assertSuccessful();
}

function draftExists(EventDraft $draft): bool
{
    return EventDraft::query()->whereKey($draft->id)->exists();
}

it('keeps a spam rejection from 29 days ago', function () {
    $draft = rejectedDaysAgo(RejectReason::Spam, 29);

    pruneDrafts();

    expect(draftExists($draft))->toBeTrue();
});

it('deletes a spam rejection from 31 days ago', function () {
    $draft = EventDraft::factory()->submission()
        ->rejected(RejectReason::Spam, CarbonImmutable::now()->subDays(31))->create();

    pruneDrafts();

    expect(draftExists($draft))->toBeFalse();
});

it('keeps a non-spam rejection from 89 days ago', function () {
    $draft = rejectedDaysAgo(RejectReason::OutOfArea, 89);

    pruneDrafts();

    expect(draftExists($draft))->toBeTrue();
});

it('deletes a non-spam rejection from 91 days ago', function () {
    $draft = rejectedDaysAgo(RejectReason::OutOfArea, 91);

    pruneDrafts();

    expect(draftExists($draft))->toBeFalse();
});

it('treats a rejection with no reason as non-spam: kept at 31 days, deleted at 91', function () {
    $kept = rejectedDaysAgo(null, 31);
    $gone = rejectedDaysAgo(null, 91);

    pruneDrafts();

    expect(draftExists($kept))->toBeTrue()
        ->and(draftExists($gone))->toBeFalse();
});

it('never prunes a pending draft, however old', function () {
    $draft = EventDraft::factory()->submission()->create();
    $draft->forceFill(['created_at' => CarbonImmutable::now()->utc()->subDays(400)])->save();

    pruneDrafts();

    expect(draftExists($draft))->toBeTrue();
});

it('never prunes an approved draft, however old', function () {
    $admin = User::factory()->create(['email' => 'admin@example.test']);
    $event = Event::factory()->create([
        'approved_by' => $admin->id,
        'approved_at' => CarbonImmutable::now()->utc()->subDays(400),
    ]);
    $draft = EventDraft::factory()->approvedFor($event)->create();
    $draft->forceFill(['created_at' => CarbonImmutable::now()->utc()->subDays(400)])->save();

    pruneDrafts();

    expect(draftExists($draft))->toBeTrue()
        ->and($draft->refresh()->reviewed_at->lessThan(CarbonImmutable::now()->subDays(399)))->toBeTrue();
});
