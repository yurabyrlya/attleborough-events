<?php

namespace App\Support;

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Models\EventOccurrence;

/**
 * Card view model for the public list (CAL-07). The card partial renders
 * only these fields, all through escaped echo.
 */
final readonly class OccurrenceCardData
{
    public function __construct(
        public string $title,
        public string $timeLabel,
        public string $datetimeIso,
        public string $venueName,
        public string $categoryName,
        public bool $familyFriendly,
        public ?string $excerpt,
        public ?string $priceLabel,
        public ?string $organiserName,
        public ?string $sourceUrl,
        public ?string $sourceHost,
        public ?string $contactText,
        public ?string $contactHref,
        public bool $isCancelled,
    ) {}

    public static function fromOccurrence(EventOccurrence $occurrence): self
    {
        $event = $occurrence->event;
        [$sourceUrl, $sourceHost] = self::safeSource($event->source_url);
        $contact = $sourceUrl === null ? self::blankToNull($event->organiser_contact) : null;

        return new self(
            title: $event->title,
            timeLabel: LondonFormatter::cardTime($occurrence),
            datetimeIso: LondonFormatter::local($occurrence->starts_at)->toIso8601String(),
            venueName: $event->venue->name,
            categoryName: $event->category->name,
            familyFriendly: $event->family_friendly,
            excerpt: self::excerpt($event->description, (int) config('calendar.card_description_chars', 140)),
            priceLabel: self::priceLabel($event->price_type, self::blankToNull($event->price_text)),
            organiserName: self::blankToNull($event->organiser_name),
            sourceUrl: $sourceUrl,
            sourceHost: $sourceHost,
            contactText: $contact,
            contactHref: $contact !== null && filter_var($contact, FILTER_VALIDATE_EMAIL) !== false
                ? 'mailto:'.$contact
                : null,
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
    private static function priceLabel(PriceType $type, ?string $text): ?string
    {
        return match ($type) {
            PriceType::Free => 'Free',
            PriceType::Paid => $text ?? 'Paid',
            PriceType::Unknown => $text,
        };
    }

    /**
     * Only http(s) URLs with a host become links (T-01-23).
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function safeSource(?string $url): array
    {
        $url = self::blankToNull($url);

        if ($url === null) {
            return [null, null];
        }

        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
        $host = is_array($parts) ? strtolower($parts['host'] ?? '') : '';

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return [null, null];
        }

        return [$url, (string) preg_replace('/^www\./', '', $host)];
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
