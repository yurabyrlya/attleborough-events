<?php

namespace App\Publishing\Data;

use App\Enums\PriceType;
use Illuminate\Support\Str;

/**
 * Everything needed to publish an event, independent of where it came from
 * (Filament manual entry now; approved drafts in Phase 2).
 */
final readonly class PublishableEventData
{
    public function __construct(
        public string $title,
        public string $description,
        public int $categoryId,
        public int $venueId,
        public string $startDate,
        public ?string $startTime,
        public ?string $endDate = null,
        public ?string $endTime = null,
        public bool $allDay = false,
        public bool $familyFriendly = false,
        public PriceType $priceType = PriceType::Unknown,
        public ?string $priceText = null,
        public ?string $organiserName = null,
        public ?string $organiserContact = null,
        public ?string $sourceUrl = null,
        public ?string $sourceNote = null,
        public string $timezone = 'Europe/London',
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated Filament form state
     */
    public static function fromForm(array $data): self
    {
        $allDay = (bool) ($data['all_day'] ?? false);

        $priceType = $data['price_type'] ?? PriceType::Unknown;

        return new self(
            title: trim((string) $data['title']),
            description: trim((string) $data['description']),
            categoryId: (int) $data['category_id'],
            venueId: (int) $data['venue_id'],
            startDate: self::date($data['start_date']),
            startTime: $allDay ? null : self::nullableString($data['start_time'] ?? null),
            endDate: self::nullableDate($data['end_date'] ?? null),
            endTime: $allDay ? null : self::nullableString($data['end_time'] ?? null),
            allDay: $allDay,
            familyFriendly: (bool) ($data['family_friendly'] ?? false),
            priceType: $priceType instanceof PriceType ? $priceType : PriceType::from((string) $priceType),
            priceText: self::nullableString($data['price_text'] ?? null),
            organiserName: self::nullableString($data['organiser_name'] ?? null),
            organiserContact: self::nullableString($data['organiser_contact'] ?? null),
            sourceUrl: self::nullableString($data['source_url'] ?? null),
            sourceNote: self::nullableString($data['source_note'] ?? null),
            timezone: (string) config('calendar.timezone', 'Europe/London'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        $slug = Str::slug($this->title);

        return [
            'title' => $this->title,
            'slug' => $slug !== '' ? $slug : 'event',
            'description' => $this->description,
            'category_id' => $this->categoryId,
            'venue_id' => $this->venueId,
            'family_friendly' => $this->familyFriendly,
            'price_type' => $this->priceType,
            'price_text' => $this->priceText,
            'organiser_name' => $this->organiserName,
            'organiser_contact' => $this->organiserContact,
            'source_url' => $this->sourceUrl,
            'source_note' => $this->sourceNote,
            'timezone' => $this->timezone,
            'all_day' => $this->allDay,
            'start_date' => $this->startDate,
            'start_time' => $this->allDay ? null : $this->startTime,
            'end_date' => $this->endDate,
            'end_time' => $this->allDay ? null : $this->endTime,
            'recurrence_rule' => null,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function date(mixed $value): string
    {
        return substr(trim((string) $value), 0, 10);
    }

    private static function nullableDate(mixed $value): ?string
    {
        $value = self::nullableString($value);

        return $value === null ? null : self::date($value);
    }
}
