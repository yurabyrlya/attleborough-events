<?php

namespace App\Filament\Resources\Sources\Schemas;

use App\Enums\ExtractionMethod;
use App\Enums\IdentityStrategy;
use App\Ingestion\Http\SsrfGuard;
use App\Ingestion\Pipeline\TitleRules;
use App\Models\Category;
use App\Models\Source;
use App\Models\Venue;
use Closure;
use DateTimeZone;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;

/**
 * The source form (SRC-01, D-04, D-25). Every configuration column of
 * `sources` is here; is_active, health, scheduling and run counters are not
 * (they move only through the Create drafts / Pause actions and the
 * ingestion pipeline). The item identity is an enum stored in
 * parser_config.identity and title rules are plain keywords: the admin
 * never types a pattern (T-03-42). Feed and events page URLs pass the same
 * SsrfGuard::checkUrl() rule the fetcher applies on every hop (T-03-40).
 *
 * Length limits are characters (Laravel's max rule uses mb_strlen, and
 * PostgreSQL varchar(n) counts characters).
 */
class SourceForm
{
    public const int NAME_MAX_LENGTH = 120;

    public const int SHORT_NAME_MAX_LENGTH = 40;

    public const int URL_MAX_LENGTH = 2048;

    public const int KEYWORD_MAX_LENGTH = 100;

    public const string URL_REFUSED = 'Use an http or https web address on the standard port, without a username or password.';

    /**
     * year_policy values. Phase 4 adds the policies for sites that leave out
     * the year; ICS dates always carry it.
     *
     * @var array<string, string>
     */
    public const array YEAR_POLICIES = [
        'none' => 'Dates include the year',
    ];

    /**
     * location_mode values (sources_location_mode_check).
     *
     * @var array<string, string>
     */
    public const array LOCATION_MODES = [
        'local' => 'Everything it lists is in Attleborough',
        'filter' => 'Keep only items in the Attleborough area',
    ];

    /**
     * check_frequency values (sources_check_frequency_check).
     *
     * @var array<string, string>
     */
    public const array CHECK_FREQUENCIES = [
        'daily' => 'Daily',
        'weekly' => 'Weekly',
    ];

    /**
     * @var array<string, string>
     */
    public const array TITLE_RULE_MODES = [
        TitleRules::MODE_SKIP => 'Skip',
        TitleRules::MODE_KEEP => 'Keep only',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Source')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(self::NAME_MAX_LENGTH),
                        TextInput::make('short_name')
                            ->label('Short name')
                            ->maxLength(self::SHORT_NAME_MAX_LENGTH)
                            ->helperText('Shown next to events from this source. Left empty, the first 40 characters of the name are used.'),
                        TextInput::make('url')
                            ->label('Feed URL')
                            ->required()
                            ->maxLength(self::URL_MAX_LENGTH)
                            ->rule(static fn (): Closure => self::urlRule())
                            ->helperText('The ICS calendar address the crawler fetches.')
                            ->columnSpanFull(),
                        TextInput::make('events_page_url')
                            ->label('Events page URL')
                            ->maxLength(self::URL_MAX_LENGTH)
                            ->rule(static fn (): Closure => self::urlRule())
                            ->helperText('The page people visit to see these events. Optional.')
                            ->columnSpanFull(),
                        Toggle::make('is_organiser_source')
                            ->label("Organiser's own site")
                            ->helperText('When two sources list the same event, this one is credited first.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Reading the feed')
                    ->columns(2)
                    ->schema([
                        Select::make('extraction_method')
                            ->label('Extraction method')
                            ->options([ExtractionMethod::Ics->value => ExtractionMethod::Ics->getLabel()])
                            ->default(ExtractionMethod::Ics->value)
                            ->selectablePlaceholder(false)
                            ->required(),
                        Select::make('adapter')
                            ->label('Adapter')
                            ->options([])
                            ->placeholder('None')
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('ICS feeds need no adapter.'),
                        Select::make('parser_config.identity')
                            ->label('Item identity')
                            ->options(IdentityStrategy::class)
                            ->default(IdentityStrategy::Uid->value)
                            ->selectablePlaceholder(false)
                            ->required()
                            ->helperText('How the same event is recognised from one check to the next. Use "Title and start time" when the feed\'s UIDs change on every fetch.'),
                        Select::make('default_timezone')
                            ->label('Default time zone')
                            ->options(fn (): array => array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers()))
                            ->default('Europe/London')
                            ->searchable()
                            ->required()
                            ->helperText('Used for times the feed gives without a time zone.'),
                        Select::make('year_policy')
                            ->label('Year policy')
                            ->options(self::YEAR_POLICIES)
                            ->default('none')
                            ->selectablePlaceholder(false)
                            ->required(),
                        Select::make('location_mode')
                            ->label('Location')
                            ->options(self::LOCATION_MODES)
                            ->default('local')
                            ->selectablePlaceholder(false)
                            ->required(),
                        Select::make('default_category_id')
                            ->label('Default category')
                            ->options(fn (): array => Category::query()->orderBy('sort')->orderBy('id')->pluck('name', 'id')->all())
                            ->placeholder('Work it out from the title'),
                        Select::make('default_venue_id')
                            ->label('Default venue')
                            ->options(fn (): array => Venue::query()->orderBy('name')->orderBy('id')->pluck('name', 'id')->all())
                            ->searchable()
                            ->placeholder('None')
                            ->helperText('Items that name no venue (or name this one) get this venue, already confirmed.'),
                    ]),
                Section::make('Checks')
                    ->columns(2)
                    ->schema([
                        Select::make('check_frequency')
                            ->label('Check frequency')
                            ->options(self::CHECK_FREQUENCIES)
                            ->default('daily')
                            ->selectablePlaceholder(false)
                            ->required(),
                        TimePicker::make('check_time')
                            ->label('Check time')
                            ->seconds(false)
                            ->default('05:30')
                            ->required()
                            ->helperText('UK time. Each source starts a few minutes after this, so they do not all start at once.'),
                        Toggle::make('supports_missing_detection')
                            ->label('Missing-item detection')
                            ->helperText('Suggest cancelling an approved event when it disappears from two complete checks in a row. Only for feeds that always list every upcoming event.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Title rules')
                    ->description('Skip drops items whose title contains the word. If there is any "Keep only" rule, an item must match one of them. Words match whole words in any case. With no rules every item is kept.')
                    ->schema([
                        Repeater::make('title_rules')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Rule'),
                                TableColumn::make('Keyword'),
                            ])
                            ->schema([
                                Select::make('mode')
                                    ->label('Rule')
                                    ->options(self::TITLE_RULE_MODES)
                                    ->default(TitleRules::MODE_SKIP)
                                    ->selectablePlaceholder(false)
                                    ->required(),
                                TextInput::make('keyword')
                                    ->label('Keyword')
                                    ->required()
                                    ->maxLength(self::KEYWORD_MAX_LENGTH)
                                    ->dehydrateStateUsing(fn (?string $state): ?string => $state === null ? null : trim($state)),
                            ])
                            ->reorderable(false)
                            ->addActionLabel('Add title rule')
                            ->defaultItems(0),
                    ]),
                Section::make('Legal basis and notes')
                    ->schema([
                        Textarea::make('legal_basis')
                            ->label('Legal basis')
                            ->rows(3)
                            ->maxLength(5000)
                            ->helperText('Why we may list these events (for example the robots.txt rules and the site\'s terms).'),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->rows(3)
                            ->maxLength(5000),
                    ]),
            ]);
    }

    /**
     * Form state → `sources` attributes for both create and edit: only the
     * fillable configuration columns (is_active, health and the pipeline
     * columns can never arrive this way), trimmed text, an empty events
     * page URL stored as null, a short name derived from the name when left
     * empty, and title rules as a plain list.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareForSave(array $data): array
    {
        $data = Arr::only($data, (new Source)->getFillable());

        foreach (['name', 'short_name', 'url', 'events_page_url', 'legal_basis', 'notes'] as $key) {
            if (is_string($data[$key] ?? null)) {
                $data[$key] = trim($data[$key]);
            }
        }

        foreach (['events_page_url', 'legal_basis', 'notes'] as $key) {
            if (array_key_exists($key, $data) && blank($data[$key])) {
                $data[$key] = null;
            }
        }

        if (array_key_exists('name', $data) && blank($data['short_name'] ?? null)) {
            $data['short_name'] = rtrim(mb_substr((string) $data['name'], 0, self::SHORT_NAME_MAX_LENGTH));
        }

        if (array_key_exists('title_rules', $data)) {
            $data['title_rules'] = array_values(array_map(
                fn (array $rule): array => ['mode' => (string) $rule['mode'], 'keyword' => (string) $rule['keyword']],
                is_array($data['title_rules']) ? $data['title_rules'] : [],
            ));
        }

        return $data;
    }

    /**
     * The fetcher's URL policy as a validation rule: http/https, port
     * 80/443, no user name or password (SsrfGuard::checkUrl). Empty values
     * are left to `required`.
     */
    public static function urlRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            if (app(SsrfGuard::class)->checkUrl(trim($value)) !== null) {
                $fail(self::URL_REFUSED);
            }
        };
    }
}
