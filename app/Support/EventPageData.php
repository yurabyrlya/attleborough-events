<?php

namespace App\Support;

use App\Calendar\Export\GoogleCalendarLink;
use App\Calendar\Export\OccurrenceIcs;
use App\Calendar\UpcomingDates;
use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventSource;
use App\Seo\EventJsonLd;
use Carbon\CarbonImmutable;
use Illuminate\Support\HtmlString;

/**
 * Event page view model (EVT-01, EVT-02). The view renders only these
 * fields, through escaped echo. Blank strings are null everywhere, so an
 * optional field that is not set leaves no label and no element.
 *
 * Absolute URLs come from PublicUrl (config('app.url')) only. Each date
 * row carries its .ics path and Google Calendar link (EVT-06); jsonLd holds
 * one Event script per listed date (EVT-03); share holds the share text and
 * no-JS share links (EVT-07).
 *
 * Discovered events (LEG-04, D-12, D-14..D-17): attribution is the primary
 * event_sources link ("Listed by"), alsoListedBy the other links in link
 * order, and readMore the "Read more at {source}" link after the
 * description. Each links to the item's own page, else the source's events
 * page, else the feed site's home page, through SafeLinks::http. With
 * attribution set, the Phase 2 "More information" line and the "Listed by
 * What's On Attleborough." fallback are not shown (C10). Organiser fields
 * are never filled from feeds (D-17), so only an organiser the admin typed
 * reaches the page. Needs eventSources.source loaded to avoid lazy loads.
 */
final readonly class EventPageData
{
    public const META_DESCRIPTION_GRAPHEMES = 160;

    /**
     * @param  list<string>  $venueLines
     * @param  list<array{datetimeIso: string, timeLabel: string, isCancelled: bool, icsPath: string, googleUrl: string}>  $dates
     * @param  array{name: string, url: string|null}|null  $attribution
     * @param  list<array{name: string, url: string|null}>  $alsoListedBy
     * @param  array{label: string, url: string}|null  $readMore
     */
    public function __construct(
        public string $title,
        public bool $isCancelled,
        public string $pageTitle,
        public string $ogTitle,
        public string $metaDescription,
        public string $canonicalUrl,
        public string $ogImageUrl,
        public string $venueName,
        public array $venueLines,
        public string $categoryName,
        public string $categorySlug,
        public ?string $priceLabel,
        public bool $familyFriendly,
        public string $description,
        public ?string $organiserName,
        public ?string $contactText,
        public ?string $contactHref,
        public ?string $organiserWebsiteUrl,
        public ?string $organiserWebsiteHost,
        public ?string $sourceNote,
        public ?string $sourceUrl,
        public ?string $sourceHost,
        public ?array $attribution,
        public array $alsoListedBy,
        public ?array $readMore,
        public bool $isSeries,
        public array $dates,
        public int $remaining,
        public HtmlString $jsonLd,
        public ShareLinks $share,
    ) {}

    public static function make(Event $event, UpcomingDates $dates, CarbonImmutable $now): self
    {
        $isCancelled = $event->status === EventStatus::Cancelled;
        $first = $dates->first();
        $prefix = $isCancelled ? 'Cancelled: ' : '';
        $venue = $event->venue;
        // D-03 (T-02-11): the contact reaches residents only with consent.
        $contact = $event->contact_public ? self::blankToNull($event->organiser_contact) : null;
        $source = SafeLinks::http($event->source_url);
        // http/https only (T-02-12); anything else leaves no website line.
        $website = SafeLinks::http($event->organiser_website);
        [$attribution, $alsoListedBy] = self::attributions($event);

        if ($attribution !== null) {
            // C10: the source credit replaces the Phase 2 source URL line.
            $source = null;
        }

        return new self(
            title: $event->title,
            isCancelled: $isCancelled,
            pageTitle: $prefix.$event->title.($first !== null ? ' – '.LondonFormatter::shortDate($first->starts_at, $now) : ''),
            ogTitle: $prefix.$event->title,
            metaDescription: self::metaDescription($event, $first),
            canonicalUrl: PublicUrl::event($event),
            ogImageUrl: PublicUrl::to('images/og-default.png'),
            venueName: $venue->name,
            venueLines: array_values(array_filter(
                array_map(self::blankToNull(...), [$venue->address, $venue->town, $venue->postcode]),
                fn (?string $line): bool => $line !== null,
            )),
            categoryName: $event->category->name,
            categorySlug: $event->category->slug,
            priceLabel: OccurrenceCardData::priceLabel($event->price_type, self::blankToNull($event->price_text)),
            familyFriendly: $event->family_friendly,
            description: $event->description,
            organiserName: self::blankToNull($event->organiser_name),
            contactText: $contact,
            contactHref: SafeLinks::contactHref($contact),
            organiserWebsiteUrl: $website['url'] ?? null,
            organiserWebsiteHost: $website['host'] ?? null,
            sourceNote: self::blankToNull($event->source_note),
            sourceUrl: $source['url'] ?? null,
            sourceHost: $source['host'] ?? null,
            attribution: $attribution,
            alsoListedBy: $alsoListedBy,
            readMore: $attribution !== null && $attribution['url'] !== null
                ? ['label' => 'Read more at '.$attribution['name'], 'url' => $attribution['url']]
                : null,
            isSeries: $event->recurrence_rule !== null,
            dates: $dates->occurrences->map(fn (EventOccurrence $occurrence): array => [
                'datetimeIso' => LondonFormatter::local($occurrence->starts_at)->toIso8601String(),
                'timeLabel' => LondonFormatter::datedTime($occurrence),
                'isCancelled' => OccurrenceIcs::isCancelled($occurrence),
                'icsPath' => PublicUrl::icsPath($occurrence),
                'googleUrl' => GoogleCalendarLink::for($occurrence),
            ])->values()->all(),
            remaining: $dates->remaining,
            jsonLd: EventJsonLd::scripts($event, $dates->occurrences),
            share: ShareLinks::for($event, $first),
        );
    }

    /**
     * "{next card time} at {venue}. {description}" (or "{venue}. {description}"
     * with no upcoming date), cut at 160 graphemes; the site tagline when the
     * description is empty after whitespace collapsing.
     */
    private static function metaDescription(Event $event, ?EventOccurrence $first): string
    {
        $description = TextLimit::graphemes($event->description, PHP_INT_MAX);

        if ($description === '') {
            return (string) config('site.tagline');
        }

        $lead = $first !== null
            ? LondonFormatter::cardTime($first).' at '.$event->venue->name
            : $event->venue->name;

        return TextLimit::graphemes($lead.'. '.$description, self::META_DESCRIPTION_GRAPHEMES);
    }

    /**
     * The primary link and the others, in linked_at then id order (the
     * eventSources relation's order).
     *
     * @return array{0: array{name: string, url: string|null}|null, 1: list<array{name: string, url: string|null}>}
     */
    private static function attributions(Event $event): array
    {
        $primary = null;
        $others = [];

        foreach ($event->eventSources as $link) {
            $entry = self::attributionFor($link);

            if ($link->is_primary && $primary === null) {
                $primary = $entry;
            } else {
                $others[] = $entry;
            }
        }

        // An event always has a primary once linked; if not, credit the oldest link.
        if ($primary === null && $others !== []) {
            $primary = array_shift($others);
        }

        return [$primary, $others];
    }

    /**
     * Source name plus the item's own page, else the source's events page
     * (D-16), else the home page of the feed's site, so every discovered
     * event links somewhere a resident can read (LEG-04). http/https only;
     * the feed file itself is never linked.
     *
     * @return array{name: string, url: string|null}
     */
    private static function attributionFor(EventSource $link): array
    {
        $source = $link->source;
        $feed = SafeLinks::http($source->url);
        $feedScheme = $feed !== null ? strtolower((string) parse_url($feed['url'], PHP_URL_SCHEME)) : null;
        $feedHost = $feed !== null ? parse_url($feed['url'], PHP_URL_HOST) : null;
        $home = is_string($feedHost) && $feedHost !== '' ? $feedScheme.'://'.$feedHost.'/' : null;

        return [
            'name' => $source->name,
            'url' => SafeLinks::http($link->item_url)['url']
                ?? SafeLinks::http($source->events_page_url)['url']
                ?? SafeLinks::http($home)['url']
                ?? null,
        ];
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
