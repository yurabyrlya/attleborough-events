<?php

namespace App\Filament\Resources\Events\Schemas;

use App\Calendar\ManualRecurrence;
use App\Calendar\RecurrenceExpander;
use App\Enums\PriceType;
use App\Enums\RepeatEnds;
use App\Enums\RepeatPattern;
use App\Models\Event;
use App\Models\EventDraft;
use App\Publishing\Actions\UpdatePublishedEvent;
use App\Review\Data\DraftData;
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
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Throwable;

/**
 * Manual event entry form. Its sections are public builders so the draft
 * review form (EventDraftForm) reuses the same UI (D-07).
 *
 * Times are local wall-clock values stored in `time` columns. Never set a
 * Filament global timezone: TimePicker would then convert (Pitfall 3).
 */
class EventForm
{
    /**
     * Display-only Repeats value for a stored rule the form cannot edit
     * (WR-09/WR-10). Not a RepeatPattern: it never reaches
     * PublishableEventData, and the read-only page cannot save.
     */
    public const CUSTOM_RULE_OPTION = 'custom';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                self::whatSection(),
                self::whenSection(),
                self::repeatsSection(),
                self::whereSection(),
                self::priceSection(),
                self::whoSection(),
            ]);
    }

    /**
     * Title, description, category and family-friendly. $configureDescription
     * receives the description Textarea and returns it with extra rules (the
     * review form's discovered-draft limits, VER-01).
     *
     * @param  (Closure(Textarea): Textarea)|null  $configureDescription
     */
    public static function whatSection(?Closure $configureDescription = null): Section
    {
        $description = Textarea::make('description')
            ->required()
            ->rows(5)
            ->maxLength(5000);

        return Section::make('What')
            ->schema([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255),
                $configureDescription === null ? $description : $configureDescription($description),
                Select::make('category_id')
                    ->label('Category')
                    ->relationship('category', 'name', fn ($query) => $query->orderBy('sort'))
                    ->required(),
                Toggle::make('family_friendly')
                    ->label('Family-friendly')
                    ->default(false),
            ]);
    }

    /**
     * All day, start and end (local wall-clock values).
     */
    public static function whenSection(): Section
    {
        return Section::make('When')
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
            ]);
    }

    /**
     * Repeat rule controls, shared by manual entry and draft review (D-06).
     *
     * @param  array<Component>  $leadingComponents  Shown first (the draft form's repeats note)
     */
    public static function repeatsSection(array $leadingComponents = []): Section
    {
        return Section::make('Repeats')
            ->columns(2)
            ->schema([
                ...$leadingComponents,
                Callout::make("This repeat rule can't be edited here")
                    ->description(fn (mixed $record): string => self::uneditableRuleDescription($record))
                    ->warning()
                    ->visible(fn (string $operation, mixed $record): bool => self::hasUneditableRule($operation, $record))
                    ->columnSpanFull(),
                // Edit page mirrors UpdatePublishedEvent's WR-06 guard:
                // an event never switches between one-off and repeating.
                Select::make('repeats')
                    ->label('Repeats')
                    ->options(fn (Get $get, string $operation, mixed $record): array => self::hasUneditableRule($operation, $record)
                        ? [self::CUSTOM_RULE_OPTION => "Custom rule (can't be edited here)"]
                        : self::repeatOptions($get, self::editedEventRepeats($operation, $record)))
                    ->disabled(fn (string $operation, mixed $record): bool => self::editedEventRepeats($operation, $record) === false)
                    ->helperText(fn (string $operation, mixed $record): ?string => self::hasUneditableRule($operation, $record) ? null : match (self::editedEventRepeats($operation, $record)) {
                        false => "To make this repeat, hide it and create a new repeating event. The hidden event's page will stop working and the new event gets its own web address.",
                        true => 'To stop it repeating, set a last date or hide it.',
                        null => null,
                    })
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
                    ->state(fn (Get $get, mixed $record): array => self::nextDates($get, self::excludedDatesOf($record)))
                    ->listWithLineBreaks()
                    ->placeholder('No upcoming dates')
                    ->visible(fn (Get $get): bool => self::repeats($get) !== RepeatPattern::None)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * The venue picker. Manual entry requires it; the draft form relaxes it
     * and pre-fills its create form (D-05).
     *
     * @param  array<Component>  $leadingComponents  Shown first (the draft form's typed venue)
     */
    public static function whereSection(?Select $venueSelect = null, array $leadingComponents = []): Section
    {
        return Section::make('Where')
            ->schema([
                ...$leadingComponents,
                $venueSelect ?? self::venueSelect(),
            ]);
    }

    public static function venueSelect(): Select
    {
        return Select::make('venue_id')
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
            ]);
    }

    /**
     * Price type and details.
     */
    public static function priceSection(): Section
    {
        return Section::make('Price')
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
            ]);
    }

    /**
     * Organiser and source.
     */
    public static function whoSection(): Section
    {
        return Section::make('Who & source')
            ->columns(2)
            ->schema([
                TextInput::make('organiser_name')
                    ->label('Organiser')
                    ->maxLength(255),
                TextInput::make('organiser_contact')
                    ->label('Organiser contact')
                    ->maxLength(255),
                TextInput::make('organiser_website')
                    ->label('Organiser website')
                    ->url()
                    ->maxLength(2048),
                Toggle::make('contact_public')
                    ->label('Show contact publicly')
                    ->helperText("Turn off to keep the organiser's email or phone for admin use only.")
                    ->default(true),
                TextInput::make('source_url')
                    ->label('Source URL')
                    ->url()
                    ->maxLength(2048)
                    ->requiredWithoutAll(['organiser_contact', 'organiser_website'])
                    ->validationMessages([
                        'required_without_all' => 'Add a source URL, an organiser contact or a website.',
                    ]),
                Textarea::make('source_note')
                    ->label('Source note')
                    ->helperText('Where this came from, e.g. "Poster in the library window".')
                    ->rows(2)
                    ->maxLength(500),
            ]);
    }

    /**
     * Repeats options computed from the chosen start date (Europe/London).
     * On the edit page a one-off event keeps only "Does not repeat" and a
     * series loses it (WR-06): see UpdatePublishedEvent.
     *
     * @param  bool|null  $editedEventRepeats  null on the create page
     * @return array<string, string>
     */
    private static function repeatOptions(Get $get, ?bool $editedEventRepeats = null): array
    {
        $none = [RepeatPattern::None->value => 'Does not repeat'];

        if ($editedEventRepeats === false) {
            return $none;
        }

        $start = self::startLocal($get);
        $options = $start === null ? $none : ManualRecurrence::optionsFor($start);

        if ($editedEventRepeats === true) {
            unset($options[RepeatPattern::None->value]);
        }

        return $options;
    }

    /**
     * Whether the event being edited is stored as a series: true for a series,
     * false for a one-off event, null when creating.
     *
     * Null for drafts too: WR-06 protects a published event's occurrence
     * UIDs, and a draft has none yet, so the admin may add a rule to a
     * one-off draft (D-06) or remove one.
     */
    private static function editedEventRepeats(string $operation, mixed $record): ?bool
    {
        if ($operation !== 'edit' || ! $record instanceof Event) {
            return null;
        }

        return $record->recurrence_rule !== null;
    }

    /**
     * True on an edit page whose record holds a stored rule the form cannot
     * represent. For events UpdatePublishedEvent::canEdit() is the single
     * check, so the form, EditEvent and the events table cannot disagree;
     * for drafts DraftData::storedRecurrence(), the check fromReviewForm()
     * uses to keep such a rule on save (Pitfall 11).
     */
    public static function hasUneditableRule(string $operation, mixed $record): bool
    {
        if ($operation !== 'edit') {
            return false;
        }

        if ($record instanceof Event) {
            return $record->recurrence_rule !== null && ! UpdatePublishedEvent::canEdit($record);
        }

        if ($record instanceof EventDraft) {
            return $record->recurrence_rule !== null && DraftData::storedRecurrence($record) === null;
        }

        return false;
    }

    /**
     * Plain string only: Callout escapes the description (stored rules may
     * come from imports).
     */
    private static function uneditableRuleDescription(mixed $record): string
    {
        if ($record instanceof EventDraft) {
            return 'It came from the organiser or a source and needs a different rule type. Stored rule: '.$record->recurrence_rule.'.';
        }

        return 'Stored rule: '.($record instanceof Event ? (string) $record->recurrence_rule : '').'. You can still hide or cancel the event with the buttons at the top of the page.';
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
     * The record's excluded dates (EXDATE, D-19) are skipped, as the
     * published series skips them.
     *
     * @param  list<string>  $excludedDates
     * @return list<string>
     */
    private static function nextDates(Get $get, array $excludedDates = []): array
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
                'excluded_dates' => $excludedDates,
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

    /**
     * The excluded dates of the event or draft being edited (none on create).
     *
     * @return list<string>
     */
    private static function excludedDatesOf(mixed $record): array
    {
        if (! $record instanceof Event && ! $record instanceof EventDraft) {
            return [];
        }

        return array_values(array_filter($record->excluded_dates, is_string(...)));
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
