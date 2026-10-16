<?php

namespace App\Calendar\Export;

use App\Models\EventOccurrence;

/**
 * Stable iCalendar UID for one occurrence. PUBLIC CONTRACT: the value is
 * stored in residents' calendar apps and reused by the Phase 5 feeds, so it
 * must never change for the same occurrence.
 *
 *  - series instance: "{event_id}-{recurrence_key}@{site.domain}"
 *    e.g. "42-2026-10-30@whatsonattleborough.co.uk"
 *  - one-off event:   "{event_id}@{site.domain}"
 *    (a moved one-off date updates the same calendar entry on re-import)
 *
 * Only the event id and the recurrence key (which OccurrenceGenerator's
 * upsert preserves) go in: never the title, slug, times or the occurrence
 * row id, so edits and re-syncs keep the UID. The domain is the brand domain
 * (D-02) from config('site.domain'), not the hosting URL.
 */
final class OccurrenceUid
{
    public static function for(EventOccurrence $occurrence): string
    {
        $event = $occurrence->event;
        $domain = (string) config('site.domain');

        if ($event->recurrence_rule === null) {
            return $event->id.'@'.$domain;
        }

        return $event->id.'-'.$occurrence->recurrence_key.'@'.$domain;
    }
}
