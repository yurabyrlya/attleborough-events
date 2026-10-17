<?php

namespace App\Review\Data;

use App\Enums\EventOrigin;
use App\Enums\ExtractionMethod;
use App\Enums\PriceType;
use App\Publishing\Data\PublishableEventData;
use Carbon\CarbonImmutable;

/**
 * Everything a draft's field data can hold, independent of where it came
 * from (admin manual entry now; public submissions in 02-05; the review edit
 * form in 02-14).
 *
 * Unlike PublishableEventData this never throws on incomplete data: drafts
 * may lack fields, and those gaps become quality reasons and approval
 * blockers. The recurrence is carried as the stored RRULE string, never as
 * the form enums.
 */
final readonly class DraftData
{
    /**
     * @param  array<string, mixed>|null  $rawPayload
     */
    public function __construct(
        public EventOrigin $origin,
        public ExtractionMethod $extractionMethod,
        public string $title,
        public ?string $description = null,
        public ?int $preferredCategoryId = null,
        public ?int $venueId = null,
        public ?string $venueName = null,
        public ?string $venueAddress = null,
        public ?string $venuePostcode = null,
        public ?string $venueTown = null,
        public string $timezone = 'Europe/London',
        public bool $allDay = false,
        public ?string $startDate = null,
        public ?string $startTime = null,
        public ?string $endDate = null,
        public ?string $endTime = null,
        public ?string $recurrenceRule = null,
        public ?string $repeatsText = null,
        public bool $repeatsAcknowledged = false,
        public bool $familyFriendly = false,
        public PriceType $priceType = PriceType::Unknown,
        public ?string $priceText = null,
        public ?string $organiserName = null,
        public ?string $organiserContact = null,
        public ?string $organiserWebsite = null,
        public bool $contactPublic = false,
        public ?string $sourceUrl = null,
        public ?string $sourceNote = null,
        public ?array $rawPayload = null,
        public ?CarbonImmutable $consentedAt = null,
    ) {}

    /**
     * The admin's New event form (EventForm keys). The form is already
     * validated, so its state is normalised through PublishableEventData and
     * the RRULE is built exactly as that DTO builds it for the event.
     *
     * @param  array<string, mixed>  $data  Validated Filament form state
     */
    public static function fromManualForm(array $data): self
    {
        $publishable = PublishableEventData::fromForm($data);
        $attributes = $publishable->toAttributes();

        return new self(
            origin: EventOrigin::Manual,
            extractionMethod: ExtractionMethod::Manual,
            title: $publishable->title,
            description: $publishable->description,
            preferredCategoryId: $publishable->categoryId,
            venueId: $publishable->venueId,
            timezone: $publishable->timezone,
            allDay: $publishable->allDay,
            startDate: $attributes['start_date'],
            startTime: $attributes['start_time'],
            endDate: $attributes['end_date'],
            endTime: $attributes['end_time'],
            recurrenceRule: $attributes['recurrence_rule'],
            familyFriendly: $publishable->familyFriendly,
            priceType: $publishable->priceType,
            priceText: $publishable->priceText,
            organiserName: $publishable->organiserName,
            organiserContact: $publishable->organiserContact,
            organiserWebsite: self::nullableString($data['organiser_website'] ?? null),
            contactPublic: (bool) ($data['contact_public'] ?? true),
            sourceUrl: $publishable->sourceUrl,
            sourceNote: $publishable->sourceNote,
        );
    }

    /**
     * The fillable content columns of EventDraft. Review state (reference,
     * origin, category, raw payload, consent ...) is never in here; the
     * DraftWriter forceFills it.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'venue_id' => $this->venueId,
            'venue_name' => $this->venueName,
            'venue_address' => $this->venueAddress,
            'venue_postcode' => $this->venuePostcode,
            'venue_town' => $this->venueTown,
            'timezone' => $this->timezone,
            'all_day' => $this->allDay,
            'start_date' => $this->startDate,
            'start_time' => $this->allDay ? null : $this->startTime,
            'end_date' => $this->endDate,
            'end_time' => $this->allDay ? null : $this->endTime,
            'recurrence_rule' => $this->recurrenceRule,
            'repeats_text' => $this->repeatsText,
            'repeats_acknowledged' => $this->repeatsAcknowledged,
            'family_friendly' => $this->familyFriendly,
            'price_type' => $this->priceType,
            'price_text' => $this->priceText,
            'organiser_name' => $this->organiserName,
            'organiser_contact' => $this->organiserContact,
            'organiser_website' => $this->organiserWebsite,
            'contact_public' => $this->contactPublic,
            'source_url' => $this->sourceUrl,
            'source_note' => $this->sourceNote,
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
}
