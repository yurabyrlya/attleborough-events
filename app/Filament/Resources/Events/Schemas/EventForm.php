<?php

namespace App\Filament\Resources\Events\Schemas;

use App\Calendar\ManualRecurrence;
use App\Calendar\RecurrenceExpander;
use App\Enums\PriceType;
use App\Enums\RepeatEnds;
use App\Enums\RepeatPattern;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Throwable;

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
                            ->live()
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
                            // Repeating events are single-day (Phase 1): no end date.
                            ->hidden(fn (Get $get): bool => self::repeats($get) !== RepeatPattern::None)
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

                Section::make('Repeats')
                    ->columns(2)
                    ->schema([
                        Select::make('repeats')
                            ->label('Repeats')
                            ->options(fn (Get $get): array => self::repeatOptions($get))
                            ->default(RepeatPattern::None->value)
                            ->selectablePlaceholder(false)
                            ->live()
                            ->required()
                            ->rules([
                                fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    $start = self::startLocal($get);
                                    $pattern = RepeatPattern::tryFrom((string) $value);

                                    if ($start === null || $pattern === null) {
                                        return;
                                    }

                                    if (! (new ManualRecurrence($pattern))->isValidFor($start)) {
                                        $fail("Pick a repeat option again: it doesn't match the new start date.");
                                    }
                                },
                            ]),
                        Radio::make('repeat_ends')
                            ->label('Ends')
                            ->options([
                                RepeatEnds::Never->value => 'Never',
                                RepeatEnds::OnDate->value => 'On a date',
                                RepeatEnds::AfterCount->value => 'After a number of times',
                            ])
                            ->default(RepeatEnds::Never->value)
                            ->live()
                            ->required()
                            ->visible(fn (Get $get): bool => self::repeats($get) !== RepeatPattern::None),
                        DatePicker::make('repeat_until')
                            ->label('Last date')
                            ->native(false)
                            ->displayFormat('D j M Y')
                            ->live()
                            ->visible(fn (Get $get): bool => self::repeats($get) !== RepeatPattern::None && self::ends($get) === RepeatEnds::OnDate)
                            ->required(fn (Get $get): bool => self::ends($get) === RepeatEnds::OnDate)
                            ->afterOrEqual('start_date')
                            ->validationMessages([
                                'after_or_equal' => 'The last date cannot be before the start date.',
                            ]),
                        TextInput::make('repeat_count')
                            ->label('Number of times')
                            ->helperText('Includes the first date.')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(200)
                            ->live(onBlur: true)
                            ->visible(fn (Get $get): bool => self::repeats($get) !== RepeatPattern::None && self::ends($get) === RepeatEnds::AfterCount)
                            ->required(fn (Get $get): bool => self::ends($get) === RepeatEnds::AfterCount),
                        TextEntry::make('next_dates')
                            ->label('Next dates')
                            ->state(fn (Get $get): array => self::nextDates($get))
                            ->listWithLineBreaks()
                            ->placeholder('No upcoming dates')
                            ->visible(fn (Get $get): bool => self::repeats($get) !== RepeatPattern::None)
                            ->columnSpanFull(),
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

    /**
     * Repeats options computed from the chosen start date (Europe/London).
     *
     * @return array<string, string>
     */
    private static function repeatOptions(Get $get): array
    {
        $start = self::startLocal($get);

        if ($start === null) {
            return [RepeatPattern::None->value => 'Does not repeat'];
        }

        return ManualRecurrence::optionsFor($start);
    }

    private static function repeats(Get $get): RepeatPattern
    {
        $value = $get('repeats');

        if ($value instanceof RepeatPattern) {
            return $value;
        }

        return RepeatPattern::tryFrom((string) $value) ?? RepeatPattern::None;
    }

    private static function ends(Get $get): RepeatEnds
    {
        $value = $get('repeat_ends');

        if ($value instanceof RepeatEnds) {
            return $value;
        }

        return RepeatEnds::tryFrom((string) $value) ?? RepeatEnds::Never;
    }

    /**
     * The first 5 dates of the series being edited, from today over the
     * horizon, formatted "Fri 23 Oct, 10am" or "Sun 25 Oct, all day" (D-05).
     * Formatted inline: LondonFormatter belongs to plan 01-06 in this wave.
     *
     * @return list<string>
     */
    private static function nextDates(Get $get): array
    {
        $start = self::startLocal($get);
        $allDay = (bool) $get('all_day');
        $startTime = self::hhmm($get('start_time'));

        if ($start === null || self::repeats($get) === RepeatPattern::None || (! $allDay && $startTime === '')) {
            return [];
        }

        try {
            $until = $get('repeat_until');
            $count = $get('repeat_count');
            $recurrence = new ManualRecurrence(
                self::repeats($get),
                self::ends($get),
                blank($until) ? null : substr((string) $until, 0, 10),
                blank($count) ? null : (int) $count,
            );

            if (! $recurrence->isValidFor($start)) {
                return [];
            }

            $event = new Event([
                'timezone' => $start->timezoneName,
                'all_day' => $allDay,
                'start_date' => $start->format('Y-m-d'),
                'start_time' => $allDay ? null : $startTime,
                'recurrence_rule' => $recurrence->toRrule($start, $allDay),
            ]);

            $today = CarbonImmutable::now($start->timezone)->startOfDay();
            $keys = app(RecurrenceExpander::class)->instanceDates(
                $event,
                $today,
                $today->addDays((int) config('calendar.horizon_days', 90)),
            );
        } catch (Throwable) {
            return [];
        }

        return array_map(function (string $key) use ($start, $allDay, $startTime): string {
            if ($allDay) {
                return CarbonImmutable::createFromFormat('!Y-m-d', $key, $start->timezone)?->format('D j M').', all day';
            }

            $local = CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$key} {$startTime}", $start->timezone);

            return $local?->format('D j M, ').str_replace(':00', '', (string) $local?->format('g:ia'));
        }, array_slice($keys, 0, 5));
    }

    private static function startLocal(Get $get): ?CarbonImmutable
    {
        $value = substr(trim((string) $get('start_date')), 0, 10);

        if ($value === '') {
            return null;
        }

        try {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $value, (string) config('calendar.timezone', 'Europe/London'));
        } catch (Throwable) {
            return null;
        }

        return $start instanceof CarbonImmutable ? $start : null;
    }

    private static function hhmm(mixed $time): string
    {
        return substr(trim((string) $time), 0, 5);
    }
}
