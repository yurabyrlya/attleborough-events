<?php

namespace App\Publishing\Actions;

use App\Calendar\ManualRecurrence;
use App\Enums\DraftKind;
use App\Enums\PriceType;
use App\Enums\ReviewStatus;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\SourceItem;
use App\Models\User;
use App\Publishing\AdminGate;
use App\Publishing\Data\PublishableEventData;
use App\Publishing\SourceBaseline;
use App\Review\Exceptions\DraftNotPublishable;
use App\Review\Exceptions\InvalidReviewTransition;
use App\Review\VenueMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Applies a source update draft to its published event (REV-06, D-07).
 *
 * Apply takes ALL changed fields the admin saw in the draft's diff
 * ({field: {published, before, now}}, 03-09) and nothing else: the form
 * state starts from PublishableEventData::fromEvent, so the admin's title,
 * description, category and every other field not in the diff keep their
 * published values. The write goes through UpdatePublishedEvent (the only
 * updater of event content), which re-syncs occurrences; its refusals
 * (one-off ↔ repeating, a rule the form cannot hold) become
 * "This change can't be applied automatically: …" and Ignore stays
 * available.
 *
 * Diff keys with no published counterpart (D-19: moved_overrides and
 * extra_dates; and cancelled, whose un-cancel is the admin's Reinstate on
 * the event, not an automatic write) are not applied; the baseline still
 * moves past them.
 *
 * A venue change arrives as feed text: it applies only with the venue the
 * admin picked on the update draft (venue_id) or an exact VenueMatcher
 * match; otherwise Apply is refused with "Pick the venue for this change
 * first." and nothing changes. A draft carrying parse errors (a D-19 shape
 * such as a moved override on a linked series) is refused too.
 *
 * Admin-only, checked before the transaction. In ONE transaction: the
 * draft is re-read FOR UPDATE, the transition and kind are checked on that
 * row, then event (FOR UPDATE, and again inside UpdatePublishedEvent) →
 * occurrences (sync) → source item last, whose baseline moves to the
 * source's current payload. Ids-only log after commit.
 */
final class ApplySourceUpdate
{
    public const string VENUE_NEEDED = 'Pick the venue for this change first.';

    public const string NOT_AUTOMATIC = "This change can't be applied automatically: ";

    public const string WRONG_KIND = 'Only source update drafts can be applied.';

    /** Diff keys only the baseline moves past (no published field to write). */
    public const array BASELINE_ONLY = ['cancelled', 'moved_overrides', 'extra_dates'];

    public function __construct(
        private UpdatePublishedEvent $update,
        private VenueMatcher $venues,
        private SourceBaseline $baseline,
    ) {}

    /**
     * @throws AuthorizationException when the reviewer is not the configured admin
     * @throws InvalidReviewTransition when the draft is no longer pending review
     * @throws DraftNotPublishable when the change cannot be applied (kind, venue, parse error, refusal)
     */
    public function __invoke(EventDraft $draft, User $reviewer): Event
    {
        // Defence in depth beside the panel gate (T-03-28).
        AdminGate::authorize($reviewer, 'Only the admin can apply source changes.');

        [$applied, $event] = DB::transaction(function () use ($draft, $reviewer): array {
            $locked = EventDraft::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();

            $locked->review_status->assertCanTransitionTo(ReviewStatus::Approved);

            if ($locked->kind !== DraftKind::Update) {
                throw new DraftNotPublishable([self::WRONG_KIND]);
            }

            $parseErrors = array_values(array_filter($locked->parse_errors ?? [], is_string(...)));

            if ($parseErrors !== []) {
                throw new DraftNotPublishable([self::NOT_AUTOMATIC.$parseErrors[0]]);
            }

            if ($locked->event_id === null) {
                throw new DraftNotPublishable([self::NOT_AUTOMATIC.'the published event is gone.']);
            }

            $event = Event::query()->whereKey($locked->event_id)->lockForUpdate()->firstOrFail();

            try {
                $data = $this->publishableData($locked, $event);
                $updated = ($this->update)($event, $data, $reviewer);
            } catch (InvalidArgumentException $e) {
                throw new DraftNotPublishable([self::NOT_AUTOMATIC.$e->getMessage()]);
            }

            $locked->forceFill([
                'review_status' => ReviewStatus::Approved,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now()->utc(),
            ])->save();

            if ($locked->source_item_id !== null) {
                $item = SourceItem::query()->whereKey($locked->source_item_id)->lockForUpdate()->first();

                if ($item !== null) {
                    $this->baseline->moveToCurrent($item);
                }
            }

            return [$locked, $updated];
        });

        // Ids only: never titles or contact details. After the outermost
        // commit, so a rolled-back apply is never logged.
        DB::afterCommit(fn () => Log::info('source update applied', [
            'draft_id' => $applied->id,
            'event_id' => $event->id,
            'user_id' => $reviewer->id,
        ]));

        return $event;
    }

    /**
     * "Published now" as form state with each applicable diff field
     * replaced by its 'now' value.
     *
     * @throws DraftNotPublishable when a venue change has no venue yet
     * @throws InvalidArgumentException when the result is not publishable
     */
    private function publishableData(EventDraft $draft, Event $event): PublishableEventData
    {
        $form = PublishableEventData::fromEvent($event);
        $diff = $draft->diff ?? [];
        $excludedDates = null;
        $rule = null;
        $ruleChanged = false;

        foreach ($diff as $field => $change) {
            if (! is_array($change) || ! array_key_exists('now', $change) || in_array($field, self::BASELINE_ONLY, true)) {
                continue;
            }

            $now = $change['now'];

            switch ($field) {
                case 'title':
                    $form['title'] = (string) $now;
                    break;
                case 'all_day':
                case 'family_friendly':
                    $form[$field] = (bool) $now;
                    break;
                case 'start_date':
                case 'end_date':
                case 'start_time':
                case 'end_time':
                    $form[$field] = is_string($now) && $now !== '' ? $now : null;
                    break;
                case 'venue':
                    $form['venue_id'] = $this->venueId($draft);
                    break;
                case 'price_type':
                    $form['price_type'] = PriceType::tryFrom((string) $now) ?? PriceType::Unknown;
                    break;
                case 'rrule':
                    $ruleChanged = true;
                    $rule = is_string($now) && $now !== '' ? $now : null;
                    break;
                case 'excluded_dates':
                    $excludedDates = is_array($now) ? array_values(array_filter($now, is_string(...))) : [];
                    break;
            }
        }

        if ($ruleChanged) {
            $form = array_merge($form, $this->recurrenceFormState($rule, $form, $event->timezone));
        }

        $data = PublishableEventData::fromForm($form);

        return $excludedDates === null ? $data : $data->withExcludedDates($excludedDates);
    }

    /**
     * The feed's new rule as the form's Repeats/Ends state, checked against
     * the (possibly new) start date exactly as publishing checks it (WR-10).
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    private function recurrenceFormState(?string $rule, array $form, string $timezone): array
    {
        $allDay = (bool) ($form['all_day'] ?? false);
        $time = $allDay ? '00:00' : substr((string) ($form['start_time'] ?? '00:00'), 0, 5);
        $startLocal = CarbonImmutable::createFromFormat('!Y-m-d H:i', substr((string) $form['start_date'], 0, 10).' '.$time, $timezone);

        $recurrence = $startLocal instanceof CarbonImmutable
            ? ManualRecurrence::tryFromStoredRule($rule, $startLocal, $allDay)
            : null;

        if ($recurrence === null) {
            throw new InvalidArgumentException("This repeat rule can't be published from the form.");
        }

        return $recurrence->toFormState();
    }

    /**
     * The admin's pick on the update draft first, else an exact match for
     * the feed's venue text; never a guess.
     */
    private function venueId(EventDraft $draft): int
    {
        if ($draft->venue_id !== null) {
            return $draft->venue_id;
        }

        $venue = $this->venues->exact($draft->venue_name, $draft->venue_postcode);

        if ($venue === null) {
            throw new DraftNotPublishable([self::VENUE_NEEDED]);
        }

        return $venue->id;
    }
}
