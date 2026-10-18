<?php

namespace App\Ingestion\Data;

/**
 * One feed item: one identity (an ICS series = master + overrides).
 *
 * facts is null when the item cannot be read at all (unknown time zone,
 * sabre recurrence failure, end before start); contentHash is then null too.
 * parseErrors may also be set while facts are kept (the D-19 shapes), so the
 * draft still shows what the feed said and tells the admin what to fix.
 */
final readonly class ExtractedItem
{
    /**
     * @param  list<string>  $parseErrors  plain British English, for the admin
     */
    public function __construct(
        public string $externalId,
        public string $rawFragment,
        public string $rawHash,
        public ?NormalisedFacts $facts,
        public ?string $contentHash,
        public array $parseErrors = [],
    ) {}

    public function hasParseErrors(): bool
    {
        return $this->parseErrors !== [];
    }
}
