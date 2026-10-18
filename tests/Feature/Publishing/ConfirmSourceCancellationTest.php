<?php

use App\Enums\DraftKind;
use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\ReviewStatus;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\EventOccurrence;
use App\Models\Source;
use App\Models\SourceItem;
use App\Models\User;
use App\Publishing\Actions\ApproveDraft;
use App\Publishing\Actions\ChangeOccurrenceStatus;
use App\Publishing\Actions\ConfirmSourceCancellation;
use App\Publishing\Actions\IgnoreSourceChange;
use App\Review\Exceptions\DraftNotPublishable;
use Database\Seeders\CategorySeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/*
 * REV-07, D-08 (03-10): confirming a cancellation cancels exactly the
 * listed dates of a series (through ChangeOccurrenceStatus, siblings
 * untouched) or the whole event (through ChangeEventStatus). The dates stay
 * on the public pages with a "Cancelled" text label. Confirmed dates are
 * also kept in events.cancelled_dates, so a date beyond the occurrence
 * horizon is inserted as Cancelled when the nightly sync reaches it.
 * C9: a rolled-back confirm logs nothing. Offline.
 */

const CONFIRM_TC_URL = 'https://attleboroughtc.org.uk/?post_type=tribe_events&ical=1';

const CONFIRM_SERIES_URL = 'https://feed.example.org/series.ics';

const CONFIRM_YOGA_UID = 'UID:10003893-1792922400-1792926000@attleboroughtc.org.uk';

/**
 * series.ics with cancelled overrides of the Wednesday Folk Club (19:00)
 * on $dates (Ymd).
 *
 * @param  list<string>  $dates
 */
function confirmFolkFeed(array $dates): string
{
    $blocks = '';

    foreach ($dates as $date) {
        $blocks .= implode("\r\n", [
            'BEGIN:VEVENT', 'UID:s-exdate@synthetic.invalid', 'DTSTAMP:20261017T050000Z',
            "RECURRENCE-ID;TZID=Europe/London:{$date}T190000", 'SUMMARY:Wednesday Folk Club',
            "DTSTART;TZID=Europe/London:{$date}T190000", 'STATUS:CANCELLED', 'END:VEVENT',
        ])."\r\n";
    }

    return str_replace('END:VCALENDAR', $blocks.'END:VCALENDAR', icsFixture('series.ics'));
}

/**
 * A weekly Whist Drive (20 Wednesdays from 21 Oct), with cancelled
 * overrides on $dates (Ymd).
 *
 * @param  list<string>  $dates
 */
function confirmWhistFeed(array $dates = []): string
{
    $lines = [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//What\'s On Attleborough//Confirm test//EN',
        'BEGIN:VEVENT', 'UID:whist@synthetic.invalid', 'DTSTAMP:20261017T050000Z', 'SUMMARY:Wednesday Whist Drive',
        'DTSTART;TZID=Europe/London:20261021T190000', 'DTEND;TZID=Europe/London:20261021T210000',
        'RRULE:FREQ=WEEKLY;BYDAY=WE;COUNT=20', 'LOCATION:Town Hall', 'END:VEVENT',
    ];

    foreach ($dates as $date) {
        array_push(
            $lines,
            'BEGIN:VEVENT', 'UID:whist@synthetic.invalid', 'DTSTAMP:20261017T050000Z', 'SUMMARY:Wednesday Whist Drive',
            "RECURRENCE-ID;TZID=Europe/London:{$date}T190000", "DTSTART;TZID=Europe/London:{$date}T190000",
            'STATUS:CANCELLED', 'END:VEVENT',
        );
    }

    $lines[] = 'END:VCALENDAR';

    return implode("\r\n", $lines)."\r\n";
}

function confirmApproveFolk(Source $series): Event
{
    ingestFeed(icsFixture('series.ics'));
    ingestLiveRun($series);

    return ingestApprove(EventDraft::query()->where('source_id', $series->id)->where('title', 'Wednesday Folk Club')->sole());
}

function confirmApproveYoga(Source $tc): Event
{
    ingestFeed(sourceFixture('attleborough-town-council/2026-10-17-ical.ics'));
    ingestLiveRun($tc);

    return ingestApprove(EventDraft::query()->where('kind', DraftKind::New)->where('title', '[name removed] ~ Yoga')->where('start_date', '2026-10-25')->sole());
}

function confirmCancelYogaFeed(): string
{
    return ingestEditEvent(
        sourceFixture('attleborough-town-council/2026-10-17-ical.ics'),
        CONFIRM_YOGA_UID,
        ["SUMMARY:[name removed] ~ Yoga\r\n" => "SUMMARY:[name removed] ~ Yoga\r\nSTATUS:CANCELLED\r\n"],
    );
}

function confirmPendingCancellation(): EventDraft
{
    return EventDraft::query()->where('kind', DraftKind::Cancellation)->where('review_status', ReviewStatus::PendingReview)->sole();
}

/** @return array<string, OccurrenceStatus> */
function confirmStatuses(Event $event): array
{
    return EventOccurrence::query()->where('event_id', $event->id)->orderBy('recurrence_key')->pluck('status', 'recurrence_key')->all();
}

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    Storage::fake('local');
    freezeLondon('2026-10-17 06:00:00');
    $this->seed(CategorySeeder::class);

    $this->tc = Source::factory()->ics()->active()->withIdentity('tec_post_id')->create(['name' => 'Attleborough Town Council', 'url' => CONFIRM_TC_URL]);
    $this->series = Source::factory()->ics()->active()->create(['name' => 'Synthetic series', 'url' => CONFIRM_SERIES_URL]);
    $this->admin = ingestAdmin();
    $this->confirm = app(ConfirmSourceCancellation::class);
});

it('cancels exactly the listed series dates, keeps them visible as Cancelled and leaves the other dates scheduled', function () {
    $event = confirmApproveFolk($this->series);

    ingestFeed(confirmFolkFeed(['20261104', '20261111']));
    ingestLiveRun($this->series->refresh());
    $cancellation = confirmPendingCancellation();
    expect($cancellation->diff)->toBe(['cancelled_keys' => ['2026-11-04', '2026-11-11']]);

    $confirmed = ($this->confirm)($cancellation, $this->admin);
    $item = SourceItem::query()->findOrFail($cancellation->source_item_id);

    $statuses = confirmStatuses($event);
    unset($statuses['2026-10-14']);

    expect($statuses)->toBe([
        '2026-10-28' => OccurrenceStatus::Scheduled,
        '2026-11-04' => OccurrenceStatus::Cancelled,
        '2026-11-11' => OccurrenceStatus::Cancelled,
        '2026-11-18' => OccurrenceStatus::Scheduled,
    ])
        ->and($event->refresh()->status)->toBe(EventStatus::Published)
        ->and($event->cancelled_dates)->toBe(['2026-11-04', '2026-11-11'])
        ->and($confirmed->review_status)->toBe(ReviewStatus::Approved)
        ->and($confirmed->reviewed_by)->toBe($this->admin->id)
        ->and($confirmed->event_id)->toBe($event->id)
        ->and($item->accepted_payload['cancelled_keys'])->toBe(['2026-11-04', '2026-11-11'])
        ->and($item->accepted_hash)->toBe($item->content_hash);

    $page = $this->get(route('events.show', $event->slug))->assertOk();
    expect(substr_count((string) $page->getContent(), '>Cancelled</strong>'))->toBe(2);

    $this->travel(1)->hours();
    expect(ingestLiveRun($this->series->refresh())->drafts_created)->toBe(0);
});

it('keeps a confirmed date beyond the horizon in events.cancelled_dates, so the sync inserts it as Cancelled', function () {
    $source = Source::factory()->ics()->active()->create(['url' => 'https://feed.example.org/whist.ics']);
    ingestFeed(confirmWhistFeed());
    ingestLiveRun($source);
    $event = ingestApprove(EventDraft::query()->where('source_id', $source->id)->sole());

    // 20 Jan 2027: 95 days after 17 Oct, beyond the 90-day horizon.
    ingestFeed(confirmWhistFeed(['20270120']));
    ingestLiveRun($source->refresh());
    $cancellation = confirmPendingCancellation();
    expect(EventOccurrence::query()->where('event_id', $event->id)->where('recurrence_key', '2027-01-20')->exists())->toBeFalse();

    ($this->confirm)($cancellation, $this->admin);

    expect($event->refresh()->cancelled_dates)->toBe(['2027-01-20']);

    freezeLondon('2026-11-01 04:00:00');
    $this->artisan('calendar:sync-occurrences')->assertSuccessful();

    $statuses = confirmStatuses($event);
    expect($statuses['2027-01-20'])->toBe(OccurrenceStatus::Cancelled)
        ->and($statuses['2027-01-13'])->toBe(OccurrenceStatus::Scheduled);
});

it('never adds an ignored cancellation to events.cancelled_dates, so the far date is created as Scheduled', function () {
    $source = Source::factory()->ics()->active()->create(['url' => 'https://feed.example.org/whist.ics']);
    ingestFeed(confirmWhistFeed());
    ingestLiveRun($source);
    $event = ingestApprove(EventDraft::query()->where('source_id', $source->id)->sole());

    ingestFeed(confirmWhistFeed(['20270120']));
    ingestLiveRun($source->refresh());
    app(IgnoreSourceChange::class)(confirmPendingCancellation(), $this->admin);

    expect($event->refresh()->cancelled_dates)->toBe([]);

    freezeLondon('2026-11-01 04:00:00');
    $this->artisan('calendar:sync-occurrences')->assertSuccessful();

    expect(confirmStatuses($event)['2027-01-20'])->toBe(OccurrenceStatus::Scheduled);
});

it('cancels a one-off through its event status, so the event page says Cancelled (A8)', function () {
    $event = confirmApproveYoga($this->tc);

    ingestFeed(confirmCancelYogaFeed());
    ingestLiveRun($this->tc->refresh());
    $cancellation = confirmPendingCancellation();

    $confirmed = ($this->confirm)($cancellation, $this->admin);
    $item = SourceItem::query()->findOrFail($cancellation->source_item_id);

    expect($event->refresh()->status)->toBe(EventStatus::Cancelled)
        ->and($event->status_changed_by)->toBe($this->admin->id)
        ->and($confirmed->review_status)->toBe(ReviewStatus::Approved)
        ->and($item->accepted_payload['cancelled'])->toBeTrue()
        ->and($item->accepted_hash)->toBe($item->content_hash);

    $this->get(route('events.show', $event->slug))
        ->assertOk()
        ->assertSee('This event has been cancelled.')
        ->assertSee('>Cancelled</strong>', false);
});

it('cancels the whole event for a missing-item cancellation', function () {
    $event = confirmApproveYoga($this->tc);

    ingestFeed(confirmCancelYogaFeed());
    ingestLiveRun($this->tc->refresh());
    // The shape MissingItemDetector writes (03-09).
    $cancellation = confirmPendingCancellation();
    $cancellation->forceFill(['diff' => ['status' => ['published' => 'published', 'before' => 'active', 'now' => 'missing']]])->save();

    ($this->confirm)($cancellation->refresh(), $this->admin);

    expect($event->refresh()->status)->toBe(EventStatus::Cancelled)
        ->and($cancellation->refresh()->review_status)->toBe(ReviewStatus::Approved);
});

it('succeeds when the admin already cancelled the date or the event', function () {
    $event = confirmApproveFolk($this->series);
    $occurrence = EventOccurrence::query()->where('event_id', $event->id)->where('recurrence_key', '2026-11-04')->sole();
    app(ChangeOccurrenceStatus::class)($occurrence, OccurrenceStatus::Cancelled, $this->admin);

    ingestFeed(confirmFolkFeed(['20261104']));
    ingestLiveRun($this->series->refresh());

    $confirmed = ($this->confirm)(confirmPendingCancellation(), $this->admin);

    expect($confirmed->review_status)->toBe(ReviewStatus::Approved)
        ->and(confirmStatuses($event)['2026-11-04'])->toBe(OccurrenceStatus::Cancelled)
        ->and($event->refresh()->cancelled_dates)->toBe(['2026-11-04']);
});

it('rolls everything back and logs no status change when the confirm fails after cancelling (C9)', function () {
    $event = confirmApproveFolk($this->series);

    ingestFeed(confirmFolkFeed(['20261104']));
    ingestLiveRun($this->series->refresh());
    $cancellation = confirmPendingCancellation();
    $baseline = SourceItem::query()->findOrFail($cancellation->source_item_id)->accepted_hash;

    // Fails at the last step (the baseline move), after ChangeOccurrenceStatus ran.
    SourceItem::saving(fn () => throw new RuntimeException('forced failure'));
    Log::spy();

    expect(fn () => ($this->confirm)($cancellation, $this->admin))->toThrow(RuntimeException::class, 'forced failure');

    Log::shouldNotHaveReceived('info', fn (string $message): bool => $message === 'occurrence status changed');
    Log::shouldNotHaveReceived('info', fn (string $message): bool => $message === 'source cancellation confirmed');

    SourceItem::flushEventListeners();

    expect(confirmStatuses($event)['2026-11-04'])->toBe(OccurrenceStatus::Scheduled)
        ->and($event->refresh()->cancelled_dates)->toBe([])
        ->and($cancellation->refresh()->review_status)->toBe(ReviewStatus::PendingReview)
        ->and(SourceItem::query()->findOrFail($cancellation->source_item_id)->accepted_hash)->toBe($baseline);
});

it('logs the status change and the confirmation after a successful commit', function () {
    confirmApproveFolk($this->series);

    ingestFeed(confirmFolkFeed(['20261104']));
    ingestLiveRun($this->series->refresh());
    $cancellation = confirmPendingCancellation();

    Log::spy();
    ($this->confirm)($cancellation, $this->admin);

    Log::shouldHaveReceived('info', fn (string $message): bool => $message === 'occurrence status changed');
    Log::shouldHaveReceived('info', fn (string $message, array $context): bool => $message === 'source cancellation confirmed'
        && $context === ['draft_id' => $cancellation->id, 'event_id' => $cancellation->event_id, 'user_id' => $this->admin->id]);
});

it('refuses the wrong kind and a non-admin; ApproveDraft still refuses cancellation drafts', function () {
    confirmApproveYoga($this->tc);

    ingestFeed(confirmCancelYogaFeed());
    ingestLiveRun($this->tc->refresh());
    $cancellation = confirmPendingCancellation();

    expect(fn () => ($this->confirm)($cancellation, User::factory()->create()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ApproveDraft::class)($cancellation, $this->admin))->toThrow(DraftNotPublishable::class, 'Only new-event drafts can be approved here.');

    $new = EventDraft::query()->where('kind', DraftKind::New)->where('review_status', ReviewStatus::PendingReview)->orderBy('id')->firstOrFail();

    expect(fn () => ($this->confirm)($new, $this->admin))
        ->toThrow(DraftNotPublishable::class, 'Only source cancellation drafts can be confirmed.')
        ->and($cancellation->refresh()->review_status)->toBe(ReviewStatus::PendingReview)
        ->and($new->refresh()->review_status)->toBe(ReviewStatus::PendingReview);
});
