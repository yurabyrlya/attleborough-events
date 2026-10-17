<?php

namespace App\Review\Data;

/**
 * The existing venue VenueMatcher thinks a typed venue means, and how it
 * got there: 'name', 'alias' or 'postcode' (exact, score 1.0) or 'similar'
 * (pg_trgm similarity of the name). Only ever pre-selects; never stored.
 */
final readonly class VenueSuggestion
{
    public function __construct(
        public int $venueId,
        public string $venueName,
        public string $matchedBy,
        public float $score,
    ) {}
}
