<?php

namespace App\Filament\Resources\Events\Tables;

use App\Calendar\ManualRecurrence;
use App\Enums\EventStatus;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

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
                TextColumn::make('repeats')
                    ->label('Repeats')
                    ->state(fn (Event $record): string => self::repeatsLabel($record)),
                TextColumn::make('status')
                    ->badge(),
            ])
            ->filters([
                // Lets the admin find hidden and cancelled events to show or
                // reinstate them. No default: every status is listed.
                SelectFilter::make('status')
                    ->options(EventStatus::class),
            ])
            // Edit opens EditEvent, which saves through UpdatePublishedEvent.
            // No delete, bulk delete or force delete (ADM-03): hiding is the
            // only way to take an event off the public calendar, and no
            // Eloquent write path bypasses App\Publishing\Actions.
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }

    private static function repeatsLabel(Event $event): string
    {
        if ($event->recurrence_rule === null) {
            return 'One-off';
        }

        try {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $event->start_date->format('Y-m-d'), $event->timezone);

            return $start instanceof CarbonImmutable
                ? ManualRecurrence::fromRrule($event->recurrence_rule, $event->all_day)->label($start)
                : 'Repeats';
        } catch (Throwable) {
            return 'Repeats';
        }
    }
}
