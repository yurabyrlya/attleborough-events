<?php

namespace App\Ingestion\Data;

/**
 * What one extraction produced. A fatalError (unreadable body, too many
 * events) means the run fails as parse_error (03-05) and items is empty.
 * eventCount is the number of VEVENT components seen in the body.
 */
final readonly class ExtractionResult
{
    /**
     * @param  list<ExtractedItem>  $items  sorted by next instance start (UTC), then external id
     */
    public function __construct(
        public array $items,
        public ?string $fatalError = null,
        public int $eventCount = 0,
    ) {}

    public static function fatal(string $message, int $eventCount = 0): self
    {
        return new self([], $message, $eventCount);
    }

    public function failed(): bool
    {
        return $this->fatalError !== null;
    }
}
