<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\CategoryKeywordRule;
use App\Models\GeofenceTerm;
use App\Review\SaveRules;
use Illuminate\Database\Seeder;

/**
 * Seeds starter title keyword rules (PIPE-03 link 3) and the geofence
 * defaults (ADM-04). Reference data only: no events, no users.
 *
 * Every row is created only when missing (firstOrCreate, matched
 * case-insensitively like the lower() unique indexes), never updated, so
 * re-seeding can't revert what the admin changed on the Rules screen. A
 * default the admin deleted comes back on the next seed; the admin can
 * move it to another category instead. Rules for a category the admin
 * removed are skipped. Categories are looked up by slug (CategorySeeder
 * runs first).
 */
class RulesSeeder extends Seeder
{
    /**
     * Category slug => title keywords (stored lower-case).
     *
     * @var array<string, list<string>>
     */
    public const KEYWORD_RULES = [
        'community' => ['quiz', 'bingo', 'coffee morning'],
        'market' => ['market', 'car boot', 'craft fair'],
        'charity' => ['fundraiser', 'charity'],
        'music' => ['concert', 'gig', 'choir', 'band', 'live music'],
        'sport' => ['football', 'cricket', 'parkrun', 'bowls'],
        'food-drink' => ['beer festival', 'food festival', 'afternoon tea'],
        'school' => ['pta', 'school fete'],
        'arts-culture' => ['theatre', 'exhibition', 'film'],
        'family' => ['family', 'kids', 'children', 'toddler'],
    ];

    /** @var list<string> */
    public const POSTCODE_DISTRICTS = ['NR17', 'NR16', 'NR18', 'IP24'];

    /** @var list<string> */
    public const PLACE_NAMES = ['Attleborough'];

    public function run(): void
    {
        $categories = Category::query()->pluck('id', 'slug');

        foreach (self::KEYWORD_RULES as $slug => $keywords) {
            $categoryId = $categories->get($slug);

            if ($categoryId === null) {
                continue;
            }

            foreach ($keywords as $keyword) {
                // No match attributes: the lower() where clause is the key,
                // so an admin's re-cased keyword still counts as present.
                CategoryKeywordRule::query()
                    ->whereRaw('lower(keyword) = ?', [mb_strtolower($keyword)])
                    ->firstOrCreate([], [
                        'keyword' => $keyword,
                        'category_id' => $categoryId,
                        'priority' => SaveRules::DEFAULT_PRIORITY,
                    ]);
            }
        }

        foreach ([GeofenceTerm::POSTCODE_DISTRICT => self::POSTCODE_DISTRICTS, GeofenceTerm::PLACE_NAME => self::PLACE_NAMES] as $type => $values) {
            foreach ($values as $value) {
                GeofenceTerm::query()
                    ->where('type', $type)
                    ->whereRaw('lower(value) = ?', [mb_strtolower($value)])
                    ->firstOrCreate([], ['type' => $type, 'value' => $value]);
            }
        }
    }
}
