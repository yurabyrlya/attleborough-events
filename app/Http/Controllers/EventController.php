<?php

namespace App\Http\Controllers;

use App\Calendar\PublicOccurrenceQuery;
use App\Models\Event;
use App\Models\EventSlug;
use App\Support\EventPageData;
use App\Support\PublicUrl;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET /events/{slug} (EVT-01, EVT-02): the public event page.
 *
 * The slug is looked up lowercased in events.slug, then in the slug history.
 * An event that is not publicly listed (hidden) is a 404, checked BEFORE any
 * redirect so a hidden event never confirms it exists. A request whose slug
 * is not byte-equal to the current slug (old or uppercase) answers 301 to
 * the canonical URL, built from the database slug and config('app.url')
 * only; the query string is dropped (T-01-61).
 */
final class EventController
{
    public function __construct(private PublicOccurrenceQuery $query) {}

    public function __invoke(Request $request, string $slug): View|RedirectResponse
    {
        $event = self::resolve($slug);

        if ($slug !== $event->slug) {
            return redirect()->away(PublicUrl::event($event), 301);
        }

        $now = CarbonImmutable::now();
        $dates = $this->query->upcomingForEvent($event, $now, (int) config('calendar.event_page_dates', 10));

        return view('events.show', [
            'page' => EventPageData::make($event, $dates, $now),
        ]);
    }

    /**
     * The publicly listed event for this slug (current or historical,
     * case-folded), or a 404. Shared with OccurrenceIcsController so both
     * routes apply the same rules.
     */
    public static function resolve(string $slug): Event
    {
        $lookup = strtolower($slug);

        $event = Event::query()->with(['venue', 'category'])->where('slug', $lookup)->first()
            ?? EventSlug::query()->where('slug', $lookup)->first()?->event()->with(['venue', 'category'])->first();

        abort_if($event === null || ! $event->status->isPubliclyListed(), 404);

        return $event;
    }
}
