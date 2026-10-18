<?php

use App\Enums\DraftKind;
use App\Enums\ReviewStatus;
use App\Ingestion\Data\ChangeVerdict;
use App\Ingestion\Data\NormalisedFacts;
use App\Ingestion\Pipeline\ContentHasher;
use App\Ingestion\Pipeline\SourceItemRecorder;
use App\Models\Category;
use App\Models\EventDraft;
use App\Models\Source;
use App\Models\SourceItem;
use App\Models\SourceRun;
use App\Models\Venue;
use App\Publishing\Actions\RejectDraft;
use App\Review\Data\DraftData;
use App\Review\DraftWriter;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/*
 * PIPE-06 (03-09): a feed change to an approved item raises ONE update
 * draft with a three-column diff (published / before / now) in the fixed
 * D-06 order, never a cancellation (Pitfall 4: the Town Council keys items
 * by TEC post id, so a moved time keeps its identity). Offline: Http::fake
 * through ingestFeed(), the fake HostResolver and Sleep::fake.
 */

const UPDATE_TC_URL = 'https://attleboroughtc.org.uk/?post_type=tribe_events&ical=1';

/** TEC post 10003893: '[name removed] ~ Yoga', Sun 25 Oct 10:00-11:00 (GMT day). */
const UPDATE_YOGA_UID = 'UID:10003893-1792922400-1792926000@attleboroughtc.org.uk';

function ingestTcFixture(): string
{
    return sourceFixture('attleborough-town-council/2026-10-17-ical.ics');
}

/**
 * The Town Council fixture with the Yoga session moved one hour later
 * (UID epochs, DTSTART and DTEND), as TEC would export it.
 */
function ingestYogaMovedLater(?string $ics = null): string
{
    return ingestEditEvent($ics ?? ingestTcFixture(), UPDATE_YOGA_UID, [
        UPDATE_YOGA_UID => 'UID:10003893-1792926000-1792929600@attleboroughtc.org.uk',
        'DTSTART;TZID=Europe/London:20261025T100000' => 'DTSTART;TZID=Europe/London:20261025T110000',
        'DTEND;TZID=Europe/London:20261025T110000' => 'DTEND;TZID=Europe/London:20261025T120000',
    ]);
}

function ingestYogaDraft(): EventDraft
{
    return EventDraft::query()
        ->where('kind', DraftKind::New)
        ->where('title', '[name removed] ~ Yoga')
        ->where('start_date', '2026-10-25')
        ->sole();
}

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    Storage::fake('local');
    freezeLondon('2026-10-17 06:00:00');
    $this->seed(CategorySeeder::class);

    $this->tc = Source::factory()->ics()->active()->withIdentity('tec_post_id')->create([
        'name' => 'Attleborough Town Council',
        'url' => UPDATE_TC_URL,
    ]);

    ingestFeed(ingestTcFixture());
    expect(ingestLiveRun($this->tc)->drafts_created)->toBeGreaterThan(0);
});

it('raises one update draft with a published/before/now diff when an approved Town Council item moves an hour', function () {
    $event = ingestApprove(ingestYogaDraft());
    $before = EventDraft::query()->count();

    ingestFeed(ingestYogaMovedLater());
    $run = ingestLiveRun($this->tc->refresh());

    $update = EventDraft::query()->where('kind', DraftKind::Update)->sole();

    expect(EventDraft::query()->count())->toBe($before + 1)
        ->and(EventDraft::query()->where('kind', DraftKind::Cancellation)->count())->toBe(0)
        ->and($update->review_status)->toBe(ReviewStatus::PendingReview)
        ->and($update->event_id)->toBe($event->id)
        ->and(array_keys($update->diff ?? []))->toBe(['start_time', 'end_time'])
        ->and($update->diff['start_time'])->toEqual(['published' => '10:00', 'before' => '10:00', 'now' => '11:00'])
        ->and($update->diff['end_time'])->toEqual(['published' => '11:00', 'before' => '11:00', 'now' => '12:00'])
        ->and($update->relevant_starts_at)->not->toBeNull()
        ->and($run->items_changed)->toBe(1)
        ->and($run->drafts_created)->toBe(1);

    // The earlier of the published occurrence (10:00 GMT) and the new start.
    $relevant = DB::selectOne("select relevant_starts_at at time zone 'UTC' as t from event_drafts where id = ?", [$update->id]);
    expect((string) $relevant->t)->toBe('2026-10-25 10:00:00');

    $this->travel(1)->hours();
    $again = ingestLiveRun($this->tc->refresh());

    expect(EventDraft::query()->where('kind', DraftKind::Update)->where('review_status', ReviewStatus::PendingReview)->count())->toBe(1)
        ->and($again->drafts_created)->toBe(0)
        ->and($again->drafts_replaced)->toBe(0);
});

it('updates the stored text and the baseline quietly when only the description changes (D-06)', function () {
    ingestApprove(ingestYogaDraft());
    $before = EventDraft::query()->count();

    ingestFeed(ingestEditEvent(ingestTcFixture(), UPDATE_YOGA_UID, ["DESCRIPTION:\r\n" => "DESCRIPTION:Bring a mat.\r\n"]));
    $run = ingestLiveRun($this->tc->refresh());

    $item = SourceItem::query()->where('external_id', '10003893')->sole();

    expect(EventDraft::query()->count())->toBe($before)
        ->and($run->items_changed)->toBe(1)
        ->and($run->drafts_created)->toBe(0)
        ->and($item->payload['description'])->toBe('Bring a mat.')
        ->and($item->accepted_payload['description'])->toBe('Bring a mat.')
        ->and($item->accepted_hash)->toBe($item->content_hash);
});

it('replaces a pending new draft in place and keeps the admin venue, category and description (Pitfall 3)', function () {
    $draft = ingestYogaDraft();
    $venue = Venue::factory()->create(['name' => 'Attleborough Town Hall']);
    $category = Category::query()->where('slug', '<>', $draft->category?->slug ?? '')->orderBy('id')->firstOrFail();

    // What the admin's review save leaves behind (DraftWriter::update).
    $draft->forceFill([
        'venue_id' => $venue->id,
        'category_id' => $category->id,
        'category_reason' => DraftData::ADMIN_CATEGORY_REASON,
        'description' => 'Gentle yoga for all abilities.',
        'quality_score' => 0,
    ])->save();

    ingestFeed(ingestYogaMovedLater());
    $run = ingestLiveRun($this->tc->refresh());
    $replaced = EventDraft::query()->findOrFail($draft->id);

    expect(EventDraft::query()->where('source_item_id', $draft->source_item_id)->count())->toBe(1)
        ->and($replaced->review_status)->toBe(ReviewStatus::PendingReview)
        ->and($replaced->kind)->toBe(DraftKind::New)
        ->and($replaced->start_time)->toBe('11:00:00')
        ->and($replaced->end_time)->toBe('12:00:00')
        ->and($replaced->venue_id)->toBe($venue->id)
        ->and($replaced->category_id)->toBe($category->id)
        ->and($replaced->category_reason)->toBe(DraftData::ADMIN_CATEGORY_REASON)
        ->and($replaced->description)->toBe('Gentle yoga for all abilities.')
        ->and($replaced->quality_score)->toBeGreaterThan(0)
        ->and($run->drafts_replaced)->toBe(1)
        ->and($run->drafts_created)->toBe(0)
        ->and($run->items_changed)->toBe(1);
});

it('takes the source excerpt on replace when the admin never edited the description', function () {
    $draft = ingestYogaDraft();

    ingestFeed(ingestEditEvent(ingestYogaMovedLater(), 'UID:10003893-', ["DESCRIPTION:\r\n" => "DESCRIPTION:Bring a mat.\r\n"]));
    ingestLiveRun($this->tc->refresh());

    expect($draft->refresh()->description)->toBe('Bring a mat.');
});

it('never overwrites a draft decided while the run was replacing it: it decides again and raises a new draft', function () {
    $stale = ingestYogaDraft();
    $item = SourceItem::query()->findOrFail($stale->source_item_id);
    $event = ingestApprove($stale);

    // The writer refuses the decided draft and writes nothing.
    $facts = NormalisedFacts::fromPayload([...$item->payload, 'start_time' => '11:00', 'end_time' => '12:00']);
    $data = DraftData::fromSourceItem($facts, $this->tc, 'Details.', [], $facts->toPayload());

    expect(app(DraftWriter::class)->replacePendingFromSource($stale, $data, []))->toBeNull()
        ->and($stale->refresh()->review_status)->toBe(ReviewStatus::Approved)
        ->and($stale->start_time)->toBe('10:00:00');

    // The recorder then works the verdict out again from the fresh state.
    $item->forceFill([
        'content_hash' => app(ContentHasher::class)->contentHash($facts),
        'payload' => $facts->toPayload(),
    ])->save();

    $written = DB::transaction(fn () => app(SourceItemRecorder::class)->act(
        $item->refresh(),
        $this->tc,
        new ChangeVerdict(ChangeVerdict::REPLACE),
        $stale,
        null,
        $facts,
        [],
        CarbonImmutable::now('Europe/London'),
    ));

    $update = EventDraft::query()->where('kind', DraftKind::Update)->sole();

    expect($written)->toBe(['created' => 1, 'replaced' => 0])
        ->and($update->event_id)->toBe($event->id)
        ->and(array_keys($update->diff ?? []))->toBe(['start_time', 'end_time'])
        ->and(EventDraft::query()->where('source_item_id', $item->id)->where('review_status', ReviewStatus::PendingReview)->count())->toBe(1);
});

it('keeps a rejected item rejected until its facts change, then drafts it again (D-23)', function () {
    $draft = ingestYogaDraft();
    app(RejectDraft::class)($draft, ingestAdmin());
    $before = EventDraft::query()->count();

    $this->travel(1)->hours();
    $same = ingestLiveRun($this->tc->refresh());

    expect(EventDraft::query()->count())->toBe($before)
        ->and($same->drafts_created)->toBe(0);

    ingestFeed(ingestYogaMovedLater());
    $moved = ingestLiveRun($this->tc->refresh());
    $item = SourceItem::query()->findOrFail($draft->source_item_id);
    $redraft = EventDraft::query()->where('source_item_id', $item->id)->whereKeyNot($draft->id)->sole();

    expect($moved->drafts_created)->toBe(1)
        ->and($redraft->kind)->toBe(DraftKind::New)
        ->and($redraft->review_status)->toBe(ReviewStatus::PendingReview)
        ->and($redraft->start_time)->toBe('11:00:00')
        ->and($item->review_outcome)->toBe('pending')
        ->and($item->current_draft_id)->toBe($redraft->id)
        ->and($draft->refresh()->review_status)->toBe(ReviewStatus::Rejected);
});

it('raises a change held back by a moved cancelled-part baseline on a byte-identical feed, once', function () {
    $event = ingestApprove(ingestYogaDraft());
    $item = SourceItem::query()->where('external_id', '10003893')->sole();

    // The state a cancelled-part baseline move leaves (03-10): the baseline
    // still has the older time, content_hash the current one.
    $baseline = [...$item->accepted_payload, 'start_time' => '09:00'];
    $item->forceFill([
        'accepted_payload' => $baseline,
        'accepted_hash' => app(ContentHasher::class)->contentHashFromPayload($baseline),
    ])->save();

    $run = ingestLiveRun($this->tc->refresh());
    $update = EventDraft::query()->where('kind', DraftKind::Update)->sole();

    expect($update->event_id)->toBe($event->id)
        ->and($update->diff)->toEqual(['start_time' => ['published' => '10:00', 'before' => '09:00', 'now' => '10:00']])
        ->and($run->drafts_created)->toBe(1);

    $this->travel(1)->hours();
    $again = ingestLiveRun($this->tc->refresh());

    expect(EventDraft::query()->where('kind', DraftKind::Update)->count())->toBe(1)
        ->and($again->drafts_created)->toBe(0)
        ->and($again->drafts_replaced)->toBe(0);
});

it('raises an update for a linked event the feed moves beyond the import window', function () {
    $first = SourceRun::query()->where('source_id', $this->tc->id)->sole();
    ingestApprove(ingestYogaDraft());

    ingestFeed(ingestEditEvent(ingestTcFixture(), UPDATE_YOGA_UID, [
        'DTSTART;TZID=Europe/London:20261025T100000' => 'DTSTART;TZID=Europe/London:20270201T100000',
        'DTEND;TZID=Europe/London:20261025T110000' => 'DTEND;TZID=Europe/London:20270201T110000',
    ]));
    $run = ingestLiveRun($this->tc->refresh());
    $update = EventDraft::query()->where('kind', DraftKind::Update)->sole();

    expect($update->diff['start_date'])->toEqual(['published' => '2026-10-25', 'before' => '2026-10-25', 'now' => '2027-02-01'])
        ->and($run->items_changed)->toBe(1)
        ->and($run->skipped_far_future)->toBe($first->skipped_far_future);
});

it('carries the parse error of a moved override that appears on an approved series', function () {
    $series = Source::factory()->ics()->active()->create(['name' => 'Synthetic series', 'url' => 'https://feed.example.org/series.ics']);
    ingestFeed(icsFixture('series.ics'));
    ingestLiveRun($series);
    $event = ingestApprove(EventDraft::query()->where('source_id', $series->id)->where('title', 'Wednesday Folk Club')->sole());

    $override = implode("\r\n", [
        'BEGIN:VEVENT',
        'UID:s-exdate@synthetic.invalid',
        'DTSTAMP:20261017T050000Z',
        'RECURRENCE-ID;TZID=Europe/London:20261028T190000',
        'SUMMARY:Wednesday Folk Club',
        'DTSTART;TZID=Europe/London:20261028T193000',
        'DTEND;TZID=Europe/London:20261028T213000',
        'LOCATION:Town Hall\, Queens Square\, Attleborough\, NR17 2AF',
        'END:VEVENT',
    ])."\r\n";

    ingestFeed(str_replace('END:VCALENDAR', $override.'END:VCALENDAR', icsFixture('series.ics')));
    ingestLiveRun($series->refresh());
    $update = EventDraft::query()->where('kind', DraftKind::Update)->sole();

    expect($update->event_id)->toBe($event->id)
        ->and(array_keys($update->diff ?? []))->toContain('moved_overrides')
        ->and($update->diff['moved_overrides']['now'])->toEqual([['key' => '2026-10-28', 'start' => '2026-10-28 19:30', 'end' => '2026-10-28 21:30']])
        ->and($update->parse_errors)->toHaveCount(1)
        ->and($update->parse_errors[0])->toContain('Wed 28 Oct');
});
