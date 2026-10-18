<?php

use App\Enums\RunMode;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Enums\SourceHealth;
use App\Filament\Resources\Sources\Pages\EditSource;
use App\Filament\Resources\Sources\Pages\ListSources;
use App\Filament\Resources\Sources\RelationManagers\RunsRelationManager;
use App\Filament\Resources\Sources\SourceResource;
use App\Models\Source;
use App\Models\SourceRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

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
