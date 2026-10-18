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
 *
 * The 301 carries Cache-Control "no-cache, private" (WR-04, T-01-73): search
 * engines still see a permanent redirect, but a browser revalidates instead
 * of replaying it from cache. A slug can become current again (rename back,
 * see EventSlugger), and a cached redirect would then loop forever; a
 * max-age cap would only shorten that window, revalidation closes it.
 */
final class EventController
{
    /**
     * Cache-Control for slug redirects (event page and .ics), WR-04.
     */
    public const REDIRECT_CACHE_CONTROL = 'no-cache, private';

    public function __construct(private PublicOccurrenceQuery $query) {}

    public function __invoke(Request $request, string $slug): View|RedirectResponse
    {
        $event = self::resolve($slug);

        if ($slug !== $event->slug) {
            return redirect()->away(PublicUrl::event($event), 301)
                ->header('Cache-Control', self::REDIRECT_CACHE_CONTROL);
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

        // eventSources.source: the attribution lines (LEG-04) without lazy loads.
        $with = ['venue', 'category', 'eventSources.source'];

        $event = Event::query()->with($with)->where('slug', $lookup)->first()
            ?? EventSlug::query()->where('slug', $lookup)->first()?->event()->with($with)->first();

        abort_if($event === null || ! $event->status->isPubliclyListed(), 404);

        return $event;
    }
}
