<?php

namespace App\Filament\Resources\Events\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['category', 'nextOccurrence']))
            ->defaultSort('start_date', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category.name')
                    ->label('Category'),
                TextColumn::make('nextOccurrence.starts_at')
                    ->label('Next occurrence')
                    ->dateTime('D j M Y, H:i')
                    ->timezone('Europe/London')
                    ->placeholder('None upcoming'),
                TextColumn::make('status')
                    ->badge(),
            ])
            ->filters([
                //
            ])
            // No edit/delete here: updates arrive through UpdatePublishedEvent (plan 01-04)
            // so no Eloquent write path bypasses App\Publishing\Actions.
            ->recordActions([])
            ->toolbarActions([]);
    }
}
