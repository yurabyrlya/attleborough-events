<?php

namespace App\Models;

use App\Enums\DraftKind;
use App\Enums\EventOrigin;
use App\Enums\ExtractionMethod;
use App\Enums\PriceType;
use App\Enums\QualityBand;
use App\Enums\RejectReason;
use App\Enums\ReviewStatus;
use Carbon\CarbonImmutable;
use Database\Factories\EventDraftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A proposed event waiting for (or past) admin review: the only path to
 * publication (REV-02). Holds the LOCAL wall-clock schedule like Event, but
 * every schedule and venue field may be empty (D-01).
 *
 * Field data is written only by App\Review\DraftWriter; review state only by
 * the review actions in App\Publishing\Actions (ApproveDraft, RejectDraft,
 * ReopenDraft).
 *
 * @property int $id
 * @property string $reference
 * @property EventOrigin $origin
 * @property DraftKind $kind
 * @property ReviewStatus $review_status
 * @property ExtractionMethod $extraction_method
 * @property int|null $event_id
 * @property int|null $duplicate_of_event_id
 * @property string $title
 * @property string|null $description
 * @property int|null $category_id
 * @property string|null $category_reason
 * @property bool $category_flagged
 * @property int|null $submitted_category_id
 * @property int|null $venue_id
 * @property string|null $venue_name
 * @property string|null $venue_address
 * @property string|null $venue_postcode
 * @property string|null $venue_town
 * @property string $timezone
 * @property bool $all_day
 * @property CarbonImmutable|null $start_date
 * @property string|null $start_time
 * @property CarbonImmutable|null $end_date
 * @property string|null $end_time
 * @property string|null $recurrence_rule
 * @property string|null $repeats_text
 * @property bool $repeats_acknowledged
 * @property bool $family_friendly
 * @property PriceType $price_type
 * @property string|null $price_text
 * @property string|null $organiser_name
 * @property string|null $organiser_contact
 * @property string|null $organiser_website
 * @property bool $contact_public
 * @property string|null $source_url
 * @property string|null $source_note
 * @property array<string, mixed>|null $raw_payload
 * @property CarbonImmutable|null $consented_at
 * @property int|null $quality_score
 * @property QualityBand|null $quality_band
 * @property list<string> $quality_reasons
 * @property list<string> $parse_errors
 * @property RejectReason|null $reject_reason
 * @property string|null $review_notes
 * @property int|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property int|null $source_id
 * @property int|null $source_item_id
 * @property string|null $target_recurrence_key
 * @property array<string, mixed>|null $diff
 * @property CarbonImmutable|null $relevant_starts_at
 * @property int|null $duplicate_of_draft_id
 * @property string|null $duplicate_reason
 * @property string|null $duplicate_score
 * @property list<string> $excluded_dates
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * Review state is never fillable: reference, origin, kind, review_status,
 * extraction_method, category_id, category_reason, category_flagged,
 * submitted_category_id, quality_*, parse_errors, reject_reason,
 * review_notes, reviewed_by, reviewed_at, event_id, duplicate_of_event_id,
 * raw_payload and consented_at are written only via forceFill by DraftWriter
 * and the Publishing review actions (WR-07 pattern). The ingestion links
 * (source_id, source_item_id, target_recurrence_key, diff,
 * relevant_starts_at, duplicate_of_draft_id, duplicate_reason,
 * duplicate_score) are written only via forceFill by DraftWriter's
 * source-aware methods. excluded_dates (ICS EXDATE London dates, D-19) is
 * content and fillable.
 */
#[Fillable([
    'title', 'description', 'venue_id', 'venue_name', 'venue_address', 'venue_postcode',
    'venue_town', 'timezone', 'all_day', 'start_date', 'start_time', 'end_date', 'end_time',
    'recurrence_rule', 'repeats_text', 'repeats_acknowledged', 'family_friendly', 'price_type',
    'price_text', 'organiser_name', 'organiser_contact', 'organiser_website', 'contact_public',
    'source_url', 'source_note', 'excluded_dates',
])]
class EventDraft extends Model
{
    /** @use HasFactory<EventDraftFactory> */
    use HasFactory;

    use MassPrunable;

    /**
     * Mirrors the migration defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => 'new',
        'review_status' => 'pending_review',
        'category_flagged' => false,
        'timezone' => 'Europe/London',
        'all_day' => false,
        'repeats_acknowledged' => false,
        'family_friendly' => false,
        'price_type' => 'unknown',
        'contact_public' => false,
        'quality_reasons' => '[]',
        'parse_errors' => '[]',
        'excluded_dates' => '[]',
    ];

    /**
     * The event this draft published (approved drafts) or proposes to change.
     *
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'duplicate_of_event_id');
    }

    /**
     * The source an ingested draft came from.
     *
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * The source item an ingested draft proposes (or changes).
     *
     * @return BelongsTo<SourceItem, $this>
     */
    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(SourceItem::class);
    }

    /**
     * A pending draft this one may duplicate (url or fuzzy match).
     *
     * @return BelongsTo<EventDraft, $this>
     */
    public function duplicateOfDraft(): BelongsTo
    {
        return $this->belongsTo(EventDraft::class, 'duplicate_of_draft_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * The category the organiser picked on the public form (kept for review).
     *
     * @return BelongsTo<Category, $this>
     */
    public function submittedCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'submitted_category_id');
    }

    /**
     * @return BelongsTo<Venue, $this>
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Retention for rejected drafts (D-12, published on /privacy): hard-delete
     * a rejected draft review.retention_days.spam days after rejection when
     * the reason is spam, review.retention_days.default days otherwise.
     * Run daily by `model:prune` (routes/console.php).
     *
     * One-way: purged personal data cannot be recovered. Only rejected drafts
     * are ever matched; pending and approved drafts (the audit trail behind
     * published events) are never pruned. Cutoffs are UTC instants compared
     * with the UTC reviewed_at column.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $now = CarbonImmutable::now()->utc();
        $spamCutoff = $now->subDays((int) config('review.retention_days.spam'));
        $defaultCutoff = $now->subDays((int) config('review.retention_days.default'));

        return static::query()
            ->where('review_status', ReviewStatus::Rejected)
            ->whereNotNull('reviewed_at')
            ->where(function (Builder $query) use ($spamCutoff, $defaultCutoff): void {
                $query
                    ->where(function (Builder $spam) use ($spamCutoff): void {
                        $spam->where('reject_reason', RejectReason::Spam)
                            ->where('reviewed_at', '<', $spamCutoff);
                    })
                    ->orWhere('reviewed_at', '<', $defaultCutoff);
            });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'origin' => EventOrigin::class,
            'kind' => DraftKind::class,
            'review_status' => ReviewStatus::class,
            'extraction_method' => ExtractionMethod::class,
            'price_type' => PriceType::class,
            'category_flagged' => 'boolean',
            'all_day' => 'boolean',
            'repeats_acknowledged' => 'boolean',
            'family_friendly' => 'boolean',
            'contact_public' => 'boolean',
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'quality_score' => 'integer',
            'quality_band' => QualityBand::class,
            'quality_reasons' => 'array',
            'parse_errors' => 'array',
            'reject_reason' => RejectReason::class,
            'raw_payload' => 'array',
            'consented_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'diff' => 'array',
            'excluded_dates' => 'array',
            'relevant_starts_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
