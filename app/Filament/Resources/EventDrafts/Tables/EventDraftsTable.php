<?php

namespace App\Filament\Resources\EventDrafts\Tables;

use App\Filament\Resources\EventDrafts\Actions\ReviewActions;
use App\Filament\Resources\EventDrafts\EventDraftResource;
use App\Models\EventDraft;
use App\Review\ReviewQueue;
use App\Support\LondonFormatter;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EventDraftsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // D-09: the same sort key as ReviewQueue::next()/previous().
            ->defaultSort(
                /** @param Builder<EventDraft> $query */
                fn (Builder $query): Builder => ReviewQueue::applyOrder($query),
            )
            ->columns([
                // Band first, number second (D-22); the band is text, not
                // colour alone.
                TextColumn::make('quality_band')
                    ->label('Quality')
                    ->badge()
                    ->placeholder('Not scored'),
                TextColumn::make('title')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('when')
                    ->label('When')
                    ->state(fn (EventDraft $record): string => LondonFormatter::wallClock($record->start_date, $record->start_time, $record->all_day)),
                TextColumn::make('origin')
                    ->label('Source'),
                TextColumn::make('review_status')
                    ->label('Status')
                    ->badge(),
            ])
            ->recordUrl(fn (EventDraft $record): string => EventDraftResource::getUrl('review', ['record' => $record]))
            // No delete, bulk delete or force delete: drafts are the audit
            // trail behind published events, and rejected drafts purge on
            // schedule (D-12). Every decision goes through the review
            // actions in App\Publishing\Actions.
            ->recordActions(ReviewActions::forTable())
            ->toolbarActions([]);
    }
}
