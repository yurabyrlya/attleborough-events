<?php

namespace App\Ingestion\Ics;

use App\Calendar\ManualRecurrence;
use App\Enums\IdentityStrategy;
use App\Enums\PriceType;
use App\Ingestion\Data\ExtractedItem;
use App\Ingestion\Data\ExtractionResult;
use App\Ingestion\Data\NormalisedFacts;
use App\Ingestion\Pipeline\ContentHasher;
use App\Models\Source;
use App\Review\CategoryAssigner;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\InvalidDataException;
use Sabre\VObject\ParseException;
use Sabre\VObject\Property;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;
use Sabre\VObject\Property\ICalendar\Duration;
use Sabre\VObject\Reader;
use Sabre\VObject\Recur\EventIterator;
use Sabre\VObject\Recur\MaxInstancesExceededException;
use Sabre\VObject\Recur\NoInstancesException;
use Throwable;

/**
 * ICS body → one ExtractedItem per identity (EXT-01, EXT-07).
 *
 * A series (master + RECURRENCE-ID overrides + EXDATE/RDATE) is ONE item and
 * one fragment. Components are grouped by UID first (so overrides always
 * join their master), then UID groups are merged by the source's identity
 * strategy (RESEARCH F4).
 *
 * Times: sabre/vobject reads every value in the source's default zone
 * (floating times) or its own TZID, after IcsTimezoneResolver has refused
 * unknown TZIDs. Facts carry the Europe/London wall clock; the only instant
 * (nextInstanceStartUtc) is UTC. Instances are expanded only by sabre's
 * EventIterator, with an explicit zone and this class's own instance cap:
 * Sabre\VObject\Settings is never changed (it is global, T-03-11).
 *
 * D-19 recurrence scope: one-offs and RRULEs that ManualRecurrence
 * round-trips are publishable; EXDATE → excludedDates; a cancelled override
 * → cancelledKeys. A moved override, an RDATE or any other rule becomes an
 * item parse error that says what to fix, and the facts are kept so the
 * draft still shows.
 *
 * Pure: no database, no network, no writes.
 */
final class IcsExtractor
{
    /** Instances walked per item when looking for the next one (T-03-11). */
    public const int MAX_INSTANCES = 500;

    /** external_id column length. */
    private const int MAX_EXTERNAL_ID = 255;

    private const string LONDON = 'Europe/London';

    public function __construct(
        private readonly IcsTimezoneResolver $timezones,
        private readonly IcsItemNormaliser $normaliser,
        private readonly ContentHasher $hasher,
    ) {}

    /**
     * @param  CarbonImmutable|null  $now  defaults to the current time
     */
    public function extract(string $body, Source $source, ?CarbonImmutable $now = null): ExtractionResult
    {
        // Counted before parsing, so a huge feed is refused cheaply.
        $declared = (int) preg_match_all('/^BEGIN:VEVENT[ \t]*\r?$/mi', $body);
        $maxEvents = (int) config('ingest.max_events', 5000);

        if ($declared > $maxEvents) {
            return $this->tooManyEvents($declared, $maxEvents);
        }

        try {
            $calendar = Reader::read($body, Reader::OPTION_FORGIVING);
        } catch (ParseException) {
            return ExtractionResult::fatal('The feed could not be read as a calendar (ICS) file.', $declared);
        }

        if (! $calendar instanceof VCalendar) {
            return ExtractionResult::fatal('The feed is not a calendar (ICS) file.', $declared);
        }

        $zone = $this->timezones->sourceZone($source);
        $identity = IdentityStrategy::forSource($source);
        $now = ($now ?? CarbonImmutable::now())->setTimezone(self::LONDON);

        $events = array_values(array_filter($calendar->select('VEVENT'), fn (mixed $node): bool => $node instanceof VEvent));

        // T-03-11: the pre-count reads the raw, still-folded text, so a folded
        // "BEGIN:VEV\r\n ENT" slips past it while sabre unfolds and parses it.
        // Count again after parsing, before any per-event work.
        if (count($events) > $maxEvents) {
            return $this->tooManyEvents(count($events), $maxEvents);
        }

        $items = [];

        foreach ($this->groups($events, $identity) as $externalId => $components) {
            $items[] = $this->item((string) $externalId, $components, $calendar, $identity, $zone, $now);
        }

        usort($items, function (ExtractedItem $a, ExtractedItem $b): int {
            $aStart = $a->facts?->nextInstanceStartUtc?->getTimestamp() ?? PHP_INT_MAX;
            $bStart = $b->facts?->nextInstanceStartUtc?->getTimestamp() ?? PHP_INT_MAX;

            return [$aStart, $a->externalId] <=> [$bStart, $b->externalId];
        });

        return new ExtractionResult($items, null, count($events));
    }

    private function tooManyEvents(int $count, int $maxEvents): ExtractionResult
    {
        return ExtractionResult::fatal("The feed lists {$count} events, more than the {$maxEvents} we read in one go.", $count);
    }

    /**
     * Groups VEVENTs by UID (master + overrides), then merges UID groups that
     * share an identity.
     *
     * @param  list<VEvent>  $events
     * @return array<string, list<VEvent>>
     */
    private function groups(array $events, IdentityStrategy $identity): array
    {
        $byUid = [];

        foreach ($events as $index => $event) {
            $uid = trim($this->text($event, 'UID') ?? '');
            $byUid[$uid !== '' ? 'uid:'.$uid : 'index:'.$index][] = $event;
        }

        $groups = [];

        foreach ($byUid as $components) {
            $master = $this->masters($components)[0] ?? $components[0];
            $groups[$this->externalId($master, $identity)][] = $components;
        }

        return array_map(fn (array $lists): array => array_merge(...$lists), $groups);
    }

    /**
     * @param  VEvent<mixed>  $master
     */
    private function externalId(VEvent $master, IdentityStrategy $identity): string
    {
        $uid = trim($this->text($master, 'UID') ?? '');

        $id = match (true) {
            $identity === IdentityStrategy::Uid && $uid !== '' => $uid,
            $identity === IdentityStrategy::TecPostId && preg_match(IdentityStrategy::TEC_POST_ID_PATTERN, $uid, $m) === 1 => $m[1],
            $identity === IdentityStrategy::TecPostId && $uid !== '' => $uid,
            default => $this->summaryStartKey($master),
        };

        if (strlen($id) <= self::MAX_EXTERNAL_ID) {
            return $id;
        }

        return mb_strcut($id, 0, 190, 'UTF-8').'#'.hash('sha256', $id);
    }

    /**
     * "{DTSTART value}|{normalised SUMMARY}". The raw DTSTART value is used
     * (not a resolved instant) so the key never depends on time zone lookups.
     *
     * @param  VEvent<mixed>  $master
     */
    private function summaryStartKey(VEvent $master): string
    {
        $start = $this->dateProperty($master, 'DTSTART');
        $title = CategoryAssigner::normalise($this->normaliser->line($this->text($master, 'SUMMARY')) ?? '');

        return ($start?->getValue() ?? '').'|'.$title;
    }

    /**
     * @param  list<VEvent>  $components
     * @param  VCalendar<mixed>  $calendar
     */
    private function item(string $externalId, array $components, VCalendar $calendar, IdentityStrategy $identity, DateTimeZone $zone, CarbonImmutable $now): ExtractedItem
    {
        $masters = $this->masters($components);
        usort($masters, fn (VEvent $a, VEvent $b): int => strcmp($this->stable($a, $identity), $this->stable($b, $identity)));

        $overrides = array_values(array_filter($components, fn (VEvent $event): bool => $this->dateProperty($event, 'RECURRENCE-ID') !== null));
        usort($overrides, fn (VEvent $a, VEvent $b): int => [(string) $this->dateProperty($a, 'RECURRENCE-ID')?->getValue(), $a->serialize()]
            <=> [(string) $this->dateProperty($b, 'RECURRENCE-ID')?->getValue(), $b->serialize()]);

        $fragment = implode('', array_map(fn (VEvent $event): string => $event->serialize(), [...$masters, ...$overrides]));
        $rawHash = $this->hasher->rawHash($fragment, $identity);
        $unreadable = fn (string $error): ExtractedItem => new ExtractedItem($externalId, $fragment, $rawHash, null, null, [$error]);

        $master = $masters[0] ?? null;

        if ($master === null) {
            return $unreadable('This item only changes one date of a repeating event, and that event is not in the feed.');
        }

        $startProperty = $this->dateProperty($master, 'DTSTART');

        if ($startProperty === null) {
            return $unreadable('This item has no start date.');
        }

        $unknownZone = $this->timezones->unknownTimeZone([$master, ...$overrides], $calendar);

        if ($unknownZone !== null) {
            return $unreadable('Unknown time zone "'.$unknownZone.'"');
        }

        $errors = $this->timezones->uncertainTimeZoneErrors([$master, ...$overrides], $zone);

        if (count(array_unique(array_map(fn (VEvent $event): string => $this->stable($event, $identity), $masters))) > 1) {
            $errors[] = 'The feed lists this event more than once with different details. Check the source and keep the right one.';
        }

        try {
            $facts = $this->facts($master, $overrides, $zone, $now, $errors);
        } catch (MaxInstancesExceededException) {
            return $unreadable('This repeating event has too many dates to read.');
        } catch (NoInstancesException) {
            return $unreadable('This repeating event has no dates left once the feed\'s exclusions are applied.');
        } catch (InvalidDataException|InvalidArgumentException) {
            return $unreadable('The dates or repeat rule of this event could not be read.');
        }

        if ($facts === null) {
            return $unreadable('The end time is before the start time. Check the times on the source.');
        }

        return new ExtractedItem($externalId, $fragment, $rawHash, $facts, $this->hasher->contentHash($facts), $errors);
    }

    /**
     * @param  list<VEvent>  $overrides
     * @param  list<string>  $errors  D-19 and text problems are appended
     * @param  VEvent<mixed>  $master
     * @return NormalisedFacts|null null when the end is before the start
     *
     * @throws MaxInstancesExceededException|NoInstancesException|InvalidDataException|InvalidArgumentException
     */
    private function facts(VEvent $master, array $overrides, DateTimeZone $zone, CarbonImmutable $now, array &$errors): ?NormalisedFacts
    {
        $startProperty = $this->dateProperty($master, 'DTSTART') ?? throw new InvalidArgumentException('No DTSTART.');
        $allDay = ! $startProperty->hasTime();
        $startAt = $this->instant($startProperty, $zone);
        $endAt = $this->endAt($master, $startAt);

        if ($endAt !== null && $endAt->lessThan($startAt)) {
            return null;
        }

        [$startDate, $startTime, $endDate, $endTime] = $allDay
            ? $this->allDaySchedule($startAt, $endAt)
            : $this->timedSchedule($startAt, $endAt);

        $title = $this->normaliser->line($this->text($master, 'SUMMARY'));

        if ($title === null) {
            $errors[] = 'The feed gives this event no title.';
        }

        $location = $this->normaliser->location($this->text($master, 'LOCATION'));
        [$rrule, $ruleError] = $this->rule($master, $startDate, $startTime, $allDay, $zone);

        if ($ruleError !== null) {
            $errors[] = $ruleError;
        }

        $extraDates = $this->extraDates($master, $zone);

        if ($extraDates !== []) {
            $errors[] = 'The feed adds extra dates to this event ('.implode(', ', array_map($this->dayLabel(...), $extraDates)).'). Publish them as separate events.';
        }

        [$cancelledKeys, $movedOverrides, $overrideErrors] = $this->overrides($master, $overrides, $startAt, $endAt, $allDay, $zone);
        array_push($errors, ...$overrideErrors);

        return new NormalisedFacts(
            title: $title ?? '',
            allDay: $allDay,
            startDate: $startDate,
            startTime: $startTime,
            endDate: $endDate,
            endTime: $endTime,
            venueName: $location['name'],
            venueAddress: $location['address'],
            postcode: $location['postcode'],
            url: $this->normaliser->url($this->text($master, 'URL')),
            categories: $this->normaliser->categories($master),
            description: $this->normaliser->description($this->text($master, 'DESCRIPTION')),
            organiser: $this->normaliser->organiser($master),
            cancelled: $this->isCancelled($master),
            rrule: $rrule,
            excludedDates: $this->excludedDates($master, $zone),
            cancelledKeys: $cancelledKeys,
            movedOverrides: $movedOverrides,
            extraDates: $extraDates,
            nextInstanceStartUtc: $this->nextInstanceStart($master, $overrides, $zone, $now, $allDay) ?? $startAt->utc(),
            priceType: PriceType::Unknown,
            familyFriendly: false,
        );
    }

    /**
     * Timed: London wall clock; end_date only when the end falls on a later
     * local day. A DTEND equal to DTSTART is zero duration, so no end.
     *
     * @return array{0: string, 1: string, 2: ?string, 3: ?string}
     */
    private function timedSchedule(CarbonImmutable $startAt, ?CarbonImmutable $endAt): array
    {
        $start = $startAt->setTimezone(self::LONDON);
        $end = $endAt !== null && $endAt->greaterThan($startAt) ? $endAt->setTimezone(self::LONDON) : null;
        $endDate = $end?->format('Y-m-d');

        return [
            $start->format('Y-m-d'),
            $start->format('H:i'),
            $endDate !== null && $endDate !== $start->format('Y-m-d') ? $endDate : null,
            $end?->format('H:i'),
        ];
    }

    /**
     * All-day: DTEND is exclusive, so the last day is DTEND minus one day
     * (null when that is the start day).
     *
     * @return array{0: string, 1: null, 2: ?string, 3: null}
     */
    private function allDaySchedule(CarbonImmutable $startAt, ?CarbonImmutable $endAt): array
    {
        $startDate = $startAt->format('Y-m-d');
        $endDate = null;

        if ($endAt !== null) {
            $lastDay = $this->londonDate($endAt->format('Y-m-d'))->subDay()->format('Y-m-d');
            $endDate = $lastDay > $startDate ? $lastDay : null;
        }

        return [$startDate, null, $endDate, null];
    }

    /**
     * The RRULE in the form's canonical shape when ManualRecurrence can
     * publish it (D-19), else the feed's rule plus a parse error.
     *
     * @param  VEvent<mixed>  $master
     * @return array{0: ?string, 1: ?string}
     */
    private function rule(VEvent $master, string $startDate, ?string $startTime, bool $allDay, DateTimeZone $zone): array
    {
        $rules = array_values(array_filter($master->select('RRULE'), fn (mixed $node): bool => $node instanceof Property));

        if ($rules === []) {
            return [null, null];
        }

        $raw = implode(' + ', array_map(fn (Property $rule): string => (string) $rule->getValue(), $rules));
        $publishable = count($rules) === 1 ? $this->publishableRule($raw, $startDate, $startTime, $allDay, $zone) : null;

        if ($publishable !== null) {
            return [$publishable, null];
        }

        return [$raw, "This repeat rule can't be published from the form ({$raw}). Publish the dates as separate events, or set the repeat by hand."];
    }

    /**
     * Rebuilds a feed RRULE in the exact shape ManualRecurrence emits
     * (FREQ;BYDAY;UNTIL|COUNT) and keeps it only if it round-trips for the
     * start date (WR-10). A WEEKLY rule without BYDAY repeats on the start's
     * weekday; INTERVAL=1 and WKST change nothing for these shapes. A timed
     * UNTIL becomes the London end of day of the last date it still includes.
     */
    private function publishableRule(string $raw, string $startDate, ?string $startTime, bool $allDay, DateTimeZone $zone): ?string
    {
        $parts = [];

        foreach (explode(';', strtoupper(trim($raw))) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');

            if (array_key_exists($key, $parts)) {
                return null;
            }

            $parts[$key] = $value;
        }

        unset($parts['WKST']);

        if (array_key_exists('INTERVAL', $parts)) {
            if ($parts['INTERVAL'] !== '1') {
                return null;
            }

            unset($parts['INTERVAL']);
        }

        $freq = $parts['FREQ'] ?? '';

        if (array_diff(array_keys($parts), ['FREQ', 'BYDAY', 'UNTIL', 'COUNT']) !== []
            || ! in_array($freq, ['WEEKLY', 'MONTHLY'], true)
            || (isset($parts['UNTIL']) && isset($parts['COUNT']))) {
            return null;
        }

        $startDay = $this->londonDate($startDate);
        $byDay = $parts['BYDAY'] ?? ($freq === 'WEEKLY' ? strtoupper(substr($startDay->englishDayOfWeek, 0, 2)) : null);

        if ($byDay === null) {
            return null;
        }

        $candidate = "FREQ={$freq};BYDAY={$byDay}";

        if (isset($parts['UNTIL'])) {
            $until = $this->canonicalUntil($parts['UNTIL'], $allDay, $startTime, $zone);

            if ($until === null) {
                return null;
            }

            $candidate .= ";UNTIL={$until}";
        }

        if (isset($parts['COUNT'])) {
            $candidate .= ";COUNT={$parts['COUNT']}";
        }

        return ManualRecurrence::tryFromStoredRule($candidate, $startDay, $allDay) !== null ? $candidate : null;
    }

    private function canonicalUntil(string $until, bool $allDay, ?string $startTime, DateTimeZone $zone): ?string
    {
        try {
            $untilLocal = CarbonImmutable::instance(DateTimeParser::parse($until, $zone))->setTimezone(self::LONDON);
        } catch (Throwable) {
            return null;
        }

        if ($allDay) {
            return $untilLocal->format('Ymd');
        }

        // The instance on UNTIL's London date is included only if it starts
        // at or before UNTIL's time of day.
        $lastDate = $untilLocal->format('H:i') >= (string) $startTime ? $untilLocal : $untilLocal->subDay();

        return $lastDate->endOfDay()->startOfSecond()->utc()->format('Ymd\THis\Z');
    }

    /**
     * Cancelled overrides become cancelledKeys; overrides that move a date
     * or change its details become movedOverrides plus a parse error.
     *
     * @param  list<VEvent>  $overrides
     * @param  VEvent<mixed>  $master
     * @return array{0: list<string>, 1: list<array{key: string, start: string, end: ?string}>, 2: list<string>}
     */
    private function overrides(VEvent $master, array $overrides, CarbonImmutable $startAt, ?CarbonImmutable $endAt, bool $allDay, DateTimeZone $zone): array
    {
        $cancelled = [];
        $moved = [];
        $errors = [];
        $duration = $endAt !== null ? $endAt->getTimestamp() - $startAt->getTimestamp() : ($allDay ? 86400 : 0);
        $masterTitle = CategoryAssigner::normalise($this->normaliser->line($this->text($master, 'SUMMARY')) ?? '');
        $masterVenue = CategoryAssigner::normalise($this->normaliser->line($this->text($master, 'LOCATION')) ?? '');

        foreach ($overrides as $override) {
            $recurrenceId = $this->dateProperty($override, 'RECURRENCE-ID');

            if ($recurrenceId === null) {
                continue;
            }

            $originalStart = $this->instant($recurrenceId, $zone);
            $key = $allDay ? $originalStart->format('Y-m-d') : $originalStart->setTimezone(self::LONDON)->format('Y-m-d');

            if ($this->isCancelled($override)) {
                $cancelled[] = $key;

                continue;
            }

            $startProperty = $this->dateProperty($override, 'DTSTART');
            $start = $startProperty !== null ? $this->instant($startProperty, $zone) : $originalStart;
            $end = $this->endAt($override, $start) ?? $start->addSeconds($duration);
            $timeMoved = $start->getTimestamp() !== $originalStart->getTimestamp()
                || $end->getTimestamp() !== $originalStart->getTimestamp() + $duration;
            $detailsChanged = CategoryAssigner::normalise($this->normaliser->line($this->text($override, 'SUMMARY')) ?? '') !== $masterTitle
                || CategoryAssigner::normalise($this->normaliser->line($this->text($override, 'LOCATION')) ?? '') !== $masterVenue;

            if (! $timeMoved && ! $detailsChanged) {
                continue;
            }

            $format = $allDay ? 'Y-m-d' : 'Y-m-d H:i';
            $startLocal = $allDay ? $start : $start->setTimezone(self::LONDON);
            $endLocal = $allDay ? $end : $end->setTimezone(self::LONDON);
            $moved[] = [
                'key' => $key,
                'start' => $startLocal->format($format),
                'end' => $end->greaterThan($start) ? $endLocal->format($format) : null,
            ];

            $errors[] = $timeMoved
                ? 'The feed moves '.$this->dayLabel($key).' to '.$this->whenLabel($startLocal, $key, $allDay).'. Publish that date as a separate event, or edit the series.'
                : 'The feed changes the details of '.$this->dayLabel($key).' only. Publish that date as a separate event, or edit the series.';
        }

        return [$cancelled, $moved, $errors];
    }

    /**
     * EXDATE values as London dates.
     *
     * @param  VEvent<mixed>  $master
     * @return list<string>
     */
    private function excludedDates(VEvent $master, DateTimeZone $zone): array
    {
        $dates = [];

        foreach ($master->select('EXDATE') as $property) {
            if (! $property instanceof DateTimeProperty) {
                continue;
            }

            foreach ($property->getDateTimes($zone) as $dateTime) {
                $dates[] = $this->localDate($dateTime, $property->hasTime());
            }
        }

        return $dates;
    }

    /**
     * RDATE values as London dates (PERIOD values keep their raw start).
     *
     * @param  VEvent<mixed>  $master
     * @return list<string>
     */
    private function extraDates(VEvent $master, DateTimeZone $zone): array
    {
        $dates = [];

        foreach ($master->select('RDATE') as $property) {
            if ($property instanceof DateTimeProperty) {
                foreach ($property->getDateTimes($zone) as $dateTime) {
                    $dates[] = $this->localDate($dateTime, $property->hasTime());
                }
            } elseif ($property instanceof Property) {
                foreach ($property->getParts() as $part) {
                    $dates[] = (string) strtok(is_scalar($part) ? (string) $part : '', '/');
                }
            }
        }

        return $dates;
    }

    /**
     * The start (UTC) of the first instance whose REC-02 expiry is after
     * now, walking sabre's EventIterator in the source zone. Null when every
     * instance has expired.
     *
     * @param  list<VEvent>  $overrides
     * @param  VEvent<mixed>  $master
     *
     * @throws MaxInstancesExceededException|NoInstancesException|InvalidDataException|InvalidArgumentException
     */
    private function nextInstanceStart(VEvent $master, array $overrides, DateTimeZone $zone, CarbonImmutable $now, bool $allDay): ?CarbonImmutable
    {
        $noEndHours = (int) config('calendar.no_end_expiry_hours', 3);
        $iterator = new EventIterator([$master, ...$overrides], null, $zone);

        // Every instance that could still be unexpired ends after this.
        $iterator->fastForward($now->subHours($noEndHours)->subSecond());

        for ($walked = 0; $walked < self::MAX_INSTANCES && $iterator->valid(); $walked++) {
            $start = $iterator->getDtStart();
            $end = $iterator->getDtEnd();

            if ($start !== null) {
                $startAt = CarbonImmutable::instance($start);
                $endAt = $end !== null ? CarbonImmutable::instance($end) : $startAt;
                $expiresAt = $allDay || $endAt->greaterThan($startAt) ? $endAt : $startAt->addHours($noEndHours);

                if ($expiresAt->greaterThan($now)) {
                    return $startAt->utc();
                }
            }

            $iterator->next();
        }

        return null;
    }

    /**
     * @param  VEvent<mixed>  $event
     */
    private function endAt(VEvent $event, CarbonImmutable $startAt): ?CarbonImmutable
    {
        $end = $this->dateProperty($event, 'DTEND');

        if ($end !== null) {
            return $this->instant($end, $startAt->getTimezone());
        }

        $duration = $event->select('DURATION')[0] ?? null;

        return $duration instanceof Duration ? $startAt->add($duration->getDateInterval()) : null;
    }

    /**
     * A DTSTART/DTEND/RECURRENCE-ID value as an instant. Floating values and
     * DATE values are read in $zone; TZID and UTC values keep their own zone.
     *
     * @param  DateTimeProperty<mixed>  $property
     */
    private function instant(DateTimeProperty $property, DateTimeZone $zone): CarbonImmutable
    {
        $dateTime = $property->getDateTime($zone) ?? throw new InvalidArgumentException('Empty date value.');

        return CarbonImmutable::instance($dateTime);
    }

    private function localDate(DateTimeInterface $dateTime, bool $hasTime): string
    {
        $value = CarbonImmutable::instance($dateTime);

        return $hasTime ? $value->setTimezone(self::LONDON)->format('Y-m-d') : $value->format('Y-m-d');
    }

    private function londonDate(string $date): CarbonImmutable
    {
        $value = CarbonImmutable::createFromFormat('!Y-m-d', $date, self::LONDON);

        if (! $value instanceof CarbonImmutable) {
            throw new InvalidArgumentException("Invalid date [{$date}].");
        }

        return $value;
    }

    /** "Wed 28 Oct" for parse error text. */
    private function dayLabel(string $date): string
    {
        try {
            return $this->londonDate($date)->format('D j M');
        } catch (Throwable) {
            return $date;
        }
    }

    /** "7:30pm", "7pm", or "Thu 29 Oct, 7:30pm" when the date changes too. */
    private function whenLabel(CarbonImmutable $local, string $originalKey, bool $allDay): string
    {
        $date = $local->format('Y-m-d');

        if ($allDay) {
            return $this->dayLabel($date);
        }

        $time = $local->format('i') === '00' ? $local->format('ga') : $local->format('g:ia');

        return $date === $originalKey ? $time : $this->dayLabel($date).', '.$time;
    }

    /**
     * @param  list<VEvent>  $components
     * @return list<VEvent>
     */
    private function masters(array $components): array
    {
        return array_values(array_filter($components, fn (VEvent $event): bool => $this->dateProperty($event, 'RECURRENCE-ID') === null));
    }

    /** A master's fragment without the volatile lines: duplicate detection and stable ordering.
     * @param  VEvent<mixed>  $event
     */
    private function stable(VEvent $event, IdentityStrategy $identity): string
    {
        return $this->hasher->rawHash($event->serialize(), $identity);
    }

    /**
     * @param  Component<mixed>  $component
     */
    private function isCancelled(Component $component): bool
    {
        return strtoupper(trim($this->text($component, 'STATUS') ?? '')) === 'CANCELLED';
    }

    /**
     * @param  Component<mixed>  $component
     * @return DateTimeProperty<mixed>|null
     */
    private function dateProperty(Component $component, string $name): ?DateTimeProperty
    {
        $property = $component->select($name)[0] ?? null;

        return $property instanceof DateTimeProperty ? $property : null;
    }

    /**
     * @param  Component<mixed>  $component
     */
    private function text(Component $component, string $name): ?string
    {
        $property = $component->select($name)[0] ?? null;

        return $property instanceof Property ? (string) $property->getValue() : null;
    }
}
