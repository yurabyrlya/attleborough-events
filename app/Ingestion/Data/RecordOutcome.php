<?php

namespace App\Ingestion\Data;

/**
 * What SourceItemRecorder::record() did with one item: the single bucket it
 * is counted in (SRC-07) plus the drafts it wrote and whether the source
 * newly cancelled it (items_cancelled).
 */
final readonly class RecordOutcome
{
    public function __construct(
        public string $bucket,
        public int $draftsCreated = 0,
        public int $draftsReplaced = 0,
        public bool $cancelled = false,
    ) {}
}
