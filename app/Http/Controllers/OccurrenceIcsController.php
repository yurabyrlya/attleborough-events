<?php

namespace App\Http\Controllers;

use App\Calendar\Export\OccurrenceIcs;
use App\Calendar\PublicOccurrenceQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /events/{slug}/{Y-m-d}.ics (EVT-06): one date as an iCalendar file.
 * Read-only; built per request from the occurrence row.
 */
final class OccurrenceIcsController
{
    public function __construct(private PublicOccurrenceQuery $query) {}

    public function __invoke(Request $request, string $slug, string $key): Response
    {
        $event = EventController::resolve($slug);
        $occurrence = $this->query->findListedOccurrence($event, $key);

        abort_if($occurrence === null, 404);

        return response(OccurrenceIcs::for($occurrence, CarbonImmutable::now()), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            // slug and key are ASCII-safe by construction (CHECK constraint, route pattern).
            'Content-Disposition' => 'attachment; filename="'.$event->slug.'-'.$occurrence->recurrence_key.'.ics"',
            'Cache-Control' => 'no-cache, private',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
