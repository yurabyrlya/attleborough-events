<?php

namespace App\Support;

use App\Models\Event;
use App\Models\EventOccurrence;

/**
 * Public URLs for events. Absolute URLs come from config('app.url') only,
 * never from the request Host header or the asset helper (T-01-59); paths
 * for in-site links are root-relative.
 */
final class PublicUrl
{
    public static function to(string $path): string
    {
        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }

    public static function eventPath(Event $event): string
    {
        return route('events.show', ['slug' => $event->slug], false);
    }

    public static function event(Event $event): string
    {
        return self::to(self::eventPath($event));
    }

    public static function icsPath(EventOccurrence $occurrence): string
    {
        return route('events.ics', [
            'slug' => $occurrence->event->slug,
            'key' => $occurrence->recurrence_key,
        ], false);
    }

    public static function ics(EventOccurrence $occurrence): string
    {
        return self::to(self::icsPath($occurrence));
    }
}
