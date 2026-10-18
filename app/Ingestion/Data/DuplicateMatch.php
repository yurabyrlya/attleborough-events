<?php

namespace App\Ingestion\Data;

use Carbon\CarbonImmutable;

/**
 * The likely duplicate DuplicateDetector found for an ingested item
 * (PIPE-05): a published event (eventId) or a pending draft (draftId),
 * exactly one of them, why ('url' = same canonical URL, score 1.0;
 * 'fuzzy' = same date and venue, close start, similar title, score = the
 * pg_trgm title similarity), and what the admin compares side by side
 * (D-13): the candidate's title, start (UTC) and venue name.
 *
 * columns() is what DraftWriter stores on the draft; event_drafts keeps
 * duplicate_score as numeric(4,3), so PostgreSQL rounds the score half
 * away from zero (0.7826087 → 0.783). formatScore() shows two decimals.
 */
final readonly class DuplicateMatch
{
    public const string URL = 'url';

    public const string FUZZY = 'fuzzy';

    public function __construct(
        public ?int $eventId,
        public ?int $draftId,
        public string $reason,
        public float $score,
        public ?CarbonImmutable $startsAtUtc,
        public ?string $venueName,
        public string $title,
    ) {}

    /**
     * The event_drafts duplicate columns for this match, or the cleared
     * columns for no match.
     *
     * @return array{duplicate_of_event_id: int|null, duplicate_of_draft_id: int|null, duplicate_reason: string|null, duplicate_score: float|null}
     */
    public static function columns(?self $match): array
    {
        return [
            'duplicate_of_event_id' => $match?->eventId,
            'duplicate_of_draft_id' => $match?->eventId === null ? $match?->draftId : null,
            'duplicate_reason' => $match?->reason,
            'duplicate_score' => $match?->score,
        ];
    }

    /**
     * The shape a D-01 preview row stores under 'duplicate' (EditSource
     * reads the title).
     *
     * @return array{title: string, reason: string, score: string}
     */
    public function toPreview(): array
    {
        return [
            'title' => mb_substr($this->title, 0, 255, 'UTF-8'),
            'reason' => $this->reason,
            'score' => (string) self::formatScore($this->score),
        ];
    }

    /**
     * A stored or measured score for display: two decimals ("0.78").
     */
    public static function formatScore(float|string|null $score): ?string
    {
        if ($score === null || $score === '' || ! is_numeric($score)) {
            return null;
        }

        return number_format((float) $score, 2, '.', '');
    }
}
