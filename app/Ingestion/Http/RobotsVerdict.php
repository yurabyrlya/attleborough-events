<?php

namespace App\Ingestion\Http;

/**
 * RobotsPolicy's answer for one URL. $crawlDelay is the host's Crawl-delay
 * for this crawler in seconds (null when none is set).
 */
final readonly class RobotsVerdict
{
    private function __construct(
        public bool $allowed,
        public ?int $crawlDelay,
        public ?string $reason,
    ) {}

    public static function allow(?int $crawlDelay = null): self
    {
        return new self(true, $crawlDelay, null);
    }

    public static function deny(string $reason, ?int $crawlDelay = null): self
    {
        return new self(false, $crawlDelay, $reason);
    }
}
