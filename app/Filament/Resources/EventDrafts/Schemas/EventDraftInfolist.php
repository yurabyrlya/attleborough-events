<?php

namespace App\Filament\Resources\EventDrafts\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The review page's draft detail (REV-01). Every entry is escaped text:
 * organiser text is never rendered as HTML (T-02-33).
 */
class EventDraftInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Summary')
                    ->columns(2)
                    ->schema([
                        // Band first, number second (D-22).
                        TextEntry::make('quality_band')
                            ->label('Quality')
                            ->badge()
                            ->placeholder('Not scored'),
                        TextEntry::make('quality_score')
                            ->label('Score')
                            ->formatStateUsing(fn (int $state): string => "{$state}/100")
                            ->placeholder('Not scored'),
                        TextEntry::make('quality_reasons')
                            ->label('What to check')
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('Nothing to fix')
                            ->columnSpanFull(),
                    ]),
                Section::make('What')
                    ->schema([
                        TextEntry::make('title')
                            ->extraAttributes(['class' => 'break-words']),
                        TextEntry::make('description')
                            ->placeholder('No description')
                            ->extraAttributes(['class' => 'whitespace-pre-line break-words']),
                    ]),
            ]);
    }
}
