<?php

use App\Enums\EventOrigin;
use App\Models\Category;
use App\Models\CategoryKeywordRule;
use App\Models\GeofenceTerm;
use App\Review\CategoryAssigner;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RulesSeeder;

/*
 * RulesSeeder: starter keyword rules and geofence defaults, created only
 * when missing (firstOrCreate), so re-seeding never overrides the admin.
 */
beforeEach(function () {
    $this->seed(CategorySeeder::class);
    $this->category = fn (string $slug): Category => Category::where('slug', $slug)->firstOrFail();
    $this->counts = fn (): array => [CategoryKeywordRule::count(), GeofenceTerm::count()];
});

it('seeds the starter keyword rules and geofence defaults', function () {
    $this->seed(RulesSeeder::class);

    expect(CategoryKeywordRule::count())->toBe(29)
        ->and(CategoryKeywordRule::where('keyword', 'beer festival')->sole()->category_id)->toBe(($this->category)('food-drink')->id)
        ->and(CategoryKeywordRule::where('keyword', 'exhibition')->sole()->category_id)->toBe(($this->category)('arts-culture')->id)
        ->and(GeofenceTerm::where('type', GeofenceTerm::POSTCODE_DISTRICT)->orderBy('id')->pluck('value')->all())->toBe(['NR17', 'NR16', 'NR18', 'IP24'])
        ->and(GeofenceTerm::where('type', GeofenceTerm::PLACE_NAME)->pluck('value')->all())->toBe(['Attleborough']);
});

it('gives the same row counts when run twice', function () {
    $this->seed(RulesSeeder::class);
    $once = ($this->counts)();

    $this->seed(RulesSeeder::class);

    expect(($this->counts)())->toBe($once);
});

it('keeps the admin\'s category and priority for a seeded keyword after re-seeding', function () {
    $this->seed(RulesSeeder::class);
    $music = ($this->category)('music');
    CategoryKeywordRule::where('keyword', 'quiz')->sole()->update(['category_id' => $music->id, 'priority' => 5]);

    $this->seed(RulesSeeder::class);

    expect(CategoryKeywordRule::where('keyword', 'quiz')->sole())->category_id->toBe($music->id)->priority->toBe(5);
});

it('treats a keyword or place name the admin re-cased as already there', function () {
    $this->seed(RulesSeeder::class);
    CategoryKeywordRule::where('keyword', 'quiz')->sole()->update(['keyword' => 'Quiz']);
    GeofenceTerm::where('value', 'Attleborough')->sole()->update(['value' => 'ATTLEBOROUGH']);
    $before = ($this->counts)();

    $this->seed(RulesSeeder::class);

    expect(($this->counts)())->toBe($before)
        ->and(CategoryKeywordRule::whereRaw('lower(keyword) = ?', ['quiz'])->sole()->keyword)->toBe('Quiz')
        ->and(GeofenceTerm::where('type', GeofenceTerm::PLACE_NAME)->sole()->value)->toBe('ATTLEBOROUGH');
});

it('re-creates a deleted default only when missing and leaves admin additions alone', function () {
    $this->seed(RulesSeeder::class);
    GeofenceTerm::where('value', 'IP24')->delete();
    GeofenceTerm::create(['type' => GeofenceTerm::PLACE_NAME, 'value' => 'Old Buckenham']);

    $this->seed(RulesSeeder::class);

    expect(GeofenceTerm::where('value', 'IP24')->count())->toBe(1)
        ->and(GeofenceTerm::where('value', 'Old Buckenham')->count())->toBe(1)
        ->and(GeofenceTerm::count())->toBe(6);
});

it('skips the rules of a category the admin removed', function () {
    ($this->category)('school')->delete();

    $this->seed(RulesSeeder::class);

    expect(CategoryKeywordRule::count())->toBe(27)
        ->and(CategoryKeywordRule::where('keyword', 'pta')->exists())->toBeFalse();
});

it('is called by the DatabaseSeeder after the categories', function () {
    $this->seed(DatabaseSeeder::class);

    expect(CategoryKeywordRule::count())->toBe(29)->and(GeofenceTerm::count())->toBe(5);
});

it('lets the seeded rules pick a category for a typical title', function () {
    $this->seed(RulesSeeder::class);

    $decision = app(CategoryAssigner::class)->assign(null, EventOrigin::Submission, [], 'Village Hall Coffee Morning');

    expect($decision->categoryId)->toBe(($this->category)('community')->id)
        ->and($decision->reason)->toBe("Keyword rule: 'coffee morning' → Community");
});
