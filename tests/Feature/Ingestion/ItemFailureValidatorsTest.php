<?php

use App\Enums\DraftKind;
use App\Enums\RunStatus;
use App\Models\EventDraft;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/*
 * WR-01: ingestion locks in the review actions' order (pending draft →
 * source item), and a run in which an item threw (e.g. a deadlock victim)
 * keeps no ETag/Last-Modified, so the next run fetches the whole body and
 * records the item instead of being answered 304 until the feed changes.
 */

const ITEM_FAILURE_URL = 'https://feed.example.org/item-failure.ics';

/**
 * @param  array<string, array{0: string, 1: string}>  $events  UID => [title, London DTSTART Ymd\THis]
 */
function itemFailureIcs(array $events): string
{
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//What\'s On Attleborough//Item failure test//EN'];

    foreach ($events as $uid => [$title, $start]) {
        $end = CarbonImmutable::createFromFormat('Ymd\THis', $start, 'Europe/London')->addHour()->format('Ymd\THis');
        $lines = [...$lines,
            'BEGIN:VEVENT', "UID:{$uid}", 'DTSTAMP:20261017T050000Z', "SUMMARY:{$title}",
            "DTSTART;TZID=Europe/London:{$start}", "DTEND;TZID=Europe/London:{$end}",
            'LOCATION:Town Hall', 'END:VEVENT',
        ];
    }

    return implode("\r\n", [...$lines, 'END:VCALENDAR'])."\r\n";
}

/**
 * Serves $body with an ETag and Last-Modified, and answers 304 when the
 * request sends that ETag back (a well-behaved server).
 */
function itemFailureServe(string $body, string $etag): void
{
    ingestFeed(function (Request $request) use ($body, $etag) {
        if ($request->header('If-None-Match') === [$etag]) {
            return Http::response('', 304, ['ETag' => $etag]);
        }

        return Http::response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'ETag' => $etag,
            'Last-Modified' => 'Sat, 17 Oct 2026 04:00:00 GMT',
        ]);
    });
}

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    Storage::fake('local');
    freezeLondon('2026-10-17 06:00:00');
    $this->seed(CategorySeeder::class);

    $this->source = Source::factory()->ics()->active()->create(['url' => ITEM_FAILURE_URL]);
    $this->feed = itemFailureIcs([
        'market@failure.invalid' => ['Market Day', '20261030T090000'],
        'quiz@failure.invalid' => ['Quiz Night', '20261024T193000'],
    ]);
});

it('stores no validators when an item threw, so the next run refetches the body and records it', function () {
    itemFailureServe($this->feed, '"v1"');

    // The Quiz Night draft insert throws, as a deadlock victim would. (A
    // real 40P01 inside RefreshDatabase's outer transaction makes Laravel
    // skip the savepoint rollback, so a plain exception stands in for it.)
    DB::unprepared(<<<'SQL'
        create function item_failure_deadlock() returns trigger language plpgsql as $$
        begin
            if new.title = 'Quiz Night' then
                raise exception 'simulated item failure';
            end if;
            return new;
        end $$;
        create trigger item_failure_deadlock before insert on event_drafts
            for each row execute function item_failure_deadlock();
        SQL);

    $first = ingestLiveRun($this->source);

    expect($first->status)->toBe(RunStatus::Succeeded)
        ->and($first->skipped_parse_error)->toBe(1)
        ->and($first->items_new)->toBe(1)
        ->and($this->source->refresh()->etag)->toBeNull()
        ->and($this->source->last_modified)->toBeNull()
        ->and($this->source->last_success_at)->not->toBeNull();

    DB::unprepared('drop trigger item_failure_deadlock on event_drafts; drop function item_failure_deadlock();');
    $this->travel(1)->hour();

    $second = ingestLiveRun($this->source->refresh());

    Http::assertSent(fn (Request $request) => $request->url() === ITEM_FAILURE_URL && ! $request->hasHeader('If-None-Match'));

    expect($second->status)->toBe(RunStatus::Succeeded)
        ->and($second->http_status)->toBe(200)
        ->and($second->items_new)->toBe(1)
        ->and(EventDraft::query()->where('title', 'Quiz Night')->where('kind', DraftKind::New)->count())->toBe(1)
        ->and($this->source->refresh()->etag)->toBe('"v1"');

    // A clean run keeps them again, so the third run is a cheap 304.
    $this->travel(1)->hour();

    expect(ingestLiveRun($this->source->refresh())->status)->toBe(RunStatus::NotModified);
});

it('locks a known item\'s pending draft before the item, like the review actions do', function () {
    itemFailureServe($this->feed, '"v1"');
    ingestLiveRun($this->source);
    expect(EventDraft::query()->count())->toBe(2);

    // The quiz moves to 20:00: both items are known and have a pending draft.
    itemFailureServe(str_replace('20261024T193000', '20261024T200000', $this->feed), '"v2"');
    $this->travel(1)->hour();

    $locks = [];
    DB::listen(function ($query) use (&$locks): void {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'for update') && (str_contains($sql, '"event_drafts"') || str_contains($sql, '"source_items"'))) {
            $locks[] = str_contains($sql, '"event_drafts"') ? 'draft' : 'item';
        }
    });

    $run = ingestLiveRun($this->source->refresh());

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->items_changed)->toBe(1)
        // Per item: pending drafts first, then the item (then the recorder's own re-lock of the draft).
        ->and(array_slice($locks, 0, 2))->toBe(['draft', 'item'])
        ->and(array_count_values($locks)['item'])->toBe(2);
});
