<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One remembered duplicate decision (D-13): a subject (the source item, or
 * a draft with no item) and one candidate (a published event or a pending
 * draft), decided 'duplicate' or 'not_duplicate'.
 *
 * Written only by App\Publishing\Actions\MarkDraftDuplicate and
 * RecordNotDuplicate (through record()), so nothing is fillable.
 *
 * @property int $id
 * @property int|null $source_item_id
 * @property int|null $draft_id
 * @property int|null $candidate_event_id
 * @property int|null $candidate_draft_id
 * @property string $decision
 * @property int $decided_by
 * @property CarbonImmutable $decided_at
 */
class DuplicateDecision extends Model
{
    public const string DUPLICATE = 'duplicate';

    public const string NOT_DUPLICATE = 'not_duplicate';

    public $timestamps = false;

    /**
     * Stores (or overwrites) the decision for one subject/candidate pair.
     * Upserts on the coalesced pair index, so repeating a decision, or
     * changing "not a duplicate" into "duplicate", never fails. Bound
     * parameters only.
     */
    public static function record(
        ?int $sourceItemId,
        ?int $draftId,
        ?int $candidateEventId,
        ?int $candidateDraftId,
        string $decision,
        User $decidedBy,
    ): void {
        static::query()->getConnection()->statement(
            'insert into duplicate_decisions (source_item_id, draft_id, candidate_event_id, candidate_draft_id, decision, decided_by, decided_at)
             values (?, ?, ?, ?, ?, ?, ?)
             on conflict ((coalesce(source_item_id, 0)), (coalesce(draft_id, 0)), (coalesce(candidate_event_id, 0)), (coalesce(candidate_draft_id, 0)))
             do update set decision = excluded.decision, decided_by = excluded.decided_by, decided_at = excluded.decided_at',
            [
                $sourceItemId,
                $sourceItemId === null ? $draftId : null,
                $candidateEventId,
                $candidateEventId === null ? $candidateDraftId : null,
                $decision,
                $decidedBy->id,
                CarbonImmutable::now()->utc()->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * @return BelongsTo<SourceItem, $this>
     */
    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(SourceItem::class);
    }

    /**
     * @return BelongsTo<EventDraft, $this>
     */
    public function draft(): BelongsTo
    {
        return $this->belongsTo(EventDraft::class, 'draft_id');
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function candidateEvent(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'candidate_event_id');
    }

    /**
     * @return BelongsTo<EventDraft, $this>
     */
    public function candidateDraft(): BelongsTo
    {
        return $this->belongsTo(EventDraft::class, 'candidate_draft_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decided_at' => 'immutable_datetime',
        ];
    }
}
