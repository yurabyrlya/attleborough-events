<?php

use App\Enums\EventOrigin;
use App\Models\Category;
use App\Models\CategoryKeywordRule;
use App\Models\User;
use App\Review\CategoryAssigner;
use App\Review\Exceptions\RulesNotSaved;
use App\Review\SaveRules;
use Database\Seeders\CategorySeeder;

/*
 * PIPE-03 keyword link: deterministic, whole-word, Unicode- and
 * whitespace-safe matching; and the keyword half of SaveRules' refusals.
 */
beforeEach(function () {
    $this->seed(CategorySeeder::class);
    $this->category = fn (string $slug): Category => Category::where('slug', $slug)->firstOrFail();
    $this->rule = fn (string $keyword, string $slug, int $priority = 100): CategoryKeywordRule => CategoryKeywordRule::create([
        'keyword' => $keyword,
        'category_id' => ($this->category)($slug)->id,
        'priority' => $priority,
    ]);
    $this->assign = fn (string $title, ?int $preferred = null) => app(CategoryAssigner::class)->assign($preferred, EventOrigin::Submission, [], $title);
    $this->admin = User::factory()->create(['email' => config('app.admin_email')]);
    $this->save = fn (array $rows) => app(SaveRules::class)(['keyword_rules' => $rows], $this->admin);
});

it('lets the preferred category beat a matching keyword rule', function () {
    ($this->rule)('quiz', 'community');

    $decision = ($this->assign)('Pub quiz night', ($this->category)('music')->id);

    expect($decision->categoryId)->toBe(($this->category)('music')->id)
        ->and($decision->reason)->toBe('Chosen by organiser')
        ->and($decision->flagged)->toBeFalse();
});

it('picks the lower priority number when two rules match', function () {
    ($this->rule)('quiz', 'community', 10);
    ($this->rule)('night', 'music', 5);

    $decision = ($this->assign)('Pub quiz night');

    expect($decision->categoryId)->toBe(($this->category)('music')->id)
        ->and($decision->reason)->toBe("Keyword rule: 'night' → Music");
});

it('picks the lower id when two matching rules share a priority', function () {
    ($this->rule)('quiz', 'community', 10);
    ($this->rule)('night', 'music', 10);

    $decision = ($this->assign)('Pub quiz night');

    expect($decision->categoryId)->toBe(($this->category)('community')->id)
        ->and($decision->reason)->toBe("Keyword rule: 'quiz' → Community");
});

it('gives the same category and reason for the same input every time', function () {
    ($this->rule)('quiz', 'community', 10);
    ($this->rule)('night', 'music', 10);

    $decisions = collect(range(1, 5))->map(fn () => ($this->assign)('Pub quiz night'));

    expect($decisions->unique(fn ($d) => $d->categoryId.'|'.$d->reason.'|'.(int) $d->flagged))->toHaveCount(1);
});

it('matches whole words case-insensitively: quiz in "Pub QUIZ night" but not "Quizzical tours"', function () {
    ($this->rule)('quiz', 'community');

    expect(($this->assign)('Pub QUIZ night')->reason)->toBe("Keyword rule: 'quiz' → Community")
        ->and(($this->assign)('Quizzical tours')->reason)->toBe('No match → Other');
});

it('matches a multi-word car boot keyword across repeated whitespace', function () {
    ($this->rule)('car boot', 'market');

    $decision = ($this->assign)("Car  Boot\tsale");

    expect($decision->categoryId)->toBe(($this->category)('market')->id)
        ->and($decision->reason)->toBe("Keyword rule: 'car boot' → Market");
});

it('matches café against "CAFÉ morning" whatever the Unicode form', function () {
    ($this->rule)("cafe\u{0301}", 'community'); // decomposed é (NFD)

    expect(($this->assign)('CAFÉ morning')->categoryId)->toBe(($this->category)('community')->id);
});

it('stores a café keyword in NFC so it matches either form', function () {
    ($this->save)([['id' => null, 'keyword' => "  Cafe\u{0301}   morning ", 'category_id' => ($this->category)('community')->id, 'priority' => 10]]);

    expect(CategoryKeywordRule::sole()->keyword)->toBe('Café morning')
        ->and(($this->assign)("CAFE\u{0301} MORNING at the hall")->reason)->toBe("Keyword rule: 'Café morning' → Community");
});

it('treats keyword punctuation literally, never as a pattern', function () {
    ($this->rule)('a.b', 'community');

    expect(($this->assign)('axb fair')->reason)->toBe('No match → Other')
        ->and(($this->assign)('a.b fair')->reason)->toBe("Keyword rule: 'a.b' → Community");
});

it('falls back to Other, flagged, when no rule matches', function () {
    ($this->rule)('quiz', 'community');

    $decision = ($this->assign)('Harvest supper');

    expect($decision->categoryId)->toBe(($this->category)('other')->id)
        ->and($decision->reason)->toBe('No match → Other')
        ->and($decision->flagged)->toBeTrue();
});

it('refuses CAFÉ and café together as the same keyword and writes nothing', function () {
    ($this->rule)('quiz', 'community');
    $community = ($this->category)('community')->id;

    expect(fn () => ($this->save)([
        ['id' => null, 'keyword' => 'CAFÉ', 'category_id' => $community, 'priority' => 10],
        ['id' => null, 'keyword' => 'café', 'category_id' => $community, 'priority' => 20],
    ]))->toThrow(RulesNotSaved::class, "Rules not saved: 'café' is listed twice.");

    expect(CategoryKeywordRule::count())->toBe(1)
        ->and(CategoryKeywordRule::sole()->keyword)->toBe('quiz');
});

it('refuses a blank keyword and a missing category, listing both, and writes nothing', function () {
    ($this->rule)('quiz', 'community');

    try {
        ($this->save)([
            ['id' => null, 'keyword' => '   ', 'category_id' => ($this->category)('market')->id, 'priority' => 10],
            ['id' => null, 'keyword' => 'bingo', 'category_id' => null, 'priority' => 10],
        ]);
        $this->fail('Expected RulesNotSaved');
    } catch (RulesNotSaved $refused) {
        expect($refused->problems)->toBe([
            'Every keyword rule needs a keyword.',
            "'bingo' needs a category.",
        ]);
    }

    expect(CategoryKeywordRule::count())->toBe(1);
});

it('refuses a priority out of range and writes nothing', function () {
    expect(fn () => ($this->save)([
        ['id' => null, 'keyword' => 'bingo', 'category_id' => ($this->category)('community')->id, 'priority' => 5000],
    ]))->toThrow(RulesNotSaved::class, "Rules not saved: 'bingo' needs a priority from 0 to 1000.");

    expect(CategoryKeywordRule::count())->toBe(0);
});

it('deletes every rule when saved with an empty keyword list, so drafts fall back to Other', function () {
    ($this->rule)('quiz', 'community');
    ($this->rule)('market', 'market');

    ($this->save)([]);

    expect(CategoryKeywordRule::count())->toBe(0)
        ->and(($this->assign)('Pub quiz night')->reason)->toBe('No match → Other');
});

it('updates kept rules, deletes dropped ones and can swap two keywords in one save', function () {
    $quiz = ($this->rule)('quiz', 'community', 10);
    $bingo = ($this->rule)('bingo', 'charity', 20);
    $gone = ($this->rule)('gig', 'music', 30);

    ($this->save)([
        ['id' => $quiz->id, 'keyword' => 'bingo', 'category_id' => $quiz->category_id, 'priority' => 10],
        ['id' => $bingo->id, 'keyword' => 'quiz', 'category_id' => $bingo->category_id, 'priority' => 20],
    ]);

    expect(CategoryKeywordRule::orderBy('id')->pluck('keyword', 'id')->all())->toBe([
        $quiz->id => 'bingo',
        $bingo->id => 'quiz',
    ])->and(CategoryKeywordRule::find($gone->id))->toBeNull();
});
