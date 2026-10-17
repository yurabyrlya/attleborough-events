<?php

namespace App\Review;

use App\Enums\DraftKind;
use App\Enums\EventOrigin;
use App\Enums\ReviewStatus;
use App\Models\Category;
use App\Models\EventDraft;
use App\Review\Data\DraftData;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY writer of event_drafts field data.
 *
 * Every write runs derive(), so derived state (category now; quality score
 * from 02-08) can never drift from the fields it is derived from.
 *
 * create() deliberately has no AdminGate check: public submissions are
 * anonymous (02-05). Review state after creation (approve, reject, reopen)
 * is written only by the review actions in App\Publishing\Actions, which do
 * check the admin.
 */
final class DraftWriter
{
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
                'submitted_category_id' => $data->origin === EventOrigin::Submission ? $data->preferredCategoryId : null,
                'raw_payload' => $data->rawPayload,
                'consented_at' => $data->consentedAt?->utc(),
            ]);

            $this->derive($draft, $data);
            $draft->save();

            return $draft;
        });
    }

    /**
     * Derived state, recomputed on every write. For now: the preferred
     * category when it exists, with a plain-English reason. 02-03 replaces
     * this with CategoryAssigner (keyword rules, Other fallback); 02-08 adds
     * quality scoring.
     */
    private function derive(EventDraft $draft, DraftData $data): void
    {
        if ($data->preferredCategoryId === null || ! Category::query()->whereKey($data->preferredCategoryId)->exists()) {
            return;
        }

        $draft->forceFill([
            'category_id' => $data->preferredCategoryId,
            'category_reason' => match ($data->origin) {
                EventOrigin::Manual => 'Chosen by admin',
                EventOrigin::Submission => 'Chosen by organiser',
                EventOrigin::Discovered => 'Source default',
            },
            'category_flagged' => false,
        ]);
    }
}
