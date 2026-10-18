<?php

namespace App\Ingestion\Http;

use RuntimeException;

/**
 * The next request slot for a host is further away than a worker may sleep
 * (ingest.max_inline_wait, or past the fetch deadline). Thrown instead of
 * sleeping so a job never outlives its timeout (RESEARCH Pitfall 7); the
 * job releases itself for $seconds (03-05). No slot was booked.
 */
final class HostBusy extends RuntimeException
{
    public function __construct(public readonly string $host, public readonly int $seconds)
    {
        parent::__construct('Host '.$host.' is busy for '.$seconds.' seconds');
    }
}
