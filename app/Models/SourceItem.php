<?php

namespace App\Models;

use App\Enums\SourceItemStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One event as a source publishes it, unique per (source_id, external_id).
 * Written only by the ingestion pipeline (SourceItemRecorder,
 * MissingItemDetector) and, for the accepted baseline and review outcome,
 * by the Publishing review actions.
 *
 * The baseline and review state (accepted_hash, accepted_payload,
 * review_outcome, missing_runs, current_draft_id) are never fillable; they
 * are written via forceFill. current_draft_id is cleared by the database
 * when the draft is deleted (nullOnDelete, Pitfall 12). payload is
 * internal only (D-17).
 *
 * @property int $id
 * @property int $source_id
 * @property string $external_id
 * @property SourceItemStatus $status
 * @property string|null $raw_fragment
 * @property string|null $raw_hash
 * @property string|null $content_hash
 * @property array<string, mixed>|null $payload
 * @property string|null $accepted_hash
 * @property array<string, mixed>|null $accepted_payload
 * @property string|null $review_outcome
 * @property CarbonImmutable|null $last_known_start_at
 * @property int|null $first_seen_run_id
 * @property int|null $last_seen_run_id
 * @property CarbonImmutable|null $first_seen_at
 * @property CarbonImmutable|null $last_seen_at
 * @property int $missing_runs
 * @property list<string> $parse_errors
 * @property int|null $current_draft_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'external_id', 'status', 'raw_fragment', 'raw_hash', 'content_hash', 'payload',
    'last_known_start_at', 'first_seen_run_id', 'last_seen_run_id', 'first_seen_at',
    'last_seen_at', 'parse_errors',
])]
class SourceItem extends Model
{
    /**
     * Mirrors the migration defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'missing_runs' => 0,
        'parse_errors' => '[]',
    ];

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * The draft currently tracking this item (pending or last decided).
     *
     * @return BelongsTo<EventDraft, $this>
     */
    public function currentDraft(): BelongsTo
    {
        return $this->belongsTo(EventDraft::class, 'current_draft_id');
    }

    /**
     * @return BelongsTo<SourceRun, $this>
     */
    public function firstSeenRun(): BelongsTo
    {
        return $this->belongsTo(SourceRun::class, 'first_seen_run_id');
    }

    /**
     * @return BelongsTo<SourceRun, $this>
     */
    public function lastSeenRun(): BelongsTo
    {
        return $this->belongsTo(SourceRun::class, 'last_seen_run_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SourceItemStatus::class,
            'payload' => 'array',
            'accepted_payload' => 'array',
            'parse_errors' => 'array',
            'missing_runs' => 'integer',
            'last_known_start_at' => 'immutable_datetime',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
