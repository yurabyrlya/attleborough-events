<?php

namespace App\Models;

use App\Enums\RunMode;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One check of one source (SRC-07). Written only by the ingestion pipeline
 * (RunLauncher, FetchSourceJob, IngestRun, the ingest:dispatch-due janitor).
 * Only mode and trigger are fillable; status, failure details, counters and
 * instants are written via forceFill. The source_runs_one_active index
 * refuses a second queued or running run per source (SRC-04).
 *
 * @property int $id
 * @property int $source_id
 * @property RunMode $mode
 * @property RunTrigger $trigger
 * @property RunStatus $status
 * @property string|null $failure_kind
 * @property int|null $http_status
 * @property string|null $final_url
 * @property int|null $bytes
 * @property int|null $duration_ms
 * @property string|null $raw_path
 * @property string|null $raw_sha256
 * @property int $items_found
 * @property int $items_new
 * @property int $items_changed
 * @property int $items_unchanged
 * @property int $items_cancelled
 * @property int $items_missing
 * @property int $skipped_past
 * @property int $skipped_far_future
 * @property int $skipped_rule
 * @property int $skipped_parse_error
 * @property int $skipped_out_of_area
 * @property int $drafts_created
 * @property int $drafts_replaced
 * @property CarbonImmutable|null $observed_from
 * @property CarbonImmutable|null $observed_until
 * @property bool $is_complete
 * @property bool $structure_alert
 * @property string|null $error_message
 * @property array<string, mixed>|null $preview
 * @property CarbonImmutable $queued_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['mode', 'trigger'])]
class SourceRun extends Model
{
    /**
     * The counters every run reports (SRC-07), all defaulting to 0.
     */
    public const array COUNTERS = [
        'items_found', 'items_new', 'items_changed', 'items_unchanged', 'items_cancelled', 'items_missing',
        'skipped_past', 'skipped_far_future', 'skipped_rule', 'skipped_parse_error', 'skipped_out_of_area',
        'drafts_created', 'drafts_replaced',
    ];

    /**
     * Mirrors the migration defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'queued',
        'items_found' => 0,
        'items_new' => 0,
        'items_changed' => 0,
        'items_unchanged' => 0,
        'items_cancelled' => 0,
        'items_missing' => 0,
        'skipped_past' => 0,
        'skipped_far_future' => 0,
        'skipped_rule' => 0,
        'skipped_parse_error' => 0,
        'skipped_out_of_area' => 0,
        'drafts_created' => 0,
        'drafts_replaced' => 0,
        'is_complete' => false,
        'structure_alert' => false,
    ];

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        $counters = array_fill_keys(self::COUNTERS, 'integer');

        return [
            ...$counters,
            'mode' => RunMode::class,
            'trigger' => RunTrigger::class,
            'status' => RunStatus::class,
            'http_status' => 'integer',
            'bytes' => 'integer',
            'duration_ms' => 'integer',
            'observed_from' => 'immutable_date',
            'observed_until' => 'immutable_date',
            'is_complete' => 'boolean',
            'structure_alert' => 'boolean',
            'preview' => 'array',
            'queued_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
