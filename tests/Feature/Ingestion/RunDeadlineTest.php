<?php

use App\Enums\FetchFailureKind;
use App\Enums\RunStatus;
use App\Models\EventDraft;
use App\Models\Source;
use App\Models\SourceItem;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/*
 * WR-07: a live run stops recording items once ingest.run_deadline (from
 * when the job claimed it) has passed, well inside FetchSourceJob's
 * timeout, instead of being killed by the worker part way through the
 * loop. It fails as timeout with the counters it reached, keeps the items
 * already recorded, stores no validators, and the next check gets further.
 */

const RUN_DEADLINE_URL = 'https://feed.example.org/run-deadline.ics';

function runDeadlineIcs(int $count): string
{
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//What\'s On Attleborough//Run deadline test//EN'];

    for ($i = 1; $i <= $count; $i++) {
        $start = CarbonImmutable::parse('2026-10-20 19:00', 'Europe/London')->addDays($i);
        $lines = [...$lines,
            'BEGIN:VEVENT', "UID:item-{$i}@run-deadline.invalid", 'DTSTAMP:20261017T050000Z', "SUMMARY:Talk number {$i}",
            'DTSTART;TZID=Europe/London:'.$start->format('Ymd\THis'), 'DTEND;TZID=Europe/London:'.$start->addHour()->format('Ymd\THis'),
            'LOCATION:Town Hall', 'END:VEVENT',
        ];
    }

    return implode("\r\n", [...$lines, 'END:VCALENDAR'])."\r\n";
}

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    Storage::fake('local');
    freezeLondon('2026-10-17 06:00:00');
    $this->seed(CategorySeeder::class);
    config()->set('ingest.run_deadline', 240);

    $this->source = Source::factory()->ics()->active()->create(['url' => RUN_DEADLINE_URL]);
});

it('stops at the run deadline, fails as timeout with partial counters, and the next check finishes the job', function () {
    ingestFeed(fn () => Http::response(runDeadlineIcs(6), 200, [
        'Content-Type' => 'text/calendar; charset=utf-8',
        'ETag' => '"v1"',
    ]));

    // Recording each new item takes 100 seconds: items 1-3 start before the
    // 240-second deadline (0, 100, 200 s after the claim), item 4 would not.
    $slow = true;
    SourceItem::created(function () use (&$slow): void {
        if ($slow) {
            test()->travel(100)->seconds();
        }
    });

    $first = ingestLiveRun($this->source);

    expect($first->status)->toBe(RunStatus::Failed)
        ->and($first->failure_kind)->toBe(FetchFailureKind::Timeout->value)
        ->and($first->error_message)->toBe('Timed out: recorded 3 of 6 items before the time limit. Items already recorded are quick to check again, so the next check gets further.')
        ->and($first->items_found)->toBe(3)
        ->and($first->items_new)->toBe(3)
        ->and($first->drafts_created)->toBe(3)
        ->and($first->finished_at)->not->toBeNull()
        ->and(SourceItem::query()->count())->toBe(3)
        ->and(EventDraft::query()->count())->toBe(3)
        ->and($this->source->refresh()->etag)->toBeNull()
        ->and($this->source->consecutive_failures)->toBe(1);

    $slow = false;
    $this->travel(1)->hour();
    $second = ingestLiveRun($this->source->refresh());

    expect($second->status)->toBe(RunStatus::Succeeded)
        ->and($second->items_found)->toBe(6)
        ->and($second->items_unchanged)->toBe(3)
        ->and($second->items_new)->toBe(3)
        ->and(EventDraft::query()->count())->toBe(6)
        ->and($this->source->refresh()->etag)->toBe('"v1"')
        ->and($this->source->consecutive_failures)->toBe(0);
});
