<?php

namespace App\Models;

use App\Enums\ExtractionMethod;
use App\Enums\RunMode;
use App\Enums\RunStatus;
use App\Enums\SourceHealth;
use Carbon\CarbonImmutable;
use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An external site the crawler checks for events (SRC-01).
 *
 * Configuration is edited by the admin (Filament source form). is_active
 * changes only through the "Create drafts" activation and "Pause" actions,
 * so re-enabling always goes through a preview (D-01). Health, scheduling
 * and conditional-GET state is written only by the ingestion pipeline via
 * forceFill (WR-07 pattern).
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $short_name
 * @property string $url
 * @property string|null $events_page_url
 * @property ExtractionMethod $extraction_method
 * @property string|null $adapter
 * @property array<string, mixed> $parser_config
 * @property int|null $default_category_id
 * @property int|null $default_venue_id
 * @property string $location_mode
 * @property string $default_timezone
 * @property string $year_policy
 * @property string|null $legal_basis
 * @property string|null $notes
 * @property bool $supports_missing_detection
 * @property bool $is_active
 * @property string $check_frequency
 * @property string $check_time
 * @property list<array{mode: string, keyword: string}> $title_rules
 * @property bool $is_organiser_source
 * @property SourceHealth $health
 * @property int $consecutive_failures
 * @property string|null $etag
 * @property string|null $last_modified
 * @property CarbonImmutable|null $next_check_at
 * @property CarbonImmutable|null $last_run_at
 * @property CarbonImmutable|null $last_success_at
 * @property int|null $last_items_found
 * @property CarbonImmutable|null $structure_alert_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * Never fillable: is_active, health, consecutive_failures, etag,
 * last_modified, next_check_at, last_run_at, last_success_at,
 * last_items_found, structure_alert_at.
 */
#[Fillable([
    'name', 'slug', 'short_name', 'url', 'events_page_url', 'extraction_method', 'adapter',
    'parser_config', 'default_category_id', 'default_venue_id', 'location_mode',
    'default_timezone', 'year_policy', 'legal_basis', 'notes', 'supports_missing_detection',
    'check_frequency', 'check_time', 'title_rules', 'is_organiser_source',
])]
class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory;

    /**
     * Mirrors the migration defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'parser_config' => '{"identity":"uid"}',
        'location_mode' => 'local',
        'default_timezone' => 'Europe/London',
        'year_policy' => 'none',
        'supports_missing_detection' => false,
        'is_active' => false,
        'check_frequency' => 'daily',
        'check_time' => '05:30:00',
        'title_rules' => '[]',
        'is_organiser_source' => false,
        'health' => 'healthy',
        'consecutive_failures' => 0,
    ];

    /**
     * @return HasMany<SourceRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(SourceRun::class);
    }

    /**
     * The newest live run (previews are not checks): the sources table's
     * "Last run" column and the needs-attention badge.
     *
     * @return HasOne<SourceRun, $this>
     */
    public function latestLiveRun(): HasOne
    {
        return $this->hasOne(SourceRun::class)->ofMany(
            ['id' => 'max'],
            fn (Builder $query) => $query->where('mode', RunMode::Live->value),
        );
    }

    /**
     * True while the source has a queued or running run (the one active slot
     * of source_runs_one_active).
     */
    public function hasActiveRun(): bool
    {
        return $this->runs()->whereIn('status', [RunStatus::Queued->value, RunStatus::Running->value])->exists();
    }

    /**
     * @return HasMany<SourceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SourceItem::class);
    }

    /**
     * @return BelongsTo<Venue, $this>
     */
    public function defaultVenue(): BelongsTo
    {
        return $this->belongsTo(Venue::class, 'default_venue_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function defaultCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'default_category_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'extraction_method' => ExtractionMethod::class,
            'health' => SourceHealth::class,
            'parser_config' => 'array',
            'title_rules' => 'array',
            'supports_missing_detection' => 'boolean',
            'is_active' => 'boolean',
            'is_organiser_source' => 'boolean',
            'consecutive_failures' => 'integer',
            'last_items_found' => 'integer',
            'next_check_at' => 'immutable_datetime',
            'last_run_at' => 'immutable_datetime',
            'last_success_at' => 'immutable_datetime',
            'structure_alert_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
