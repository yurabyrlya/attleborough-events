<?php

namespace App\Console\Commands;

use App\Enums\RunStatus;
use App\Ingestion\RawBodyStore;
use App\Models\SourceRun;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Retention for ingestion data (A10), run daily at 03:50 London.
 *
 * 1. Deletes raw bodies last written more than ingest.raw_retention_days
 *    (30) ago, then clears raw_path on older runs whose body is gone, so
 *    the UI never offers a body that no longer exists. raw_sha256 stays as
 *    the body's fingerprint.
 * 2. Deletes run logs queued more than a year ago, except each source's
 *    latest run (the source page still shows when it was last checked) and
 *    any queued or running run. source_items' first/last seen run links
 *    are set to null by their foreign keys.
 */
class IngestPruneRaw extends Command
{
    protected $signature = 'ingest:prune-raw';

    protected $description = 'Delete old raw source bodies and year-old source run logs';

    public function handle(RawBodyStore $store): int
    {
        $days = (int) config('ingest.raw_retention_days', 30);
        $now = CarbonImmutable::now('Europe/London');

        $deletedBodies = $store->prune($days);
        $cleared = $this->clearMissingRawPaths($store, $now->subDays($days)->utc());
        $deletedRuns = $this->deleteOldRuns($now->subYear()->utc());

        $this->info("Deleted {$deletedBodies} raw bodies, cleared {$cleared} raw paths, deleted {$deletedRuns} run logs.");

        return self::SUCCESS;
    }

    private function clearMissingRawPaths(RawBodyStore $store, CarbonImmutable $cutoffUtc): int
    {
        $cleared = 0;

        SourceRun::query()
            ->whereNotNull('raw_path')
            ->where('queued_at', '<', $cutoffUtc)
            ->select(['id', 'source_id'])
            ->chunkById(500, function ($runs) use ($store, &$cleared): void {
                $gone = $runs
                    ->reject(fn (SourceRun $run) => $store->exists($run->source_id, $run->id))
                    ->modelKeys();

                if ($gone !== []) {
                    $cleared += SourceRun::query()->whereKey($gone)->update(['raw_path' => null]);
                }
            });

        return $cleared;
    }

    private function deleteOldRuns(CarbonImmutable $cutoffUtc): int
    {
        return SourceRun::query()
            ->where('queued_at', '<', $cutoffUtc)
            ->whereNotIn('status', [RunStatus::Queued, RunStatus::Running])
            ->whereRaw('source_runs.id <> (select max(latest.id) from source_runs as latest where latest.source_id = source_runs.source_id)')
            ->delete();
    }
}
