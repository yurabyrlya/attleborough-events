<?php

namespace App\Filament\Resources\Events\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only list of a series' generated dates. Rows are written only by
 * OccurrenceGenerator; plan 01-05 adds Cancel/Hide/Restore record actions.
 */
class OccurrencesRelationManager extends RelationManager
{
    protected static string $relationship = 'occurrences';

    protected static ?string $title = 'Occurrences';

    protected static ?string $recordTitleAttribute = 'recurrence_key';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at', 'asc')
            ->columns([
                TextColumn::make('starts_at')
                    ->label('Starts')
                    ->dateTime('D j M Y, H:i')
                    ->timezone('Europe/London')
                    ->sortable(),
                TextColumn::make('recurrence_key')
                    ->label('Date key'),
                TextColumn::make('status')
                    ->badge(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
