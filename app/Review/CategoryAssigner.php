<?php

namespace App\Review;

use App\Enums\EventOrigin;
use App\Models\Category;
use App\Models\CategoryKeywordRule;
use App\Review\Data\CategoryDecision;
use Normalizer;

/**
 * Picks a draft's category (PIPE-03 chain), run by DraftWriter on every
 * write.
 *
 * The chain, first match wins:
 * 1. the preferred category (the admin's, the organiser's or the source's
 *    default), when it exists;
 * 2. (02-11) source-value mappings;
 * 3. title keyword rules, by (priority asc, id asc): the first keyword found
 *    as a whole word in the normalised title;
 * 4. Other, flagged for the admin to check.
 *
 * Deterministic: the same draft and rules always give the same category and
 * reason. No AI, probabilistic or network classification (PIPE-03).
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

        $keywordDecision = $this->matchKeywordRule($title);

        if ($keywordDecision !== null) {
            return $keywordDecision;
        }

        $other = Category::query()->where('slug', 'other')->firstOrFail();

        return new CategoryDecision(categoryId: $other->id, reason: 'No match → Other', flagged: true);
    }

    /**
     * Whether a keyword appears in a title as whole words: both normalised,
     * the keyword quoted (never an admin-written pattern) and bounded by
     * anything that is not a letter or digit, so 'quiz' does not match
     * 'quizzical' and 'café' matches 'CAFÉ morning'.
     */
    public static function titleContainsKeyword(string $title, string $keyword): bool
    {
        $needle = self::normalise($keyword);

        if ($needle === '') {
            return false;
        }

        $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u';

        return preg_match($pattern, self::normalise($title)) === 1;
    }

    private function matchKeywordRule(string $title): ?CategoryDecision
    {
        $rules = CategoryKeywordRule::query()
            ->with('category')
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            if ($rule->category !== null && self::titleContainsKeyword($title, $rule->keyword)) {
                return new CategoryDecision(
                    categoryId: $rule->category_id,
                    reason: "Keyword rule: '{$rule->keyword}' → {$rule->category->name}",
                    flagged: false,
                );
            }
        }

        return null;
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
