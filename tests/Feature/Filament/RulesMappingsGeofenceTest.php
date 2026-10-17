<?php

use App\Enums\EventOrigin;
use App\Filament\Pages\Rules;
use App\Models\Category;
use App\Models\CategoryKeywordRule;
use App\Models\CategoryMapping;
use App\Models\GeofenceTerm;
use App\Models\User;
use App\Review\CategoryAssigner;
use App\Review\Exceptions\RulesNotSaved;
use App\Review\SaveRules;
use Database\Seeders\CategorySeeder;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

/*
 * ADM-04 Category mappings and Geofence tabs, and PIPE-03 link 2: a source's
 * own label maps to a category before keyword rules are tried. Refusals are
 * judged against the submitted state and save nothing in any rules table.
 */
beforeEach(function () {
    freezeLondon('2026-10-17 12:00');
    $this->seed(CategorySeeder::class);
    $this->admin = User::factory()->create(['email' => config('app.admin_email')]);
    $this->category = fn (string $slug): Category => Category::where('slug', $slug)->firstOrFail();
    $this->community = ($this->category)('community');

    // The Rules screen's state as stored now, in SaveRules' shape.
    $this->state = fn (): array => [
        'categories' => Category::orderBy('sort')->orderBy('id')->get()
            ->map(fn (Category $c): array => ['id' => $c->id, 'name' => $c->name, 'icon' => $c->icon, 'sort' => $c->sort])
            ->all(),
        'keyword_rules' => CategoryKeywordRule::orderBy('priority')->orderBy('id')->get()
            ->map(fn (CategoryKeywordRule $r): array => ['id' => $r->id, 'keyword' => $r->keyword, 'category_id' => $r->category_id, 'priority' => $r->priority])
            ->all(),
        'category_mappings' => CategoryMapping::orderBy('id')->get()
            ->map(fn (CategoryMapping $m): array => ['id' => $m->id, 'source_value' => $m->source_value, 'category_id' => $m->category_id])
            ->all(),
        'geofence' => [
            'postcode_districts' => GeofenceTerm::where('type', GeofenceTerm::POSTCODE_DISTRICT)->orderBy('id')->pluck('value')->all(),
            'place_names' => GeofenceTerm::where('type', GeofenceTerm::PLACE_NAME)->orderBy('id')->pluck('value')->all(),
        ],
    ];
    $this->without = fn (array $state, int $categoryId): array => [
        ...$state,
        'categories' => array_values(array_filter($state['categories'], fn (array $row): bool => $row['id'] !== $categoryId)),
    ];
    $this->save = fn (array $state) => app(SaveRules::class)($state, $this->admin);
    $this->counts = fn (): array => [Category::count(), CategoryKeywordRule::count(), CategoryMapping::count(), GeofenceTerm::count()];
    $this->map = fn (string $value, string $slug): CategoryMapping => CategoryMapping::create(['source_value' => $value, 'category_id' => ($this->category)($slug)->id]);
    $this->assign = fn (array $sourceValues, string $title, ?int $preferred = null) => app(CategoryAssigner::class)->assign($preferred, EventOrigin::Discovered, $sourceValues, $title);
});

it('saves a category mapping and geofence terms from the Rules screen in one go', function () {
    actingAs($this->admin);

    livewire(Rules::class)
        ->set('data.category_mappings', ['new-1' => ['id' => null, 'source_value' => 'Quiz Night', 'category_id' => (string) $this->community->id]])
        ->set('data.geofence.place_names', ['Old Buckenham'])
        ->set('data.geofence.postcode_districts', [' nr17 '])
        ->callAction('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Rules saved');

    expect(CategoryMapping::sole())->source_value->toBe('Quiz Night')->category_id->toBe($this->community->id)
        ->and(GeofenceTerm::where('type', GeofenceTerm::PLACE_NAME)->pluck('value')->all())->toBe(['Old Buckenham'])
        ->and(GeofenceTerm::where('type', GeofenceTerm::POSTCODE_DISTRICT)->pluck('value')->all())->toBe(['NR17']);
});

it('maps a source value to its category, keeping the value as given in the reason', function () {
    ($this->map)('Quiz Night', 'community');

    $decision = ($this->assign)(['quiz  night'], 'Anything');

    expect($decision->categoryId)->toBe($this->community->id)
        ->and($decision->reason)->toBe("Mapped from 'quiz  night'")
        ->and($decision->flagged)->toBeFalse();
});

it('lets the preferred category beat a mapping', function () {
    ($this->map)('Quiz Night', 'community');
    $music = ($this->category)('music');

    $decision = ($this->assign)(['Quiz Night'], 'Anything', $music->id);

    expect($decision->categoryId)->toBe($music->id)->and($decision->reason)->toBe('Source default');
});

it('lets a mapping beat a matching keyword rule', function () {
    ($this->map)('Quiz Night', 'community');
    CategoryKeywordRule::create(['keyword' => 'gig', 'category_id' => ($this->category)('music')->id, 'priority' => 1]);

    $decision = ($this->assign)(['Live music', 'Quiz Night'], 'Pub gig');

    expect($decision->categoryId)->toBe($this->community->id)->and($decision->reason)->toBe("Mapped from 'Quiz Night'");
});

it('uses the first mapping by id when several source values match', function () {
    ($this->map)('Bingo', 'charity');
    ($this->map)('Quiz Night', 'community');

    $decision = ($this->assign)(['Quiz Night', 'BINGO'], 'Anything');

    expect($decision->categoryId)->toBe(($this->category)('charity')->id)->and($decision->reason)->toBe("Mapped from 'BINGO'");
});

it('falls through to keyword rules when no mapping matches', function () {
    ($this->map)('Quiz Night', 'community');
    CategoryKeywordRule::create(['keyword' => 'gig', 'category_id' => ($this->category)('music')->id, 'priority' => 1]);

    expect(($this->assign)(['Concerts'], 'Pub gig')->reason)->toBe("Keyword rule: 'gig' → Music");
});

it('refuses mappings that differ only in case or spacing, saving nothing anywhere', function () {
    $state = ($this->state)();
    $state['categories'][0]['name'] = 'Renamed';
    $state['category_mappings'] = [
        ['id' => null, 'source_value' => 'Quiz Night', 'category_id' => $this->community->id],
        ['id' => null, 'source_value' => 'quiz  night', 'category_id' => $this->community->id],
    ];
    $state['geofence']['place_names'] = ['Old Buckenham'];
    $before = ($this->counts)();

    expect(fn () => ($this->save)($state))->toThrow(RulesNotSaved::class, "Rules not saved: 'quiz night' is mapped twice.");

    expect(($this->counts)())->toBe($before)
        ->and(Category::where('name', 'Renamed')->exists())->toBeFalse();
});

it('refuses a mapping with a blank source value or no category', function () {
    $state = ($this->state)();
    $state['category_mappings'] = [
        ['id' => null, 'source_value' => '   ', 'category_id' => $this->community->id],
        ['id' => null, 'source_value' => 'Quiz Night', 'category_id' => null],
    ];

    expect(fn () => ($this->save)($state))
        ->toThrow(RulesNotSaved::class, "Rules not saved: Every category mapping needs a source value. 'Quiz Night' needs a category.");

    expect(CategoryMapping::count())->toBe(0);
});

it('refuses an invalid postcode district', function () {
    $state = ($this->state)();
    $state['geofence']['postcode_districts'] = ['NR17', 'N1717X'];

    expect(fn () => ($this->save)($state))
        ->toThrow(RulesNotSaved::class, "Rules not saved: 'N1717X' isn't a postcode district like NR17.");

    expect(GeofenceTerm::count())->toBe(0);
});

it('refuses geofence terms that collide once cleaned', function () {
    $state = ($this->state)();
    $state['geofence'] = ['postcode_districts' => ['NR17', 'nr 17'], 'place_names' => ['Old Buckenham', 'old  buckenham']];

    expect(fn () => ($this->save)($state))
        ->toThrow(RulesNotSaved::class, "Rules not saved: Postcode district 'NR17' is listed twice. Place name 'old buckenham' is listed twice.");
});

it('stores postcode districts upper-case without spaces and place names trimmed', function () {
    $state = ($this->state)();
    $state['geofence'] = ['postcode_districts' => [' nr17 ', 'ip 24'], 'place_names' => ['  Old   Buckenham ', '']];

    ($this->save)($state);

    expect(GeofenceTerm::orderBy('id')->get(['type', 'value'])->map(fn (GeofenceTerm $t): array => [$t->type, $t->value])->all())->toBe([
        [GeofenceTerm::POSTCODE_DISTRICT, 'NR17'],
        [GeofenceTerm::POSTCODE_DISTRICT, 'IP24'],
        [GeofenceTerm::PLACE_NAME, 'Old Buckenham'],
    ]);
});

it('keeps a geofence term row when it is resubmitted, and updates a mapping in place', function () {
    GeofenceTerm::create(['type' => GeofenceTerm::PLACE_NAME, 'value' => 'old buckenham']);
    $mapping = ($this->map)('Quiz Night', 'community');
    $term = GeofenceTerm::sole();
    $state = ($this->state)();
    $state['geofence']['place_names'] = ['Old Buckenham'];
    $state['category_mappings'][0]['category_id'] = ($this->category)('music')->id;

    ($this->save)($state);

    expect(GeofenceTerm::sole())->id->toBe($term->id)->value->toBe('Old Buckenham')
        ->and($mapping->refresh()->category_id)->toBe(($this->category)('music')->id);
});

it('saves empty mapping and geofence lists, removing what was there', function () {
    ($this->map)('Quiz Night', 'community');
    GeofenceTerm::create(['type' => GeofenceTerm::POSTCODE_DISTRICT, 'value' => 'NR17']);
    $state = ($this->state)();
    $state['category_mappings'] = [];
    $state['geofence'] = ['postcode_districts' => [], 'place_names' => null];

    ($this->save)($state);

    expect(CategoryMapping::count())->toBe(0)->and(GeofenceTerm::count())->toBe(0);
});

it('refuses to remove a category still mapped in the submitted state, saving nothing', function () {
    ($this->map)('Quiz Night', 'community');
    $before = ($this->counts)();

    expect(fn () => ($this->save)(($this->without)(($this->state)(), $this->community->id)))
        ->toThrow(RulesNotSaved::class, "Rules not saved: 'Community' still has 1 mapping. Move or remove it first.");

    expect(($this->counts)())->toBe($before);
});

it('uses the plural form for more than one mapping', function () {
    ($this->map)('Quiz Night', 'community');
    ($this->map)('Coffee Morning', 'community');

    expect(fn () => ($this->save)(($this->without)(($this->state)(), $this->community->id)))
        ->toThrow(RulesNotSaved::class, "Rules not saved: 'Community' still has 2 mappings. Move or remove them first.");
});

it('counts stored mappings when the mappings section is not submitted', function () {
    ($this->map)('Quiz Night', 'community');
    $state = ($this->without)(($this->state)(), $this->community->id);
    unset($state['category_mappings']);

    expect(fn () => ($this->save)($state))
        ->toThrow(RulesNotSaved::class, "Rules not saved: 'Community' still has 1 mapping. Move or remove it first.");
});

it('removes a category together with its mapping in the same save', function () {
    ($this->map)('Quiz Night', 'community');
    $state = ($this->without)(($this->state)(), $this->community->id);
    $state['category_mappings'] = [];

    ($this->save)($state);

    expect(Category::find($this->community->id))->toBeNull()->and(CategoryMapping::count())->toBe(0);
});

it('shows the four tabs in order', function () {
    actingAs($this->admin);

    get('/admin/rules')->assertOk()->assertSeeInOrder(['Categories', 'Keyword rules', 'Category mappings', 'Geofence']);
});

it('shows stored mappings and geofence terms on the Rules screen', function () {
    actingAs($this->admin);
    ($this->map)('Quiz Night', 'community');
    GeofenceTerm::create(['type' => GeofenceTerm::POSTCODE_DISTRICT, 'value' => 'NR17']);

    $page = livewire(Rules::class);

    expect(array_values($page->get('data.category_mappings')))->toBe([
        ['id' => CategoryMapping::sole()->id, 'source_value' => 'Quiz Night', 'category_id' => $this->community->id],
    ])->and($page->get('data.geofence.postcode_districts'))->toBe(['NR17']);
});
