<?php

namespace App\Support;

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Models\EventOccurrence;

/**
 * Card view model for the public list (CAL-07). The card partial renders
 * only these fields, all through escaped echo.
 *
 * A discovered event shows "via {source short name}" (D-14) from the
 * eager-loaded event.primarySource.source instead of the Phase 2
 * "Source: {host}" line (C10). The contact fallback is unchanged.
 */
final readonly class OccurrenceCardData
{
    public function __construct(
        public string $title,
        public string $eventPath,
        public string $timeLabel,
        public string $datetimeIso,
        public string $venueName,
        public string $categoryName,
        public string $categorySlug,
        public bool $familyFriendly,
        public ?string $excerpt,
        public ?string $priceLabel,
        public ?string $organiserName,
        public ?string $sourceUrl,
        public ?string $sourceHost,
        public ?string $contactText,
        public ?string $contactHref,
        public ?string $via,
        public bool $isCancelled,
    ) {}

    public static function fromOccurrence(EventOccurrence $occurrence): self
    {
        $event = $occurrence->event;
        $source = SafeLinks::http($event->source_url);
        $sourceUrl = $source['url'] ?? null;
        // Source URL first; the contact is the fallback only when the
        // organiser agreed to show it (contact_public, D-03 / T-02-11).
        $contact = $sourceUrl === null && $event->contact_public ? self::blankToNull($event->organiser_contact) : null;
        $via = self::via($occurrence);

        if ($via !== null) {
            $source = null;
            $sourceUrl = null;
        }

        return new self(
            title: $event->title,
            eventPath: PublicUrl::eventPath($event),
            timeLabel: LondonFormatter::cardTime($occurrence),
            datetimeIso: LondonFormatter::local($occurrence->starts_at)->toIso8601String(),
            venueName: $event->venue->name,
            categoryName: $event->category->name,
            categorySlug: $event->category->slug,
            familyFriendly: $event->family_friendly,
            excerpt: self::excerpt($event->description, (int) config('calendar.card_description_chars', 140)),
            priceLabel: self::priceLabel($event->price_type, self::blankToNull($event->price_text)),
            organiserName: self::blankToNull($event->organiser_name),
            sourceUrl: $sourceUrl,
            sourceHost: $source['host'] ?? null,
            contactText: $contact,
            // Cards link only email contacts (never tel:), as in 01-06.
            contactHref: SafeLinks::mailto($contact),
            via: $via,
            isCancelled: $event->status === EventStatus::Cancelled
                || $occurrence->status === OccurrenceStatus::Cancelled,
        );
    }

    /**
     * Plain-text excerpt: whitespace runs (Unicode-aware) collapse to one
     * space; longer than $limit code points is cut to at most $limit, at a
     * word boundary when one exists, followed by "…". Multibyte-safe, and
     * never strips or rewrites the text itself.
     */
    public static function excerpt(?string $text, int $limit): ?string
    {
        $text = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', (string) $text));

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $cut = mb_substr($text, 0, $limit);

        if (mb_substr($text, $limit, 1) !== ' ') {
            $lastSpace = mb_strrpos($cut, ' ');

            if ($lastSpace !== false && $lastSpace > 0) {
                $cut = mb_substr($cut, 0, $lastSpace);
            }
        }

        return rtrim($cut).'…';
    }

    /**
     * Free → "Free"; paid → price text or "Paid"; unknown → price text or
     * nothing. The price text is shown exactly as entered.
     */
    public static function priceLabel(PriceType $type, ?string $text): ?string
    {
        return match ($type) {
            PriceType::Free => 'Free',
            PriceType::Paid => $text ?? 'Paid',
            PriceType::Unknown => $text,
        };
    }

    /**
     * The primary source's short name, or its name when the short name is
     * empty; null for an event with no source link.
     */
    private static function via(EventOccurrence $occurrence): ?string
    {
        $source = $occurrence->event->primarySource?->source;

        if ($source === null) {
            return null;
        }

        return self::blankToNull($source->short_name) ?? self::blankToNull($source->name);
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
