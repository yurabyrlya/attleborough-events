<?php

namespace App\Filament\Resources\Events\RelationManagers;

use App\Enums\OccurrenceStatus;
use App\Filament\Resources\Events\Actions\StatusActions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Livewire\Attributes\On;

/**
 * A series' generated dates. Rows are written only by OccurrenceGenerator;
 * the only mutations offered are the status record actions (cancel / hide /
 * restore), which delegate to ChangeOccurrenceStatus. Read-only otherwise:
 * no create, edit or delete.
 */
class OccurrencesRelationManager extends RelationManager
{
    /**
     * Browser event EditEvent dispatches after a successful save, so this
     * table shows the regenerated times without a page reload.
     */
    public const REFRESH_EVENT = 'event-occurrences-saved';

    protected static string $relationship = 'occurrences';

    protected static ?string $title = 'Occurrences';

    protected static ?string $recordTitleAttribute = 'recurrence_key';

    public function isReadOnly(): bool
    {
        return true;
    }

    /**
     * Re-renders the table after the event is saved on the edit page; the
     * rows are re-queried on render. Writes nothing.
     */
    #[On(self::REFRESH_EVENT)]
    public function refreshOccurrences(): void {}

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
            ->filters([
                SelectFilter::make('status')
                    ->options(OccurrenceStatus::class),
            ])
            ->headerActions([])
            ->recordActions(StatusActions::forOccurrence())
            ->toolbarActions([]);
    }
}
