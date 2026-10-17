<?php

namespace App\Seo;

use App\Calendar\Export\OccurrenceIcs;
use App\Enums\PriceType;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Support\LondonFormatter;
use App\Support\PublicUrl;
use App\Support\TextLimit;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Spatie\SchemaOrg\EventAttendanceModeEnumeration;
use Spatie\SchemaOrg\EventStatusType;
use Spatie\SchemaOrg\Schema;

/**
 * Schema.org Event JSON-LD for the event page (EVT-03): one script per
 * occurrence passed in, which is the same at most 10 listed upcoming dates
 * as the visible dates list (hidden dates are already left out).
 *
 *  - startDate: London-zoned instant (ATOM, +01:00 / +00:00) for timed
 *    dates, the London Y-m-d for all-day dates
 *  - endDate only when known; all-day ends are stored exclusive, schema.org
 *    endDate is inclusive, so the last day is the exclusive end minus a day
 *  - eventStatus EventCancelled when the event or the date is cancelled;
 *    every other property is kept (Pitfall 14)
 *  - url/image are absolute from config('app.url') via PublicUrl, never
 *    the Host header (T-01-68)
 *  - free events get isAccessibleForFree and a GBP 0 Offer; paid or unknown
 *    prices get no Offer because price_text is never parsed
 *
 * spatie's toScript() encodes with JSON_HEX_TAG, so a "</script>" in admin
 * text cannot close the element (T-01-67). The concatenated scripts are
 * returned as an HtmlString so the view prints them with escaped echo.
 */
final class EventJsonLd
{
    public const DESCRIPTION_GRAPHEMES = 300;

    /**
     * @param  Collection<int, EventOccurrence>  $occurrences
     */
    public static function scripts(Event $event, Collection $occurrences): HtmlString
    {
        return new HtmlString($occurrences
            ->map(fn (EventOccurrence $occurrence): string => self::forOccurrence($event, $occurrence))
            ->implode(''));
    }

    private static function forOccurrence(Event $event, EventOccurrence $occurrence): string
    {
        $occurrence->setRelation('event', $event);
        $url = PublicUrl::event($event);

        // eventStatus, eventAttendanceMode and all-day dates are schema.org
        // URL / Date strings, which spatie's generated setter docblocks do not
        // model (they list only enum contracts and DateTimeInterface), so
        // they go through the generic setProperty().
        $schema = Schema::event()
            ->name($event->title)
            ->setProperty('eventStatus', OccurrenceIcs::isCancelled($occurrence)
                ? EventStatusType::EventCancelled
                : EventStatusType::EventScheduled)
            ->setProperty('eventAttendanceMode', EventAttendanceModeEnumeration::OfflineEventAttendanceMode)
            ->url($url)
            ->image(PublicUrl::to('images/og-default.png'));

        if ($event->all_day) {
            $end = $occurrence->ends_at ?? $occurrence->expires_at;
            $schema->setProperty('startDate', LondonFormatter::local($occurrence->starts_at)->format('Y-m-d'))
                ->setProperty('endDate', LondonFormatter::local($end)->startOfDay()->subDay()->format('Y-m-d'));
        } else {
            $schema->startDate(LondonFormatter::local($occurrence->starts_at));

            if ($occurrence->ends_at !== null) {
                $schema->endDate(LondonFormatter::local($occurrence->ends_at));
            }
        }

        $venue = $event->venue;

        if ($venue !== null) {
            $address = Schema::postalAddress()->addressCountry('GB');

            if (($street = self::filled($venue->address)) !== null) {
                $address->streetAddress($street);
            }

            if (($town = self::filled($venue->town)) !== null) {
                $address->addressLocality($town);
            }

            if (($postcode = self::filled($venue->postcode)) !== null) {
                $address->postalCode($postcode);
            }

            $schema->location(Schema::place()->name($venue->name)->address($address));
        }

        if (($organiser = self::filled($event->organiser_name)) !== null) {
            $schema->organizer(Schema::organization()->name($organiser));
        }

        $description = TextLimit::graphemes($event->description, self::DESCRIPTION_GRAPHEMES);

        if ($description !== '') {
            $schema->description($description);
        }

        if ($event->price_type === PriceType::Free) {
            $schema->isAccessibleForFree(true)
                ->offers(Schema::offer()->price(0)->priceCurrency('GBP')->url($url));
        }

        return $schema->toScript();
    }

    private static function filled(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
