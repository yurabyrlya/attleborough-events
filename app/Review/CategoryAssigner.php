<?php

namespace App\Review;

use App\Enums\EventOrigin;
use App\Models\Category;
use App\Models\CategoryKeywordRule;
use App\Models\CategoryMapping;
use App\Review\Data\CategoryDecision;
use Normalizer;

/**
 * Picks a draft's category (PIPE-03 chain), run by DraftWriter on every
 * write.
 *
 * The chain, first match wins:
 * 1. the preferred category (the admin's, the organiser's or the source's
 *    default), when it exists;
 * 2. source-value mappings: the first mapping whose normalised source value
 *    equals a normalised source value the caller passed. Mappings scoped to
 *    the draft's source come first, then global ones, each by id; another
 *    source's mappings never apply (EXT-01). Phase 2 callers pass no values;
 *    Phase 3 ingestion passes the item's ICS CATEGORIES and its source id;
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
     * @param  list<string>  $sourceValues  The source's own category labels
     * @param  int|null  $sourceId  The discovered draft's source; null uses global mappings only
     */
    public function assign(?int $preferredCategoryId, EventOrigin $origin, array $sourceValues, string $title, ?int $sourceId = null): CategoryDecision
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

        $mappingDecision = $this->matchMapping($sourceValues, $sourceId);

        if ($mappingDecision !== null) {
            return $mappingDecision;
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

    /**
     * The first mapping whose normalised source value equals any given
     * source value's normalised form: the source's own mappings first, then
     * global ones, each by id. The reason quotes the value as the source
     * gave it, and names the source when its own mapping won.
     *
     * @param  list<string>  $sourceValues
     */
    private function matchMapping(array $sourceValues, ?int $sourceId): ?CategoryDecision
    {
        $given = [];

        foreach ($sourceValues as $value) {
            $normalised = self::normalise($value);

            if ($normalised !== '' && ! isset($given[$normalised])) {
                $given[$normalised] = $value;
            }
        }

        if ($given === []) {
            return null;
        }

        $mappings = CategoryMapping::query()
            ->with(['category', 'source'])
            ->where(fn ($query) => $sourceId === null
                ? $query->whereNull('source_id')
                : $query->whereNull('source_id')->orWhere('source_id', $sourceId))
            ->orderByRaw('source_id is null')
            ->orderBy('id')
            ->get();

        foreach ($mappings as $mapping) {
            $value = $given[self::normalise($mapping->source_value)] ?? null;

            if ($value !== null && $mapping->category !== null) {
                return new CategoryDecision(
                    categoryId: $mapping->category_id,
                    reason: $mapping->source === null
                        ? "Mapped from '{$value}'"
                        : "Mapped from '{$value}' ({$mapping->source->name})",
                    flagged: false,
                );
            }
        }

        return null;
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
