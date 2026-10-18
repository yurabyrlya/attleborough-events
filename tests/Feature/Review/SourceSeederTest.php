<?php

use App\Enums\ExtractionMethod;
use App\Enums\SourceHealth;
use App\Filament\Resources\Sources\Pages\EditSource;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\Source;
use App\Models\User;
use App\Models\Venue;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SourceSeeder;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

/*
 * SourceSeeder (D-05, SRC-01): the three ready ICS sources, seeded inactive
 * as reference configuration. Create-only (firstOrCreate on slug), so
 * re-seeding never reverts what the admin changed.
 */
beforeEach(function () {
    $this->seed(CategorySeeder::class);
});

/**
 * @return array<string, Source>
 */
function seededSources(): array
{
    return Source::query()->orderBy('id')->get()->keyBy('slug')->all();
}

it('seeds the Baptist Church, Town Council and Academy feeds, inactive and healthy', function () {
    $this->seed(SourceSeeder::class);
    $sources = seededSources();

    expect(array_keys($sources))->toBe(['attleborough-baptist-church', 'attleborough-town-council', 'attleborough-academy']);

    $baptist = $sources['attleborough-baptist-church'];
    $council = $sources['attleborough-town-council'];
    $academy = $sources['attleborough-academy'];

    expect($baptist)
        ->name->toBe('Attleborough Baptist Church')
        ->url->toBe('https://www.attleborough.church/Calendar/Calendar.ics')
        ->events_page_url->toBe('https://www.attleborough.church/Calendar/Month.aspx')
        ->parser_config->toBe(['identity' => 'uid'])
        ->supports_missing_detection->toBeTrue()
        ->is_organiser_source->toBeTrue()
        ->title_rules->toBe([])
        ->default_category_id->toBeNull();

    expect($council)
        ->name->toBe('Attleborough Town Council')
        ->url->toBe('https://attleboroughtc.org.uk/?post_type=tribe_events&ical=1')
        ->events_page_url->toBe('https://attleboroughtc.org.uk/events/')
        ->parser_config->toBe(['identity' => 'tec_post_id'])
        ->supports_missing_detection->toBeTrue()
        ->is_organiser_source->toBeFalse()
        ->title_rules->toBe([
            ['mode' => 'skip', 'keyword' => 'Private hire'],
            ['mode' => 'skip', 'keyword' => 'Council meeting'],
        ])
        ->default_category_id->toBeNull();

    expect($academy)
        ->name->toBe('Attleborough Academy')
        ->url->toBe('https://www.attleboroughacademy.org/calendar/events.ics')
        ->events_page_url->toBe('https://www.attleboroughacademy.org/100/calendar')
        ->parser_config->toBe(['identity' => 'summary_start'])
        ->supports_missing_detection->toBeFalse()
        ->is_organiser_source->toBeTrue()
        ->default_category_id->toBe(Category::query()->where('slug', 'school')->value('id'));

    foreach ($sources as $source) {
        expect($source)
            ->is_active->toBeFalse()
            ->health->toBe(SourceHealth::Healthy)
            ->extraction_method->toBe(ExtractionMethod::Ics)
            ->default_timezone->toBe('Europe/London')
            ->check_frequency->toBe('daily')
            ->location_mode->toBe('local')
            ->year_policy->toBe('none')
            ->default_venue_id->toBeNull()
            ->next_check_at->toBeNull()
            ->and(trim((string) $source->legal_basis))->not->toBe('')
            ->and(trim((string) $source->notes))->not->toBe('')
            ->and(mb_strlen($source->short_name))->toBeLessThanOrEqual(40);
    }

    expect($baptist->legal_basis)->toContain('own_feed_published_for_syndication')
        ->and($council->legal_basis)->toContain('factual_extract_only')
        ->and($academy->legal_basis)->toContain('permission_granted');
});

it('passes the Sources admin form\'s own validation unchanged', function () {
    $this->seed(SourceSeeder::class);
    actingAs(ingestAdmin());

    foreach (seededSources() as $source) {
        $before = $source->only(['name', 'short_name', 'url', 'events_page_url', 'parser_config', 'title_rules', 'legal_basis', 'notes', 'supports_missing_detection', 'is_organiser_source', 'default_category_id', 'check_frequency']);

        livewire(EditSource::class, ['record' => $source->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($source->refresh()->only(array_keys($before)))->toBe($before)
            ->and($source->is_active)->toBeFalse();
    }
});

it('sets a default venue only when a matching venue is already known', function () {
    $baptist = Venue::factory()->create(['name' => 'Attleborough Baptist Church']);

    $this->seed(SourceSeeder::class);
    $sources = seededSources();

    expect($sources['attleborough-baptist-church']->default_venue_id)->toBe($baptist->id)
        ->and($sources['attleborough-town-council']->default_venue_id)->toBeNull()
        ->and($sources['attleborough-academy']->default_venue_id)->toBeNull();
});

it('leaves exactly three sources when run twice', function () {
    $this->seed(SourceSeeder::class);
    $this->seed(SourceSeeder::class);

    expect(Source::query()->count())->toBe(3);
});

it('keeps the admin\'s edits after re-seeding and brings back a deleted seeded source', function () {
    $this->seed(SourceSeeder::class);
    $sources = seededSources();

    $sources['attleborough-baptist-church']->update(['name' => 'Baptist Church (Leys Lane)', 'title_rules' => [['mode' => 'skip', 'keyword' => 'Sunday Service']]]);
    $sources['attleborough-town-council']->update(['title_rules' => []]);
    $sources['attleborough-academy']->delete();

    $this->seed(SourceSeeder::class);
    $after = seededSources();

    expect($after)->toHaveCount(3)
        ->and($after['attleborough-baptist-church']->name)->toBe('Baptist Church (Leys Lane)')
        ->and($after['attleborough-baptist-church']->title_rules)->toBe([['mode' => 'skip', 'keyword' => 'Sunday Service']])
        ->and($after['attleborough-town-council']->title_rules)->toBe([])
        ->and($after['attleborough-academy']->name)->toBe('Attleborough Academy')
        ->and($after['attleborough-academy']->is_active)->toBeFalse();
});

it('leaves a source the admin activated live after re-seeding', function () {
    $this->seed(SourceSeeder::class);
    $council = seededSources()['attleborough-town-council'];
    $council->forceFill(['is_active' => true])->save();

    $this->seed(SourceSeeder::class);

    expect($council->refresh()->is_active)->toBeTrue();
});

it('is called from DatabaseSeeder and creates no events, drafts or users', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Source::query()->count())->toBe(3)
        ->and(Event::query()->count())->toBe(0)
        ->and(EventDraft::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0);
});
