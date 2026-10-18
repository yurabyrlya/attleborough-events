<?php

namespace App\Review;

use App\Models\Venue;
use App\Review\Data\VenueSuggestion;

/**
 * Finds the existing venue a typed venue most likely means (D-05).
 *
 * exact(): case-insensitive name, then an alias, then the postcode
 * (spaces ignored); first hit wins in that order, lowest id on ties.
 * suggest(): exact() first, else the most similar name by pg_trgm
 * similarity at or above config('review.venue_similarity_threshold').
 *
 * Read-only: it never creates a venue and never sets a draft's venue_id;
 * the suggestion only pre-selects the venue for the admin to confirm.
 * (Ingestion's D-22 rule compares exact() with the source's admin-set
 * default venue in DraftData::fromSourceItem(); the venue_id then comes
 * from that admin configuration, never from this matcher.)
 * Organiser text reaches SQL only as bound parameters.
 */
final class VenueMatcher
{
    public function exact(?string $name, ?string $postcode): ?Venue
    {
        return $this->exactMatch($name, $postcode)[0] ?? null;
    }

    public function suggest(?string $name, ?string $postcode): ?VenueSuggestion
    {
        $exact = $this->exactMatch($name, $postcode);

        if ($exact !== null) {
            [$venue, $matchedBy] = $exact;

            return new VenueSuggestion($venue->id, $venue->name, $matchedBy, 1.0);
        }

        $name = self::clean($name);

        if ($name === null) {
            return null;
        }

        $threshold = (float) config('review.venue_similarity_threshold');

        $similar = Venue::query()
            ->selectRaw('id, name, similarity(name, ?) as score', [$name])
            ->whereRaw('similarity(name, ?) >= ?', [$name, $threshold])
            ->orderByDesc('score')
            ->orderBy('id')
            ->first();

        if ($similar === null) {
            return null;
        }

        return new VenueSuggestion($similar->id, $similar->name, 'similar', (float) $similar->getAttribute('score'));
    }

    /**
     * @return array{0: Venue, 1: 'name'|'alias'|'postcode'}|null
     */
    private function exactMatch(?string $name, ?string $postcode): ?array
    {
        $name = self::clean($name);

        if ($name !== null) {
            $byName = Venue::query()->whereRaw('lower(name) = lower(?)', [$name])->orderBy('id')->first();

            if ($byName !== null) {
                return [$byName, 'name'];
            }

            $byAlias = Venue::query()
                ->whereRaw('exists (select 1 from jsonb_array_elements_text(aliases) a where lower(a) = lower(?))', [$name])
                ->orderBy('id')
                ->first();

            if ($byAlias !== null) {
                return [$byAlias, 'alias'];
            }
        }

        $postcode = self::clean($postcode);

        if ($postcode !== null) {
            $normalised = strtoupper((string) preg_replace('/\s+/', '', $postcode));

            $byPostcode = Venue::query()
                ->whereRaw("replace(upper(postcode), ' ', '') = ?", [$normalised])
                ->orderBy('id')
                ->first();

            if ($byPostcode !== null) {
                return [$byPostcode, 'postcode'];
            }
        }

        return null;
    }

    private static function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
