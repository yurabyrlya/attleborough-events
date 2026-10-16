<?php

namespace App\Http\Controllers;

use App\Calendar\PublicOccurrenceQuery;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * GET /events/{slug} (EVT-01, EVT-02): the public event page.
 *
 * An event that is not publicly listed (hidden) is a 404, checked before
 * anything else so a hidden event never confirms it exists.
 */
final class EventController
{
    public function __construct(private PublicOccurrenceQuery $query) {}

    public function __invoke(Request $request, string $slug): View
    {
        $event = self::resolve($slug);
        $now = CarbonImmutable::now();

        return view('events.show', [
            'event' => $event,
            'dates' => $this->query->upcomingForEvent($event, $now, (int) config('calendar.event_page_dates', 10)),
        ]);
    }

    /**
     * The publicly listed event with this slug, or a 404. Shared with
     * OccurrenceIcsController so both routes apply the same rules.
     */
    public static function resolve(string $slug): Event
    {
        $event = Event::query()->with(['venue', 'category'])->where('slug', $slug)->first();

        abort_if($event === null || ! $event->status->isPubliclyListed(), 404);

        return $event;
    }
}
