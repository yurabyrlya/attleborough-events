<?php

namespace App\Ingestion\Pipeline;

use App\Calendar\EventTiming;
use App\Enums\DraftKind;
use App\Enums\EventStatus;
use App\Ingestion\Data\ChangeVerdict;
use App\Ingestion\Data\NormalisedFacts;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\EventOccurrence;
use App\Models\EventSource;
use App\Models\SourceItem;
use App\Publishing\Data\PublishableEventData;
use App\Review\CategoryAssigner;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Decides what a run does with a known feed item (03-09, RESEARCH Pattern 4
 * and the source item lifecycle; PIPE-06, PIPE-07, PIPE-08, D-06, D-11,
 * D-23). Reads only; SourceItemRecorder acts on the verdict.
 *
 * - A pending NEW draft is replaced in place when the D-06 facts changed,
 *   and superseded when the item is now cancelled (nothing was published).
 * - A linked item (event_sources, approved or duplicate, D-11) is compared
 *   with its accepted baseline: master STATUS:CANCELLED newly true → whole
 *   event cancellation; newly cancelled override dates from today on →
 *   one cancellation listing them; other D-06 differences → an update
 *   with the three-column diff. A cancellation comes first: a fact change
 *   in the same snapshot is raised by a later run once the cancellation is
 *   decided (only the cancelled part of the baseline moves, 03-10).
 * - With a pending update or cancellation: the same proposal again → none;
 *   a different update → replace; a cancellation replaces a pending update;
 *   an update never replaces a pending cancellation; back to the baseline
 *   → supersede.
 * - Not linked: a rejected item stays rejected until its D-06 facts differ
 *   from the baseline set at rejection (→ redraft, D-23); an undecided item
 *   without a pending draft gets a new one unless it is cancelled (a
 *   cancelled item nobody approved needs no draft, PIPE-07).
 *
 * diff values are display/apply values: times "H:i", dates "Y-m-d", venue
 * "name, address", lists as stored. "published" is what the event shows
 * now (PublishableEventData::fromEvent), "before" the accepted baseline,
 * "now" the feed.
 */
final class ChangeDetector
{
    /** D-06 facts an update draft can list, in their fixed order. */
    public const array FIELDS = [
        'title', 'all_day', 'start_date', 'start_time', 'end_date', 'end_time', 'venue',
        'price_type', 'family_friendly', 'cancelled', 'rrule', 'excluded_dates',
        'moved_overrides', 'extra_dates',
    ];

    private const string LONDON = 'Europe/London';

    public function __construct(private readonly EventTiming $timing) {}

    public function detect(
        SourceItem $item,
        NormalisedFacts $facts,
        string $contentHash,
        ?EventDraft $pending,
        ?EventSource $link,
        ?CarbonImmutable $nowLondon = null,
    ): ChangeVerdict {
        $now = ($nowLondon ?? CarbonImmutable::now())->setTimezone(self::LONDON);

        if ($pending !== null && $pending->kind === DraftKind::New) {
            if ($facts->cancelled) {
                return new ChangeVerdict(ChangeVerdict::SUPERSEDE);
            }

            return $contentHash !== $item->content_hash
                ? new ChangeVerdict(ChangeVerdict::REPLACE)
                : ChangeVerdict::none();
        }

        $event = $link?->event;

        if ($event instanceof Event) {
            return $this->withPending($this->linked($item, $facts, $contentHash, $event, $now), $pending);
        }

        if ($pending !== null) {
            // An update/cancellation whose event link is gone: leave it to the admin.
            return ChangeVerdict::none();
        }

        if ($facts->cancelled) {
            return ChangeVerdict::none();
        }

        if ($item->review_outcome === 'rejected') {
            if ($item->accepted_hash === $contentHash) {
                return new ChangeVerdict(ChangeVerdict::QUIET);
            }

            $baseline = self::baseline($item);

            return $baseline !== null && self::changedFields($baseline, $facts) === []
                ? ChangeVerdict::none()
                : new ChangeVerdict(ChangeVerdict::REDRAFT);
        }

        if (in_array($item->review_outcome, ['approved', 'duplicate'], true)) {
            return ChangeVerdict::none();
        }

        return new ChangeVerdict(ChangeVerdict::REDRAFT);
    }

    /**
     * PIPE-08: the item reached two consecutive complete runs without being
     * listed. A linked item gets a whole-event cancellation (status active →
     * missing); a pending new draft is superseded; a pending cancellation
     * already says it.
     */
    public function missing(SourceItem $item, ?EventDraft $pending, ?EventSource $link): ChangeVerdict
    {
        if ($pending !== null && $pending->kind === DraftKind::New) {
            return new ChangeVerdict(ChangeVerdict::SUPERSEDE);
        }

        $event = $link?->event;

        if (! $event instanceof Event || $pending?->kind === DraftKind::Cancellation) {
            return ChangeVerdict::none();
        }

        return new ChangeVerdict(
            ChangeVerdict::CANCEL,
            diff: ['status' => ['published' => $event->status->value, 'before' => 'active', 'now' => 'missing']],
            relevantStartsAt: $this->nextOccurrenceStart($event) ?? $item->last_known_start_at?->utc(),
        );
    }

    /**
     * The D-06 fields whose baseline and feed values differ, in FIELDS
     * order, with their comparable values (title and venue compared as the
     * content hash normalises them).
     *
     * @return list<string>
     */
    public static function changedFields(NormalisedFacts $before, NormalisedFacts $now): array
    {
        $beforeValues = self::values($before);
        $nowValues = self::values($now);
        $changed = [];

        foreach (self::FIELDS as $field) {
            if (self::comparable($field, $beforeValues[$field]) !== self::comparable($field, $nowValues[$field])) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /**
     * The D-06 facts as diff values.
     *
     * @return array<string, mixed>
     */
    public static function values(NormalisedFacts $facts): array
    {
        $venue = trim(implode(', ', array_filter([$facts->venueName, $facts->venueAddress], fn (?string $part): bool => $part !== null && $part !== '')));

        return [
            'title' => $facts->title,
            'all_day' => $facts->allDay,
            'start_date' => $facts->startDate,
            'start_time' => self::clock($facts->startTime),
            'end_date' => $facts->endDate,
            'end_time' => self::clock($facts->endTime),
            'venue' => $venue === '' ? null : $venue,
            'price_type' => $facts->priceType->value,
            'family_friendly' => $facts->familyFriendly,
            'cancelled' => $facts->cancelled,
            'rrule' => $facts->rrule,
            'excluded_dates' => $facts->excludedDates,
            'moved_overrides' => $facts->movedOverrides,
            'extra_dates' => $facts->extraDates,
        ];
    }

    /**
     * What the published event shows for each D-06 field.
     *
     * @return array<string, mixed>
     */
    public static function published(Event $event): array
    {
        $form = PublishableEventData::fromEvent($event);

        return [
            'title' => $event->title,
            'all_day' => $event->all_day,
            'start_date' => $form['start_date'],
            'start_time' => self::clock($event->start_time),
            'end_date' => $form['end_date'],
            'end_time' => self::clock($event->end_time),
            'venue' => $event->venue?->name,
            'price_type' => $event->price_type->value,
            'family_friendly' => $event->family_friendly,
            'cancelled' => $event->status === EventStatus::Cancelled,
            'rrule' => $event->recurrence_rule,
            'excluded_dates' => $event->excluded_dates,
            // Events cannot hold moved dates or RDATE extras (D-19).
            'moved_overrides' => [],
            'extra_dates' => [],
        ];
    }

    private function linked(SourceItem $item, NormalisedFacts $facts, string $contentHash, Event $event, CarbonImmutable $now): ChangeVerdict
    {
        if ($item->accepted_hash === $contentHash) {
            return new ChangeVerdict(ChangeVerdict::QUIET);
        }

        $baseline = self::baseline($item);

        if ($baseline === null) {
            return ChangeVerdict::none();
        }

        if ($facts->cancelled && ! $baseline->cancelled) {
            return new ChangeVerdict(
                ChangeVerdict::CANCEL,
                diff: ['cancelled' => ['published' => $event->status === EventStatus::Cancelled, 'before' => false, 'now' => true]],
                relevantStartsAt: $this->nextOccurrenceStart($event) ?? $facts->nextInstanceStartUtc,
            );
        }

        $today = $now->toDateString();
        $newKeys = array_values(array_filter(
            array_diff($facts->cancelledKeys, $baseline->cancelledKeys),
            fn (string $key): bool => strcmp($key, $today) >= 0,
        ));
        sort($newKeys, SORT_STRING);

        if ($newKeys !== []) {
            return new ChangeVerdict(
                ChangeVerdict::CANCEL,
                diff: ['cancelled_keys' => $newKeys],
                targetKeys: $newKeys,
                relevantStartsAt: $this->keyStart($event, $facts, $newKeys[0]),
            );
        }

        $changed = self::changedFields($baseline, $facts);

        if ($changed === []) {
            // Only parts with no draft changed (a past cancelled date, a
            // date the source un-cancelled): nothing for the admin.
            return ChangeVerdict::none();
        }

        $published = self::published($event);
        $before = self::values($baseline);
        $current = self::values($facts);
        $diff = [];

        foreach ($changed as $field) {
            $diff[$field] = ['published' => $published[$field], 'before' => $before[$field], 'now' => $current[$field]];
        }

        $next = $this->nextOccurrenceStart($event);
        $new = $facts->nextInstanceStartUtc;

        return new ChangeVerdict(
            ChangeVerdict::UPDATE,
            diff: $diff,
            relevantStartsAt: match (true) {
                $next === null => $new,
                $new === null => $next,
                default => $next->lessThan($new) ? $next : $new,
            },
        );
    }

    /**
     * Folds the verdict for the linked item into the pending update or
     * cancellation draft it may already have (one pending draft per item).
     */
    private function withPending(ChangeVerdict $raised, ?EventDraft $pending): ChangeVerdict
    {
        if ($pending === null) {
            return $raised;
        }

        $same = ($pending->diff ?? []) == $raised->diff;

        return match ($raised->type) {
            ChangeVerdict::CANCEL => $pending->kind === DraftKind::Cancellation && $same ? ChangeVerdict::none() : $raised,
            ChangeVerdict::UPDATE => match (true) {
                $pending->kind === DraftKind::Cancellation, $same => ChangeVerdict::none(),
                default => new ChangeVerdict(ChangeVerdict::REPLACE, $raised->diff, [], $raised->relevantStartsAt),
            },
            default => new ChangeVerdict(ChangeVerdict::SUPERSEDE),
        };
    }

    private static function baseline(SourceItem $item): ?NormalisedFacts
    {
        return is_array($item->accepted_payload) ? NormalisedFacts::fromPayload($item->accepted_payload) : null;
    }

    private static function comparable(string $field, mixed $value): mixed
    {
        if (($field === 'title' || $field === 'venue') && is_string($value)) {
            return CategoryAssigner::normalise($value);
        }

        return $value;
    }

    private static function clock(?string $time): ?string
    {
        return $time === null || $time === '' ? null : substr($time, 0, 5);
    }

    private function nextOccurrenceStart(Event $event): ?CarbonImmutable
    {
        /** @var EventOccurrence|null $next */
        $next = $event->nextOccurrence;

        return $next?->starts_at->utc();
    }

    /**
     * The UTC start of one London date of the series: its occurrence when
     * it exists, otherwise computed from the feed's schedule.
     */
    private function keyStart(Event $event, NormalisedFacts $facts, string $key): ?CarbonImmutable
    {
        /** @var EventOccurrence|null $occurrence */
        $occurrence = $event->occurrences->firstWhere('recurrence_key', $key);

        if ($occurrence !== null) {
            return $occurrence->starts_at->utc();
        }

        try {
            $schedule = (new Event)->forceFill([
                'timezone' => self::LONDON,
                'all_day' => $facts->allDay,
                'start_date' => $facts->startDate,
                'start_time' => $facts->startTime,
                'end_date' => $facts->endDate,
                'end_time' => $facts->endTime,
                'recurrence_rule' => $facts->rrule,
            ]);

            return $this->timing->forDate($schedule, $key)->startsAt->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
