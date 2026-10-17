<?php

use App\Filament\Pages\Rules;
use App\Models\Category;
use App\Models\CategoryKeywordRule;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\User;
use App\Review\Exceptions\RulesNotSaved;
use App\Review\SaveRules;
use Database\Seeders\CategorySeeder;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

/*
 * ADM-04 Categories tab: add, rename, reorder and remove categories. Slugs
 * are set once from the name and never change (public filter URLs use
 * them). Refusals are judged against the submitted state and save nothing.
 */
beforeEach(function () {
    freezeLondon('2026-10-17 12:00');
    $this->seed(CategorySeeder::class);
    $this->admin = User::factory()->create(['email' => config('app.admin_email')]);
    $this->category = fn (string $slug): Category => Category::where('slug', $slug)->firstOrFail();

    // The Rules screen's state as stored now, in SaveRules' shape.
    $this->state = fn (): array => [
        'categories' => Category::orderBy('sort')->orderBy('id')->get()
            ->map(fn (Category $c): array => ['id' => $c->id, 'name' => $c->name, 'icon' => $c->icon, 'sort' => $c->sort])
            ->all(),
        'keyword_rules' => CategoryKeywordRule::orderBy('priority')->orderBy('id')->get()
            ->map(fn (CategoryKeywordRule $r): array => ['id' => $r->id, 'keyword' => $r->keyword, 'category_id' => $r->category_id, 'priority' => $r->priority])
            ->all(),
    ];
    $this->without = fn (array $state, int $categoryId): array => [
        ...$state,
        'categories' => array_values(array_filter($state['categories'], fn (array $row): bool => $row['id'] !== $categoryId)),
    ];
    $this->save = fn (array $state) => app(SaveRules::class)($state, $this->admin);

    $this->counts = fn (): array => [Category::count(), CategoryKeywordRule::count()];
    $this->market = ($this->category)('market');
    $this->marketRules = fn () => collect(['market', 'car boot', 'craft fair'])
        ->each(fn (string $keyword) => CategoryKeywordRule::create(['keyword' => $keyword, 'category_id' => $this->market->id, 'priority' => 100]));
});

it('keeps the slug when a category is renamed', function () {
    $music = ($this->category)('music');
    $state = ($this->state)();
    $state['categories'] = array_map(
        fn (array $row): array => $row['id'] === $music->id ? [...$row, 'name' => 'Music & Gigs'] : $row,
        $state['categories'],
    );

    ($this->save)($state);

    expect($music->refresh())->name->toBe('Music & Gigs')->slug->toBe('music');
});

it('creates a slug from the name for a new category, adding -2 on a collision', function () {
    $state = ($this->state)();
    $state['categories'][] = ['id' => null, 'name' => 'Heritage', 'icon' => null, 'sort' => 200];
    ($this->save)($state);

    $state = ($this->state)();
    $state['categories'][] = ['id' => null, 'name' => 'Heritage', 'icon' => '', 'sort' => 210];
    ($this->save)($state);

    expect(Category::where('name', 'Heritage')->orderBy('id')->pluck('slug')->all())->toBe(['heritage', 'heritage-2'])
        ->and(Category::where('slug', 'heritage')->value('icon'))->toBe('heroicon-o-tag');
});

it('gives two new categories with the same name distinct slugs in one save', function () {
    $state = ($this->state)();
    $state['categories'][] = ['id' => null, 'name' => 'Heritage', 'icon' => null, 'sort' => 200];
    $state['categories'][] = ['id' => null, 'name' => 'Heritage', 'icon' => null, 'sort' => 210];

    ($this->save)($state);

    expect(Category::where('name', 'Heritage')->orderBy('id')->pluck('slug')->all())->toBe(['heritage', 'heritage-2']);
});

it('reorders categories by their sort numbers', function () {
    $other = ($this->category)('other');
    $state = ($this->state)();
    $state['categories'] = array_map(
        fn (array $row): array => $row['id'] === $other->id ? [...$row, 'sort' => 1] : $row,
        $state['categories'],
    );

    ($this->save)($state);

    expect(Category::orderBy('sort')->first()->slug)->toBe('other');
});

it('removes an unused category with no keyword rules', function () {
    $school = ($this->category)('school');

    ($this->save)(($this->without)(($this->state)(), $school->id));

    expect(Category::find($school->id))->toBeNull();
});

it('removes a category together with its keyword rules in the same save', function () {
    ($this->marketRules)();
    $state = ($this->without)(($this->state)(), $this->market->id);
    $state['keyword_rules'] = array_values(array_filter($state['keyword_rules'], fn (array $row): bool => $row['category_id'] !== $this->market->id));

    ($this->save)($state);

    expect(Category::find($this->market->id))->toBeNull()
        ->and(CategoryKeywordRule::count())->toBe(0);
});

it('refuses to remove a category whose keyword rules are still submitted, saving nothing', function () {
    ($this->marketRules)();
    $before = ($this->counts)();

    expect(fn () => ($this->save)(($this->without)(($this->state)(), $this->market->id)))
        ->toThrow(RulesNotSaved::class, "Rules not saved: 'Market' still has 3 keyword rules. Move or remove them first.");

    expect(($this->counts)())->toBe($before);
});

it('refuses to remove a category used by events and drafts, saving nothing', function () {
    Event::factory()->count(2)->create(['category_id' => $this->market->id]);
    EventDraft::factory()->create(['category_id' => $this->market->id]);
    $before = ($this->counts)();

    expect(fn () => ($this->save)(($this->without)(($this->state)(), $this->market->id)))
        ->toThrow(RulesNotSaved::class, "Rules not saved: 'Market' is used by 2 events and 1 draft.");

    expect(($this->counts)())->toBe($before);
});

it('counts a submission still holding the organiser\'s pick as using the category', function () {
    EventDraft::factory()->submission()->create(['category_id' => ($this->category)('other')->id, 'submitted_category_id' => $this->market->id]);

    expect(fn () => ($this->save)(($this->without)(($this->state)(), $this->market->id)))
        ->toThrow(RulesNotSaved::class, "Rules not saved: 'Market' is used by 1 draft.");
});

it('refuses to remove the Other category, saving nothing', function () {
    $before = ($this->counts)();

    expect(fn () => ($this->save)(($this->without)(($this->state)(), ($this->category)('other')->id)))
        ->toThrow(RulesNotSaved::class, "Rules not saved: The Other category can't be removed.");

    expect(($this->counts)())->toBe($before);
});

it('saves nothing at all when one part of the save is refused', function () {
    $music = ($this->category)('music');
    $state = ($this->without)(($this->state)(), ($this->category)('other')->id);
    $state['categories'] = array_map(
        fn (array $row): array => $row['id'] === $music->id ? [...$row, 'name' => 'Music & Gigs'] : $row,
        $state['categories'],
    );
    $state['keyword_rules'][] = ['id' => null, 'keyword' => 'gig', 'category_id' => $music->id, 'priority' => 10];
    $before = ($this->counts)();

    expect(fn () => ($this->save)($state))->toThrow(RulesNotSaved::class);

    expect(($this->counts)())->toBe($before)
        ->and($music->refresh()->name)->toBe('Music');
});

it('refuses a category with no name', function () {
    $state = ($this->state)();
    $state['categories'][] = ['id' => null, 'name' => '  ', 'icon' => null, 'sort' => 5];

    expect(fn () => ($this->save)($state))->toThrow(RulesNotSaved::class, 'Rules not saved: Every category needs a name.');
});

it('shows the Categories and Keyword rules tabs', function () {
    actingAs($this->admin);

    get('/admin/rules')->assertOk()->assertSeeInOrder(['Categories', 'Keyword rules']);
});

it('renames a category from the Rules screen and keeps its slug', function () {
    actingAs($this->admin);
    $music = ($this->category)('music');

    $page = livewire(Rules::class);
    $categories = $page->get('data.categories');
    $key = collect($categories)->search(fn (array $row): bool => $row['id'] === $music->id);
    $categories[$key]['name'] = 'Music & Gigs';

    $page->set('data.categories', $categories)
        ->callAction('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Rules saved');

    expect($music->refresh())->name->toBe('Music & Gigs')->slug->toBe('music');
});

it('tells the admin why a save was refused on the Rules screen', function () {
    actingAs($this->admin);
    $other = ($this->category)('other');

    $page = livewire(Rules::class);
    $categories = collect($page->get('data.categories'))->reject(fn (array $row): bool => $row['id'] === $other->id)->all();

    $page->set('data.categories', $categories)
        ->callAction('save')
        ->assertNotified('Rules not saved');

    expect(Category::find($other->id))->not->toBeNull();
});
