<?php

use App\Enums\EventOrigin;
use App\Enums\RunMode;
use App\Filament\Resources\Sources\Pages\EditSource;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\Source;
use App\Models\Venue;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Data\PublishableEventData;
use App\Review\DraftPublishability;
use Database\Seeders\CategorySeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/*
 * PIPE-05 (03-11): an ingested item that would become a new draft is
 * compared with published events (by occurrence) and with pending drafts
 * from other sources or origins. Same canonical URL → 'url' (1.000);
 * otherwise same London date, starts within 60 minutes (or both all-day),
 * same or similar venue and pg_trgm title similarity ≥ 0.3 → 'fuzzy'.
 * Offline: Http::fake through ingestFeed(), Sleep::fake.
 */

const DUP_TC_URL = 'https://attleboroughtc.org.uk/?post_type=tribe_events&ical=1';

function dupTcFixture(): string
{
    return sourceFixture('attleborough-town-council/2026-10-17-ical.ics');
}

/**
 * A published event entered by hand (the manual path through PublishEvent),
 * at the named venue (reused when it exists).
 *
 * @param  array<string, mixed>  $overrides  PublishableEventData arguments
 */
function dupPublish(string $title, string $date = '2026-10-25', ?string $time = '10:00', string $venueName = 'Town Hall', array $overrides = []): Event
{
    $venue = Venue::query()->where('name', $venueName)->first() ?? Venue::factory()->create(['name' => $venueName]);

    return app(PublishEvent::class)(new PublishableEventData(...array_merge([
        'title' => $title,
        'description' => 'A friendly session for all.',
        'categoryId' => (int) Category::query()->orderBy('id')->value('id'),
        'venueId' => $venue->id,
        'startDate' => $date,
        'startTime' => $time,
        'organiserContact' => 'hello@example.test',
    ], $overrides)), ingestAdmin(), EventOrigin::Manual);
}

/**
 * @return Collection<int, EventDraft>
 */
function dupFlagged(): Collection
{
    return EventDraft::query()
        ->where(fn ($q) => $q->whereNotNull('duplicate_of_event_id')->orWhereNotNull('duplicate_of_draft_id'))
        ->orderBy('id')
        ->get();
}

function dupTcSource(): Source
{
    return Source::factory()->ics()->active()->withIdentity('tec_post_id')->create([
        'name' => 'Attleborough Town Council',
        'url' => DUP_TC_URL,
    ]);
}

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    Storage::fake('local');
    freezeLondon('2026-10-17 06:00:00');
    $this->seed(CategorySeeder::class);

    $this->tc = dupTcSource();
    ingestFeed(dupTcFixture());
});

it('flags only the 25 Oct Town Council Yoga draft as a fuzzy duplicate of the published "Yoga with [name removed]"', function () {
    $event = dupPublish('Yoga with [name removed]');

    $run = ingestLiveRun($this->tc);
    expect($run->drafts_created)->toBeGreaterThan(0);

    $flagged = dupFlagged();

    expect($flagged)->toHaveCount(1);

    $draft = $flagged->sole();

    expect($draft->title)->toBe('[name removed] ~ Yoga')
        ->and($draft->start_date->toDateString())->toBe('2026-10-25')
        ->and($draft->duplicate_of_event_id)->toBe($event->id)
        ->and($draft->duplicate_of_draft_id)->toBeNull()
        ->and($draft->duplicate_reason)->toBe('fuzzy')
        ->and($draft->duplicate_score)->toBe('0.783')
        ->and($draft->event_id)->toBeNull();

    // The 18 and 21 Oct Yoga drafts (other dates) carry no warning.
    $otherYoga = EventDraft::query()->where('title', '[name removed] ~ Yoga')->where('start_date', '<>', '2026-10-25')->get();
    expect($otherYoga)->toHaveCount(2)
        ->and($otherYoga->every(fn (EventDraft $d): bool => $d->duplicate_of_event_id === null && $d->duplicate_reason === null))->toBeTrue();

    expect(app(DraftPublishability::class)->bulkBlockerKeys($draft))->toContain('duplicate');
});

it('leaves a bare "Yoga" event alone: similarity 0.2777778 is under the 0.3 threshold', function () {
    dupPublish('Yoga');

    ingestLiveRun($this->tc);

    expect(dupFlagged())->toHaveCount(0)
        ->and(config('ingest.duplicate.title_threshold'))->toBe(0.3);
});

it('raises no warning when the whole Town Council fixture arrives in an empty database (Pitfall 6)', function () {
    $run = ingestLiveRun($this->tc);

    expect($run->drafts_created)->toBeGreaterThan(20)
        ->and(dupFlagged())->toHaveCount(0);
});

it('notes the possible duplicate on the D-01 preview row and writes nothing', function () {
    $event = dupPublish('Yoga with [name removed]');

    $run = ingestLiveRun($this->tc, RunMode::Preview);

    $rows = collect($run->preview['rows'])->filter(fn (array $row): bool => $row['duplicate'] !== null)->values();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['title'])->toBe('[name removed] ~ Yoga')
        ->and($rows[0]['starts'])->toBe('2026-10-25 10:00')
        ->and($rows[0]['duplicate'])->toEqual(['title' => $event->title, 'reason' => 'fuzzy', 'score' => '0.78'])
        ->and(EditSource::previewRow($rows[0])['duplicate'])->toBe('Possible duplicate of Yoga with [name removed]')
        ->and(EventDraft::query()->count())->toBe(0);
});
