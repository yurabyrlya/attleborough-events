<?php

namespace App\Review;

use App\Enums\DraftKind;
use App\Enums\QualityBand;
use App\Models\EventDraft;
use App\Publishing\Data\PublishableEventData;
use InvalidArgumentException;

/**
 * Whether a draft can be published, and if not, why.
 *
 * The single check behind the A key, the Approve button, bulk approve and
 * ApproveDraft, so they cannot disagree (D-10). blockers() gives the
 * plain-English reasons a single approve shows; bulkBlockerKeys() gives
 * bulk approve's stricter skip reasons (D-06, D-21), most specific first.
 */
final class DraftPublishability
{
    /**
     * Bulk skip reasons, in the fixed order bulkBlockerKeys() reports them.
     * already_reviewed and not_pending are reported by bulk approve itself
     * (a draft decided between page load and click).
     */
    private const SKIP_REASONS = [
        'parse_errors' => 'has parse errors',
        'duplicate' => 'possible duplicate',
        'low_quality' => 'Low quality',
        'unresolved_venue' => 'no confirmed venue',
        'repeats_unset' => 'repeat rule not set',
        'end_time_same_as_start' => 'end time same as start time',
        'blocked' => 'not ready to publish',
        'already_reviewed' => 'already reviewed',
        'not_pending' => 'already reviewed',
        // Bulk reject: a source update or cancellation is applied, confirmed
        // or ignored on its own, so its baseline moves (03-13).
        'source_change' => 'source change (use Apply, Confirm or Ignore)',
    ];

    /**
     * Blockers that bulk approve reports under a specific key, so they never
     * also count as "blocked".
     */
    private const SPECIFIC_BULK_BLOCKERS = ['no_venue', 'repeats_unacknowledged', 'end_time_same_as_start'];

    /**
     * What stops a single approve, in a fixed order; empty when the draft can
     * be published.
     *
     * @return list<string>
     */
    public function blockers(EventDraft $draft): array
    {
        return array_values($this->blockerMessages($draft));
    }

    /**
     * Why bulk approve skips this draft, every applicable key in the order
     * parse_errors, duplicate, low_quality, unresolved_venue, repeats_unset,
     * end_time_same_as_start, blocked; empty when bulk approve may take it.
     * Bulk approve reports the first key, so each skipped draft is counted
     * once.
     *
     * Stricter than blockers(): bulk needs a High or Medium score (unscored
     * counts as Low), a confirmed venue even when an exact match is suggested
     * (D-21), and a repeat rule whenever the organiser says it repeats, even
     * if acknowledged (D-06).
     *
     * @return list<string>
     */
    public function bulkBlockerKeys(EventDraft $draft): array
    {
        $keys = [];

        if (! empty($draft->parse_errors)) {
            $keys[] = 'parse_errors';
        }

        // PIPE-05: a likely duplicate of a published event or of another
        // pending draft.
        if ($draft->duplicate_of_event_id !== null || $draft->duplicate_of_draft_id !== null) {
            $keys[] = 'duplicate';
        }

        if ($draft->quality_band === null || $draft->quality_band === QualityBand::Low) {
            $keys[] = 'low_quality';
        }

        if ($draft->venue_id === null) {
            $keys[] = 'unresolved_venue';
        }

        if (self::filled($draft->repeats_text) && $draft->recurrence_rule === null) {
            $keys[] = 'repeats_unset';
        }

        if (self::endTimeIsStartTime($draft)) {
            $keys[] = 'end_time_same_as_start';
        }

        $otherBlockers = array_diff(array_keys($this->blockerMessages($draft)), self::SPECIFIC_BULK_BLOCKERS);

        if ($otherBlockers !== []) {
            $keys[] = 'blocked';
        }

        return $keys;
    }

    /**
     * The bulk approve result line for one skip reason, e.g.
     * "2 skipped: no confirmed venue".
     */
    public static function skipMessage(string $key, int $count): string
    {
        if (! array_key_exists($key, self::SKIP_REASONS)) {
            throw new InvalidArgumentException("Unknown skip reason [{$key}].");
        }

        return "{$count} skipped: ".self::SKIP_REASONS[$key];
    }

    /**
     * Blocker id => message. The PublishableEventData invariants (multi-day
     * repeat, a rule that does not fit the start date or that the form
     * cannot represent, bad dates or times) are only checked once every
     * field-level blocker is cleared, so the admin fixes the obvious gaps
     * first and never sees the same gap twice.
     *
     * @return array<string, string>
     */
    private function blockerMessages(EventDraft $draft): array
    {
        $blockers = [];

        if ($draft->kind !== DraftKind::New) {
            $blockers['kind'] = 'Only new-event drafts can be approved here.';
        }

        if ($draft->venue_id === null) {
            $blockers['no_venue'] = 'Pick or create a venue.';
        }

        if ($draft->category_id === null) {
            $blockers['no_category'] = 'Pick a category.';
        }

        if (! self::filled($draft->title)) {
            $blockers['no_title'] = 'Add a title.';
        }

        if (! self::filled($draft->description)) {
            $blockers['no_description'] = 'Add a description.';
        }

        if ($draft->start_date === null) {
            $blockers['no_start_date'] = 'Add a start date.';
        }

        if (! $draft->all_day && ! self::filled($draft->start_time)) {
            $blockers['no_start_time'] = 'Add a start time.';
        }

        if (self::endTimeIsStartTime($draft)) {
            $blockers['end_time_same_as_start'] = 'The end time must be different from the start time.';
        }

        if (! self::filled($draft->source_url) && ! self::filled($draft->organiser_contact) && ! self::filled($draft->organiser_website)) {
            $blockers['no_contact'] = 'Add a source URL, an organiser contact or a website.';
        }

        if (self::filled($draft->repeats_text) && $draft->recurrence_rule === null && ! $draft->repeats_acknowledged) {
            $blockers['repeats_unacknowledged'] = "The organiser says it repeats. Set a repeat rule, or tick 'No repeat rule needed'.";
        }

        if ($blockers === []) {
            try {
                PublishableEventData::fromDraft($draft);
            } catch (InvalidArgumentException $e) {
                $blockers['invariant'] = $e->getMessage();
            }
        }

        return $blockers;
    }

    private static function filled(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }

    /**
     * Review CR-02: a same-day end time equal to the start time. EventTiming
     * reads end <= start as overnight, so it would publish as a 24-hour
     * event. Mirrors the event form's rule and PublishableEventData.
     */
    private static function endTimeIsStartTime(EventDraft $draft): bool
    {
        if ($draft->all_day || $draft->start_date === null || ! self::filled($draft->start_time) || ! self::filled($draft->end_time)) {
            return false;
        }

        $sameDay = $draft->end_date === null || $draft->end_date->isSameDay($draft->start_date);

        return $sameDay && PublishableEventData::sameClockTime((string) $draft->start_time, (string) $draft->end_time);
    }
}
