<?php

namespace App\Review\Data;

use App\Calendar\ManualRecurrence;
use App\Enums\EventOrigin;
use App\Enums\ExtractionMethod;
use App\Enums\PriceType;
use App\Enums\RepeatEnds;
use App\Enums\RepeatPattern;
use App\Models\EventDraft;
use App\Publishing\Data\PublishableEventData;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Everything a draft's field data can hold, independent of where it came
 * from (admin manual entry, public submissions, the review edit form).
 *
 * Unlike PublishableEventData this never throws on incomplete data: drafts
 * may lack fields, and those gaps become quality reasons and approval
 * blockers. The recurrence is carried as the stored RRULE string, never as
 * the form enums.
 */
final readonly class DraftData
{
    /**
     * Submission keys that never go into raw_payload: the consent tick (kept
     * as consented_at) and the spam-defence fields (02-09).
     */
    private const NOT_IN_RAW_PAYLOAD = ['consent', 'fax_number', 'form_token', 'cf-turnstile-response'];

    /**
     * CategoryAssigner's reason for an admin's pick.
     */
    public const ADMIN_CATEGORY_REASON = 'Chosen by admin';

    /**
     * @param  array<string, mixed>|null  $rawPayload
     */
    public function __construct(
        public EventOrigin $origin,
        public ExtractionMethod $extractionMethod,
        public string $title,
        public ?string $description = null,
        public ?int $preferredCategoryId = null,
        // True when preferredCategoryId is the admin's pick on the review
        // form, so the reason reads "Chosen by admin" whatever the origin.
        public bool $categoryChosenByAdmin = false,
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
     * A public submission (SUB-01), from StoreSubmissionRequest::validated().
     *
     * The schedule is stored as the organiser's London wall-clock values,
     * exactly as typed (H:i becomes H:i:00); nothing is converted to UTC.
     * "Does it repeat?" stays free text: the admin turns it into a rule at
     * review (D-06), so recurrenceRule is always null here. Contact details
     * are private unless the organiser ticked "Show my contact publicly".
     * raw_payload keeps what was sent, minus the consent tick and the spam
     * fields (02-09), for the reviewer.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromSubmission(array $validated): self
    {
        $venueChoice = (string) ($validated['venue_choice'] ?? '');
        $knownVenue = ctype_digit($venueChoice);
        $categoryChoice = (string) ($validated['category_choice'] ?? '');
        $allDay = (bool) ($validated['all_day'] ?? false);

        return new self(
            origin: EventOrigin::Submission,
            extractionMethod: ExtractionMethod::Submission,
            title: (string) $validated['title'],
            description: self::nullableString($validated['description'] ?? null),
            preferredCategoryId: ctype_digit($categoryChoice) ? (int) $categoryChoice : null,
            venueId: $knownVenue ? (int) $venueChoice : null,
            venueName: $knownVenue ? null : self::nullableString($validated['venue_name'] ?? null),
            venueAddress: $knownVenue ? null : self::nullableString($validated['venue_address'] ?? null),
            venuePostcode: $knownVenue ? null : self::nullableString($validated['venue_postcode'] ?? null),
            timezone: (string) config('calendar.timezone', 'Europe/London'),
            allDay: $allDay,
            startDate: self::nullableString($validated['start_date'] ?? null),
            startTime: $allDay ? null : self::wallClockTime($validated['start_time'] ?? null),
            endDate: self::nullableString($validated['end_date'] ?? null),
            endTime: $allDay ? null : self::wallClockTime($validated['end_time'] ?? null),
            recurrenceRule: null,
            repeatsText: self::nullableString($validated['repeats_text'] ?? null),
            familyFriendly: (bool) ($validated['family_friendly'] ?? false),
            priceType: PriceType::tryFrom((string) ($validated['price_type'] ?? '')) ?? PriceType::Unknown,
            priceText: self::nullableString($validated['price_text'] ?? null),
            organiserName: self::nullableString($validated['organiser_name'] ?? null),
            organiserContact: self::nullableString($validated['organiser_email'] ?? null),
            organiserWebsite: self::nullableString($validated['organiser_website'] ?? null),
            contactPublic: (bool) ($validated['contact_public'] ?? false),
            sourceUrl: self::nullableString($validated['source_url'] ?? null),
            rawPayload: array_diff_key($validated, array_flip(self::NOT_IN_RAW_PAYLOAD)),
            consentedAt: CarbonImmutable::now()->utc(),
        );
    }

    /**
     * The review edit form (EventForm keys, REV-03) applied to an existing
     * draft.
     *
     * Origin, extraction method, the organiser's typed venue, the repeats
     * text, the raw payload and consent stay the draft's. The RRULE is built
     * with ManualRecurrence exactly as PublishableEventData builds it, but
     * without constructing PublishableEventData, so an incomplete draft
     * (e.g. no venue yet) still saves. A stored rule the form cannot
     * represent (Repeats shown read-only) is kept as stored, whatever the
     * submitted state says, so a save never drops it (Pitfall 11).
     *
     * Category: when the admin picks a different category than the stored
     * one (or already chose it before), it is the admin's choice and the
     * reason becomes "Chosen by admin". An unchanged category re-runs the
     * same chain the draft was created with (the organiser's pick for a
     * submission), so saving does not relabel it.
     *
     * The draft's stored values are read with getOriginal(): Filament's
     * BelongsTo selects associate the form's pick on the in-memory record
     * before the page hands the state over.
     *
     * @param  array<string, mixed>  $data  Validated Filament form state
     */
    public static function fromReviewForm(array $data, EventDraft $draft): self
    {
        $allDay = (bool) ($data['all_day'] ?? false);
        $startDate = self::nullableDate($data['start_date'] ?? null);
        $storedCategoryId = self::nullableInt($draft->getOriginal('category_id'));
        $formCategoryId = self::nullableInt($data['category_id'] ?? null);
        $chosenByAdmin = $formCategoryId !== null
            && ($formCategoryId !== $storedCategoryId || $draft->getOriginal('category_reason') === self::ADMIN_CATEGORY_REASON);
        $priceType = $data['price_type'] ?? PriceType::Unknown;

        return new self(
            origin: $draft->origin,
            extractionMethod: $draft->extraction_method,
            title: trim((string) ($data['title'] ?? $draft->title)),
            description: self::nullableString($data['description'] ?? null),
            preferredCategoryId: match (true) {
                $chosenByAdmin => $formCategoryId,
                $draft->origin === EventOrigin::Submission => $draft->submitted_category_id,
                default => $formCategoryId,
            },
            categoryChosenByAdmin: $chosenByAdmin,
            venueId: self::nullableInt($data['venue_id'] ?? null),
            venueName: $draft->venue_name,
            venueAddress: $draft->venue_address,
            venuePostcode: $draft->venue_postcode,
            venueTown: $draft->venue_town,
            timezone: $draft->timezone,
            allDay: $allDay,
            startDate: $startDate,
            startTime: $allDay ? null : self::formWallClockTime($data['start_time'] ?? null),
            endDate: self::nullableDate($data['end_date'] ?? null),
            endTime: $allDay ? null : self::formWallClockTime($data['end_time'] ?? null),
            recurrenceRule: self::reviewedRule($data, $draft, $startDate, $allDay),
            repeatsText: $draft->repeats_text,
            repeatsAcknowledged: (bool) ($data['repeats_acknowledged'] ?? $draft->repeats_acknowledged),
            familyFriendly: (bool) ($data['family_friendly'] ?? false),
            priceType: $priceType instanceof PriceType ? $priceType : (PriceType::tryFrom((string) $priceType) ?? PriceType::Unknown),
            priceText: self::nullableString($data['price_text'] ?? null),
            organiserName: self::nullableString($data['organiser_name'] ?? null),
            organiserContact: self::nullableString($data['organiser_contact'] ?? null),
            organiserWebsite: self::nullableString($data['organiser_website'] ?? null),
            contactPublic: (bool) ($data['contact_public'] ?? $draft->contact_public),
            sourceUrl: self::nullableString($data['source_url'] ?? null),
            sourceNote: self::nullableString($data['source_note'] ?? null),
            rawPayload: $draft->raw_payload,
            consentedAt: $draft->consented_at,
        );
    }

    /**
     * The draft's stored rule as a recurrence the form can edit, or null
     * when it does not round-trip exactly for the draft's own start date
     * and all_day (WR-10, as PublishableEventData::fromDraft() requires).
     * No rule gives "Does not repeat"; a rule without a start date cannot be
     * checked, so it counts as uneditable.
     */
    public static function storedRecurrence(EventDraft $draft): ?ManualRecurrence
    {
        $rule = $draft->getOriginal('recurrence_rule');
        $startDate = $draft->getOriginal('start_date');

        if ($rule === null) {
            return new ManualRecurrence;
        }

        if (! $startDate instanceof CarbonInterface) {
            return null;
        }

        $start = CarbonImmutable::createFromFormat('!Y-m-d', $startDate->format('Y-m-d'), $draft->timezone);

        if (! $start instanceof CarbonImmutable) {
            return null;
        }

        return ManualRecurrence::tryFromStoredRule((string) $rule, $start, (bool) $draft->getOriginal('all_day'));
    }

    /**
     * The rule a review save stores: the stored rule when the form cannot
     * represent it (or the submitted Repeats state cannot be built, which
     * validation prevents), otherwise the RRULE built from Repeats/Ends.
     *
     * @param  array<string, mixed>  $data
     */
    private static function reviewedRule(array $data, EventDraft $draft, ?string $startDate, bool $allDay): ?string
    {
        $storedRule = $draft->getOriginal('recurrence_rule');
        $storedRule = is_string($storedRule) ? $storedRule : null;

        if (self::storedRecurrence($draft) === null) {
            return $storedRule;
        }

        $pattern = $data['repeats'] ?? RepeatPattern::None;
        $pattern = $pattern instanceof RepeatPattern ? $pattern : RepeatPattern::tryFrom((string) $pattern);

        if ($pattern === null) {
            return $storedRule;
        }

        if ($pattern === RepeatPattern::None) {
            return null;
        }

        $ends = $data['repeat_ends'] ?? RepeatEnds::Never;
        $ends = $ends instanceof RepeatEnds ? $ends : RepeatEnds::tryFrom((string) $ends);
        $start = $startDate === null ? null : CarbonImmutable::createFromFormat('!Y-m-d', $startDate, $draft->timezone);

        if ($ends === null || ! $start instanceof CarbonImmutable) {
            return $storedRule;
        }

        try {
            $recurrence = new ManualRecurrence($pattern, $ends, self::nullableDate($data['repeat_until'] ?? null), self::nullableInt($data['repeat_count'] ?? null));

            return $recurrence->isValidFor($start) ? $recurrence->toRrule($start, $allDay) : $storedRule;
        } catch (InvalidArgumentException) {
            return $storedRule;
        }
    }

    /**
     * A form time (H:i or H:i:s) as the time column's H:i:s.
     */
    private static function formWallClockTime(mixed $value): ?string
    {
        $value = self::nullableString($value);

        if ($value === null) {
            return null;
        }

        return strlen($value) === 5 ? $value.':00' : $value;
    }

    private static function nullableDate(mixed $value): ?string
    {
        $value = self::nullableString($value);

        return $value === null ? null : substr($value, 0, 10);
    }

    private static function nullableInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        $value = self::nullableString(is_scalar($value) ? (string) $value : null);

        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }

    /**
     * A validated H:i as the time column's H:i:s, unchanged otherwise.
     */
    private static function wallClockTime(mixed $value): ?string
    {
        $value = self::nullableString($value);

        return $value === null ? null : $value.':00';
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
