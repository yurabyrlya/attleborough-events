<?php

namespace App\Review;

use App\Enums\DraftKind;
use App\Enums\EventOrigin;
use App\Enums\ReviewStatus;
use App\Models\Category;
use App\Models\EventDraft;
use App\Review\Data\DraftData;
use App\Review\Exceptions\InvalidReviewTransition;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY writer of event_drafts field data.
 *
 * Every write runs derive(), so derived state (category and quality score)
 * can never drift from the fields it is derived from.
 *
 * create() deliberately has no AdminGate check: public submissions are
 * anonymous (02-05). update() is reached only from admin pages and actions
 * that already passed the panel gate (the review edit page, 02-14). Review
 * state after creation (approve, reject, reopen) is written only by the
 * review actions in App\Publishing\Actions, which do check the admin.
 */
final class DraftWriter
{
    public function __construct(
        private CategoryAssigner $categories,
        private VenueMatcher $venues,
        private QualityScorer $quality,
    ) {}

    public function create(DraftData $data): EventDraft
    {
        return DB::transaction(function () use ($data): EventDraft {
            $draft = new EventDraft($data->toAttributes());
            $draft->forceFill([
                'reference' => ReferenceCode::unique(),
                'origin' => $data->origin,
                'kind' => DraftKind::New,
                'review_status' => ReviewStatus::PendingReview,
                'extraction_method' => $data->extractionMethod,
                'submitted_category_id' => $this->submittedCategoryId($data),
                'raw_payload' => $data->rawPayload,
                'consented_at' => $data->consentedAt?->utc(),
            ]);

            $this->derive($draft, $data);
            $draft->save();

            return $draft;
        });
    }

    /**
     * The organiser's category pick, kept for review. Only an existing
     * category: a stale or tampered id is dropped (the draft then falls back
     * to Other) instead of failing the submission on the foreign key.
     */
    private function submittedCategoryId(DraftData $data): ?int
    {
        if ($data->origin !== EventOrigin::Submission || $data->preferredCategoryId === null) {
            return null;
        }

        return Category::query()->whereKey($data->preferredCategoryId)->exists() ? $data->preferredCategoryId : null;
    }

    /**
     * The admin's fix to a pending draft's content (e.g. picking the venue),
     * then derived state recomputed. Refuses a draft that is no longer
     * pending, checked on the locked row so a stale edit page cannot rewrite
     * a decided draft. Review state is untouched.
     *
     * @throws InvalidReviewTransition when the draft is no longer pending review
     */
    public function update(EventDraft $draft, DraftData $data): EventDraft
    {
        return DB::transaction(function () use ($draft, $data): EventDraft {
            $locked = EventDraft::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();

            if ($locked->review_status !== ReviewStatus::PendingReview) {
                throw new InvalidReviewTransition($locked->review_status, ReviewStatus::PendingReview);
            }

            $locked->fill($data->toAttributes());
            $this->derive($locked, $data);
            $locked->save();

            return $locked->refresh();
        });
    }

    /**
     * Derived state, recomputed on every write: the category
     * (CategoryAssigner's PIPE-03 chain), then the quality score, band and
     * reasons (QualityScorer, PIPE-04), so fixing the venue or adding an end
     * time during review lifts the score on save.
     *
     * A venue counts as recognised when venue_id is set or the typed name,
     * alias or postcode exactly matches a known venue. Matching is read-only:
     * venue_id is set only by the organiser's or admin's pick (D-05, D-21).
     */
    private function derive(EventDraft $draft, DraftData $data): void
    {
        $decision = $this->categories->assign($data->preferredCategoryId, $data->origin, [], $draft->title);

        $draft->forceFill([
            'category_id' => $decision->categoryId,
            'category_reason' => $decision->reason,
            'category_flagged' => $decision->flagged,
        ]);

        $venueRecognised = $draft->venue_id !== null
            || $this->venues->exact($draft->venue_name, $draft->venue_postcode) !== null;

        $result = $this->quality->score($draft, $venueRecognised, $decision->flagged);

        $draft->forceFill([
            'quality_score' => $result->score,
            'quality_band' => $result->band,
            'quality_reasons' => $result->reasons,
        ]);
    }
}
