<?php

namespace App\Filament\Resources\Sources\RelationManagers;

use App\Enums\FetchFailureKind;
use App\Enums\RunStatus;
use App\Filament\Resources\Sources\Actions\SourceActions;
use App\Models\Source;
use App\Models\SourceRun;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

/**
 * The run log (SRC-07): every run of the source, newest first (queued_at
 * desc, then id desc), with its counters and error message. Read-only:
 * runs are written by the ingestion pipeline only. Polls every 3 seconds
 * while the source has a queued or running run, and not at all otherwise
 * (RESEARCH Pitfall 13). Instants are shown in Europe/London.
 */
class RunsRelationManager extends RelationManager
{
    protected static string $relationship = 'runs';

    protected static ?string $title = 'Run log';

    public function isReadOnly(): bool
    {
        return true;
    }

    /**
     * Re-renders after a Test run / Create drafts / Run now / Pause on the
     * edit page, so a new run shows (and polling starts) without a reload.
     */
    #[On(SourceActions::RUNS_CHANGED)]
    public function refreshRuns(): void {}

    public function hasActiveRun(): bool
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof Source && $owner->hasActiveRun();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->reorder()->orderByDesc('queued_at')->orderByDesc('id'))
            ->poll(fn (): ?string => $this->hasActiveRun() ? '3s' : null)
            ->columns([
                TextColumn::make('queued_at')
                    ->label('Queued')
                    ->dateTime('D j M Y, H:i')
                    ->timezone('Europe/London'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('failure_kind')
                    ->label('Problem')
                    ->formatStateUsing(fn (?string $state): string => FetchFailureKind::tryFrom((string) $state)?->getLabel() ?? '')
                    ->placeholder('None'),
                TextColumn::make('trigger')
                    ->label('Started by'),
                TextColumn::make('mode')
                    ->label('Mode'),
                TextColumn::make('duration')
                    ->label('Duration')
                    ->state(fn (SourceRun $record): ?string => self::duration($record))
                    ->placeholder('—'),
                TextColumn::make('items_found')->label('Found')->numeric(),
                TextColumn::make('items_new')->label('New')->numeric(),
                TextColumn::make('items_changed')->label('Changed')->numeric(),
                TextColumn::make('items_unchanged')->label('Unchanged')->numeric(),
                TextColumn::make('items_cancelled')->label('Cancelled')->numeric(),
                TextColumn::make('items_missing')->label('Missing')->numeric(),
                TextColumn::make('skipped_past')->label('Skipped: past')->numeric(),
                TextColumn::make('skipped_far_future')
                    ->label(fn (): string => 'Skipped: beyond '.(int) config('calendar.horizon_days', 90).' days')
                    ->numeric(),
                TextColumn::make('skipped_rule')->label('Skipped: title rule')->numeric(),
                TextColumn::make('skipped_parse_error')->label('Skipped: parse error')->numeric(),
                TextColumn::make('skipped_out_of_area')->label('Skipped: out of area')->numeric(),
                TextColumn::make('drafts_created')->label('Drafts created')->numeric(),
                TextColumn::make('drafts_replaced')->label('Drafts replaced')->numeric(),
                TextColumn::make('error_message')
                    ->label('Error')
                    ->wrap()
                    ->placeholder('None'),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('No runs yet')
            ->emptyStateDescription('Runs appear here after a test run or a check.');
    }

    /**
     * "850 ms" / "12.4 s" / "3 min 5 s" for finished runs; "Running" while
     * running; null while queued.
     */
    public static function duration(SourceRun $run): ?string
    {
        if ($run->status === RunStatus::Running) {
            return 'Running';
        }

        $ms = $run->duration_ms;

        if ($ms === null && $run->started_at !== null && $run->finished_at !== null) {
            $ms = (int) $run->started_at->diffInMilliseconds($run->finished_at, true);
        }

        if ($ms === null) {
            return null;
        }

        return match (true) {
            $ms < 1000 => $ms.' ms',
            $ms < 60_000 => number_format($ms / 1000, 1).' s',
            default => intdiv($ms, 60_000).' min '.intdiv($ms % 60_000, 1000).' s',
        };
    }
}
