<?php

namespace App\Support;

use App\Calendar\Export\GoogleCalendarLink;
use App\Calendar\Export\OccurrenceIcs;
use App\Calendar\UpcomingDates;
use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\EventOccurrence;
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
 */
final readonly class EventPageData
{
    public const META_DESCRIPTION_GRAPHEMES = 160;

    /**
     * @param  list<string>  $venueLines
     * @param  list<array{datetimeIso: string, timeLabel: string, isCancelled: bool, icsPath: string, googleUrl: string}>  $dates
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

    private static function blankToNull(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
