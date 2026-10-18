<?php

namespace App\Filament\Resources\Sources\Tables;

use App\Enums\SourceHealth;
use App\Models\Source;
use App\Support\LondonFormatter;
use Carbon\CarbonImmutable;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Source health at a glance (SRC-08): health badge, active flag, the last
 * live run's status ("Blocked by robots.txt" for blocked runs), structure
 * alert, last success and next check in Europe/London. No delete: a source
 * with runs and items is paused, not removed.
 */
class SourcesTable
{
    public const string STRUCTURE_ALERT = 'Possible structure change';

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('latestLiveRun'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('health')
                    ->label('Health')
                    ->badge(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('latestLiveRun.status')
                    ->label('Last run')
                    ->badge()
                    ->placeholder('Not run yet'),
                TextColumn::make('structure_alert_at')
                    ->label('Structure')
                    ->formatStateUsing(fn (): string => self::STRUCTURE_ALERT)
                    ->badge()
                    ->color('warning')
                    ->placeholder('OK'),
                TextColumn::make('last_success_at')
                    ->label('Last success')
                    ->timezone('Europe/London')
                    ->formatStateUsing(fn (?CarbonImmutable $state): string => self::londonInstant($state))
                    ->placeholder('Never')
                    ->sortable(),
                TextColumn::make('next_check_at')
                    ->label('Next check')
                    ->timezone('Europe/London')
                    ->formatStateUsing(fn (?CarbonImmutable $state, Source $record): string => $record->is_active ? self::londonInstant($state) : 'Paused')
                    ->placeholder(fn (Source $record): string => $record->is_active ? 'Soon' : 'Not scheduled')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active'),
                SelectFilter::make('health')
                    ->label('Health')
                    ->options(SourceHealth::class),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('No sources yet')
            ->emptyStateDescription('Add an ICS feed with "New source", run a test, then click "Create drafts".');
    }

    /**
     * "Sun 18 Oct, 5:42am" in London time (year added outside this year).
     */
    public static function londonInstant(?CarbonImmutable $instant): string
    {
        if ($instant === null) {
            return '';
        }

        return LondonFormatter::shortDate($instant).', '.LondonFormatter::time($instant);
    }
}
