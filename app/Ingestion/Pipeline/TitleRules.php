<?php

namespace App\Ingestion\Pipeline;

use App\Review\CategoryAssigner;

/**
 * Per-source title rules (D-04): skip rules drop items whose title contains
 * the keyword; keep rules, when a source has any, drop every item whose
 * title matches none of them. A skip match beats a keep match.
 *
 * Matching is CategoryAssigner::titleContainsKeyword, the same whole-word,
 * case-insensitive, NFC-normalised rule as the Phase 2 keyword rules.
 * Malformed entries (wrong shape, unknown mode, blank keyword) are ignored.
 */
final class TitleRules
{
    public const string MODE_SKIP = 'skip';

    public const string MODE_KEEP = 'keep';

    /**
     * @param  array<array-key, mixed>  $rules  sources.title_rules: list of {mode, keyword}
     */
    public static function allows(array $rules, string $title): bool
    {
        $hasKeepRule = false;
        $kept = false;

        foreach ($rules as $rule) {
            if (! is_array($rule) || ! is_string($rule['keyword'] ?? null) || trim($rule['keyword']) === '') {
                continue;
            }

            $mode = $rule['mode'] ?? null;
            $matches = CategoryAssigner::titleContainsKeyword($title, $rule['keyword']);

            if ($mode === self::MODE_SKIP && $matches) {
                return false;
            }

            if ($mode === self::MODE_KEEP) {
                $hasKeepRule = true;
                $kept = $kept || $matches;
            }
        }

        return ! $hasKeepRule || $kept;
    }
}
