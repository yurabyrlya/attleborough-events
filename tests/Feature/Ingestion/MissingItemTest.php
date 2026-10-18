<?php

use App\Enums\DraftKind;
use App\Enums\ReviewStatus;
use App\Enums\RunMode;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Enums\SourceItemStatus;
use App\Ingestion\Pipeline\MissingItemDetector;
use App\Ingestion\Pipeline\TitleRules;
use App\Models\EventDraft;
use App\Models\Source;
use App\Models\SourceItem;
use App\Models\SourceRun;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/*
 * PIPE-08 (03-09): an item is missing only after two consecutive COMPLETE
 * live runs that did not list it, only when it is still ahead, inside the
 * run's observed window and the 90-day horizon, and only for sources that
 * support missing detection. Failed, blocked, not-modified, preview and
 * incomplete runs never count. Offline: ingestFeed(), the fake
 * HostResolver and Sleep::fake.
 */

const MISSING_URL = 'https://feed.example.org/missing.ics';

/**
 * An ICS body: one-hour events keyed by UID => [title, London DTSTART
 * Ymd\THis, optional TZID].
 *
 * @param  array<string, array{0: string, 1: string, 2?: string}>  $events
 */
function ingestMissingIcs(array $events): string
{
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//What\'s On Attleborough//Missing test//EN'];

    foreach ($events as $uid => $event) {
        $start = CarbonImmutable::createFromFormat('Ymd\THis', $event[1], 'Europe/London');
        $tz = $event[2] ?? 'Europe/London';
        $lines = [...$lines,
            'BEGIN:VEVENT', "UID:{$uid}", 'DTSTAMP:20261017T050000Z', "SUMMARY:{$event[0]}",
            "DTSTART;TZID={$tz}:{$event[1]}", "DTEND;TZID={$tz}:".$start->addHour()->format('Ymd\THis'),
            'LOCATION:Town Hall', 'END:VEVENT',
        ];
    }

    return implode("\r\n", [...$lines, 'END:VCALENDAR'])."\r\n";
}

/**
 * The anchor (keeps observed_until at Fri 30 Oct) plus $extra events.
 *
 * @param  array<string, array{0: string, 1: string, 2?: string}>  $extra
 */
function ingestMissingFeed(array $extra = []): string
{
    return ingestMissingIcs(['anchor@missing.invalid' => ['Market Day', '20261030T090000'], ...$extra]);
}

/** The item that later disappears: Sat 24 Oct 19:30. */
const MISSING_QUIZ = ['quiz@missing.invalid' => ['Quiz Night', '20261024T193000']];

function ingestMissingItem(string $uid = 'quiz@missing.invalid'): SourceItem
{
    return SourceItem::query()->where('external_id', $uid)->sole();
}

/**
 * $ics without the one VEVENT block that contains $needle.
 */
function ingestRemoveEvent(string $ics, string $needle): string
{
    $blocks = explode('BEGIN:VEVENT', $ics);
    $kept = [$blocks[0]];

    foreach (array_slice($blocks, 1) as $block) {
        if (! str_contains($block, $needle)) {
            $kept[] = $block;

            continue;
        }

        // Keep whatever follows the removed event (END:VCALENDAR).
        $tail = substr($block, strpos($block, "END:VEVENT\r\n") + strlen("END:VEVENT\r\n"));
        $kept[count($kept) - 1] .= $tail;
    }

    expect(count($kept))->toBe(count($blocks) - 1, "Expected one VEVENT containing {$needle}");

    return implode('BEGIN:VEVENT', $kept);
}

function ingestMissingDraftFor(string $uid): EventDraft
{
    return EventDraft::query()->where('source_item_id', ingestMissingItem($uid)->id)->where('kind', DraftKind::New)->sole();
}

/**
 * A finished live run row for direct MissingItemDetector calls.
 */
function ingestMissingRunRow(Source $source, string $observedUntil, bool $complete = true): SourceRun
{
    $run = new SourceRun(['mode' => RunMode::Live, 'trigger' => RunTrigger::Schedule]);
    $run->forceFill([
        'source_id' => $source->id,
        'status' => RunStatus::Succeeded,
        'is_complete' => $complete,
        'items_found' => 1,
        'observed_from' => CarbonImmutable::now('Europe/London')->toDateString(),
        'observed_until' => $observedUntil,
        'finished_at' => CarbonImmutable::now()->utc(),
    ])->save();

    return $run;
}

function ingestMissingItemRow(Source $source, string $uid, CarbonImmutable $startsAt, ?int $lastSeenRunId = null): SourceItem
{
    $item = new SourceItem;
    $item->forceFill([
        'source_id' => $source->id,
        'external_id' => $uid,
        'status' => SourceItemStatus::Active,
        'review_outcome' => 'pending',
        'last_known_start_at' => $startsAt->utc(),
        'last_seen_run_id' => $lastSeenRunId,
        'missing_runs' => 0,
    ])->save();

    return $item;
}

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    Storage::fake('local');
    freezeLondon('2026-10-17 06:00:00');
    $this->seed(CategorySeeder::class);

    $this->source = Source::factory()->ics()->active()->create([
        'name' => 'Synthetic church',
        'url' => MISSING_URL,
        'supports_missing_detection' => true,
    ]);
});

it('cancels an approved Baptist Church item missing from two complete runs, not after one', function () {
    $baptist = Source::factory()->ics()->active()->create([
        'name' => 'Attleborough Baptist Church',
        'url' => 'https://www.attleborough.church/Calendar/Calendar.ics',
        'supports_missing_detection' => true,
    ]);
    $fixture = sourceFixture('attleborough-baptist-church/2026-10-17-ical.ics');
    ingestFeed($fixture);
    ingestLiveRun($baptist);

    $draft = EventDraft::query()->where('source_id', $baptist->id)->whereNull('recurrence_rule')
        ->where('start_date', '>=', '2026-10-18')->orderBy('start_date')->orderBy('start_time')->orderBy('id')->firstOrFail();
    $event = ingestApprove($draft);
    $item = SourceItem::query()->findOrFail($draft->source_item_id);

    ingestFeed(ingestRemoveEvent($fixture, 'UID:'.$item->external_id));

    $this->travel(1)->hours();
    $second = ingestLiveRun($baptist->refresh());

    expect($second->is_complete)->toBeTrue()
        ->and($second->items_missing)->toBe(1)
        ->and($item->refresh()->missing_runs)->toBe(1)
        ->and($item->status)->toBe(SourceItemStatus::Active)
        ->and(EventDraft::query()->where('kind', DraftKind::Cancellation)->count())->toBe(0);

    $this->travel(1)->hours();
    ingestLiveRun($baptist->refresh());
    $cancellation = EventDraft::query()->where('kind', DraftKind::Cancellation)->sole();

    expect($item->refresh()->missing_runs)->toBe(2)
        ->and($item->status)->toBe(SourceItemStatus::Missing)
        ->and($cancellation->event_id)->toBe($event->id)
        ->and($cancellation->review_status)->toBe(ReviewStatus::PendingReview)
        ->and($cancellation->diff)->toEqual(['status' => ['published' => 'published', 'before' => 'active', 'now' => 'missing']])
        ->and($cancellation->relevant_starts_at)->not->toBeNull();
});

it('only counts consecutive complete runs: missing, seen, missing leaves 1 and no draft', function () {
    ingestFeed(ingestMissingFeed(MISSING_QUIZ));
    ingestLiveRun($this->source);
    ingestApprove(ingestMissingDraftFor('quiz@missing.invalid'));

    foreach ([ingestMissingFeed(), ingestMissingFeed(MISSING_QUIZ), ingestMissingFeed()] as $body) {
        $this->travel(1)->hours();
        ingestFeed($body);
        ingestLiveRun($this->source->refresh());
    }

    expect(ingestMissingItem()->missing_runs)->toBe(1)
        ->and(ingestMissingItem()->status)->toBe(SourceItemStatus::Active)
        ->and(EventDraft::query()->where('kind', DraftKind::Cancellation)->count())->toBe(0);
});

it('makes a missing item active again when it reappears and withdraws its pending cancellation', function () {
    ingestFeed(ingestMissingFeed(MISSING_QUIZ));
    ingestLiveRun($this->source);
    ingestApprove(ingestMissingDraftFor('quiz@missing.invalid'));

    foreach ([ingestMissingFeed(), ingestMissingFeed()] as $body) {
        $this->travel(1)->hours();
        ingestFeed($body);
        ingestLiveRun($this->source->refresh());
    }

    $cancellation = EventDraft::query()->where('kind', DraftKind::Cancellation)->sole();
    expect(ingestMissingItem()->status)->toBe(SourceItemStatus::Missing);

    $this->travel(1)->hours();
    ingestFeed(ingestMissingFeed(MISSING_QUIZ));
    ingestLiveRun($this->source->refresh());

    expect(ingestMissingItem()->status)->toBe(SourceItemStatus::Active)
        ->and(ingestMissingItem()->missing_runs)->toBe(0)
        ->and($cancellation->refresh()->review_status)->toBe(ReviewStatus::Superseded)
        ->and(EventDraft::query()->where('review_status', ReviewStatus::PendingReview)->where('source_item_id', ingestMissingItem()->id)->count())->toBe(0);
});

it('leaves the missing count alone after a run that is not complete', function (string $kind) {
    ingestFeed(ingestMissingFeed(MISSING_QUIZ));
    ingestLiveRun($this->source);
    ingestApprove(ingestMissingDraftFor('quiz@missing.invalid'));

    $this->travel(1)->hours();
    ingestFeed(ingestMissingFeed());
    ingestLiveRun($this->source->refresh());
    expect(ingestMissingItem()->missing_runs)->toBe(1);

    // Past the 24-hour robots cache, so a changed robots.txt is read.
    $this->travel(25)->hours();

    $run = match ($kind) {
        'failed' => (function () {
            ingestFeed(fn () => Http::response('Server error', 500));

            return ingestLiveRun($this->source->refresh());
        })(),
        'blocked' => (function () {
            ingestFeed(ingestMissingFeed(), "User-agent: *\nDisallow: /\n");

            return ingestLiveRun($this->source->refresh());
        })(),
        'not_modified' => (function () {
            ingestFeed(fn () => Http::response('', 304));

            return ingestLiveRun($this->source->refresh());
        })(),
        'preview' => (function () {
            ingestFeed(ingestMissingFeed());

            return ingestLiveRun($this->source->refresh(), RunMode::Preview);
        })(),
        'incomplete' => (function () {
            ingestFeed(ingestMissingIcs([]));

            return ingestLiveRun($this->source->refresh());
        })(),
    };

    expect($run->status)->toBe(match ($kind) {
        'failed' => RunStatus::Failed,
        'blocked' => RunStatus::Blocked,
        'not_modified' => RunStatus::NotModified,
        default => RunStatus::Succeeded,
    })
        ->and($run->is_complete)->toBeFalse()
        ->and($run->items_missing)->toBe(0)
        ->and(ingestMissingItem()->missing_runs)->toBe(1)
        ->and(ingestMissingItem()->status)->toBe(SourceItemStatus::Active)
        ->and(EventDraft::query()->where('kind', DraftKind::Cancellation)->count())->toBe(0);
})->with(['failed', 'blocked', 'not_modified', 'preview', 'incomplete']);

it('counts only items after now, before the end of observed_until and inside the horizon', function () {
    $now = CarbonImmutable::now('Europe/London');
    $seen = ingestMissingRunRow($this->source, '2026-10-30');
    $exactNow = ingestMissingItemRow($this->source, 'now', $now, $seen->id);
    $justAfter = ingestMissingItemRow($this->source, 'after', $now->addSecond(), $seen->id);
    $lastDay = ingestMissingItemRow($this->source, 'last-day', CarbonImmutable::parse('2026-10-30 23:30', 'Europe/London'), $seen->id);
    $nextDay = ingestMissingItemRow($this->source, 'next-day', CarbonImmutable::parse('2026-10-31 00:00', 'Europe/London'), $seen->id);
    $past = ingestMissingItemRow($this->source, 'past', $now->subHour(), $seen->id);

    $run = ingestMissingRunRow($this->source, '2026-10-30');
    app(MissingItemDetector::class)->detect($run);

    expect($exactNow->refresh()->missing_runs)->toBe(0)
        ->and($justAfter->refresh()->missing_runs)->toBe(1)
        ->and($lastDay->refresh()->missing_runs)->toBe(1)
        ->and($nextDay->refresh()->missing_runs)->toBe(0)
        ->and($past->refresh()->missing_runs)->toBe(0)
        ->and($run->refresh()->items_missing)->toBe(2);

    $horizonRun = ingestMissingRunRow($this->source, '2027-06-01');
    $inside = ingestMissingItemRow($this->source, 'inside', $now->addDays(89), $seen->id);
    $beyond = ingestMissingItemRow($this->source, 'beyond', $now->addDays(91), $seen->id);
    app(MissingItemDetector::class)->detect($horizonRun);

    expect($inside->refresh()->missing_runs)->toBe(1)
        ->and($beyond->refresh()->missing_runs)->toBe(0);
});

it('never counts for a source without missing detection or after an incomplete run', function () {
    $seen = ingestMissingRunRow($this->source, '2026-10-30');
    $item = ingestMissingItemRow($this->source, 'quiz', CarbonImmutable::parse('2026-10-24 19:30', 'Europe/London'), $seen->id);

    app(MissingItemDetector::class)->detect(ingestMissingRunRow($this->source, '2026-10-30', complete: false));
    expect($item->refresh()->missing_runs)->toBe(0);

    $this->source->forceFill(['supports_missing_detection' => false])->save();
    app(MissingItemDetector::class)->detect(ingestMissingRunRow($this->source, '2026-10-30'));
    app(MissingItemDetector::class)->detect(ingestMissingRunRow($this->source, '2026-10-30'));

    expect($item->refresh()->missing_runs)->toBe(0)
        ->and($item->status)->toBe(SourceItemStatus::Active);
});

it('never treats a listed but skipped identity as missing', function () {
    $items = [
        'rule@missing.invalid' => ['Bingo Evening', '20261022T190000'],
        'moved@missing.invalid' => ['Choir Concert', '20261023T190000'],
        'broken@missing.invalid' => ['Craft Fair', '20261025T100000'],
    ];
    ingestFeed(ingestMissingFeed($items));
    ingestLiveRun($this->source);

    foreach (array_keys($items) as $uid) {
        ingestApprove(ingestMissingDraftFor($uid));
    }

    // A title rule added after approval, a date beyond the 90-day window,
    // and a time zone nobody can resolve.
    $this->source->forceFill(['title_rules' => [['mode' => TitleRules::MODE_SKIP, 'keyword' => 'bingo']]])->save();
    $changed = ingestMissingFeed([
        'rule@missing.invalid' => ['Bingo Evening', '20261022T190000'],
        'moved@missing.invalid' => ['Choir Concert', '20270301T190000'],
        'broken@missing.invalid' => ['Craft Fair', '20261025T100000', 'Mars/Olympus_Mons'],
    ]);

    foreach ([1, 2] as $round) {
        $this->travel(1)->hours();
        ingestFeed($changed);
        $run = ingestLiveRun($this->source->refresh());
        expect($run->is_complete)->toBeTrue();
    }

    foreach (array_keys($items) as $uid) {
        expect(ingestMissingItem($uid)->missing_runs)->toBe(0, $uid)
            ->and(ingestMissingItem($uid)->status)->toBe(SourceItemStatus::Active, $uid);
    }

    expect(EventDraft::query()->where('kind', DraftKind::Cancellation)->count())->toBe(0)
        ->and(ingestMissingItem('broken@missing.invalid')->parse_errors)->not->toBeEmpty();
});

it('supersedes the pending new draft of a missing item instead of raising a cancellation', function () {
    ingestFeed(ingestMissingFeed(MISSING_QUIZ));
    ingestLiveRun($this->source);
    $draft = ingestMissingDraftFor('quiz@missing.invalid');

    foreach ([ingestMissingFeed(), ingestMissingFeed()] as $body) {
        $this->travel(1)->hours();
        ingestFeed($body);
        ingestLiveRun($this->source->refresh());
    }

    expect(ingestMissingItem()->status)->toBe(SourceItemStatus::Missing)
        ->and($draft->refresh()->review_status)->toBe(ReviewStatus::Superseded)
        ->and(EventDraft::query()->where('kind', DraftKind::Cancellation)->count())->toBe(0);
});
