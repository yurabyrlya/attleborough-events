<?php

use App\Enums\EventOrigin;
use App\Enums\ExtractionMethod;
use App\Filament\Pages\Rules;
use App\Models\Category;
use App\Models\CategoryKeywordRule;
use App\Models\User;
use App\Review\Data\DraftData;
use App\Review\DraftWriter;
use App\Review\SaveRules;
use Database\Seeders\CategorySeeder;
use Illuminate\Auth\Access\AuthorizationException;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

/*
 * PIPE-03 link 3 end to end: the admin adds a title keyword rule on the
 * Rules screen, SaveRules stores it, and the next draft written takes its
 * category from it, with the reason the admin sees in the queue.
 */
beforeEach(function () {
    freezeLondon('2026-10-17 12:00');
    $this->seed(CategorySeeder::class);
    $this->community = Category::where('slug', 'community')->firstOrFail();
    $this->admin = User::factory()->create(['email' => config('app.admin_email')]);
});

it('categorises the next draft by a keyword rule saved on the Rules screen', function () {
    actingAs($this->admin);

    livewire(Rules::class)
        ->set('data.keyword_rules', [
            'new-row' => ['id' => null, 'keyword' => 'pumpkin', 'category_id' => $this->community->id, 'priority' => 10],
        ])
        ->callAction('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Rules saved');

    expect(CategoryKeywordRule::count())->toBe(1);

    $draft = app(DraftWriter::class)->create(new DraftData(
        origin: EventOrigin::Submission,
        extractionMethod: ExtractionMethod::Submission,
        title: 'Pumpkin carving at the hall',
    ));

    expect($draft->category_id)->toBe($this->community->id)
        ->and($draft->category_reason)->toBe("Keyword rule: 'pumpkin' → Community")
        ->and($draft->category_flagged)->toBeFalse();
});

it('keeps the Rules screen admin-only', function () {
    actingAs(User::factory()->create(['email' => 'someone@example.test']));

    get('/admin/rules')->assertForbidden();
});

it('opens the Rules screen for the admin', function () {
    actingAs($this->admin);

    get('/admin/rules')->assertOk()->assertSee('Keyword rules');
});

it('refuses SaveRules from anyone but the admin and writes nothing', function () {
    $intruder = User::factory()->create(['email' => 'someone@example.test']);

    expect(fn () => app(SaveRules::class)([
        'keyword_rules' => [
            ['id' => null, 'keyword' => 'pumpkin', 'category_id' => $this->community->id, 'priority' => 10],
        ],
    ], $intruder))->toThrow(AuthorizationException::class, 'Only the admin can change rules.');

    expect(CategoryKeywordRule::count())->toBe(0);
});
