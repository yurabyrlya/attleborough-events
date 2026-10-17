<?php

use App\Models\Category;
use App\Models\CategoryKeywordRule;
use Tests\Browser\UatFixtures as Uat;

use function Pest\Laravel\actingAs;

/*
 * Browser UAT F14 (Rules screen, ADM-04), docs/uat/browser-uat.md.
 */
beforeEach(function () {
    freezeLondon('2026-10-20 10:00');
    Uat::seedCategories();
    Uat::venue();
    $this->admin = Uat::admin();
    actingAs($this->admin);
});

/**
 * The id of the last input in a Rules repeater whose id ends with $suffix
 * (repeater rows are keyed by a UUID, e.g. form.keyword_rules.<uuid>.keyword).
 */
function lastRepeaterInputId(mixed $page, string $repeater, string $suffix): string
{
    return $page->script('() => [...document.querySelectorAll(\'[id^="form.'.$repeater.'."][id$=".'.$suffix.'"]\')].at(-1)?.id ?? ""');
}

/**
 * Mark the Categories row whose Name is $name so a role locator can be
 * scoped to it (row inputs hold their value as a property, not an attribute).
 */
function markCategoryRow(mixed $page, string $name, string $marker): string
{
    $match = '[...document.querySelectorAll(\'input[id^="form.categories."][id$=".name"]\')].find((el) => el.value === '.json_encode($name).')';

    // Inputs exist before Livewire has filled their values: wait for the value.
    Uat::waitUntil($page, $match.' !== undefined');

    $found = $page->script('() => { const input = '.$match.'; input?.closest("tr")?.setAttribute("data-uat-row", '.json_encode($marker).'); return Boolean(input); }');

    expect($found)->toBeTrue("No Categories row named {$name}.");

    return 'tr[data-uat-row="'.$marker.'"]';
}

function openRules(): mixed
{
    $page = visit('/admin/rules')->waitForText('Save rules');

    Uat::waitUntil($page, 'document.querySelector(\'[id^="form.categories."][id$=".name"]\') !== null');

    // The tabs' Alpine component is lazy-loaded (x-load): wait until it is live.
    return Uat::waitUntil($page, "document.querySelector('.fi-sc-tabs')?._x_dataStack !== undefined");
}

it('F14: a keyword rule decides the next submission\'s category', function () {
    $page = openRules();

    $page->click(Uat::role('tab', 'Keyword rules'))
        ->waitForText("Words match whole words only, so 'quiz' doesn't match 'quizzical'.")
        ->click(Uat::role('button', 'Add keyword rule'));
    Uat::waitUntil($page, 'document.querySelector(\'[id^="form.keyword_rules."][id$=".keyword"]\') !== null');

    $keywordId = lastRepeaterInputId($page, 'keyword_rules', 'keyword');
    $categoryId = lastRepeaterInputId($page, 'keyword_rules', 'category_id');

    $page->fill('[id="'.$keywordId.'"]', 'pumpkin')
        ->select('[id="'.$categoryId.'"]', 'Community');

    Uat::settleFields($page, [$keywordId => 'pumpkin'])
        ->click(Uat::role('button', 'Save rules'))
        ->waitForText('Rules saved')
        ->assertNoJavaScriptErrors();

    $rule = CategoryKeywordRule::query()->sole();
    expect($rule->keyword)->toBe('pumpkin')
        ->and($rule->category_id)->toBe(Uat::category('community')->id);

    // The organiser picked "Not sure", so the keyword rule decides.
    $draft = Uat::submissionDraft(['title' => 'UAT Pumpkin carving', 'category_choice' => 'not_sure']);

    $page->navigate('/admin/event-drafts')
        ->waitForText('UAT Pumpkin carving')
        ->assertSeeIn('tr:has-text("UAT Pumpkin carving")', 'Community')
        ->click('tr:has-text("UAT Pumpkin carving") >> text=UAT Pumpkin carving')
        ->waitForText('Why this category')
        ->assertPathIs('/admin/event-drafts/'.$draft->getRouteKey().'/review')
        ->assertSee("Keyword rule: 'pumpkin' → Community")
        ->assertNoJavaScriptErrors();
});

it('F14: the Other category can\'t be removed and a category in use is refused', function () {
    Uat::publish($this->admin, ['title' => 'UAT Coffee Morning']);
    $categories = Category::query()->count();

    $page = openRules();

    // Other has no Delete button; every other category row has one.
    $other = markCategoryRow($page, 'Other', 'other');
    $page->assertNotPresent($other.' >> '.Uat::role('button', 'Delete'));
    expect($page->script('() => document.querySelectorAll(\'tr:has(input[id^="form.categories."][id$=".name"]) button[aria-label="Delete"]\').length'))
        ->toBe($categories - 1);

    // Community has a published event: removing it is refused, nothing changes.
    $community = markCategoryRow($page, 'Community', 'community');
    $page->click($community.' >> '.Uat::role('button', 'Delete'))
        ->assertNotPresent($community)
        ->click(Uat::role('button', 'Save rules'))
        ->waitForText('Rules not saved')
        ->assertSee("'Community' is used by")
        ->assertNoJavaScriptErrors();

    expect(Category::query()->count())->toBe($categories)
        ->and(Category::query()->where('slug', 'community')->exists())->toBeTrue();
});
