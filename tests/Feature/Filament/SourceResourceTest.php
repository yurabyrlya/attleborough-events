<?php

use App\Enums\ExtractionMethod;
use App\Enums\RunMode;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Enums\SourceHealth;
use App\Filament\Resources\Sources\Pages\CreateSource;
use App\Filament\Resources\Sources\Pages\EditSource;
use App\Filament\Resources\Sources\Pages\ListSources;
use App\Filament\Resources\Sources\RelationManagers\RunsRelationManager;
use App\Filament\Resources\Sources\Schemas\SourceForm;
use App\Filament\Resources\Sources\SourceResource;
use App\Ingestion\RunLauncher;
use App\Models\EventDraft;
use App\Models\Source;
use App\Models\SourceRun;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

/*
 * 03-15: the Sources table (health at a glance, SRC-08), the run log
 * (SRC-07) and the source form's validation edges (SRC-01).
 */

/**
 * A finished run of $source, queued at $queuedLondon (London wall clock).
 *
 * @param  array<string, mixed>  $attributes
 */
function sourceResourceRun(Source $source, string $queuedLondon, array $attributes = []): SourceRun
{
    $queued = CarbonImmutable::parse($queuedLondon, 'Europe/London')->utc();
    $run = new SourceRun(['mode' => $attributes['mode'] ?? RunMode::Live, 'trigger' => RunTrigger::Schedule]);
    unset($attributes['mode']);

    $run->forceFill([
        'source_id' => $source->id,
        'status' => RunStatus::Succeeded,
        'queued_at' => $queued,
        'started_at' => $queued,
        'finished_at' => $queued->addSeconds(2),
        'duration_ms' => 1500,
        ...$attributes,
    ])->save();

    return $run;
}

beforeEach(function () {
    freezeLondon('2026-10-17 09:00:00');
    actingAs(ingestAdmin());
});

describe('sources table and run log (SRC-07, SRC-08)', function () {
    it('shows health badges, the last live run status, structure alerts and London times', function () {
        $healthy = Source::factory()->active()->create(['name' => 'Baptist Church']);
        $degraded = Source::factory()->active()->create(['name' => 'Town Council']);
        $unhealthy = Source::factory()->active()->create(['name' => 'Academy']);

        $degraded->forceFill(['health' => SourceHealth::Degraded, 'consecutive_failures' => 3])->save();
        $unhealthy->forceFill(['health' => SourceHealth::Unhealthy, 'consecutive_failures' => 5])->save();
        $healthy->forceFill([
            'next_check_at' => CarbonImmutable::parse('2026-10-18 05:42', 'Europe/London')->utc(),
            'last_success_at' => CarbonImmutable::parse('2026-10-17 05:31', 'Europe/London')->utc(),
            'structure_alert_at' => now()->utc(),
        ])->save();

        // Last LIVE run blocked; a later preview must not hide it.
        sourceResourceRun($degraded, '2026-10-17 05:30', ['status' => RunStatus::Blocked]);
        sourceResourceRun($degraded, '2026-10-17 08:00', ['mode' => RunMode::Preview]);

        livewire(ListSources::class)
            ->assertCanSeeTableRecords([$healthy, $degraded, $unhealthy])
            ->assertSee('Healthy')
            ->assertSee('Degraded')
            ->assertSee('Unhealthy (paused)')
            ->assertSee('Blocked by robots.txt')
            ->assertSee('Possible structure change')
            ->assertSee('Sun 18 Oct, 5:42am')
            ->assertSee('Sat 17 Oct, 5:31am');

        // Every live source here needs attention; a paused or healthy one does not.
        expect(SourceResource::getNavigationBadge())->toBe('3');
        $healthy->forceFill(['structure_alert_at' => null])->save();
        $unhealthy->forceFill(['is_active' => false])->save();
        expect(SourceResource::getNavigationBadge())->toBe('1');
    });

    it('filters sources by active flag and health', function () {
        $live = Source::factory()->active()->create();
        $paused = Source::factory()->create();
        $paused->forceFill(['health' => SourceHealth::Degraded])->save();

        livewire(ListSources::class)
            ->filterTable('is_active', true)
            ->assertCanSeeTableRecords([$live])
            ->assertCanNotSeeTableRecords([$paused])
            ->resetTableFilters()
            ->filterTable('health', SourceHealth::Degraded->value)
            ->assertCanSeeTableRecords([$paused])
            ->assertCanNotSeeTableRecords([$live]);
    });

    it('lists runs newest first by queued time, then id, with counters and the failure', function () {
        $source = Source::factory()->active()->create();

        $middle = sourceResourceRun($source, '2026-10-16 05:30', ['items_found' => 64, 'items_new' => 12, 'skipped_far_future' => 40, 'drafts_created' => 12]);
        $newest = sourceResourceRun($source, '2026-10-17 05:30', [
            'status' => RunStatus::Failed,
            'failure_kind' => 'content_invalid',
            'error_message' => 'The response was an HTML page, not a calendar.',
        ]);
        $oldest = sourceResourceRun($source, '2026-10-15 05:30');
        // Same queued time as $newest, lower id: listed after it.
        $tie = sourceResourceRun($source, '2026-10-17 05:30');
        DB::table('source_runs')->where('id', $tie->id)->update(['id' => $oldest->id + 100]);
        $tie = SourceRun::query()->findOrFail($oldest->id + 100);

        livewire(RunsRelationManager::class, ['ownerRecord' => $source, 'pageClass' => EditSource::class])
            ->assertCanSeeTableRecords([$tie, $newest, $middle, $oldest], inOrder: true)
            ->assertSee('Content invalid')
            ->assertSee('The response was an HTML page, not a calendar.')
            ->assertSee('Sat 17 Oct 2026, 05:30')
            ->assertTableColumnStateSet('items_found', 64, $middle)
            ->assertTableColumnStateSet('items_new', 12, $middle)
            ->assertTableColumnStateSet('skipped_far_future', 40, $middle)
            ->assertTableColumnStateSet('drafts_created', 12, $middle)
            ->assertTableColumnStateSet('duration', '1.5 s', $middle);
    });

    it('polls the run log only while a run is queued or running', function () {
        $source = Source::factory()->active()->create();
        $run = sourceResourceRun($source, '2026-10-17 05:30');

        livewire(RunsRelationManager::class, ['ownerRecord' => $source, 'pageClass' => EditSource::class])
            ->assertDontSeeHtml('wire:poll.3s');

        $run->forceFill(['status' => RunStatus::Running, 'finished_at' => null])->save();

        livewire(RunsRelationManager::class, ['ownerRecord' => $source, 'pageClass' => EditSource::class])
            ->assertSeeHtml('wire:poll.3s')
            ->assertTableColumnStateSet('duration', 'Running', $run);
    });

    it('warns on the edit page when the host Crawl-delay is too long for the checks', function (int $delay, string $frequency, bool $warns) {
        $source = Source::factory()->create(['url' => 'https://www.attleboroughprimary.example/calendar.ics', 'check_frequency' => $frequency]);
        DB::table('crawl_hosts')->insert([
            'host' => 'www.attleboroughprimary.example',
            'next_allowed_at' => now()->utc(),
            'robots_status' => 'rules',
            'crawl_delay_seconds' => $delay,
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        $page = livewire(EditSource::class, ['record' => $source->getRouteKey()]);

        $warns
            ? $page->assertSee("Crawl-delay {$delay}s is longer than the check interval")
            : $page->assertDontSee('Crawl-delay');
    })->with([
        'Attleborough Primary 12000 s, daily' => [12000, 'daily', true],
        'one day (the largest stored), daily' => [86400, 'daily', true],
        'ten seconds, daily' => [10, 'daily', false],
        'two hours exactly, weekly' => [7200, 'weekly', false],
    ]);
});

describe('source form (SRC-01)', function () {
    beforeEach(function () {
        $this->valid = [
            'name' => 'Town Council',
            'url' => 'https://www.attleboroughtowncouncil.gov.uk/events/?ical=1',
        ];
    });

    it('requires a name and a feed URL and saves nothing without them', function () {
        livewire(CreateSource::class)
            ->fillForm(['name' => '', 'url' => ''])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required', 'url' => 'required']);

        expect(Source::query()->count())->toBe(0);
    });

    it('saves with no title rules, no events page URL and no short name', function () {
        livewire(CreateSource::class)
            ->fillForm([...$this->valid, 'name' => 'Attleborough Town Council and Community Hall Bookings', 'events_page_url' => '', 'title_rules' => []])
            ->call('create')
            ->assertHasNoFormErrors();

        $source = Source::query()->sole();

        expect($source->title_rules)->toBe([])
            ->and($source->events_page_url)->toBeNull()
            ->and($source->short_name)->toBe('Attleborough Town Council and Community')
            ->and(mb_strlen($source->short_name))->toBeLessThanOrEqual(40)
            ->and($source->extraction_method)->toBe(ExtractionMethod::Ics)
            ->and($source->default_timezone)->toBe('Europe/London')
            ->and($source->check_frequency)->toBe('daily')
            ->and($source->check_time)->toBe('05:30:00');
    });

    it('refuses feed and events page URLs the fetcher would refuse', function (string $url) {
        livewire(CreateSource::class)
            ->fillForm([...$this->valid, 'url' => $url, 'events_page_url' => $url])
            ->call('create')
            ->assertHasFormErrors(['url', 'events_page_url'])
            ->assertSee(SourceForm::URL_REFUSED);

        expect(Source::query()->count())->toBe(0);
    })->with([
        'ftp' => 'ftp://x',
        'user and password' => 'https://user:secret@x/',
        'non-standard port' => 'https://x:8080/',
        'no scheme' => 'www.example.org/calendar.ics',
    ]);

    it('counts the 120-character name limit in characters, not bytes', function () {
        $name = str_repeat('É', 119).'a';

        livewire(CreateSource::class)
            ->fillForm([...$this->valid, 'name' => $name])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Source::query()->sole()->name)->toBe($name);

        livewire(CreateSource::class)
            ->fillForm([...$this->valid, 'name' => $name.'b'])
            ->call('create')
            ->assertHasFormErrors(['name' => 'max']);

        livewire(CreateSource::class)
            ->fillForm([...$this->valid, 'short_name' => str_repeat('ü', 41)])
            ->call('create')
            ->assertHasFormErrors(['short_name' => 'max']);

        expect(Source::query()->count())->toBe(1);
    });

    it('makes the slug from the name with a numeric suffix, and keeps it on rename', function () {
        foreach ([1, 2, 3] as $attempt) {
            livewire(CreateSource::class)->fillForm($this->valid)->call('create')->assertHasNoFormErrors();
        }

        expect(Source::query()->orderBy('id')->pluck('slug')->all())->toBe(['town-council', 'town-council-2', 'town-council-3']);

        $first = Source::query()->orderBy('id')->firstOrFail();

        livewire(EditSource::class, ['record' => $first->getRouteKey()])
            ->fillForm(['name' => 'Attleborough Town Council'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($first->refresh()->slug)->toBe('town-council')
            ->and($first->name)->toBe('Attleborough Town Council');
    });

    it('reports a slug taken by a parallel save as a validation error', function () {
        // Another save takes the slug between the free-slug check and the insert.
        Source::creating(function (Source $source): void {
            DB::table('sources')->insert([
                'name' => 'Town Council', 'slug' => $source->slug, 'short_name' => 'Town Council',
                'url' => 'https://example.org/a.ics', 'extraction_method' => 'ics',
            ]);
        });

        livewire(CreateSource::class)
            ->fillForm($this->valid)
            ->call('create')
            ->assertHasErrors(['data.name'])
            ->assertSee(CreateSource::SLUG_TAKEN);
    });

    it('round-trips title rules and the item identity', function () {
        livewire(CreateSource::class)
            ->fillForm([
                ...$this->valid,
                'parser_config.identity' => 'tec_post_id',
                'title_rules' => [
                    ['mode' => 'skip', 'keyword' => '  Private hire '],
                    ['mode' => 'keep', 'keyword' => 'yoga'],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $source = Source::query()->sole();
        $rules = [['mode' => 'skip', 'keyword' => 'Private hire'], ['mode' => 'keep', 'keyword' => 'yoga']];

        expect($source->title_rules)->toBe($rules)
            ->and($source->parser_config)->toBe(['identity' => 'tec_post_id']);

        // parser_config keys the form does not edit survive a save.
        $source->forceFill(['parser_config' => ['identity' => 'tec_post_id', 'future_key' => 'kept']])->save();

        livewire(EditSource::class, ['record' => $source->getRouteKey()])
            ->assertFormSet(['parser_config.identity' => 'tec_post_id'])
            ->fillForm(['parser_config.identity' => 'summary_start'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($source->refresh()->title_rules)->toBe($rules)
            ->and($source->parser_config)->toBe(['identity' => 'summary_start', 'future_key' => 'kept']);
    });

    it('refuses a title rule without a keyword', function () {
        livewire(CreateSource::class)
            ->fillForm([...$this->valid, 'title_rules' => [['mode' => 'skip', 'keyword' => '']]])
            ->call('create')
            ->assertHasFormErrors(['title_rules.0.keyword' => 'required']);
    });

    it('always saves a new source inactive', function () {
        livewire(CreateSource::class)
            ->fillForm([...$this->valid, 'is_active' => true, 'health' => 'unhealthy'])
            ->set('data.is_active', true)
            ->call('create')
            ->assertHasNoFormErrors();

        $source = Source::query()->sole();

        expect($source->is_active)->toBeFalse()
            ->and($source->health)->toBe(SourceHealth::Healthy);
    });

    it('leaves a queued run untouched when the source is edited; the run uses the saved settings', function () {
        Sleep::fake(syncWithCarbon: true);
        Storage::fake('local');
        $this->seed(CategorySeeder::class);
        ingestFeed(implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Test//EN',
            'BEGIN:VEVENT', 'UID:coffee-1@test.invalid', 'DTSTAMP:20261017T050000Z', 'SUMMARY:Coffee Morning',
            'DTSTART;TZID=Europe/London:20261024T100000', 'DTEND;TZID=Europe/London:20261024T120000', 'END:VEVENT',
            'END:VCALENDAR', '',
        ]));

        $before = Venue::factory()->create(['name' => 'Church Hall']);
        $after = Venue::factory()->create(['name' => 'Town Hall']);
        $source = Source::factory()->ics()->active()->create(['default_venue_id' => $before->id]);

        $run = app(RunLauncher::class)->runNow($source);
        $snapshot = $run->refresh()->getAttributes();

        livewire(EditSource::class, ['record' => $source->getRouteKey()])
            ->fillForm(['default_venue_id' => $after->id])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($run->refresh()->getAttributes())->toBe($snapshot)
            ->and($source->refresh()->default_venue_id)->toBe($after->id)
            ->and($source->is_active)->toBeTrue();

        test()->artisan('queue:work', ['connection' => 'database', '--queue' => 'default', '--once' => true])->assertExitCode(0);

        expect($run->refresh()->status)->toBe(RunStatus::Succeeded)
            ->and(EventDraft::query()->sole()->venue_id)->toBe($after->id);
    });
});

it('shows a test run that previews all-day, timed and unreadable start values', function () {
    $source = Source::factory()->create(['name' => 'Attleborough Academy']);
    sourceResourceRun($source, '2026-10-17 08:00', [
        'mode' => RunMode::Preview,
        'preview' => [
            'would_create' => 2,
            'skipped' => [],
            'rows' => [
                ['title' => 'INSET day', 'starts' => '2026-10-07', 'verdict' => 'new', 'reason' => null],
                ['title' => 'Open evening', 'starts' => '2026-10-15 19:00', 'verdict' => 'new', 'reason' => null],
                ['title' => 'Odd row', 'starts' => 'not a date', 'verdict' => 'skipped', 'reason' => 'parse_error'],
            ],
        ],
    ]);

    livewire(EditSource::class, ['record' => $source->getRouteKey()])
        ->assertOk()
        ->assertSee('Wed 7 Oct')
        ->assertSee('Thu 15 Oct, 7pm')
        ->assertSee('not a date');
});
