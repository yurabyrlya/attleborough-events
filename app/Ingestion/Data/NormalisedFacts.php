<?php

namespace App\Ingestion\Data;

use App\Enums\PriceType;
use Carbon\CarbonImmutable;

/**
 * The normalised facts of one feed item (one series identity).
 *
 * Dates and times are the LOCAL Europe/London wall-clock schedule, exactly as
 * drafts and events store them (start_date Y-m-d, start_time H:i, end_date
 * null when the event ends on its start date). nextInstanceStartUtc is the
 * only instant and is UTC.
 *
 * description, url, categories and organiser are kept for review only and
 * never feed the content hash (D-06). organiser never reaches public fields
 * (D-17): it lives only in source_items.payload.
 *
 * Series shapes (D-19): excludedDates (EXDATE, London Y-m-d) and
 * cancelledKeys (cancelled RECURRENCE-ID overrides) are publishable;
 * movedOverrides and extraDates (RDATE) are not, and the extractor adds an
 * item parse error for them, but they are still hashed so a linked series
 * cannot change shape silently. Every list is sorted on construction, so
 * argument order never changes toPayload() or the hash.
 */
final readonly class NormalisedFacts
{
    public string $title;

    public bool $allDay;

    public string $startDate;

    public ?string $startTime;

    public ?string $endDate;

    public ?string $endTime;

    public ?string $venueName;

    public ?string $venueAddress;

    public ?string $postcode;

    public ?string $url;

    /** @var list<string> */
    public array $categories;

    public ?string $description;

    /** @var array{name: ?string, email: ?string}|null */
    public ?array $organiser;

    public bool $cancelled;

    public ?string $rrule;

    /** @var list<string> */
    public array $excludedDates;

    /** @var list<string> */
    public array $cancelledKeys;

    /** @var list<array{key: string, start: string, end: ?string}> */
    public array $movedOverrides;

    /** @var list<string> */
    public array $extraDates;

    public ?CarbonImmutable $nextInstanceStartUtc;

    public PriceType $priceType;

    public bool $familyFriendly;

    /**
     * @param  list<string>  $categories
     * @param  array{name: ?string, email: ?string}|null  $organiser
     * @param  list<string>  $excludedDates
     * @param  list<string>  $cancelledKeys
     * @param  list<array{key: string, start: string, end: ?string}>  $movedOverrides
     * @param  list<string>  $extraDates
     */
    public function __construct(
        string $title,
        bool $allDay,
        string $startDate,
        ?string $startTime = null,
        ?string $endDate = null,
        ?string $endTime = null,
        ?string $venueName = null,
        ?string $venueAddress = null,
        ?string $postcode = null,
        ?string $url = null,
        array $categories = [],
        ?string $description = null,
        ?array $organiser = null,
        bool $cancelled = false,
        ?string $rrule = null,
        array $excludedDates = [],
        array $cancelledKeys = [],
        array $movedOverrides = [],
        array $extraDates = [],
        ?CarbonImmutable $nextInstanceStartUtc = null,
        PriceType $priceType = PriceType::Unknown,
        bool $familyFriendly = false,
    ) {
        $this->title = $title;
        $this->allDay = $allDay;
        $this->startDate = $startDate;
        $this->startTime = $startTime;
        $this->endDate = $endDate;
        $this->endTime = $endTime;
        $this->venueName = $venueName;
        $this->venueAddress = $venueAddress;
        $this->postcode = $postcode;
        $this->url = $url;
        $this->categories = $categories;
        $this->description = $description;
        $this->organiser = $organiser;
        $this->cancelled = $cancelled;
        $this->rrule = $rrule;
        $this->excludedDates = self::sortedDates($excludedDates);
        $this->cancelledKeys = self::sortedDates($cancelledKeys);
        $this->movedOverrides = self::sortedOverrides($movedOverrides);
        $this->extraDates = self::sortedDates($extraDates);
        $this->nextInstanceStartUtc = $nextInstanceStartUtc?->utc();
        $this->priceType = $priceType;
        $this->familyFriendly = $familyFriendly;
    }

    /**
     * The source_items.payload shape (internal; organiser included, D-17).
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'title' => $this->title,
            'all_day' => $this->allDay,
            'start_date' => $this->startDate,
            'start_time' => $this->startTime,
            'end_date' => $this->endDate,
            'end_time' => $this->endTime,
            'venue_name' => $this->venueName,
            'venue_address' => $this->venueAddress,
            'postcode' => $this->postcode,
            'url' => $this->url,
            'categories' => $this->categories,
            'description' => $this->description,
            'organiser' => $this->organiser,
            'cancelled' => $this->cancelled,
            'rrule' => $this->rrule,
            'excluded_dates' => $this->excludedDates,
            'cancelled_keys' => $this->cancelledKeys,
            'moved_overrides' => $this->movedOverrides,
            'extra_dates' => $this->extraDates,
            'next_instance_start_utc' => $this->nextInstanceStartUtc?->format('Y-m-d\TH:i:s\Z'),
            'price_type' => $this->priceType->value,
            'family_friendly' => $this->familyFriendly,
        ];
    }

    /**
     * Rebuilds the facts from source_items.payload (or accepted_payload), the
     * inverse of toPayload(). Missing or mistyped keys fall back to the
     * constructor defaults instead of throwing, so an older payload still
     * hashes.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        $next = self::stringOrNull($payload['next_instance_start_utc'] ?? null);
        $organiser = $payload['organiser'] ?? null;

        return new self(
            title: self::stringOrNull($payload['title'] ?? null) ?? '',
            allDay: (bool) ($payload['all_day'] ?? false),
            startDate: self::stringOrNull($payload['start_date'] ?? null) ?? '',
            startTime: self::stringOrNull($payload['start_time'] ?? null),
            endDate: self::stringOrNull($payload['end_date'] ?? null),
            endTime: self::stringOrNull($payload['end_time'] ?? null),
            venueName: self::stringOrNull($payload['venue_name'] ?? null),
            venueAddress: self::stringOrNull($payload['venue_address'] ?? null),
            postcode: self::stringOrNull($payload['postcode'] ?? null),
            url: self::stringOrNull($payload['url'] ?? null),
            categories: self::stringList($payload['categories'] ?? []),
            description: self::stringOrNull($payload['description'] ?? null),
            organiser: is_array($organiser) ? [
                'name' => self::stringOrNull($organiser['name'] ?? null),
                'email' => self::stringOrNull($organiser['email'] ?? null),
            ] : null,
            cancelled: (bool) ($payload['cancelled'] ?? false),
            rrule: self::stringOrNull($payload['rrule'] ?? null),
            excludedDates: self::stringList($payload['excluded_dates'] ?? []),
            cancelledKeys: self::stringList($payload['cancelled_keys'] ?? []),
            movedOverrides: self::overrideList($payload['moved_overrides'] ?? []),
            extraDates: self::stringList($payload['extra_dates'] ?? []),
            nextInstanceStartUtc: $next !== null ? CarbonImmutable::parse($next, 'UTC') : null,
            priceType: PriceType::tryFrom(self::stringOrNull($payload['price_type'] ?? null) ?? '') ?? PriceType::Unknown,
            familyFriendly: (bool) ($payload['family_friendly'] ?? false),
        );
    }

    /**
     * @param  list<string>  $dates
     * @return list<string>
     */
    private static function sortedDates(array $dates): array
    {
        $dates = array_values(array_unique($dates));
        sort($dates, SORT_STRING);

        return $dates;
    }

    /**
     * @param  list<array{key: string, start: string, end: ?string}>  $overrides
     * @return list<array{key: string, start: string, end: ?string}>
     */
    private static function sortedOverrides(array $overrides): array
    {
        $normalised = array_map(
            fn (array $override): array => ['key' => $override['key'], 'start' => $override['start'], 'end' => $override['end']],
            $overrides,
        );

        usort($normalised, fn (array $a, array $b): int => [$a['key'], $a['start'], $a['end'] ?? ''] <=> [$b['key'], $b['start'], $b['end'] ?? '']);

        return $normalised;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }

    /**
     * @return list<array{key: string, start: string, end: ?string}>
     */
    private static function overrideList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $overrides = [];

        foreach ($value as $override) {
            if (! is_array($override) || ! is_string($override['key'] ?? null) || ! is_string($override['start'] ?? null)) {
                continue;
            }

            $overrides[] = [
                'key' => $override['key'],
                'start' => $override['start'],
                'end' => self::stringOrNull($override['end'] ?? null),
            ];
        }

        return $overrides;
    }
}
