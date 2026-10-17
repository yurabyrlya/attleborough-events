<?php

namespace App\Review;

use App\Models\Category;
use App\Models\CategoryKeywordRule;
use App\Models\CategoryMapping;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\GeofenceTerm;
use App\Models\User;
use App\Publishing\AdminGate;
use App\Review\Exceptions\RulesNotSaved;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Normalizer;

/**
 * The one writer behind the Rules screen's "Save rules" (ADM-04).
 *
 * Admin-only. Takes the whole submitted state and judges it against itself:
 * a category is "removed" when it is missing from the submitted list, and
 * its keyword rules and mappings are counted in the submitted lists, so
 * removing a category together with its rules and mappings in one save
 * works. Every problem is collected and the save refused before anything is
 * written; otherwise every section is synced in one transaction, in this
 * order: upsert categories → sync keyword rules → sync mappings → sync
 * geofence terms → delete removed categories. The FK restrictOnDelete on
 * events/drafts/rules/mappings stays the backstop. A section missing from
 * the state is left untouched.
 *
 * Sections:
 * - 'categories' (rows: id?, name, icon?, sort). Slugs are made from the
 *   name on create (unique, -2/-3 on collision) and never change, because
 *   public filter URLs use them. 'other' can't be removed.
 * - 'keyword_rules' (rows: id?, keyword, category_id, priority).
 * - 'category_mappings' (rows: id?, source_value, category_id). Source
 *   values are unique by CategoryAssigner::normalise().
 * - 'geofence' (postcode_districts: list<string>, place_names:
 *   list<string>). Districts are stored upper-case without spaces and must
 *   look like NR17; place names are trimmed and unique by
 *   CategoryAssigner::normalise(). Blank entries are dropped. A list
 *   missing from the section is left untouched.
 */
final class SaveRules
{
    public const KEYWORD_MAX_LENGTH = 80;

    public const PRIORITY_MIN = 0;

    public const PRIORITY_MAX = 1000;

    public const DEFAULT_PRIORITY = 100;

    public const CATEGORY_NAME_MAX_LENGTH = 60;

    public const SORT_MAX = 9999;

    public const SOURCE_VALUE_MAX_LENGTH = 120;

    public const PLACE_NAME_MAX_LENGTH = 120;

    /** An outward postcode district, stored upper-case without spaces. */
    public const POSTCODE_DISTRICT_PATTERN = '/^[A-Z]{1,2}[0-9][0-9A-Z]?$/';

    /** The generic icon new categories get when none is given. */
    public const DEFAULT_ICON = 'heroicon-o-tag';

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

        $categories = array_key_exists('categories', $state)
            ? $this->categoryRows($state['categories'])
            : null;

        $keywordRules = array_key_exists('keyword_rules', $state)
            ? $this->keywordRows($state['keyword_rules'])
            : null;

        $mappings = array_key_exists('category_mappings', $state)
            ? $this->mappingRows($state['category_mappings'])
            : null;

        $geofence = array_key_exists('geofence', $state)
            ? $this->geofenceLists($state['geofence'])
            : [];

        DB::transaction(function () use ($categories, $keywordRules, $mappings, $geofence): void {
            // Lock first, so the checks and the writes see the same rows.
            $stored = Category::query()->lockForUpdate()->orderBy('id')->get()->keyBy('id');

            $removed = $categories === null
                ? collect()
                : $stored->reject(fn (Category $category): bool => in_array($category->id, array_column($categories, 'id'), true));

            $problems = [
                ...($categories === null ? [] : $this->categoryProblems($categories, $removed, $keywordRules, $mappings)),
                ...($keywordRules === null ? [] : $this->keywordProblems($keywordRules, $stored->keys()->all())),
                ...($mappings === null ? [] : $this->mappingProblems($mappings, $stored->keys()->all())),
                ...$this->geofenceProblems($geofence),
            ];

            if ($problems !== []) {
                throw new RulesNotSaved(array_values(array_unique($problems)));
            }

            if ($categories !== null) {
                $this->upsertCategories($categories, $stored);
            }

            if ($keywordRules !== null) {
                $this->syncKeywordRules($keywordRules);
            }

            if ($mappings !== null) {
                $this->syncMappings($mappings);
            }

            foreach ($geofence as $type => $values) {
                $this->syncGeofenceTerms($type, $values);
            }

            if ($removed->isNotEmpty()) {
                Category::query()->whereKey($removed->keys()->all())->delete();
            }
        });

        // Counts only: never names or keywords.
        Log::info('rules saved', [
            'user_id' => $actor->id,
            'categories' => $categories === null ? null : count($categories),
            'keyword_rules' => $keywordRules === null ? null : count($keywordRules),
            'category_mappings' => $mappings === null ? null : count($mappings),
            'geofence_terms' => $geofence === [] ? null : array_sum(array_map('count', $geofence)),
        ]);
    }

    /**
     * The trimmed, NFC, single-spaced form keywords and category names are
     * stored in. Case is kept for display; keyword comparisons use
     * CategoryAssigner::normalise().
     */
    public static function cleanText(string $value): string
    {
        $normalised = Normalizer::normalize($value, Normalizer::FORM_C);

        return trim((string) preg_replace('/\s+/u', ' ', $normalised === false ? $value : $normalised));
    }

    /**
     * @return list<array{id: int|null, name: string, icon: string|null, sort: int|null}>
     */
    private function categoryRows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $clean = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $icon = is_string($row['icon'] ?? null) ? trim($row['icon']) : '';

            $clean[] = [
                'id' => self::positiveInt($row['id'] ?? null),
                'name' => self::cleanText(is_scalar($row['name'] ?? null) ? (string) $row['name'] : ''),
                'icon' => $icon === '' ? null : $icon,
                'sort' => self::boundedInt($row['sort'] ?? null, 0, self::SORT_MAX, default: 0),
            ];
        }

        return $clean;
    }

    /**
     * @param  list<array{id: int|null, name: string, icon: string|null, sort: int|null}>  $rows
     * @param  Collection<int, Category>  $removed  stored categories missing from the submitted list
     * @param  list<array{id: int|null, keyword: string, category_id: int|null, priority: int|null}>|null  $keywordRules
     * @param  list<array{id: int|null, source_value: string, category_id: int|null}>|null  $mappings
     * @return list<string>
     */
    private function categoryProblems(array $rows, Collection $removed, ?array $keywordRules, ?array $mappings): array
    {
        $problems = [];

        foreach ($rows as $row) {
            $name = $row['name'];

            if ($name === '') {
                $problems[] = 'Every category needs a name.';
            } elseif (mb_strlen($name) > self::CATEGORY_NAME_MAX_LENGTH) {
                $problems[] = "'{$name}' is longer than ".self::CATEGORY_NAME_MAX_LENGTH.' characters.';
            }

            $label = $name === '' ? 'A category' : "'{$name}'";

            if ($row['icon'] !== null && preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $row['icon']) !== 1) {
                $problems[] = "{$label} needs an icon name like ".self::DEFAULT_ICON.', or none.';
            }

            if ($row['sort'] === null) {
                $problems[] = "{$label} needs a sort number from 0 to ".self::SORT_MAX.'.';
            }
        }

        foreach ($removed as $category) {
            if ($category->slug === 'other') {
                $problems[] = "The Other category can't be removed.";

                continue;
            }

            $events = Event::query()->where('category_id', $category->id)->count();
            $drafts = EventDraft::query()
                ->where(fn ($query) => $query->where('category_id', $category->id)->orWhere('submitted_category_id', $category->id))
                ->count();

            $uses = array_filter([
                $events > 0 ? $events.' '.Str::plural('event', $events) : null,
                $drafts > 0 ? $drafts.' '.Str::plural('draft', $drafts) : null,
            ]);

            if ($uses !== []) {
                $problems[] = "'{$category->name}' is used by ".implode(' and ', $uses).'.';
            }

            $rules = $keywordRules === null
                ? CategoryKeywordRule::query()->where('category_id', $category->id)->count()
                : count(array_filter($keywordRules, fn (array $rule): bool => $rule['category_id'] === $category->id));

            if ($rules > 0) {
                $problems[] = "'{$category->name}' still has {$rules} ".Str::plural('keyword rule', $rules).'. Move or remove them first.';
            }

            $mapped = $mappings === null
                ? CategoryMapping::query()->where('category_id', $category->id)->count()
                : count(array_filter($mappings, fn (array $mapping): bool => $mapping['category_id'] === $category->id));

            if ($mapped > 0) {
                $problems[] = "'{$category->name}' still has {$mapped} ".Str::plural('mapping', $mapped).'. Move or remove '.($mapped === 1 ? 'it' : 'them').' first.';
            }
        }

        return $problems;
    }

    /**
     * Update kept categories (never their slug) and create new ones with a
     * slug made from the name, unique against every stored slug (including
     * ones about to be removed) and the ones made in this save.
     *
     * @param  list<array{id: int|null, name: string, icon: string|null, sort: int|null}>  $rows
     * @param  Collection<int, Category>  $stored
     */
    private function upsertCategories(array $rows, Collection $stored): void
    {
        $taken = $stored->pluck('slug')->all();

        foreach ($rows as $row) {
            $category = $row['id'] === null ? null : $stored->get($row['id']);

            if ($category !== null) {
                $category->forceFill([
                    'name' => $row['name'],
                    'icon' => $row['icon'] ?? $category->icon,
                    'sort' => $row['sort'],
                ])->save();

                continue;
            }

            $slug = self::uniqueSlug($row['name'], $taken);
            $taken[] = $slug;

            Category::query()->forceCreate([
                'name' => $row['name'],
                'slug' => $slug,
                'icon' => $row['icon'] ?? self::DEFAULT_ICON,
                'sort' => $row['sort'],
            ]);
        }
    }

    /**
     * @param  list<string>  $taken
     */
    private static function uniqueSlug(string $name, array $taken): string
    {
        $base = Str::slug($name);
        $base = $base === '' ? 'category' : $base;
        $slug = $base;

        for ($suffix = 2; in_array($slug, $taken, true); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
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
                'keyword' => self::cleanText(is_scalar($row['keyword'] ?? null) ? (string) $row['keyword'] : ''),
                'category_id' => self::positiveInt($row['category_id'] ?? null),
                'priority' => self::boundedInt($row['priority'] ?? null, self::PRIORITY_MIN, self::PRIORITY_MAX, default: self::DEFAULT_PRIORITY),
            ];
        }

        return $clean;
    }

    /**
     * @param  list<array{id: int|null, keyword: string, category_id: int|null, priority: int|null}>  $rows
     * @param  list<int>  $categoryIds  every stored category (a removed one is refused on the category itself)
     * @return list<string>
     */
    private function keywordProblems(array $rows, array $categoryIds): array
    {
        $problems = [];
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

    /**
     * @return list<array{id: int|null, source_value: string, category_id: int|null}>
     */
    private function mappingRows(mixed $rows): array
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
                'source_value' => self::cleanText(is_scalar($row['source_value'] ?? null) ? (string) $row['source_value'] : ''),
                'category_id' => self::positiveInt($row['category_id'] ?? null),
            ];
        }

        return $clean;
    }

    /**
     * @param  list<array{id: int|null, source_value: string, category_id: int|null}>  $rows
     * @param  list<int>  $categoryIds  every stored category (a removed one is refused on the category itself)
     * @return list<string>
     */
    private function mappingProblems(array $rows, array $categoryIds): array
    {
        $problems = [];
        $seen = [];

        foreach ($rows as $row) {
            $value = $row['source_value'];

            if ($value === '') {
                $problems[] = 'Every category mapping needs a source value.';
            } elseif (mb_strlen($value) > self::SOURCE_VALUE_MAX_LENGTH) {
                $problems[] = "'{$value}' is longer than ".self::SOURCE_VALUE_MAX_LENGTH.' characters.';
            }

            $label = $value === '' ? 'A category mapping' : "'{$value}'";

            if ($row['category_id'] === null) {
                $problems[] = "{$label} needs a category.";
            } elseif (! in_array($row['category_id'], $categoryIds, true)) {
                $problems[] = "{$label} points at a category that no longer exists.";
            }

            if ($value !== '') {
                $normalised = CategoryAssigner::normalise($value);

                if (isset($seen[$normalised])) {
                    $problems[] = "'{$normalised}' is mapped twice.";
                }

                $seen[$normalised] = true;
            }
        }

        return $problems;
    }

    /**
     * Same approach as syncKeywordRules(): delete, park changed values,
     * update, then insert in the order submitted.
     *
     * @param  list<array{id: int|null, source_value: string, category_id: int|null}>  $rows
     */
    private function syncMappings(array $rows): void
    {
        $existing = CategoryMapping::query()->lockForUpdate()->get()->keyBy('id');

        $kept = array_filter($rows, fn (array $row): bool => $row['id'] !== null && $existing->has($row['id']));
        $keptIds = array_map(fn (array $row): int => (int) $row['id'], $kept);

        CategoryMapping::query()->whereKeyNot($keptIds)->delete();

        foreach ($kept as $row) {
            $mapping = $existing->get($row['id']);

            if ($mapping !== null && mb_strtolower($mapping->source_value) !== mb_strtolower($row['source_value'])) {
                $mapping->forceFill(['source_value' => "\u{2060}parked-{$mapping->id}"])->save();
            }
        }

        foreach ($kept as $row) {
            $existing->get($row['id'])?->forceFill([
                'source_value' => $row['source_value'],
                'category_id' => $row['category_id'],
            ])->save();
        }

        foreach ($rows as $row) {
            if ($row['id'] !== null && $existing->has($row['id'])) {
                continue;
            }

            CategoryMapping::query()->create([
                'source_value' => $row['source_value'],
                'category_id' => $row['category_id'],
            ]);
        }
    }

    /**
     * The cleaned geofence lists, keyed by GeofenceTerm type. A list missing
     * from the section is left out (untouched); a null list is empty.
     * Districts lose every space and are upper-cased; place names are
     * cleaned like category names. Blank entries are dropped.
     *
     * @return array<string, list<string>>
     */
    private function geofenceLists(mixed $section): array
    {
        if (! is_array($section)) {
            return [];
        }

        $lists = [];

        foreach (['postcode_districts' => GeofenceTerm::POSTCODE_DISTRICT, 'place_names' => GeofenceTerm::PLACE_NAME] as $key => $type) {
            if (! array_key_exists($key, $section)) {
                continue;
            }

            $values = [];

            foreach (is_array($section[$key]) ? $section[$key] : [] as $value) {
                $value = is_scalar($value) ? (string) $value : '';
                $value = $type === GeofenceTerm::POSTCODE_DISTRICT
                    ? mb_strtoupper((string) preg_replace('/\s+/u', '', $value))
                    : self::cleanText($value);

                if ($value !== '') {
                    $values[] = $value;
                }
            }

            $lists[$type] = $values;
        }

        return $lists;
    }

    /**
     * @param  array<string, list<string>>  $lists
     * @return list<string>
     */
    private function geofenceProblems(array $lists): array
    {
        $problems = [];

        foreach ($lists as $type => $values) {
            $seen = [];

            foreach ($values as $value) {
                if ($type === GeofenceTerm::POSTCODE_DISTRICT && preg_match(self::POSTCODE_DISTRICT_PATTERN, $value) !== 1) {
                    $problems[] = "'{$value}' isn't a postcode district like NR17.";
                }

                if ($type === GeofenceTerm::PLACE_NAME && mb_strlen($value) > self::PLACE_NAME_MAX_LENGTH) {
                    $problems[] = "'{$value}' is longer than ".self::PLACE_NAME_MAX_LENGTH.' characters.';
                }

                $key = CategoryAssigner::normalise($value);

                if (isset($seen[$key])) {
                    $problems[] = $type === GeofenceTerm::POSTCODE_DISTRICT
                        ? "Postcode district '{$value}' is listed twice."
                        : "Place name '{$key}' is listed twice.";
                }

                $seen[$key] = true;
            }
        }

        return $problems;
    }

    /**
     * Make the stored terms of one type match the submitted list: keep rows
     * whose normalised value is still listed (updating their spelling),
     * delete the rest, insert the new ones in the order given.
     *
     * @param  list<string>  $values
     */
    private function syncGeofenceTerms(string $type, array $values): void
    {
        $wanted = [];

        foreach ($values as $value) {
            $wanted[CategoryAssigner::normalise($value)] = $value;
        }

        /** @var array<string, GeofenceTerm> $existing */
        $existing = [];
        $stale = [];

        foreach (GeofenceTerm::query()->where('type', $type)->lockForUpdate()->orderBy('id')->get() as $term) {
            $key = CategoryAssigner::normalise($term->value);

            if (! array_key_exists($key, $wanted) || isset($existing[$key])) {
                $stale[] = $term->id;
            } else {
                $existing[$key] = $term;
            }
        }

        if ($stale !== []) {
            GeofenceTerm::query()->whereKey($stale)->delete();
        }

        foreach ($wanted as $key => $value) {
            $term = $existing[$key] ?? null;

            if ($term === null) {
                GeofenceTerm::query()->create(['type' => $type, 'value' => $value]);
            } elseif ($term->value !== $value) {
                $term->forceFill(['value' => $value])->save();
            }
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
     * A blank value takes the default; anything else must be a whole number
     * in range, or null (a problem).
     */
    private static function boundedInt(mixed $value, int $min, int $max, int $default): ?int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_string($value) && preg_match('/^\s*\d+\s*$/', $value) === 1) {
            $value = (int) trim($value);
        }

        if (is_int($value) && $value >= $min && $value <= $max) {
            return $value;
        }

        return null;
    }
}
