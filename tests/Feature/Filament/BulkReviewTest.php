<?php

use App\Enums\EventOrigin;
use App\Enums\QualityBand;
use App\Enums\RejectReason;
use App\Enums\ReviewStatus;
use App\Filament\Resources\EventDrafts\Pages\ListEventDrafts;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\ApproveDraft;
use Filament\Notifications\NotificationMailbox;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

/*
 * Bulk approve and bulk reject on the review queue (REV-08, D-06, D-21).
 * Every skipped draft is counted under one named reason; nothing is
 * skipped silently.
 */
beforeEach(function () {
    freezeLondon('2026-10-20 10:00');

    $this->admin = User::factory()->create(['email' => config('app.admin_email')]);
    actingAs($this->admin);
});

/**
 * The bodies of every notification sent so far, as plain text lines.
 *
 * @return list<string>
 */
function bulkNotificationLines(): array
{
    $mailbox = app(NotificationMailbox::class);
    $mailbox->flush();

    return collect($mailbox->pull())
        ->flatMap(fn (array $notification): array => [
            (string) ($notification['title'] ?? ''),
            ...preg_split('/<\/?p>/', (string) ($notification['body'] ?? ''), flags: PREG_SPLIT_NO_EMPTY),
        ])
        ->filter()
        ->values()
        ->all();
}

it('approves the publishable drafts and names every skipped draft by its reason', function () {
    Venue::factory()->create(['name' => 'The Old Barn']);
    $existing = Event::factory()->create();
    $eventsBefore = Event::count();

    $clean = EventDraft::factory()->count(2)->sequence(['title' => 'Harvest Supper'], ['title' => 'Quiz Night'])->create();
    $low = EventDraft::factory()->scored(QualityBand::Low, 30, ['No description'])->create(['title' => 'Low Draft']);
    $parse = EventDraft::factory()->withParseErrors(['Could not read the date'])->create(['title' => 'Parse Draft']);
    $duplicate = EventDraft::factory()->duplicateOf($existing)->create(['title' => 'Duplicate Draft']);
    $unconfirmed = EventDraft::factory()->freeTextVenue()->create(['title' => 'Barn Dance']);

    livewire(ListEventDrafts::class)
        ->callTableBulkAction('approveSelected', [...$clean, $low, $parse, $duplicate, $unconfirmed]);

    $lines = bulkNotificationLines();

    expect(Event::count())->toBe($eventsBefore + 2)
        ->and($clean->map(fn (EventDraft $d) => $d->refresh()->review_status)->all())->each->toBe(ReviewStatus::Approved)
        ->and($lines)->toContain('Approved 2 of 6. The rest were skipped:')
        ->and($lines)->toContain('1 skipped: Low quality')
        ->and($lines)->toContain('1 skipped: has parse errors')
        ->and($lines)->toContain('1 skipped: possible duplicate')
        ->and($lines)->toContain('1 skipped: no confirmed venue');

    foreach ([$low, $parse, $duplicate, $unconfirmed] as $skipped) {
        expect($skipped->refresh()->review_status)->toBe(ReviewStatus::PendingReview)
            ->and($skipped->event_id)->toBeNull();
    }

    get('/')->assertOk()->assertSee('Harvest Supper')->assertSee('Quiz Night')->assertDontSee('Barn Dance');
});

it('adds up the skip counts to the drafts that were not approved', function () {
    $clean = EventDraft::factory()->create();
    $lows = EventDraft::factory()->count(2)->scored(QualityBand::Low, 20)->create();
    $unscored = EventDraft::factory()->create(['quality_band' => null, 'quality_score' => null]);
    $both = EventDraft::factory()->scored(QualityBand::Low, 10)->withParseErrors(['Bad time'])->create();

    livewire(ListEventDrafts::class)
        ->callTableBulkAction('approveSelected', [$clean, ...$lows, $unscored, $both]);

    $lines = bulkNotificationLines();

    // Unscored counts as Low; a draft with two problems is counted once,
    // under its first key (parse errors before Low quality).
    expect($lines)->toContain('Approved 1 of 5. The rest were skipped:')
        ->and($lines)->toContain('3 skipped: Low quality')
        ->and($lines)->toContain('1 skipped: has parse errors')
        ->and(EventDraft::where('review_status', ReviewStatus::PendingReview)->count())->toBe(4);
});

it('skips a draft whose organiser says it repeats but has no rule', function () {
    $repeats = EventDraft::factory()->withRepeatsText('Every other Tuesday')->create(['repeats_acknowledged' => true]);

    livewire(ListEventDrafts::class)
        ->callTableBulkAction('approveSelected', [$repeats]);

    expect(bulkNotificationLines())->toContain('1 skipped: repeat rule not set')
        ->and($repeats->refresh()->review_status)->toBe(ReviewStatus::PendingReview)
        ->and(Event::count())->toBe(0);
});

it('skips a discovered draft whose description may still hold a contact (review CR-01)', function () {
    $draft = EventDraft::factory()->create(['origin' => EventOrigin::Discovered, 'description' => 'Ring 01953.452300 to book']);

    livewire(ListEventDrafts::class)
        ->callTableBulkAction('approveSelected', [$draft]);

    expect(bulkNotificationLines())->toContain('1 skipped: description may contain a phone number or email')
        ->and($draft->refresh()->review_status)->toBe(ReviewStatus::PendingReview)
        ->and(Event::count())->toBe(0);
});

it('skips a draft whose same-day end time equals its start time', function () {
    $draft = EventDraft::factory()->create(['start_time' => '19:00:00', 'end_time' => '19:00:00']);

    livewire(ListEventDrafts::class)
        ->callTableBulkAction('approveSelected', [$draft]);

    expect(bulkNotificationLines())->toContain('1 skipped: end time same as start time')
        ->and($draft->refresh()->review_status)->toBe(ReviewStatus::PendingReview)
        ->and(Event::count())->toBe(0);
});

it('reports a draft approved elsewhere first as already reviewed and approves the rest', function () {
    $raced = EventDraft::factory()->create(['title' => 'Raced Draft']);
    $others = EventDraft::factory()->count(2)->create();

    $component = livewire(ListEventDrafts::class);

    // Another tab approves it after the queue was loaded.
    app(ApproveDraft::class)($raced, $this->admin);

    $component->callTableBulkAction('approveSelected', [$raced, ...$others]);

    $lines = bulkNotificationLines();

    expect($lines)->toContain('1 skipped: already reviewed')
        ->and($lines)->toContain('Approved 2 of 3. The rest were skipped:')
        ->and(Event::count())->toBe(3)
        ->and($others->map(fn (EventDraft $d) => $d->refresh()->review_status)->all())->each->toBe(ReviewStatus::Approved);
});

it('bulk rejects every selected pending draft, including Low-quality and parse-error drafts', function () {
    $drafts = collect([
        EventDraft::factory()->create(),
        EventDraft::factory()->scored(QualityBand::Low, 15)->create(),
        EventDraft::factory()->withParseErrors(['Could not read the date'])->create(),
    ]);

    livewire(ListEventDrafts::class)
        ->callTableBulkAction('rejectSelected', $drafts, data: [
            'reject_reason' => RejectReason::Spam->value,
            'review_notes' => 'Same advert three times',
        ])
        ->assertHasNoFormErrors();

    foreach ($drafts as $draft) {
        $draft->refresh();

        expect($draft->review_status)->toBe(ReviewStatus::Rejected)
            ->and($draft->reject_reason)->toBe(RejectReason::Spam)
            ->and($draft->review_notes)->toBe('Same advert three times');
    }

    expect(Event::count())->toBe(0);
});

it('bulk rejects without a reason or note', function () {
    $draft = EventDraft::factory()->create();

    livewire(ListEventDrafts::class)
        ->callTableBulkAction('rejectSelected', [$draft]);

    expect($draft->refresh()->review_status)->toBe(ReviewStatus::Rejected)
        ->and($draft->reject_reason)->toBeNull();
});

it('reports an already-approved draft in a bulk reject as already reviewed', function () {
    $approved = EventDraft::factory()->create();
    app(ApproveDraft::class)($approved, $this->admin);
    $pending = EventDraft::factory()->create();

    livewire(ListEventDrafts::class)
        ->callTableBulkAction('rejectSelected', [$approved, $pending], data: ['reject_reason' => RejectReason::Spam->value]);

    expect(bulkNotificationLines())->toContain('1 skipped: already reviewed')
        ->and($approved->refresh()->review_status)->toBe(ReviewStatus::Approved)
        ->and($pending->refresh()->review_status)->toBe(ReviewStatus::Rejected);
});

it('lets every draft be selected and labels the bulk dialogs', function () {
    $low = EventDraft::factory()->scored(QualityBand::Low, 10)->withParseErrors(['x'])->create();

    livewire(ListEventDrafts::class)
        ->assertTableBulkActionExists('approveSelected')
        ->assertTableBulkActionExists('rejectSelected')
        ->mountTableBulkAction('approveSelected', [$low])
        ->assertMountedActionModalSee([
            'Approve the selected drafts?',
            'Drafts with problems are skipped and listed afterwards.',
            'Approve',
            'Keep as is',
        ]);

    expect(livewire(ListEventDrafts::class)->instance()->getTable()->isRecordSelectable($low))->toBeTrue();
});
