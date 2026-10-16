<?php

namespace App\Filament\Resources\Events\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Manual event entry form.
 *
 * Times are local wall-clock values stored in `time` columns. Never set a
 * Filament global timezone: TimePicker would then convert (Pitfall 3).
 */
class EventForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('What')
                    ->schema([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255),
                        Textarea::make('description')
                            ->required()
                            ->rows(5)
                            ->maxLength(5000),
                        Select::make('category_id')
                            ->label('Category')
                            ->relationship('category', 'name', fn ($query) => $query->orderBy('sort'))
                            ->required(),
                    ]),

                Section::make('When')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('start_date')
                            ->label('Date')
                            ->native(false)
                            ->displayFormat('D j M Y')
                            ->required(),
                        TimePicker::make('start_time')
                            ->label('Start time')
                            ->seconds(false)
                            ->required(),
                    ]),

                Section::make('Where')
                    ->schema([
                        Select::make('venue_id')
                            ->label('Venue')
                            ->relationship('venue', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->createOptionForm([
                                TextInput::make('name')->required()->maxLength(255),
                                TextInput::make('address')->required()->maxLength(255),
                                TextInput::make('postcode')->required()->maxLength(10),
                                TextInput::make('town')->required()->maxLength(100)->default('Attleborough'),
                            ]),
                    ]),

                Section::make('Who & source')
                    ->columns(2)
                    ->schema([
                        TextInput::make('organiser_contact')
                            ->label('Organiser contact')
                            ->maxLength(255),
                        TextInput::make('source_url')
                            ->label('Source URL')
                            ->url()
                            ->maxLength(2048)
                            ->requiredWithout('organiser_contact')
                            ->validationMessages([
                                'required_without' => 'Add a source URL or an organiser contact.',
                            ]),
                    ]),
            ]);
    }
}
