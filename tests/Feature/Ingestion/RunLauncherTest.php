<?php

use App\Enums\RunMode;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Ingestion\RunAlreadyActive;
use App\Ingestion\RunLauncher;
use App\Jobs\FetchSourceJob;
use App\Models\Source;
use App\Models\SourceRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * SRC-03 / SRC-04: "Run now" queues exactly one live run per source; the
 * source_runs_one_active index refuses a second while one is queued or
 * running. next-check maths keeps the London wall-clock slot (D-02).
 */

beforeEach(function () {
    Queue::fake();
    freezeLondon('2026-10-17 10:15:00');
    $this->launcher = app(RunLauncher::class);
    $this->source = Source::factory()->ics()->active()->create();
});

it('queues one manual live run and its job', function () {
    $run = $this->launcher->runNow($this->source);

    $stored = SourceRun::query()->findOrFail($run->id);

    expect($stored->source_id)->toBe($this->source->id)
        ->and($stored->mode)->toBe(RunMode::Live)
        ->and($stored->trigger)->toBe(RunTrigger::Manual)
        ->and($stored->status)->toBe(RunStatus::Queued)
        ->and($stored->started_at)->toBeNull()
        ->and($stored->queued_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-17 09:15:00');

    Queue::assertPushed(FetchSourceJob::class, fn (FetchSourceJob $job) => $job->runId === $run->id);
    Queue::assertPushed(FetchSourceJob::class, 1);
});

it('refuses a second run while the first is queued', function () {
    $this->launcher->runNow($this->source);

    expect(fn () => $this->launcher->runNow($this->source, RunTrigger::Schedule))
        ->toThrow(RunAlreadyActive::class, 'This source is already being checked.');

    expect(SourceRun::query()->where('source_id', $this->source->id)->count())->toBe(1);
    Queue::assertPushed(FetchSourceJob::class, 1);
});

it('refuses a second run while the first is running', function () {
    $run = $this->launcher->runNow($this->source);
    $run->forceFill(['status' => RunStatus::Running, 'started_at' => CarbonImmutable::now()->utc()])->save();

    try {
        $this->launcher->runNow($this->source);
        $this->fail('RunAlreadyActive was not thrown');
    } catch (RunAlreadyActive $e) {
        expect($e->sourceId)->toBe($this->source->id);
    }

    expect(SourceRun::query()->where('source_id', $this->source->id)->count())->toBe(1);
});

it('starts a new run once the previous one has finished', function () {
    $first = $this->launcher->runNow($this->source);
    $first->forceFill([
        'status' => RunStatus::Succeeded,
        'started_at' => CarbonImmutable::now()->utc(),
        'finished_at' => CarbonImmutable::now()->utc(),
    ])->save();
    // A worker releases the job's unique lock when the job finishes.
    Queue::releaseUniqueJobLocks();

    $second = $this->launcher->runNow($this->source);

    expect($second->id)->not->toBe($first->id)
        ->and(SourceRun::query()->where('source_id', $this->source->id)->count())->toBe(2);
    Queue::assertPushed(FetchSourceJob::class, 2);
});

it('lets another source start while one source is busy', function () {
    $other = Source::factory()->ics()->active()->create();

    $this->launcher->runNow($this->source);
    $this->launcher->runNow($other);

    expect(SourceRun::query()->count())->toBe(2);
});

it('keeps the caller\'s transaction usable when the insert is refused', function () {
    $this->launcher->runNow($this->source);

    $countInside = DB::transaction(function () {
        try {
            $this->launcher->runNow($this->source);
        } catch (RunAlreadyActive) {
            // expected
        }

        // Would fail with "current transaction is aborted" without the savepoint.
        return SourceRun::query()->count();
    });

    expect($countInside)->toBe(1);
});

it('computes the next check from the London check time, frequency and jitter', function (string $now, string $frequency, string $checkTime, string $expectedUtc) {
    $source = Source::factory()->ics()->create(['check_frequency' => $frequency, 'check_time' => $checkTime])->refresh();
    $jitter = ($source->id * 7) % 20;

    $next = $this->launcher->nextCheckAt($source, CarbonImmutable::parse($now, 'Europe/London'));

    expect($next->getTimezone()->getName())->toBe('UTC')
        ->and($next->format('Y-m-d H:i:s'))
        ->toBe(CarbonImmutable::parse($expectedUtc, 'UTC')->addMinutes($jitter)->format('Y-m-d H:i:s'));
})->with([
    'daily, BST' => ['2026-10-17 06:00:00', 'daily', '05:30', '2026-10-18 04:30:00'],
    'just after London midnight in BST (UTC is still the day before)' => ['2026-10-18 00:30:00', 'daily', '05:30', '2026-10-19 04:30:00'],
    'BST ends: Sun 25 Oct 05:30 GMT' => ['2026-10-24 23:30:00', 'daily', '05:30', '2026-10-25 05:30:00'],
    'BST starts: Sun 28 Mar 05:30 BST' => ['2027-03-27 23:30:00', 'daily', '05:30', '2027-03-28 04:30:00'],
    'weekly: seven days later' => ['2026-10-17 06:00:00', 'weekly', '05:30', '2026-10-24 04:30:00'],
    'weekly across BST end' => ['2026-10-20 06:00:00', 'weekly', '05:30', '2026-10-27 05:30:00'],
    'custom check time 07:00' => ['2026-11-02 08:00:00', 'daily', '07:00', '2026-11-03 07:00:00'],
]);

it('spreads sources over the jitter range deterministically', function () {
    $now = CarbonImmutable::parse('2026-11-02 08:00:00', 'Europe/London');
    $source = Source::factory()->ics()->create()->refresh();

    $first = $this->launcher->nextCheckAt($source, $now);
    $again = $this->launcher->nextCheckAt($source, $now);
    $minutes = (int) $first->format('i') - 30;

    expect($first->equalTo($again))->toBeTrue()
        ->and($minutes)->toBe(($source->id * 7) % 20)
        ->and($minutes)->toBeGreaterThanOrEqual(0)->toBeLessThan(20);
});
