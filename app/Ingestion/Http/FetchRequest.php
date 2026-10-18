<?php

namespace App\Ingestion\Http;

use Carbon\CarbonImmutable;

/**
 * One fetch for HttpFetcher. The conditional validators come from the last
 * complete live run (never from a preview). $startedAt lets a caller (the
 * job) count time it already spent against the fetch deadline; it defaults
 * to the moment fetch() starts.
 */
final readonly class FetchRequest
{
    public const string METHOD_ICS = 'ics';

    public function __construct(
        public string $url,
        public ?string $etag = null,
        public ?string $lastModified = null,
        public string $method = self::METHOD_ICS,
        public ?CarbonImmutable $startedAt = null,
    ) {}
}
