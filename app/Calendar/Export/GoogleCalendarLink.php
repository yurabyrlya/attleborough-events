<?php

namespace App\Calendar\Export;

use App\Models\EventOccurrence;
use App\Support\LondonFormatter;
use App\Support\PublicUrl;
use App\Support\TextLimit;

/**
 * Google Calendar "add event" template URL for one occurrence (EVT-06,
 * Google half; the .ics half is OccurrenceIcs). Built per request, never
 * stored. The query is RFC 3986 encoded (spaces as %20), keys in order:
 *
 *  - action TEMPLATE
 *  - text: the event title
 *  - dates: timed = UTC start / UTC end (ends_at, or expires_at when no
 *    finish time is given) as Ymd\THis\Z; all-day = London start date /
 *    exclusive London end date as Ymd
 *  - details: "Finish time not given." when applicable, the description cut
 *    at 500 grapheme clusters, a blank line, then the canonical URL
 *  - location: the same single line as the ICS LOCATION (venue name,
 *    address, town, postcode, blanks left out, joined with ", ")
 *
 * Google does not formally document this URL (RESEARCH A1); it is checked
 * by hand in the end-of-phase UAT. Absolute URLs come from PublicUrl only.
 */
final class GoogleCalendarLink
{
    public const BASE = 'https://calendar.google.com/calendar/render';

    public const DESCRIPTION_GRAPHEMES = 500;

    public static function for(EventOccurrence $occurrence): string
    {
        $event = $occurrence->event;
        $end = $occurrence->ends_at ?? $occurrence->expires_at;

        $dates = $event->all_day
            ? LondonFormatter::local($occurrence->starts_at)->format('Ymd').'/'.LondonFormatter::local($end)->format('Ymd')
            : $occurrence->starts_at->utc()->format('Ymd\THis\Z').'/'.$end->utc()->format('Ymd\THis\Z');

        $query = [
            'action' => 'TEMPLATE',
            'text' => $event->title,
            'dates' => $dates,
            'details' => self::details($occurrence),
        ];

        $location = self::location($occurrence);

        if ($location !== '') {
            $query['location'] = $location;
        }

        return self::BASE.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private static function details(EventOccurrence $occurrence): string
    {
        $lines = [];

        if (! $occurrence->event->all_day && $occurrence->ends_at === null) {
            $lines[] = 'Finish time not given.';
        }

        $text = TextLimit::graphemes($occurrence->event->description, self::DESCRIPTION_GRAPHEMES);

        if ($text !== '') {
            $lines[] = $text;
        }

        $lines[] = '';
        $lines[] = PublicUrl::event($occurrence->event);

        return implode("\n", $lines);
    }

    /**
     * Mirrors the OccurrenceIcs LOCATION rule so both calendar exports
     * agree on where the event is.
     */
    private static function location(EventOccurrence $occurrence): string
    {
        $venue = $occurrence->event->venue;

        if ($venue === null) {
            return '';
        }

        $parts = array_map(
            fn (?string $part): string => trim((string) $part),
            [$venue->name, $venue->address, $venue->town, $venue->postcode],
        );

        return implode(', ', array_values(array_filter($parts, fn (string $part): bool => $part !== '')));
    }
}
