<?php

use App\Enums\DraftKind;
use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\RejectReason;
use App\Enums\ReviewStatus;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\EventOccurrence;
use App\Models\Source;
use App\Models\SourceItem;
use App\Models\User;
use App\Publishing\Actions\IgnoreSourceChange;
use App\Review\Exceptions\DraftNotPublishable;
use Database\Seeders\CategorySeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/*
 * REV-06, REV-07 (03-10): Ignore rejects a pending update or cancellation
 * draft with reason "ignored" and moves the accepted baseline so the same
 * change is not raised again. An update moves the whole baseline; a
 * cancellation moves only its cancelled part, so another change in the
 * same snapshot is raised by the next run, and an ignored cancellation
 * never cancels anything (events.cancelled_dates untouched). Offline.
 */

const IGNORE_TC_URL = 'https://attleboroughtc.org.uk/?post_type=tribe_events&ical=1';

const IGNORE_SERIES_URL = 'https://feed.example.org/series.ics';

const IGNORE_YOGA_UID = 'UID:10003893-1792922400-1792926000@attleboroughtc.org.uk';

function ignoreTcFixture(): string
{
    return sourceFixture('attleborough-town-council/2026-10-17-ical.ics');
}

/** @param  array<string, string>  $replacements */
function ignoreYogaEdited(array $replacements, ?string $ics = null): string
{
    return ingestEditEvent($ics ?? ignoreTcFixture(), IGNORE_YOGA_UID, $replacements);
}

function ignoreYogaMoved(): string
{
    return ignoreYogaEdited([
        'DTSTART;TZID=Europe/London:20261025T100000' => 'DTSTART;TZID=Europe/London:20261025T110000',
        'DTEND;TZID=Europe/London:20261025T110000' => 'DTEND;TZID=Europe/London:20261025T120000',
    ]);
}

function ignoreYogaCancelled(): string
{
    return ignoreYogaEdited(["SUMMARY:[name removed] ~ Yoga\r\n" => "SUMMARY:[name removed] ~ Yoga\r\nSTATUS:CANCELLED\r\n"]);
}

function ignoreApproveYoga(Source $tc): Event
{
    ingestFeed(ignoreTcFixture());
    ingestLiveRun($tc);

    return ingestApprove(EventDraft::query()->where('kind', DraftKind::New)->where('title', '[name removed] ~ Yoga')->where('start_date', '2026-10-25')->sole());
}

/**
 * series.ics with cancelled overrides of the Wednesday Folk Club on $dates
 * (Ymd), optionally with the master retimed to 19:30.
 *
 * @param  list<string>  $dates
 */
function ignoreFolkFeed(array $dates, bool $retimed = false): string
{
    $ics = icsFixture('series.ics');
    $time = '190000';

    if ($retimed) {
        $ics = str_replace(
            "DTSTART;TZID=Europe/London:20261014T190000\r\nDTEND;TZID=Europe/London:20261014T210000\r\nRRULE:FREQ=WEEKLY;BYDAY=WE;COUNT=6\r\nEXDATE",
            "DTSTART;TZID=Europe/London:20261014T193000\r\nDTEND;TZID=Europe/London:20261014T213000\r\nRRULE:FREQ=WEEKLY;BYDAY=WE;COUNT=6\r\nEXDATE",
            $ics,
        );
        $time = '193000';
    }

    $blocks = '';

    foreach ($dates as $date) {
        $blocks .= implode("\r\n", [
            'BEGIN:VEVENT', 'UID:s-exdate@synthetic.invalid', 'DTSTAMP:20261017T050000Z',
            "RECURRENCE-ID;TZID=Europe/London:{$date}T{$time}", 'SUMMARY:Wednesday Folk Club',
            "DTSTART;TZID=Europe/London:{$date}T{$time}", 'STATUS:CANCELLED', 'END:VEVENT',
        ])."\r\n";
    }

    return str_replace('END:VCALENDAR', $blocks.'END:VCALENDAR', $ics);
}

function ignoreApproveFolk(Source $series): Event
{
    ingestFeed(icsFixture('series.ics'));
    ingestLiveRun($series);

    return ingestApprove(EventDraft::query()->where('source_id', $series->id)->where('title', 'Wednesday Folk Club')->sole());
}

function ignorePending(DraftKind $kind): EventDraft
{
    return EventDraft::query()->where('kind', $kind)->where('review_status', ReviewStatus::PendingReview)->sole();
}

function ignoreItemFor(EventDraft $draft): SourceItem
{
    return SourceItem::query()->findOrFail($draft->source_item_id);
}

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    Storage::fake('local');
    freezeLondon('2026-10-17 06:00:00');
    $this->seed(CategorySeeder::class);

    $this->tc = Source::factory()->ics()->active()->withIdentity('tec_post_id')->create(['name' => 'Attleborough Town Council', 'url' => IGNORE_TC_URL]);
    $this->series = Source::factory()->ics()->active()->create(['name' => 'Synthetic series', 'url' => IGNORE_SERIES_URL]);
    $this->admin = ingestAdmin();
    $this->ignore = app(IgnoreSourceChange::class);
});

it('ignores an update: rejected as ignored, the baseline moves to the feed, the event is unchanged and nothing is raised again', function () {
    $event = ignoreApproveYoga($this->tc);

    ingestFeed(ignoreYogaMoved());
    ingestLiveRun($this->tc->refresh());
    $update = ignorePending(DraftKind::Update);

    $ignored = ($this->ignore)($update, $this->admin);
    $item = ignoreItemFor($update);

    expect($ignored->review_status)->toBe(ReviewStatus::Rejected)
        ->and($ignored->reject_reason)->toBe(RejectReason::Ignored)
        ->and($ignored->reviewed_by)->toBe($this->admin->id)
        ->and($item->accepted_hash)->toBe($item->content_hash)
        ->and($item->accepted_payload['start_time'])->toBe('11:00')
        ->and(substr((string) $event->refresh()->start_time, 0, 5))->toBe('10:00');

    $this->travel(1)->hours();
    $again = ingestLiveRun($this->tc->refresh());

    expect($again->drafts_created)->toBe(0)
        ->and(EventDraft::query()->where('review_status', ReviewStatus::PendingReview)->where('kind', '<>', DraftKind::New)->count())->toBe(0);
});

it('ignores a date cancellation without cancelling the date, and the next byte-identical run raises the held-back time change', function () {
    $event = ignoreApproveFolk($this->series);

    ingestFeed(ignoreFolkFeed(['20261104'], retimed: true));
    ingestLiveRun($this->series->refresh());
    $cancellation = ignorePending(DraftKind::Cancellation);
    expect(EventDraft::query()->where('kind', DraftKind::Update)->count())->toBe(0);

    $ignored = ($this->ignore)($cancellation, $this->admin);
    $item = ignoreItemFor($cancellation);

    expect($ignored->review_status)->toBe(ReviewStatus::Rejected)
        ->and($ignored->reject_reason)->toBe(RejectReason::Ignored)
        ->and($item->accepted_payload['cancelled_keys'])->toContain('2026-11-04')
        // Only the cancelled part moved: the time change is still a difference.
        ->and($item->accepted_payload['start_time'])->toBe('19:00')
        ->and($item->accepted_hash)->not->toBe($item->content_hash)
        ->and($event->refresh()->cancelled_dates)->not->toContain('2026-11-04')
        ->and(EventOccurrence::query()->where('event_id', $event->id)->where('recurrence_key', '2026-11-04')->value('status'))->toBe(OccurrenceStatus::Scheduled);

    $this->travel(1)->hours();
    $run = ingestLiveRun($this->series->refresh());
    $update = ignorePending(DraftKind::Update);

    expect($run->drafts_created)->toBe(1)
        ->and($update->event_id)->toBe($event->id)
        ->and($update->diff['start_time'])->toEqual(['published' => '19:00', 'before' => '19:00', 'now' => '19:30'])
        ->and(EventDraft::query()->where('kind', DraftKind::Cancellation)->where('review_status', ReviewStatus::PendingReview)->count())->toBe(0);
});

it('leaves accepted_hash equal to content_hash when the cancellation was the only difference', function () {
    $event = ignoreApproveFolk($this->series);

    ingestFeed(ignoreFolkFeed(['20261104']));
    ingestLiveRun($this->series->refresh());
    $cancellation = ignorePending(DraftKind::Cancellation);

    ($this->ignore)($cancellation, $this->admin);
    $item = ignoreItemFor($cancellation);

    expect($item->accepted_hash)->toBe($item->content_hash)
        ->and($event->refresh()->cancelled_dates)->toBe([]);

    $this->travel(1)->hours();
    expect(ingestLiveRun($this->series->refresh())->drafts_created)->toBe(0);
});

it('ignores a whole-event cancellation: the event stays published and the cancellation is not raised again', function () {
    $event = ignoreApproveYoga($this->tc);

    ingestFeed(ignoreYogaCancelled());
    ingestLiveRun($this->tc->refresh());
    $cancellation = ignorePending(DraftKind::Cancellation);

    ($this->ignore)($cancellation, $this->admin);
    $item = ignoreItemFor($cancellation);

    expect($event->refresh()->status)->toBe(EventStatus::Published)
        ->and($item->accepted_payload['cancelled'])->toBeTrue()
        ->and($item->accepted_hash)->toBe($item->content_hash);

    $this->travel(1)->hours();
    expect(ingestLiveRun($this->tc->refresh())->drafts_created)->toBe(0);
});

it('refuses a new-event draft and a non-admin, writing nothing', function () {
    ingestFeed(ignoreTcFixture());
    ingestLiveRun($this->tc);
    $new = EventDraft::query()->where('kind', DraftKind::New)->orderBy('id')->firstOrFail();

    expect(fn () => ($this->ignore)($new, $this->admin))
        ->toThrow(DraftNotPublishable::class, 'Only source update and cancellation drafts can be ignored.');
    expect($new->refresh()->review_status)->toBe(ReviewStatus::PendingReview);

    ignoreApproveYoga($this->tc);
    ingestFeed(ignoreYogaMoved());
    ingestLiveRun($this->tc->refresh());
    $update = ignorePending(DraftKind::Update);
    $baseline = ignoreItemFor($update)->accepted_hash;

    expect(fn () => ($this->ignore)($update, User::factory()->create()))
        ->toThrow(AuthorizationException::class);
    expect($update->refresh()->review_status)->toBe(ReviewStatus::PendingReview)
        ->and(ignoreItemFor($update)->accepted_hash)->toBe($baseline);
});

it('keeps "Ignored" out of the Reject dialog: only IgnoreSourceChange sets it (A7)', function () {
    expect(RejectReason::rejectOptions())->not->toHaveKey('ignored')
        ->and(RejectReason::rejectOptions())->toHaveKey('other')
        ->and(RejectReason::Ignored->getLabel())->toBe('Ignored (source change)');
});
