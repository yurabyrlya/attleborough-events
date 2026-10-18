<?php

use App\Enums\RunMode;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Filament\Resources\Sources\Pages\CreateSource;
use App\Filament\Resources\Sources\Pages\EditSource;
use App\Filament\Resources\Sources\RelationManagers\RunsRelationManager;
use App\Filament\Resources\Sources\SourceResource;
use App\Models\EventDraft;
use App\Models\Source;
use App\Models\SourceRun;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

/*
 * 03-15 tracer: the admin onboards an ICS source in Filament (D-01): create
 * it (saved inactive), Test run (nothing drafted, preview counts shown),
 * Create drafts (live, drafts in the queue, both runs in the run log), Run
 * now and Pause. Offline: ingestFeed (Http::fake), the Pest fake
 * HostResolver and Sleep::fake. FetchSourceJob is pinned to the database
 * queue, so queued runs are worked with queue:work --once.
 */

const SOURCE_ACTIONS_BAPTIST_URL = 'https://www.attleborough.church/Calendar/Calendar.ics';

/**
 * Work one job on the real database queue.
 */
function sourceActionsWork(): void
{
    test()->artisan('queue:work', ['connection' => 'database', '--queue' => 'default', '--once' => true])->assertExitCode(0);
}

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    Storage::fake('local');
    freezeLondon('2026-10-17 06:00:00');
    $this->seed(CategorySeeder::class);
    ingestFeed(sourceFixture('attleborough-baptist-church/2026-10-17-ical.ics'), "User-agent: *\nCrawl-delay: 10\nDisallow: /Admin/\n");
    actingAs(ingestAdmin());
});

it('onboards a source: create inactive, test run drafts nothing, Create drafts drafts the preview count and logs both runs', function () {
    livewire(CreateSource::class)
        ->fillForm([
            'name' => 'Attleborough Baptist Church',
            'url' => SOURCE_ACTIONS_BAPTIST_URL,
            'parser_config.identity' => 'uid',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $source = Source::query()->sole();

    expect($source->is_active)->toBeFalse()
        ->and($source->slug)->toBe('attleborough-baptist-church')
        ->and($source->parser_config['identity'])->toBe('uid');

    $page = livewire(EditSource::class, ['record' => $source->getRouteKey()])
        ->assertActionVisible('testRun')
        ->assertActionHidden('createDrafts')
        ->assertActionHidden('runNow')
        ->callAction('testRun')
        ->assertNotified('Test run started')
        ->assertSee('Checking the source');

    sourceActionsWork();

    $preview = SourceRun::query()->sole();
    $wouldCreate = $preview->preview['would_create'];

    expect($preview->mode)->toBe(RunMode::Preview)
        ->and($preview->status)->toBe(RunStatus::Succeeded)
        ->and($wouldCreate)->toBeGreaterThan(0)
        ->and(EventDraft::query()->count())->toBe(0);

    $page->call('$refresh')
        ->assertSee('Would create')
        ->assertSee("{$wouldCreate} drafts")
        ->assertSee('Beyond 90 days')
        ->assertActionVisible('createDrafts')
        ->callAction('createDrafts')
        ->assertNotified('Drafts are being created');

    expect($source->refresh()->is_active)->toBeTrue();

    sourceActionsWork();

    $activation = SourceRun::query()->where('trigger', RunTrigger::Activation)->sole();

    expect($activation->status)->toBe(RunStatus::Succeeded)
        ->and(EventDraft::query()->count())->toBe($wouldCreate)
        ->and($activation->drafts_created)->toBe($wouldCreate);

    livewire(RunsRelationManager::class, ['ownerRecord' => $source, 'pageClass' => EditSource::class])
        ->assertCanSeeTableRecords([$activation, $preview], inOrder: true);

    // The full pages render (layout, header actions, preview panel, run log).
    get(SourceResource::getUrl('index'))->assertOk()->assertSee('Attleborough Baptist Church');
    get(SourceResource::getUrl('edit', ['record' => $source]))->assertOk()->assertSee('Latest test run')->assertSee('Would create');
});

it('runs an active source now and refuses a second run while one is queued', function () {
    $source = Source::factory()->ics()->active()->create(['url' => SOURCE_ACTIONS_BAPTIST_URL]);

    $page = livewire(EditSource::class, ['record' => $source->getRouteKey()])
        ->assertActionVisible('runNow')
        ->assertActionHidden('createDrafts')
        ->callAction('runNow')
        ->assertNotified('Check started');

    $run = SourceRun::query()->sole();

    expect($run->mode)->toBe(RunMode::Live)
        ->and($run->trigger)->toBe(RunTrigger::Manual)
        ->and($run->status)->toBe(RunStatus::Queued);

    $page->callAction('runNow')
        ->assertNotified('This source is already being checked.')
        ->callAction('testRun')
        ->assertNotified('This source is already being checked.');

    expect(SourceRun::query()->count())->toBe(1);

    sourceActionsWork();

    expect($run->refresh()->status)->toBe(RunStatus::Succeeded)
        ->and(EventDraft::query()->count())->toBeGreaterThan(0);
});

it('polls the preview only while a run is queued or running', function () {
    $source = Source::factory()->ics()->create(['url' => SOURCE_ACTIONS_BAPTIST_URL]);

    $page = livewire(EditSource::class, ['record' => $source->getRouteKey()])
        ->assertDontSeeHtml('wire:poll.3s')
        ->callAction('testRun')
        ->assertSeeHtml('wire:poll.3s');

    sourceActionsWork();

    $page->call('$refresh')->assertDontSeeHtml('wire:poll.3s');
});

it('pauses a live source; Create drafts comes back only after a new test run', function () {
    $source = Source::factory()->ics()->active()->create(['url' => SOURCE_ACTIONS_BAPTIST_URL]);
    $source->forceFill(['next_check_at' => now()->addDay()->utc()])->save();

    // An older succeeded preview, then the live activation run. (ingestLiveRun
    // leaves queued_at to the column default, PostgreSQL's real clock.)
    ingestLiveRun($source, RunMode::Preview)->forceFill(['queued_at' => now()->subHours(2)->utc()])->save();
    ingestLiveRun($source)->forceFill(['queued_at' => now()->subHour()->utc()])->save();

    $page = livewire(EditSource::class, ['record' => $source->getRouteKey()])
        ->assertActionVisible('pause')
        ->callAction('pause')
        ->assertNotified('Source paused');

    $source->refresh();

    expect($source->is_active)->toBeFalse()
        ->and($source->next_check_at)->toBeNull();

    $page->assertActionHidden('pause')
        ->assertActionHidden('runNow')
        ->assertActionHidden('createDrafts')
        ->callAction('testRun');

    sourceActionsWork();

    $page->call('$refresh')->assertActionVisible('createDrafts');
});

it('hides Create drafts after a failed test run', function () {
    $source = Source::factory()->ics()->create(['url' => SOURCE_ACTIONS_BAPTIST_URL]);
    ingestFeed('<html><body>Blocked</body></html>');
    ingestLiveRun($source, RunMode::Preview);

    livewire(EditSource::class, ['record' => $source->getRouteKey()])
        ->assertSee('Test run failed')
        ->assertActionHidden('createDrafts');
});

it('refuses the Sources pages to anyone but the admin', function () {
    $source = Source::factory()->create();
    actingAs(User::factory()->create(['email' => 'someone@example.test']));

    get(SourceResource::getUrl('index'))->assertForbidden();
    get(SourceResource::getUrl('edit', ['record' => $source]))->assertForbidden();
});
