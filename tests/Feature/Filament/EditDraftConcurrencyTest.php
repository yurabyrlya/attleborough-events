<?php

use App\Enums\DraftKind;
use App\Enums\ReviewStatus;
use App\Filament\Resources\EventDrafts\Pages\EditEventDraft;
use App\Ingestion\Pipeline\ContentHasher;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\Source;
use App\Models\SourceItem;
use App\Models\Venue;
use App\Review\Exceptions\DraftChangedMeanwhile;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

/*
 * Review CR-02: an edit page opened before ingestion replaced the draft must
 * not write its stale values back, and approval must set the item's
 * baseline to the feed facts the approved draft was built from, so a feed
 * change the draft never showed is raised as an update instead of going
 * quiet. Offline: Http::fake through ingestFeed(), Sleep::fake.
 */

const CONCURRENCY_TC_URL = 'https://attleboroughtc.org.uk/?post_type=tribe_events&ical=1';

/** TEC post 10003893: '[name removed] ~ Yoga', Sun 25 Oct 10:00-11:00. */
const CONCURRENCY_YOGA_UID = 'UID:10003893-1792922400-1792926000@attleboroughtc.org.uk';

function concurrencyTcFixture(): string
{
    return sourceFixture('attleborough-town-council/2026-10-17-ical.ics');
}

/** The fixture with the Yoga session moved one hour later. */
function concurrencyYogaMovedLater(): string
{
    return ingestEditEvent(concurrencyTcFixture(), CONCURRENCY_YOGA_UID, [
        CONCURRENCY_YOGA_UID => 'UID:10003893-1792926000-1792929600@attleboroughtc.org.uk',
        'DTSTART;TZID=Europe/London:20261025T100000' => 'DTSTART;TZID=Europe/London:20261025T110000',
        'DTEND;TZID=Europe/London:20261025T110000' => 'DTEND;TZID=Europe/London:20261025T120000',
    ]);
}

function concurrencyYogaDraft(): EventDraft
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

    actingAs(ingestAdmin());

    $this->townHall = Venue::factory()->create(['name' => 'Town Hall']);
    $this->tc = Source::factory()->ics()->active()->withIdentity('tec_post_id')->create([
        'name' => 'Attleborough Town Council',
        'url' => CONCURRENCY_TC_URL,
        'default_venue_id' => $this->townHall->id,
    ]);

    ingestFeed(concurrencyTcFixture());
    ingestLiveRun($this->tc);
});

it('refuses Save & approve and Save on an edit page opened before ingestion replaced the draft', function () {
    $draft = concurrencyYogaDraft();

    expect($draft->start_time)->toBe('10:00:00');

    $page = livewire(EditEventDraft::class, ['record' => $draft->id])
        ->assertFormSet(['start_time' => '10:00']);

    // A scheduled run sees the feed move to 11:00 and replaces the draft.
    ingestFeed(concurrencyYogaMovedLater());
    $this->travel(1)->hours();
    expect(ingestLiveRun($this->tc->refresh())->drafts_replaced)->toBe(1);

    $page->call('saveAndApprove')->assertNotified(DraftChangedMeanwhile::MESSAGE);

    expect($draft->refresh()->review_status)->toBe(ReviewStatus::PendingReview)
        ->and($draft->start_time)->toBe('11:00:00')
        ->and(Event::count())->toBe(0);

    $page->fillForm(['title' => 'Edited in a stale page'])
        ->call('save')
        ->assertNotified(DraftChangedMeanwhile::MESSAGE);

    expect($draft->refresh()->title)->toBe('[name removed] ~ Yoga')
        ->and($draft->start_time)->toBe('11:00:00');

    // Reloaded, the page shows 11:00 and publishes it with a matching baseline.
    livewire(EditEventDraft::class, ['record' => $draft->id])
        ->assertFormSet(['start_time' => '11:00'])
        ->call('saveAndApprove')
        ->assertNotified('Published');

    $item = SourceItem::query()->findOrFail($draft->source_item_id);
    $event = Event::query()->sole();

    expect(substr((string) $event->start_time, 0, 5))->toBe('11:00')
        ->and($item->accepted_hash)->toBe($item->content_hash);
});

it('lets the admin save twice on the same page', function () {
    $draft = concurrencyYogaDraft();

    $page = livewire(EditEventDraft::class, ['record' => $draft->id])
        ->fillForm(['title' => 'Yoga (first save)'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotNotified(DraftChangedMeanwhile::MESSAGE);

    $page->fillForm(['title' => 'Yoga (second save)'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotNotified(DraftChangedMeanwhile::MESSAGE);

    expect($draft->refresh()->title)->toBe('Yoga (second save)');
});

it('sets the baseline to the facts the approved draft was built from, so a feed change it never showed is raised as an update', function () {
    $draft = concurrencyYogaDraft();
    $item = SourceItem::query()->findOrFail($draft->source_item_id);
    $shown = $item->content_hash;

    // The item already holds the feed's 11:00 while the draft still shows
    // 10:00 (the feed moved and the draft was not replaced).
    $moved = [...($item->payload ?? []), 'start_time' => '11:00', 'end_time' => '12:00'];
    $movedHash = app(ContentHasher::class)->contentHashFromPayload($moved);
    $item->forceFill(['payload' => $moved, 'content_hash' => $movedHash])->save();

    $event = ingestApprove($draft->refresh());

    $item->refresh();

    expect(substr((string) $event->start_time, 0, 5))->toBe('10:00')
        ->and($item->accepted_hash)->toBe($shown)
        ->and($item->accepted_hash)->not->toBe($movedHash)
        ->and($item->accepted_payload['start_time'] ?? null)->toBe($draft->raw_payload['start_time'] ?? 'missing');

    // The next run raises the 10:00 → 11:00 move for the published event.
    ingestFeed(concurrencyYogaMovedLater());
    $this->travel(1)->hours();
    ingestLiveRun($this->tc->refresh());

    $update = EventDraft::query()->where('kind', DraftKind::Update)->sole();

    expect($update->event_id)->toBe($event->id)
        ->and($update->diff['start_time'] ?? null)->toEqual(['published' => '10:00', 'before' => '10:00', 'now' => '11:00']);
});
