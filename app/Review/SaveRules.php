<?php

namespace App\Review;

use App\Models\Category;
use App\Models\CategoryKeywordRule;
use App\Models\User;
use App\Publishing\AdminGate;
use App\Review\Exceptions\RulesNotSaved;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Normalizer;

/**
 * The one writer behind the Rules screen's "Save rules" (ADM-04).
 *
 * Admin-only. Takes the whole submitted state, checks it against itself
 * (never against the rows already stored), collects every problem and
 * refuses before writing anything; otherwise syncs every section in one
 * transaction. A section missing from the state is left untouched.
 *
 * Sections: 'keyword_rules' (rows: id?, keyword, category_id, priority).
 */
final class SaveRules
{
    public const KEYWORD_MAX_LENGTH = 80;

    public const PRIORITY_MIN = 0;

    public const PRIORITY_MAX = 1000;

    public const DEFAULT_PRIORITY = 100;

    /**
     * @param  array<string, mixed>  $state
     *
     * @throws AuthorizationException when the actor is not the configured admin
     * @throws RulesNotSaved when the submitted state has problems; nothing is written
     */
    public function __invoke(array $state, User $actor): void
    {
        // Defence in depth beside the panel gate (T-02-16).
        AdminGate::authorize($actor, 'Only the admin can change rules.');

        $keywordRules = array_key_exists('keyword_rules', $state)
            ? $this->keywordRows($state['keyword_rules'])
            : null;

        $problems = $keywordRules === null ? [] : $this->keywordProblems($keywordRules);

        if ($problems !== []) {
            throw new RulesNotSaved(array_values(array_unique($problems)));
        }

        DB::transaction(function () use ($keywordRules): void {
            if ($keywordRules !== null) {
                $this->syncKeywordRules($keywordRules);
            }
        });

        // Counts only: never the keywords themselves.
        Log::info('rules saved', [
            'user_id' => $actor->id,
            'keyword_rules' => $keywordRules === null ? null : count($keywordRules),
        ]);
    }

    /**
     * The trimmed, NFC, single-spaced form a keyword is stored in. Case is
     * kept for display; comparisons use CategoryAssigner::normalise().
     */
    public static function cleanKeyword(string $keyword): string
    {
        $normalised = Normalizer::normalize($keyword, Normalizer::FORM_C);

        return trim((string) preg_replace('/\s+/u', ' ', $normalised === false ? $keyword : $normalised));
    }

    /**
     * @return list<array{id: int|null, keyword: string, category_id: int|null, priority: int|null}>
     */
    private function keywordRows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $clean = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $clean[] = [
                'id' => self::positiveInt($row['id'] ?? null),
                'keyword' => self::cleanKeyword(is_scalar($row['keyword'] ?? null) ? (string) $row['keyword'] : ''),
                'category_id' => self::positiveInt($row['category_id'] ?? null),
                'priority' => self::priority($row['priority'] ?? null),
            ];
        }

        return $clean;
    }

    /**
     * @param  list<array{id: int|null, keyword: string, category_id: int|null, priority: int|null}>  $rows
     * @return list<string>
     */
    private function keywordProblems(array $rows): array
    {
        $problems = [];
        $categoryIds = Category::query()->pluck('id')->all();
        $seen = [];

        foreach ($rows as $row) {
            $keyword = $row['keyword'];

            if ($keyword === '') {
                $problems[] = 'Every keyword rule needs a keyword.';
            } elseif (mb_strlen($keyword) > self::KEYWORD_MAX_LENGTH) {
                $problems[] = "'{$keyword}' is longer than ".self::KEYWORD_MAX_LENGTH.' characters.';
            }

            $label = $keyword === '' ? 'A keyword rule' : "'{$keyword}'";

            if ($row['category_id'] === null) {
                $problems[] = "{$label} needs a category.";
            } elseif (! in_array($row['category_id'], $categoryIds, true)) {
                $problems[] = "{$label} points at a category that no longer exists.";
            }

            if ($row['priority'] === null) {
                $problems[] = "{$label} needs a priority from ".self::PRIORITY_MIN.' to '.self::PRIORITY_MAX.'.';
            }

            if ($keyword !== '') {
                $normalised = CategoryAssigner::normalise($keyword);

                if (isset($seen[$normalised])) {
                    $problems[] = "'{$normalised}' is listed twice.";
                }

                $seen[$normalised] = true;
            }
        }

        return $problems;
    }

    /**
     * Delete rules missing from the state, update the ones kept, then insert
     * new ones in the order submitted (so, at equal priority, earlier rows
     * get the lower id and win). A changed keyword is parked on a unique
     * placeholder first, so swapping two keywords cannot trip the
     * lower(keyword) unique index halfway through.
     *
     * @param  list<array{id: int|null, keyword: string, category_id: int|null, priority: int|null}>  $rows
     */
    private function syncKeywordRules(array $rows): void
    {
        $existing = CategoryKeywordRule::query()->lockForUpdate()->get()->keyBy('id');

        $kept = array_filter($rows, fn (array $row): bool => $row['id'] !== null && $existing->has($row['id']));
        $keptIds = array_map(fn (array $row): int => (int) $row['id'], $kept);

        CategoryKeywordRule::query()->whereKeyNot($keptIds)->delete();

        foreach ($kept as $row) {
            $rule = $existing->get($row['id']);

            if ($rule !== null && mb_strtolower($rule->keyword) !== mb_strtolower($row['keyword'])) {
                $rule->forceFill(['keyword' => "\u{2060}parked-{$rule->id}"])->save();
            }
        }

        foreach ($kept as $row) {
            $existing->get($row['id'])?->forceFill([
                'keyword' => $row['keyword'],
                'category_id' => $row['category_id'],
                'priority' => $row['priority'],
            ])->save();
        }

        foreach ($rows as $row) {
            if ($row['id'] !== null && $existing->has($row['id'])) {
                continue;
            }

            CategoryKeywordRule::query()->create([
                'keyword' => $row['keyword'],
                'category_id' => $row['category_id'],
                'priority' => $row['priority'],
            ]);
        }
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value) && $value !== '0') {
            return (int) $value;
        }

        return null;
    }

    /**
     * A blank priority takes the default; anything else must be a whole
     * number in range, or null (a problem).
     */
    private static function priority(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_PRIORITY;
        }

        if (is_string($value) && preg_match('/^\s*\d+\s*$/', $value) === 1) {
            $value = (int) trim($value);
        }

        if (is_int($value) && $value >= self::PRIORITY_MIN && $value <= self::PRIORITY_MAX) {
            return $value;
        }

        return null;
    }
}
