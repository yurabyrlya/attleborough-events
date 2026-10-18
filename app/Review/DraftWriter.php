<?php

namespace App\Review;

use App\Enums\DraftKind;
use App\Enums\EventOrigin;
use App\Enums\ReviewStatus;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\Source;
use App\Models\SourceItem;
use App\Review\Data\DraftData;
use App\Review\Exceptions\InvalidReviewTransition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY writer of event_drafts field data.
 *
 * Every write runs derive(), so derived state (category and quality score)
 * can never drift from the fields it is derived from.
 *
 * create() deliberately has no AdminGate check: public submissions are
 * anonymous (02-05). createFromSource() has none either: ingestion is a
 * system writer (the queued FetchSourceJob, 03-05), like submissions, and
 * only ever creates pending drafts. update() is reached only from admin pages and actions
 * that already passed the panel gate (the review edit page, 02-14). Review
 * state after creation (approve, reject, reopen) is written only by the
 * review actions in App\Publishing\Actions, which do check the admin. The
 * one exception is supersedeFromSource(): ingestion retires its own pending
 * draft when the source went back to the baseline or the item was
 * cancelled or went missing (03-09); it never touches a decided draft.
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
     * A draft for a source item (ingestion, 03-05), in one transaction with
     * the item's current_draft_id link. The ingestion links, kind, raw
     * payload and parse errors are forceFilled (never Fillable). The
     * item's ICS CATEGORIES ($sourceValues) reach CategoryAssigner's
     * mapping link after the source's default category (PIPE-03).
     *
     * @param  list<string>  $sourceValues
     */
    public function createFromSource(DraftData $data, DraftKind $kind, Source $source, SourceItem $item, array $sourceValues): EventDraft
    {
        return $this->createSourced($data, $kind, $source, $item, $sourceValues);
    }

    /**
     * An update or cancellation draft for the event a source item is linked
     * to (03-09, PIPE-06/07): event_id is the linked event, diff the
     * three-column change (or the cancelled dates / status), the target key
     * the earliest cancelled date, relevant_starts_at the UTC instant that
     * orders the queue (D-09). When the venue is not part of the change,
     * venue_id is the event's venue, so the draft shows what will stay.
     *
     * @param  array<string, mixed>  $diff
     * @param  list<string>  $sourceValues
     */
    public function createChangeDraft(
        DraftData $data,
        DraftKind $kind,
        Source $source,
        SourceItem $item,
        Event $event,
        array $diff,
        ?string $targetRecurrenceKey,
        ?CarbonImmutable $relevantStartsAt,
        array $sourceValues,
    ): EventDraft {
        if (! array_key_exists('venue', $diff)) {
            $data = $data->with(['venueId' => $event->venue_id]);
        }

        return $this->createSourced($data, $kind, $source, $item, $sourceValues, [
            'event_id' => $event->id,
            'diff' => $diff,
            'target_recurrence_key' => $targetRecurrenceKey,
            'relevant_starts_at' => $relevantStartsAt?->utc(),
        ]);
    }

    /**
     * Rewrites a pending ingested draft with the source's new values (one
     * pending draft per item, PIPE-06). Returns null, writing nothing, when
     * the locked draft is no longer pending (decided meanwhile): the caller
     * then raises a new draft instead of overwriting a decision.
     *
     * Pitfall 3: the admin's work survives. venue_id stays while the feed
     * names the same venue as before; a category whose reason is "Chosen by
     * admin" stays; a description that differs from the excerpt last written
     * by the source (auto_excerpt_hash in raw_payload; unknown counts as
     * edited) stays. Everything else is the source's, and the category and
     * quality are re-derived. Kind, origin and links are unchanged; for an
     * update or cancellation draft, diff, target key and relevant_starts_at
     * are replaced.
     *
     * @param  list<string>  $sourceValues
     * @param  array<string, mixed>|null  $diff
     */
    public function replacePendingFromSource(
        EventDraft $draft,
        DraftData $data,
        array $sourceValues,
        ?array $diff = null,
        ?string $targetRecurrenceKey = null,
        ?CarbonImmutable $relevantStartsAt = null,
    ): ?EventDraft {
        return DB::transaction(function () use ($draft, $data, $sourceValues, $diff, $targetRecurrenceKey, $relevantStartsAt): ?EventDraft {
            $locked = EventDraft::query()->whereKey($draft->id)->lockForUpdate()->first();

            if ($locked === null || $locked->review_status !== ReviewStatus::PendingReview) {
                return null;
            }

            $keep = [];

            if ($locked->venue_id !== null
                && self::sameText($locked->venue_name, $data->venueName)
                && self::sameText($locked->venue_address, $data->venueAddress)) {
                $keep['venueId'] = $locked->venue_id;
            }

            if ($locked->category_reason === DraftData::ADMIN_CATEGORY_REASON && $locked->category_id !== null) {
                $keep['preferredCategoryId'] = $locked->category_id;
                $keep['categoryChosenByAdmin'] = true;
            }

            $autoHash = $locked->raw_payload['auto_excerpt_hash'] ?? null;

            if (! is_string($autoHash) || ! hash_equals($autoHash, self::excerptHash($locked->description))) {
                $keep['description'] = $locked->description;
            }

            // The excerpt the source would write, hashed even when the
            // admin's description is kept: the next replace compares to it.
            $sourceExcerpt = $data->description;
            $data = $data->with($keep);

            $locked->fill($data->toAttributes());
            $locked->forceFill([
                'raw_payload' => self::withExcerptHash($data->rawPayload, $sourceExcerpt),
                'parse_errors' => $data->parseErrors,
            ]);

            if ($locked->kind !== DraftKind::New) {
                $locked->forceFill([
                    'diff' => $diff,
                    'target_recurrence_key' => $targetRecurrenceKey,
                    'relevant_starts_at' => $relevantStartsAt?->utc(),
                ]);
            }

            $this->derive($locked, $data, $sourceValues);
            $locked->save();

            return $locked;
        });
    }

    /**
     * Marks a pending ingested draft superseded (03-09): the source went
     * back to the baseline, or the item a new draft proposes was cancelled
     * or went missing. A system decision like ingestion itself, so no
     * reviewer. False, writing nothing, when the draft is no longer pending.
     */
    public function supersedeFromSource(EventDraft $draft): bool
    {
        return DB::transaction(function () use ($draft): bool {
            $locked = EventDraft::query()->whereKey($draft->id)->lockForUpdate()->first();

            if ($locked === null || ! $locked->review_status->canTransitionTo(ReviewStatus::Superseded)) {
                return false;
            }

            $locked->forceFill([
                'review_status' => ReviewStatus::Superseded,
                'reviewed_at' => now()->utc(),
            ])->save();

            return true;
        });
    }

    /**
     * @param  list<string>  $sourceValues
     * @param  array<string, mixed>  $links
     */
    private function createSourced(DraftData $data, DraftKind $kind, Source $source, SourceItem $item, array $sourceValues, array $links = []): EventDraft
    {
        return DB::transaction(function () use ($data, $kind, $source, $item, $sourceValues, $links): EventDraft {
            $draft = new EventDraft($data->toAttributes());
            $draft->forceFill([
                'reference' => ReferenceCode::unique(),
                'origin' => $data->origin,
                'kind' => $kind,
                'review_status' => ReviewStatus::PendingReview,
                'extraction_method' => $data->extractionMethod,
                'raw_payload' => self::withExcerptHash($data->rawPayload, $data->description),
                'parse_errors' => $data->parseErrors,
                'source_id' => $source->id,
                'source_item_id' => $item->id,
                ...$links,
            ]);

            $this->derive($draft, $data, $sourceValues);
            $draft->save();

            $item->forceFill(['current_draft_id' => $draft->id])->save();

            return $draft;
        });
    }

    /**
     * raw_payload with the hash of the excerpt the source wrote, so a later
     * replace can tell an admin-edited description from its own (Pitfall 3).
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private static function withExcerptHash(?array $payload, ?string $excerpt): array
    {
        return [...($payload ?? []), 'auto_excerpt_hash' => self::excerptHash($excerpt)];
    }

    private static function excerptHash(?string $description): string
    {
        return hash('sha256', $description ?? '');
    }

    private static function sameText(?string $a, ?string $b): bool
    {
        return CategoryAssigner::normalise($a ?? '') === CategoryAssigner::normalise($b ?? '');
    }

    /**
     * An ingested draft's ICS CATEGORIES (kept in raw_payload), so a review
     * save re-derives the category from the same inputs it was created with.
     *
     * @return list<string>
     */
    private static function storedSourceValues(EventDraft $draft): array
    {
        $categories = $draft->source_item_id !== null ? ($draft->raw_payload['categories'] ?? []) : [];

        return is_array($categories) ? array_values(array_filter($categories, is_string(...))) : [];
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
            $this->derive($locked, $data, self::storedSourceValues($locked));
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
     * venue_id is set only by the organiser's or admin's pick (D-05, D-21),
     * or for ingested drafts by the source's admin-set default venue (D-22,
     * DraftData::fromSourceItem()).
     *
     * @param  list<string>  $sourceValues  the source's own category labels
     */
    private function derive(EventDraft $draft, DraftData $data, array $sourceValues = []): void
    {
        // The admin's pick on the review form reads "Chosen by admin" for
        // any origin; the draft's own origin is never rewritten.
        $chooser = $data->categoryChosenByAdmin ? EventOrigin::Manual : $data->origin;
        // A discovered draft's source can have its own mappings (EXT-01);
        // manual and submitted drafts have no source, so global ones only.
        $decision = $this->categories->assign($data->preferredCategoryId, $chooser, $sourceValues, $draft->title, $draft->source_id);

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
