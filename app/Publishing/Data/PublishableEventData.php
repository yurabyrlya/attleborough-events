<?php

namespace App\Publishing\Data;

use App\Calendar\ManualRecurrence;
use App\Enums\PriceType;
use App\Enums\RepeatPattern;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

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
        public RepeatPattern $repeats = RepeatPattern::None,
        public string $repeatEnds = 'never',
        public ?string $repeatUntil = null,
        public ?int $repeatCount = null,
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
            repeats: RepeatPattern::from((string) ($data['repeats'] ?? RepeatPattern::None->value)),
            repeatEnds: (string) ($data['repeat_ends'] ?? 'never'),
            repeatUntil: self::nullableDate($data['repeat_until'] ?? null),
            repeatCount: self::nullableInt($data['repeat_count'] ?? null),
        );
    }

    /**
     * Form state for an existing event: its attributes plus the Repeats/Ends
     * fields recovered from the stored recurrence_rule.
     *
     * @return array<string, mixed>
     */
    public static function fromEvent(Event $event): array
    {
        $attributes = $event->attributesToArray();
        $attributes['start_date'] = $event->start_date->format('Y-m-d');
        $attributes['end_date'] = $event->end_date?->format('Y-m-d');

        return array_merge(
            $attributes,
            ManualRecurrence::fromRrule($event->recurrence_rule, $event->all_day)->toFormState(),
        );
    }

    public function recurrence(): ManualRecurrence
    {
        return new ManualRecurrence($this->repeats, $this->repeatEnds, $this->repeatUntil, $this->repeatCount);
    }

    public function isRecurring(): bool
    {
        return $this->repeats !== RepeatPattern::None;
    }

    public function startLocal(): CarbonImmutable
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $this->startDate, $this->timezone);

        if (! $start instanceof CarbonImmutable) {
            throw new InvalidArgumentException("Invalid start date [{$this->startDate}].");
        }

        return $start;
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
            // Repeating events are single-day (overnight end allowed): no end_date.
            'end_date' => $this->isRecurring() ? null : $this->endDate,
            'end_time' => $this->allDay ? null : $this->endTime,
            'recurrence_rule' => $this->recurrence()->toRrule($this->startLocal(), $this->allDay),
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

    private static function nullableInt(mixed $value): ?int
    {
        $value = self::nullableString($value);

        return $value === null ? null : (int) $value;
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
