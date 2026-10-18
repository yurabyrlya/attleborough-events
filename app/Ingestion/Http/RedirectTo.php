<?php

namespace App\Ingestion\Http;

/**
 * A hop's answer was a redirect: HttpFetcher re-checks $url and follows it.
 */
final readonly class RedirectTo
{
    public function __construct(
        public string $url,
        public int $status,
    ) {}
}
