<?php

namespace App\Review;

use App\Enums\QualityBand;
use App\Models\EventDraft;
use App\Review\Data\QualityResult;

/**
 * A draft's 0-100 quality score, band and reasons (PIPE-04), run by
 * DraftWriter on every write.
 *
 * Starts at 100 and takes off the weights in config('review.quality.weights'),
 * each factor once, in this fixed order (which is also the reasons order):
 * 1. each missing field: title, start date, start time (unless all-day),
 *    venue (id or typed name), description, a contact/website/source link;
 * 2. any parse errors (one deduction; up to three "Couldn't read" lines);
 * 3. a typed venue that matches no known venue;
 * 4. category fell back to Other;
 * 5. a timed event with no end time;
 * 6. "repeats" text with no rule that the admin has not acknowledged (D-06).
 *
 * Pure and deterministic: integer arithmetic only, clamped to 0..100, and
 * the same draft always gives the same score and reasons. Reasons hold the
 * raw organiser text; escaping happens at display.
 */
final class QualityScorer
{
    /**
     * Parse errors listed one per line before the rest are summarised.
     */
    private const MAX_PARSE_ERROR_LINES = 3;

    /**
     * @param  bool  $venueRecognised  A confirmed venue_id or an exact name/alias/postcode match
     * @param  bool  $categoryFlagged  The category fell back to Other
     */
    public function score(EventDraft $draft, bool $venueRecognised, bool $categoryFlagged): QualityResult
    {
        $score = 100;
        $reasons = [];

        $venueMissing = $draft->venue_id === null && self::blank($draft->venue_name);

        $missing = [
            'Missing title' => self::blank($draft->title),
            'Missing start date' => $draft->start_date === null,
            'Missing start time' => ! $draft->all_day && self::blank($draft->start_time),
            'Missing venue' => $venueMissing,
            'Missing description' => self::blank($draft->description),
            'Missing a contact, website or link' => self::blank($draft->organiser_contact)
                && self::blank($draft->organiser_website)
                && self::blank($draft->source_url),
        ];

        foreach ($missing as $reason => $isMissing) {
            if ($isMissing) {
                $score -= self::weight('missing_field');
                $reasons[] = $reason;
            }
        }

        $parseErrors = array_values(array_filter(
            array_map(fn (mixed $error): string => trim((string) $error), $draft->parse_errors ?? []),
            fn (string $error): bool => $error !== '',
        ));

        if ($parseErrors !== []) {
            $score -= self::weight('parse_error');

            foreach (array_slice($parseErrors, 0, self::MAX_PARSE_ERROR_LINES) as $error) {
                $reasons[] = "Couldn't read: {$error}";
            }

            $more = count($parseErrors) - self::MAX_PARSE_ERROR_LINES;

            if ($more > 0) {
                $reasons[] = "and {$more} more";
            }
        }

        // A missing venue is already counted above; only a typed one can be unrecognised.
        if (! $venueMissing && ! $venueRecognised) {
            $score -= self::weight('venue_unrecognised');
            $reasons[] = $draft->venue_id === null
                ? "Venue not recognised: '".trim((string) $draft->venue_name)."'"
                : 'Venue not recognised';
        }

        if ($categoryFlagged) {
            $score -= self::weight('category_other');
            $reasons[] = 'No category matched, set to Other';
        }

        if (! $draft->all_day && self::blank($draft->end_time)) {
            $score -= self::weight('no_end_time');
            $reasons[] = 'No end time';
        }

        if (! self::blank($draft->repeats_text) && self::blank($draft->recurrence_rule) && ! $draft->repeats_acknowledged) {
            $score -= self::weight('repeats_unset');
            $reasons[] = "Organiser says it repeats ('".trim((string) $draft->repeats_text)."') but no repeat rule is set";
        }

        $score = max(0, min(100, $score));

        return new QualityResult($score, QualityBand::fromScore($score), $reasons);
    }

    private static function weight(string $factor): int
    {
        return (int) config("review.quality.weights.{$factor}");
    }

    private static function blank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }
}
