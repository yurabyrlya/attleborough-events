<?php

namespace App\Ingestion\Ics;

use App\Models\Source;
use DateTimeZone;
use InvalidArgumentException;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Parameter;
use Sabre\VObject\Property;
use Sabre\VObject\TimeZoneUtil;
use Throwable;

/**
 * Time zones for ICS items (EXT-07).
 *
 * sabre resolves TZIDs leniently: Property\ICalendar\DateTime::getDateTimes()
 * calls TimeZoneUtil::getTimeZone() WITHOUT failIfUncertain, so an unknown
 * TZID silently becomes UTC (RESEARCH Pattern 3). Every TZID on DTSTART,
 * DTEND, EXDATE, RDATE and RECURRENCE-ID is therefore pre-validated here with
 * failIfUncertain = true; an unknown one makes the item a parse error.
 *
 * Floating times (no TZID, no Z) use the source's default_timezone: that
 * zone is passed to every getDateTime() call and to EventIterator.
 */
final class IcsTimezoneResolver
{
    /** Properties whose TZID parameter decides an instant. */
    private const array DATE_PROPERTIES = ['DTSTART', 'DTEND', 'EXDATE', 'RDATE', 'RECURRENCE-ID'];

    /** sabre maps this Windows name to Atlantic/Reykjavik (no summer time). */
    private const string GREENWICH_STANDARD_TIME = 'greenwich standard time';

    private const string LONDON = 'Europe/London';

    /**
     * The zone floating times are read in: the source's default_timezone,
     * or the calendar zone when that value is not a PHP zone.
     */
    public function sourceZone(Source $source): DateTimeZone
    {
        try {
            return new DateTimeZone($source->default_timezone);
        } catch (Throwable) {
            return new DateTimeZone((string) config('calendar.timezone', self::LONDON));
        }
    }

    /**
     * The first TZID on the components that sabre cannot resolve with
     * certainty, or null when every TZID is known.
     *
     * @param  list<Component>  $components
     * @param  VCalendar<mixed>  $calendar
     */
    public function unknownTimeZone(array $components, VCalendar $calendar): ?string
    {
        foreach ($this->tzids($components) as $tzid) {
            try {
                TimeZoneUtil::getTimeZone($tzid, $calendar, true);
            } catch (InvalidArgumentException) {
                return $tzid;
            }
        }

        return null;
    }

    /**
     * Parse errors for TZIDs that resolve, but probably not to what a UK
     * organiser meant. "Greenwich Standard Time" is Windows' name for
     * Monrovia/Reykjavik (GMT all year), so summer times would be an hour
     * out when the source is in Europe/London.
     *
     * @param  list<Component>  $components
     * @return list<string>
     */
    public function uncertainTimeZoneErrors(array $components, DateTimeZone $sourceZone): array
    {
        if ($sourceZone->getName() !== self::LONDON) {
            return [];
        }

        foreach ($this->tzids($components) as $tzid) {
            if (strtolower(trim($tzid)) === self::GREENWICH_STANDARD_TIME) {
                return ['The feed uses the time zone "'.$tzid.'", which is Iceland time with no summer time, not UK time. Check the times on the source before publishing.'];
            }
        }

        return [];
    }

    /**
     * @param  list<Component>  $components
     * @return list<string>
     */
    private function tzids(array $components): array
    {
        $tzids = [];

        foreach ($components as $component) {
            foreach (self::DATE_PROPERTIES as $name) {
                foreach ($component->select($name) as $property) {
                    $parameter = $property instanceof Property ? $property['TZID'] : null;
                    $tzid = $parameter instanceof Parameter ? $parameter->getValue() : null;

                    if ($tzid === null || $tzid === '') {
                        continue;
                    }

                    if (! in_array($tzid, $tzids, true)) {
                        $tzids[] = $tzid;
                    }
                }
            }
        }

        return $tzids;
    }
}
