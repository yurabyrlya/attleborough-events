<?php

use App\Enums\RunMode;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Ingestion\RawBodyStore;
use App\Models\Source;
use App\Models\SourceRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/*
 * A10 retention: raw bodies older than ingest.raw_retention_days (30) are
 * deleted and their runs' raw_path cleared; run logs older than a year go,
 * except each source's latest run.
 */

function pruneFinishedRun(Source $source, CarbonImmutable $queuedAt, RunStatus $status = RunStatus::Succeeded): SourceRun
{
    $run = new SourceRun(['mode' => RunMode::Live, 'trigger' => RunTrigger::Schedule]);
    $run->forceFill([
        'source_id' => $source->id,
        'status' => $status,
        'queued_at' => $queuedAt->utc(),
        'started_at' => $status === RunStatus::Queued ? null : $queuedAt->utc(),
        'finished_at' => $status->isActive() ? null : $queuedAt->addMinute()->utc(),
        'created_at' => $queuedAt->utc(),
        'updated_at' => $queuedAt->utc(),
    ])->save();

    return $run;
}

/**
 * A run with a stored body whose file was last written at $queuedAt.
 */
function pruneRunWithBody(Source $source, CarbonImmutable $queuedAt): SourceRun
{
    $store = app(RawBodyStore::class);
    $run = pruneFinishedRun($source, $queuedAt);
    $path = $store->put($source->id, $run->id, "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n");
    touch(Storage::disk('local')->path($path), $queuedAt->getTimestamp());
    $run->forceFill(['raw_path' => $path])->save();

    return $run;
}

beforeEach(function () {
    Storage::fake('local');
    freezeLondon('2026-10-17 03:50:00');
    $this->now = CarbonImmutable::now();
});

it('deletes raw bodies older than the retention period and clears their raw_path', function () {
    $source = Source::factory()->ics()->create();
    $old = pruneRunWithBody($source, $this->now->subDays(31));
    $recent = pruneRunWithBody($source, $this->now->subDays(29));

    $this->artisan('ingest:prune-raw')->assertExitCode(0);

    $old->refresh();
    $recent->refresh();

    expect(config('ingest.raw_retention_days'))->toBe(30)
        ->and(Storage::disk('local')->exists(app(RawBodyStore::class)->pathFor($source->id, $old->id)))->toBeFalse()
        ->and($old->raw_path)->toBeNull()
        ->and(Storage::disk('local')->exists($recent->raw_path))->toBeTrue()
        ->and(app(RawBodyStore::class)->get($source->id, $recent->id))->toBe("BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n");
});

it('keeps raw_path on an old run whose body is still on disk', function () {
    $source = Source::factory()->ics()->create();
    // Run queued 31 days ago, but its body was rewritten 29 days ago.
    $run = pruneRunWithBody($source, $this->now->subDays(31));
    touch(Storage::disk('local')->path($run->raw_path), $this->now->subDays(29)->getTimestamp());

    $this->artisan('ingest:prune-raw')->assertExitCode(0);

    expect($run->refresh()->raw_path)->not->toBeNull()
        ->and(Storage::disk('local')->exists($run->raw_path))->toBeTrue();
});

it('deletes run logs older than a year unless the run is its source\'s latest', function () {
    $busy = Source::factory()->ics()->create();
    $quiet = Source::factory()->ics()->create();

    $busyOld = pruneFinishedRun($busy, $this->now->subMonths(13));
    $busyYoung = pruneFinishedRun($busy, $this->now->subMonths(11));
    $busyLatest = pruneFinishedRun($busy, $this->now->subDay());
    $quietOnly = pruneFinishedRun($quiet, $this->now->subMonths(13));

    $this->artisan('ingest:prune-raw')->assertExitCode(0);

    expect(SourceRun::query()->whereKey($busyOld->id)->exists())->toBeFalse()
        ->and(SourceRun::query()->whereKey($busyYoung->id)->exists())->toBeTrue()
        ->and(SourceRun::query()->whereKey($busyLatest->id)->exists())->toBeTrue()
        ->and(SourceRun::query()->whereKey($quietOnly->id)->exists())->toBeTrue();
});

it('does nothing when there is nothing to prune', function () {
    $source = Source::factory()->ics()->create();
    $run = pruneRunWithBody($source, $this->now->subDays(2));

    $this->artisan('ingest:prune-raw')->assertExitCode(0);

    expect($run->refresh()->raw_path)->not->toBeNull()
        ->and(SourceRun::query()->count())->toBe(1);
});
