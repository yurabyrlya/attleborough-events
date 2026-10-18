<?php

namespace App\Filament\Resources\Sources\Actions;

use App\Enums\RunMode;
use App\Enums\RunStatus;
use App\Ingestion\RunAlreadyActive;
use App\Ingestion\RunLauncher;
use App\Models\Source;
use App\Models\SourceRun;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Livewire\Component;

/**
 * The edit page's run buttons (D-01, SRC-03):
 *  - Test run: RunLauncher::testRun, a preview that drafts nothing.
 *  - Create drafts: RunLauncher::createDrafts, visible while the source is
 *    inactive and its latest run is a succeeded test run, so re-enabling a
 *    paused source always goes through a fresh preview.
 *  - Run now: RunLauncher::runNow, visible while the source is live.
 *  - Pause: turns the source off (it is no longer scheduled).
 *
 * Nothing is fetched in the request: the launcher queues FetchSourceJob.
 * RunAlreadyActive (one queued or running run per source) is shown as a
 * plain warning with its own message.
 */
final class SourceActions
{
    /**
     * Livewire event the edit page dispatches after an action, so the runs
     * table re-renders (and starts polling) without a reload.
     */
    public const string RUNS_CHANGED = 'source-runs-changed';

    /**
     * @return list<Action>
     */
    public static function forEditPage(): array
    {
        return [self::testRun(), self::createDrafts(), self::runNow(), self::pause()];
    }

    /**
     * True when "Create drafts" may be offered: the source is inactive and
     * its newest run (queued_at, then id) is a succeeded test run.
     */
    public static function canCreateDrafts(Source $source): bool
    {
        if ($source->is_active) {
            return false;
        }

        $latest = self::latestRun($source);

        return $latest !== null && $latest->mode === RunMode::Preview && $latest->status === RunStatus::Succeeded;
    }

    /**
     * The source's newest run, in run-log order.
     */
    public static function latestRun(Source $source): ?SourceRun
    {
        return $source->runs()->orderByDesc('queued_at')->orderByDesc('id')->first();
    }

    private static function testRun(): Action
    {
        return Action::make('testRun')
            ->label('Test run')
            ->icon(Heroicon::OutlinedBeaker)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Run a test? Nothing is drafted.')
            ->modalDescription('The feed is fetched once and this page shows what a real check would draft and skip.')
            ->modalSubmitActionLabel('Run test')
            ->action(fn (Source $record, Component $livewire) => self::launch(
                fn () => app(RunLauncher::class)->testRun($record),
                'Test run started',
                'The preview appears on this page when the check finishes.',
                $record,
                $livewire,
            ));
    }

    private static function createDrafts(): Action
    {
        return Action::make('createDrafts')
            ->label('Create drafts')
            ->icon(Heroicon::OutlinedPlay)
            ->color('primary')
            ->visible(fn (Source $record): bool => self::canCreateDrafts($record))
            ->requiresConfirmation()
            ->modalHeading(fn (Source $record): string => 'Create drafts and check this source '.($record->check_frequency === 'weekly' ? 'weekly' : 'daily').'?')
            ->modalDescription('The items the test run would draft go to the review queue, and the source is checked on its schedule from now on.')
            ->modalSubmitActionLabel('Create drafts')
            ->action(fn (Source $record, Component $livewire) => self::launch(
                fn () => app(RunLauncher::class)->createDrafts($record),
                'Drafts are being created',
                'They appear in the review queue when the check finishes.',
                $record,
                $livewire,
            ));
    }

    private static function runNow(): Action
    {
        return Action::make('runNow')
            ->label('Run now')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('primary')
            ->visible(fn (Source $record): bool => $record->is_active)
            ->requiresConfirmation()
            ->modalHeading('Check this source now?')
            ->modalDescription('New and changed items become drafts in the review queue.')
            ->modalSubmitActionLabel('Run now')
            ->action(fn (Source $record, Component $livewire) => self::launch(
                fn () => app(RunLauncher::class)->runNow($record),
                'Check started',
                'The run log updates when the check finishes.',
                $record,
                $livewire,
            ));
    }

    private static function pause(): Action
    {
        return Action::make('pause')
            ->label('Pause')
            ->icon(Heroicon::OutlinedPause)
            ->color('gray')
            ->visible(fn (Source $record): bool => $record->is_active)
            ->requiresConfirmation()
            ->modalHeading('Pause this source?')
            ->modalDescription('It is no longer checked. Published events stay as they are. To turn it back on, run a test and click "Create drafts".')
            ->modalSubmitActionLabel('Pause')
            ->action(function (Source $record, Component $livewire): void {
                // A check already queued or running still finishes.
                $record->forceFill(['is_active' => false, 'next_check_at' => null])->save();

                Notification::make()->title('Source paused')->success()->send();
                $livewire->dispatch(self::RUNS_CHANGED);
            });
    }

    /**
     * @param  callable(): SourceRun  $start
     */
    private static function launch(callable $start, string $title, string $body, Source $record, Component $livewire): void
    {
        try {
            $start();
        } catch (RunAlreadyActive $e) {
            Notification::make()->title($e->getMessage())->body('Wait for the current check to finish.')->warning()->send();

            return;
        } finally {
            $record->refresh();
        }

        Notification::make()->title($title)->body($body)->success()->send();
        $livewire->dispatch(self::RUNS_CHANGED);
    }
}
