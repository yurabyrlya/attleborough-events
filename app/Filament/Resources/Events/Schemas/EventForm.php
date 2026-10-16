<?php

namespace App\Filament\Resources\Events\Schemas;

use App\Enums\PriceType;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
                        Toggle::make('family_friendly')
                            ->label('Family-friendly')
                            ->default(false),
                    ]),

                Section::make('When')
                    ->columns(2)
                    ->schema([
                        Toggle::make('all_day')
                            ->label('All day')
                            ->default(false)
                            ->live()
                            ->columnSpanFull(),
                        DatePicker::make('start_date')
                            ->label('Start date')
                            ->native(false)
                            ->displayFormat('D j M Y')
                            ->required(),
                        TimePicker::make('start_time')
                            ->label('Start time')
                            ->seconds(false)
                            ->hidden(fn (Get $get): bool => (bool) $get('all_day'))
                            ->required(fn (Get $get): bool => ! $get('all_day')),
                        DatePicker::make('end_date')
                            ->label('End date')
                            ->helperText('Only for events that run over several days.')
                            ->native(false)
                            ->displayFormat('D j M Y')
                            ->afterOrEqual('start_date')
                            ->validationMessages([
                                'after_or_equal' => 'The end date cannot be before the start date.',
                            ]),
                        TimePicker::make('end_time')
                            ->label('End time')
                            ->helperText('An end time earlier than the start means it finishes after midnight.')
                            ->seconds(false)
                            ->hidden(fn (Get $get): bool => (bool) $get('all_day'))
                            ->rules([
                                fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    if (blank($value) || blank($get('start_time'))) {
                                        return;
                                    }

                                    $endDate = $get('end_date');
                                    $sameDay = blank($endDate) || (string) $endDate === (string) $get('start_date');

                                    if ($sameDay && self::hhmm($value) === self::hhmm($get('start_time'))) {
                                        $fail('The end time must be different from the start time.');
                                    }
                                },
                            ]),
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

                Section::make('Price')
                    ->columns(2)
                    ->schema([
                        Radio::make('price_type')
                            ->label('Price')
                            ->options(PriceType::class)
                            ->default(PriceType::Unknown->value)
                            ->required(),
                        TextInput::make('price_text')
                            ->label('Price details')
                            ->placeholder('e.g. £5, under-12s free')
                            ->maxLength(60),
                    ]),

                Section::make('Who & source')
                    ->columns(2)
                    ->schema([
                        TextInput::make('organiser_name')
                            ->label('Organiser')
                            ->maxLength(255),
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
                        Textarea::make('source_note')
                            ->label('Source note')
                            ->helperText('Where this came from, e.g. "Poster in the library window".')
                            ->rows(2)
                            ->maxLength(500),
                    ]),
            ]);
    }

    private static function hhmm(mixed $time): string
    {
        return substr(trim((string) $time), 0, 5);
    }
}
