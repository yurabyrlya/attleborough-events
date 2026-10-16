<?php

namespace App\Calendar\Export;

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Models\EventOccurrence;
use App\Support\LondonFormatter;
use App\Support\PublicUrl;
use App\Support\TextLimit;
use Carbon\CarbonImmutable;
use Sabre\VObject\Component\VCalendar;

/**
 * One occurrence as a VCALENDAR with a single VEVENT (EVT-06), generated per
 * request and never stored. sabre/vobject builds, escapes and folds every
 * property; no line is built by hand (T-01-60).
 *
 *  - UID from OccurrenceUid (stable across edits and re-syncs)
 *  - timed: DTSTART/DTEND in UTC; DTEND = ends_at, or expires_at with
 *    "Finish time not given." as the first DESCRIPTION line
 *  - all-day: DTSTART/DTEND;VALUE=DATE in Europe/London (end exclusive)
 *  - SEQUENCE = whole seconds from 2026-01-01T00:00:00Z to LAST-MODIFIED
 *    (the later of the event's and the occurrence's updated_at), so it
 *    only ever rises
 *  - DESCRIPTION: the description cut at 1000 grapheme clusters with its
 *    line breaks kept (TextLimit without collapsing), then the event URL
 *  - STATUS CANCELLED when the event or the occurrence is cancelled
 */
final class OccurrenceIcs
{
    public const PRODID = "-//What's On Attleborough//Events//EN";

    private const SEQUENCE_EPOCH = '2026-01-01T00:00:00Z';

    private const DESCRIPTION_GRAPHEMES = 1000;

    public static function for(EventOccurrence $occurrence, CarbonImmutable $now): string
    {
        $event = $occurrence->event;
        $url = PublicUrl::event($event);
        $lastModified = self::lastModified($occurrence, $now);

        $calendar = new VCalendar([
            'PRODID' => self::PRODID,
            'METHOD' => 'PUBLISH',
            'CALSCALE' => 'GREGORIAN',
        ]);

        $vevent = $calendar->createComponent('VEVENT', [
            'UID' => OccurrenceUid::for($occurrence),
            'DTSTAMP' => $now->utc(),
            'LAST-MODIFIED' => $lastModified,
            'SEQUENCE' => max(0, (int) floor(CarbonImmutable::parse(self::SEQUENCE_EPOCH)->diffInSeconds($lastModified, false))),
        ]);

        if ($event->all_day) {
            $vevent->add('DTSTART', LondonFormatter::local($occurrence->starts_at)->startOfDay(), ['VALUE' => 'DATE']);
            $vevent->add('DTEND', LondonFormatter::local($occurrence->ends_at ?? $occurrence->expires_at)->startOfDay(), ['VALUE' => 'DATE']);
        } else {
            $vevent->add('DTSTART', $occurrence->starts_at->utc());
            $vevent->add('DTEND', ($occurrence->ends_at ?? $occurrence->expires_at)->utc());
        }

        $vevent->add('SUMMARY', $event->title);
        $vevent->add('DESCRIPTION', self::description($occurrence, $url));

        $location = self::location($occurrence);

        if ($location !== '') {
            $vevent->add('LOCATION', $location);
        }

        $vevent->add('URL', $url);
        $vevent->add('STATUS', self::isCancelled($occurrence) ? 'CANCELLED' : 'CONFIRMED');

        $calendar->add($vevent);

        return $calendar->serialize();
    }

    public static function isCancelled(EventOccurrence $occurrence): bool
    {
        return $occurrence->event->status === EventStatus::Cancelled
            || $occurrence->status === OccurrenceStatus::Cancelled;
    }

    private static function lastModified(EventOccurrence $occurrence, CarbonImmutable $now): CarbonImmutable
    {
        $stamps = array_filter([$occurrence->event->updated_at, $occurrence->updated_at]);

        if ($stamps === []) {
            return $now->utc();
        }

        return CarbonImmutable::parse(max(array_map(fn ($stamp): string => $stamp->utc()->format('Y-m-d H:i:s'), $stamps)), 'UTC');
    }

    private static function description(EventOccurrence $occurrence, string $url): string
    {
        $lines = [];

        if (! $occurrence->event->all_day && $occurrence->ends_at === null) {
            $lines[] = 'Finish time not given.';
        }

        // Grapheme-safe cut that keeps the line breaks (no whitespace collapsing).
        $text = TextLimit::graphemes($occurrence->event->description, self::DESCRIPTION_GRAPHEMES, collapse: false);

        if ($text !== '') {
            $lines[] = $text;
        }

        return implode("\n", $lines)."\n\nMore details: ".$url;
    }

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
