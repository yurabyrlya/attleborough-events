<?php

namespace App\Ingestion;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Fetched source bodies, gzip-compressed on the private local disk
 * (storage/app/private/ingest/raw/{source_id}/{run_id}.gz), kept for
 * inspection, debugging and preview reuse (D-24) for
 * ingest.raw_retention_days (A10), then removed by prune().
 *
 * Paths are built from integer ids only, so nothing from a source can steer
 * a write outside ingest/raw (T-03-09).
 */
class RawBodyStore
{
    public const string ROOT = 'ingest/raw';

    public function pathFor(int $sourceId, int $runId): string
    {
        if ($sourceId <= 0 || $runId <= 0) {
            throw new RuntimeException('Raw body ids must be positive integers');
        }

        return self::ROOT.'/'.$sourceId.'/'.$runId.'.gz';
    }

    /**
     * Store the body and return its path on the local disk.
     */
    public function put(int $sourceId, int $runId, string $body): string
    {
        $path = $this->pathFor($sourceId, $runId);
        $compressed = gzencode($body, 6);

        if ($compressed === false || ! $this->disk()->put($path, $compressed)) {
            throw new RuntimeException('Could not store the raw body for run '.$runId);
        }

        return $path;
    }

    /**
     * The stored body, or null when there is none (pruned or never stored).
     */
    public function get(int $sourceId, int $runId): ?string
    {
        $path = $this->pathFor($sourceId, $runId);

        if (! $this->disk()->exists($path)) {
            return null;
        }

        $body = gzdecode((string) $this->disk()->get($path));

        return $body === false ? null : $body;
    }

    /**
     * Whether a body is stored for this run.
     */
    public function exists(int $sourceId, int $runId): bool
    {
        return $this->disk()->exists($this->pathFor($sourceId, $runId));
    }

    /**
     * Delete stored bodies last written more than $days days ago.
     *
     * @return int how many files were deleted
     */
    public function prune(int $days): int
    {
        $cutoff = CarbonImmutable::now()->subDays($days)->getTimestamp();
        $deleted = 0;

        foreach ($this->disk()->allFiles(self::ROOT) as $file) {
            if (! str_ends_with($file, '.gz')) {
                continue;
            }

            if ($this->disk()->lastModified($file) < $cutoff && $this->disk()->delete($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function disk(): Filesystem
    {
        return Storage::disk('local');
    }
}
