<?php

use App\Enums\DraftKind;
use App\Enums\ReviewStatus;
use App\Models\EventDraft;
use App\Models\Source;
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
