<?php

namespace App\Ingestion\Pipeline;

use App\Enums\IdentityStrategy;
use App\Ingestion\Data\NormalisedFacts;
use App\Review\CategoryAssigner;

/**
 * The two hashes of a feed item (RESEARCH Pattern 4, D-06).
 *
 * raw_hash: sha256 of the unfolded fragment without the lines that churn on
 * every fetch (DTSTAMP, LAST-MODIFIED, CREATED, SEQUENCE; plus UID when the
 * identity is not the UID, because the Academy and Town Council UIDs embed
 * request times or epochs). Equal raw hashes let a run skip unchanged items.
 *
 * content_hash: sha256 of the D-06 facts residents rely on, in a FIXED key
 * order: title, date/time, venue, price, family-friendly, cancelled, and the
 * series shape. description, URL, categories and organiser never change it,
 * so wording-only edits are quiet (D-06). Moved overrides and RDATE extras
 * are D-19 parse errors but are hashed anyway: the same shape appearing on an
 * already-linked series must still reach the admin.
 *
 * One hashing contract for ingestion and baseline moves:
 * contentHashFromPayload(toPayload()) === contentHash(facts).
 */
final class ContentHasher
{
    /** Property names whose lines churn on every fetch. */
    private const array VOLATILE_PROPERTIES = ['DTSTAMP', 'LAST-MODIFIED', 'CREATED', 'SEQUENCE'];

    public function rawHash(string $fragment, IdentityStrategy $identity): string
    {
        $volatile = self::VOLATILE_PROPERTIES;

        if ($identity !== IdentityStrategy::Uid) {
            $volatile[] = 'UID';
        }

        // RFC 5545 §3.1 unfolding: CRLF (or LF) followed by one space or tab.
        $unfolded = (string) preg_replace('/\r?\n[ \t]/', '', $fragment);
        $kept = [];

        foreach (preg_split('/\r?\n/', $unfolded) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            $name = strtoupper((string) strtok($line, ':;'));

            if (! in_array($name, $volatile, true)) {
                $kept[] = $line;
            }
        }

        return hash('sha256', implode("\r\n", $kept));
    }

    public function contentHash(NormalisedFacts $facts): string
    {
        $venue = trim(implode(', ', array_filter([$facts->venueName, $facts->venueAddress], fn (?string $part): bool => $part !== null && $part !== '')));

        // Key order is part of the contract: never reorder or rename.
        $factSet = [
            'title_norm' => CategoryAssigner::normalise($facts->title),
            'all_day' => $facts->allDay,
            'start_date' => $facts->startDate,
            'start_time' => $facts->startTime,
            'end_date' => $facts->endDate,
            'end_time' => $facts->endTime,
            'venue_norm' => $venue === '' ? null : CategoryAssigner::normalise($venue),
            'price_type' => $facts->priceType->value,
            'family_friendly' => $facts->familyFriendly,
            'cancelled' => $facts->cancelled,
            'rrule' => $facts->rrule,
            'excluded_dates' => $facts->excludedDates,
            'cancelled_keys' => $facts->cancelledKeys,
            'moved_overrides' => $facts->movedOverrides,
            'extra_dates' => $facts->extraDates,
        ];

        return hash('sha256', json_encode($factSet, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * The content hash of a stored payload (source_items.payload or
     * accepted_payload).
     *
     * @param  array<string, mixed>  $payload
     */
    public function contentHashFromPayload(array $payload): string
    {
        return $this->contentHash(NormalisedFacts::fromPayload($payload));
    }
}
