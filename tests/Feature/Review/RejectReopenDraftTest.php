<?php

use App\Enums\RejectReason;
use App\Enums\ReviewStatus;
use App\Models\EventDraft;
use App\Models\User;
use App\Publishing\Actions\RejectDraft;
use App\Publishing\Actions\ReopenDraft;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Facades\DB;

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
