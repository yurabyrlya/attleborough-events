<?php

namespace App\Http\Controllers;

use App\Calendar\Export\OccurrenceIcs;
use App\Calendar\PublicOccurrenceQuery;
use App\Support\PublicUrl;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /events/{slug}/{Y-m-d}.ics (EVT-06): one date as an iCalendar file.
 * Read-only; built per request from the occurrence row. Same slug rules as
 * the event page: hidden → 404, old or uppercase slug → 301 to the current
 * slug's .ics URL for the same date.
 */
final class OccurrenceIcsController
{
    public function __construct(private PublicOccurrenceQuery $query) {}

    public function __invoke(Request $request, string $slug, string $key): Response|RedirectResponse
    {
        $event = EventController::resolve($slug);

        if ($slug !== $event->slug) {
            return redirect()->away(PublicUrl::to(route('events.ics', ['slug' => $event->slug, 'key' => $key], false)), 301);
        }
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
