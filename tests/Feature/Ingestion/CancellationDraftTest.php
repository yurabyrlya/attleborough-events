<?php

use App\Enums\DraftKind;
use App\Enums\ReviewStatus;
use App\Enums\SourceItemStatus;
use App\Models\EventDraft;
use App\Models\Source;
use App\Models\SourceItem;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/*
 * PIPE-07 (03-09): STATUS:CANCELLED on a linked one-off raises a
 * whole-event cancellation draft; newly cancelled dates of a linked series
 * raise one cancellation draft listing them; nothing is drafted for an item
 * nobody published. Offline: ingestFeed(), the fake HostResolver and
 * Sleep::fake.
 */

const CANCEL_TC_URL = 'https://attleboroughtc.org.uk/?post_type=tribe_events&ical=1';

const CANCEL_SERIES_URL = 'https://feed.example.org/series.ics';

const CANCEL_YOGA_UID = 'UID:10003893-1792922400-1792926000@attleboroughtc.org.uk';

function ingestCancelYoga(string $ics): string
{
    return ingestEditEvent($ics, CANCEL_YOGA_UID, ["SUMMARY:[name removed] ~ Yoga\r\n" => "SUMMARY:[name removed] ~ Yoga\r\nSTATUS:CANCELLED\r\n"]);
}

function ingestCancelYogaDraft(): EventDraft
{
    return EventDraft::query()->where('kind', DraftKind::New)->where('title', '[name removed] ~ Yoga')->where('start_date', '2026-10-25')->sole();
}

/**
 * series.ics plus cancelled overrides of the Wednesday Folk Club (19:00,
 * or $time when the master moved) on $dates (Ymd), in the order given.
 *
 * @param  list<string>  $dates
 */
function ingestFolkCancelled(array $dates, ?string $ics = null, string $time = '190000'): string
{
    $blocks = '';

    foreach ($dates as $date) {
        $blocks .= implode("\r\n", [
            'BEGIN:VEVENT',
            'UID:s-exdate@synthetic.invalid',
            'DTSTAMP:20261017T050000Z',
            "RECURRENCE-ID;TZID=Europe/London:{$date}T{$time}",
            'SUMMARY:Wednesday Folk Club',
            "DTSTART;TZID=Europe/London:{$date}T{$time}",
            'STATUS:CANCELLED',
            'END:VEVENT',
        ])."\r\n";
    }

    return str_replace('END:VCALENDAR', $blocks.'END:VCALENDAR', $ics ?? icsFixture('series.ics'));
}

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    Storage::fake('local');
    freezeLondon('2026-10-17 06:00:00');
    $this->seed(CategorySeeder::class);

    $this->tc = Source::factory()->ics()->active()->withIdentity('tec_post_id')->create(['name' => 'Attleborough Town Council', 'url' => CANCEL_TC_URL]);
    $this->series = Source::factory()->ics()->active()->create(['name' => 'Synthetic series', 'url' => CANCEL_SERIES_URL]);
});

/**
 * Ingests series.ics and approves the Wednesday Folk Club (EXDATE 21 Oct).
 */
function ingestApprovedFolkClub(Source $series): EventDraft
{
    ingestFeed(icsFixture('series.ics'));
    ingestLiveRun($series);
    $draft = EventDraft::query()->where('source_id', $series->id)->where('title', 'Wednesday Folk Club')->sole();
    ingestApprove($draft);

    return $draft->refresh();
}

it('raises one whole-event cancellation for an approved one-off the feed cancels, once', function () {
    ingestFeed(sourceFixture('attleborough-town-council/2026-10-17-ical.ics'));
    ingestLiveRun($this->tc);
    $event = ingestApprove(ingestCancelYogaDraft());

    ingestFeed(ingestCancelYoga(sourceFixture('attleborough-town-council/2026-10-17-ical.ics')));
    $run = ingestLiveRun($this->tc->refresh());
    $cancellation = EventDraft::query()->where('kind', DraftKind::Cancellation)->sole();
    $item = SourceItem::query()->findOrFail($cancellation->source_item_id);

    expect($cancellation->event_id)->toBe($event->id)
        ->and($cancellation->review_status)->toBe(ReviewStatus::PendingReview)
        ->and($cancellation->diff)->toEqual(['cancelled' => ['published' => false, 'before' => false, 'now' => true]])
        ->and($cancellation->target_recurrence_key)->toBeNull()
        ->and($item->status)->toBe(SourceItemStatus::Cancelled)
        ->and($run->items_cancelled)->toBe(1)
        ->and($run->drafts_created)->toBe(1)
        ->and(EventDraft::query()->where('kind', DraftKind::Update)->count())->toBe(0);

    $relevant = DB::selectOne("select relevant_starts_at at time zone 'UTC' as t from event_drafts where id = ?", [$cancellation->id]);
    expect((string) $relevant->t)->toBe('2026-10-25 10:00:00');

    $this->travel(1)->hours();
    $again = ingestLiveRun($this->tc->refresh());

    expect(EventDraft::query()->where('kind', DraftKind::Cancellation)->count())->toBe(1)
        ->and($again->drafts_created)->toBe(0)
        ->and($again->drafts_replaced)->toBe(0);
});

it('lists newly cancelled series dates in ascending order with the earliest as target', function () {
    $folk = ingestApprovedFolkClub($this->series);

    ingestFeed(ingestFolkCancelled(['20261118', '20261104']));
    $run = ingestLiveRun($this->series->refresh());
    $cancellation = EventDraft::query()->where('kind', DraftKind::Cancellation)->sole();

    expect($cancellation->event_id)->toBe($folk->event_id)
        ->and($cancellation->diff)->toBe(['cancelled_keys' => ['2026-11-04', '2026-11-18']])
        ->and($cancellation->target_recurrence_key)->toBe('2026-11-04')
        ->and($run->items_cancelled)->toBe(1);

    $relevant = DB::selectOne("select relevant_starts_at at time zone 'UTC' as t from event_drafts where id = ?", [$cancellation->id]);
    // 19:00 GMT on 4 Nov.
    expect((string) $relevant->t)->toBe('2026-11-04 19:00:00');
});

it('raises nothing for a cancelled date that has already passed', function () {
    ingestApprovedFolkClub($this->series);
    $before = EventDraft::query()->count();

    ingestFeed(ingestFolkCancelled(['20261014']));
    $run = ingestLiveRun($this->series->refresh());

    expect(EventDraft::query()->count())->toBe($before)
        ->and($run->drafts_created)->toBe(0);
});

it('drafts nothing for a cancelled item that was never approved or drafted, and marks it cancelled', function () {
    $ics = implode("\r\n", [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//What\'s On Attleborough//Cancellation test//EN',
        'BEGIN:VEVENT', 'UID:never-seen@synthetic.invalid', 'DTSTAMP:20261017T050000Z',
        'SUMMARY:Autumn Fair', 'DTSTART;TZID=Europe/London:20261030T100000', 'DTEND;TZID=Europe/London:20261030T140000',
        'LOCATION:Town Hall', 'STATUS:CANCELLED', 'END:VEVENT', 'END:VCALENDAR',
    ])."\r\n";

    ingestFeed($ics);
    $run = ingestLiveRun($this->series);
    $item = SourceItem::query()->where('external_id', 'never-seen@synthetic.invalid')->sole();

    expect(EventDraft::query()->count())->toBe(0)
        ->and($item->status)->toBe(SourceItemStatus::Cancelled)
        ->and($run->items_new)->toBe(1)
        ->and($run->items_cancelled)->toBe(1)
        ->and($run->drafts_created)->toBe(0);
});

it('supersedes a pending new draft whose item the feed cancels', function () {
    ingestFeed(sourceFixture('attleborough-town-council/2026-10-17-ical.ics'));
    ingestLiveRun($this->tc);
    $draft = ingestCancelYogaDraft();
    $count = EventDraft::query()->count();

    ingestFeed(ingestCancelYoga(sourceFixture('attleborough-town-council/2026-10-17-ical.ics')));
    $run = ingestLiveRun($this->tc->refresh());

    expect($draft->refresh()->review_status)->toBe(ReviewStatus::Superseded)
        ->and(EventDraft::query()->count())->toBe($count)
        ->and(SourceItem::query()->findOrFail($draft->source_item_id)->status)->toBe(SourceItemStatus::Cancelled)
        ->and($run->drafts_created)->toBe(0);
});

it('lets a cancellation replace a pending update draft for the same item', function () {
    ingestFeed(sourceFixture('attleborough-town-council/2026-10-17-ical.ics'));
    ingestLiveRun($this->tc);
    ingestApprove(ingestCancelYogaDraft());

    $moved = ingestEditEvent(sourceFixture('attleborough-town-council/2026-10-17-ical.ics'), CANCEL_YOGA_UID, [
        'DTSTART;TZID=Europe/London:20261025T100000' => 'DTSTART;TZID=Europe/London:20261025T110000',
        'DTEND;TZID=Europe/London:20261025T110000' => 'DTEND;TZID=Europe/London:20261025T120000',
    ]);
    ingestFeed($moved);
    ingestLiveRun($this->tc->refresh());
    $update = EventDraft::query()->where('kind', DraftKind::Update)->sole();

    ingestFeed(ingestCancelYoga($moved));
    ingestLiveRun($this->tc->refresh());

    expect($update->refresh()->review_status)->toBe(ReviewStatus::Superseded)
        ->and(EventDraft::query()->where('kind', DraftKind::Cancellation)->where('review_status', ReviewStatus::PendingReview)->count())->toBe(1)
        ->and(EventDraft::query()->where('source_item_id', $update->source_item_id)->where('review_status', ReviewStatus::PendingReview)->count())->toBe(1);
});

it('raises only the cancellation when a date is cancelled and the time changes in the same run', function () {
    ingestApprovedFolkClub($this->series);

    $retimed = str_replace(
        ["DTSTART;TZID=Europe/London:20261014T190000\r\nDTEND;TZID=Europe/London:20261014T210000\r\nRRULE:FREQ=WEEKLY;BYDAY=WE;COUNT=6\r\nEXDATE"],
        ["DTSTART;TZID=Europe/London:20261014T193000\r\nDTEND;TZID=Europe/London:20261014T213000\r\nRRULE:FREQ=WEEKLY;BYDAY=WE;COUNT=6\r\nEXDATE"],
        icsFixture('series.ics'),
    );
    expect($retimed)->not->toBe(icsFixture('series.ics'));

    ingestFeed(ingestFolkCancelled(['20261104'], $retimed, '193000'));
    $run = ingestLiveRun($this->series->refresh());

    expect(EventDraft::query()->where('kind', DraftKind::Cancellation)->sole()->diff)->toBe(['cancelled_keys' => ['2026-11-04']])
        ->and(EventDraft::query()->where('kind', DraftKind::Update)->count())->toBe(0)
        ->and($run->drafts_created)->toBe(1);
});
