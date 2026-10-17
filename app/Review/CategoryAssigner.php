<?php

namespace App\Review;

use App\Enums\EventOrigin;
use App\Models\Category;
use App\Review\Data\CategoryDecision;
use Normalizer;

/**
 * Picks a draft's category (PIPE-03 chain), run by DraftWriter on every
 * write.
 *
 * The chain, first match wins:
 * 1. the preferred category (the admin's, the organiser's or the source's
 *    default), when it exists;
 * 2. Other, flagged for the admin to check.
 *
 * Plan 02-11 inserts source-value mappings and plan 02-07 inserts title
 * keyword rules between these two links.
 */
final class CategoryAssigner
{
    /**
     * @param  list<string>  $sourceValues  The source's own category labels (used from 02-11)
     */
    public function assign(?int $preferredCategoryId, EventOrigin $origin, array $sourceValues, string $title): CategoryDecision
    {
        if ($preferredCategoryId !== null && Category::query()->whereKey($preferredCategoryId)->exists()) {
            return new CategoryDecision(
                categoryId: $preferredCategoryId,
                reason: match ($origin) {
                    EventOrigin::Manual => 'Chosen by admin',
                    EventOrigin::Submission => 'Chosen by organiser',
                    EventOrigin::Discovered => 'Source default',
                },
                flagged: false,
            );
        }

        $other = Category::query()->where('slug', 'other')->firstOrFail();

        return new CategoryDecision(categoryId: $other->id, reason: 'No match → Other', flagged: true);
    }

    /**
     * The form two labels or keywords are compared in: Unicode NFC,
     * lower case, runs of whitespace collapsed to one space, trimmed. Used by
     * the keyword rules (02-07) and source mappings (02-11).
     */
    public static function normalise(string $value): string
    {
        $normalised = Normalizer::normalize($value, Normalizer::FORM_C);

        if ($normalised === false) {
            $normalised = $value;
        }

        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($normalised)));
    }
}
