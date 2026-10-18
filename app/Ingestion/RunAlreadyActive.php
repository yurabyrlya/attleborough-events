<?php

namespace App\Ingestion;

use RuntimeException;
use Throwable;

/**
 * The source already has a queued or running run, so a second one was not
 * started (SRC-04). Raised by RunLauncher when the source_runs_one_active
 * index refuses the insert; "Run now" shows the message, the dispatcher
 * skips the source.
 */
final class RunAlreadyActive extends RuntimeException
{
    public function __construct(public readonly int $sourceId, ?Throwable $previous = null)
    {
        parent::__construct('This source is already being checked.', 0, $previous);
    }
}
