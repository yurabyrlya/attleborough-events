<?php

use App\Enums\RejectReason;
use App\Enums\ReviewStatus;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\User;
use App\Publishing\Actions\ApproveDraft;
use App\Publishing\Actions\RejectDraft;
use App\Publishing\Actions\ReopenDraft;
use App\Review\Exceptions\InvalidReviewTransition;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    freezeLondon('2026-10-16 12:00');
    $this->admin = User::factory()->create(['email' => 'admin@example.test']);
    $this->seed(CategorySeeder::class);
});

it('rejects as spam with a note, reopens, rejects again and the scheduled prune deletes it after 31 days', function () {
    $draft = EventDraft::factory()->submission()->create();

    $rejected = app(RejectDraft::class)($draft, $this->admin, RejectReason::Spam, '  Link farm  ');

    expect($rejected->review_status)->toBe(ReviewStatus::Rejected)
        ->and($rejected->reject_reason)->toBe(RejectReason::Spam)
        ->and($rejected->review_notes)->toBe('Link farm')
        ->and($rejected->reviewed_by)->toBe($this->admin->id);

    $row = DB::selectOne("select reject_reason, reviewed_at at time zone 'UTC' as t from event_drafts where id = ?", [$draft->id]);

    expect($row->reject_reason)->toBe('spam')
        ->and(substr((string) $row->t, 0, 19))->toBe('2026-10-16 11:00:00');

    $reopened = app(ReopenDraft::class)($draft, $this->admin);

    expect($reopened->review_status)->toBe(ReviewStatus::PendingReview)
        ->and($reopened->reject_reason)->toBeNull()
        ->and($reopened->reviewed_by)->toBeNull()
        ->and($reopened->reviewed_at)->toBeNull()
        ->and($reopened->review_notes)->toBe('Link farm');

    app(RejectDraft::class)($draft, $this->admin, RejectReason::Spam);

    freezeLondon('2026-11-16 12:00');

    $this->artisan('model:prune', ['--model' => [EventDraft::class]])->assertSuccessful();

    expect(EventDraft::query()->whereKey($draft->id)->exists())->toBeFalse();
});

it('rejects with no reason and no note, storing both as null', function () {
    $draft = EventDraft::factory()->submission()->create();

    $rejected = app(RejectDraft::class)($draft, $this->admin);

    expect($rejected->review_status)->toBe(ReviewStatus::Rejected)
        ->and($rejected->reject_reason)->toBeNull()
        ->and($rejected->review_notes)->toBeNull()
        ->and($rejected->reviewed_by)->toBe($this->admin->id);
});

it('stores a whitespace-only note as null', function () {
    $draft = EventDraft::factory()->submission()->create();

    $rejected = app(RejectDraft::class)($draft, $this->admin, RejectReason::Other, "   \n ");

    expect($rejected->reject_reason)->toBe(RejectReason::Other)
        ->and($rejected->review_notes)->toBeNull();
});

it('refuses to reject an approved draft and changes nothing', function () {
    $event = Event::factory()->create(['approved_by' => $this->admin->id]);
    $draft = EventDraft::factory()->approvedFor($event)->create();
    $before = $draft->refresh()->getAttributes();

    expect(fn () => app(RejectDraft::class)($draft, $this->admin, RejectReason::Spam, 'Nope'))
        ->toThrow(InvalidReviewTransition::class);

    expect($draft->refresh()->getAttributes())->toBe($before)
        ->and($draft->review_status)->toBe(ReviewStatus::Approved);
});

it('refuses to reopen a draft that is not rejected', function () {
    $draft = EventDraft::factory()->submission()->create();

    expect(fn () => app(ReopenDraft::class)($draft, $this->admin))
        ->toThrow(InvalidReviewTransition::class);

    expect($draft->refresh()->review_status)->toBe(ReviewStatus::PendingReview);
});

it('refuses a non-admin reject or reopen and changes nothing', function () {
    $intruder = User::factory()->create(['email' => 'someone@example.test']);
    $pending = EventDraft::factory()->submission()->create();
    $rejected = EventDraft::factory()->submission()
        ->rejected(RejectReason::Spam, CarbonImmutable::now())->create();

    expect(fn () => app(RejectDraft::class)($pending, $intruder, RejectReason::Spam))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(ReopenDraft::class)($rejected, $intruder))
        ->toThrow(AuthorizationException::class);

    expect($pending->refresh()->review_status)->toBe(ReviewStatus::PendingReview)
        ->and($pending->reviewed_by)->toBeNull()
        ->and($rejected->refresh()->review_status)->toBe(ReviewStatus::Rejected)
        ->and($rejected->reject_reason)->toBe(RejectReason::Spam);
});

it('sends no mail or notification when drafts are approved, rejected or reopened (D-02)', function () {
    Mail::fake();
    Notification::fake();

    $toApprove = EventDraft::factory()->create();
    $toReject = EventDraft::factory()->submission()->create();

    app(ApproveDraft::class)($toApprove, $this->admin);
    app(RejectDraft::class)($toReject, $this->admin, RejectReason::Spam, 'Link farm');
    app(ReopenDraft::class)($toReject, $this->admin);
    app(RejectDraft::class)($toReject, $this->admin);

    expect($toApprove->refresh()->review_status)->toBe(ReviewStatus::Approved)
        ->and($toReject->refresh()->review_status)->toBe(ReviewStatus::Rejected);

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
});
