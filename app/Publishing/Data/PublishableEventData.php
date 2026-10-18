<?php

namespace App\Publishing\Data;

use App\Calendar\ManualRecurrence;
use App\Enums\PriceType;
use App\Enums\RepeatEnds;
use App\Enums\RepeatPattern;
use App\Models\Event;
use App\Models\EventDraft;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Everything needed to publish an event, independent of where it came from.
 * Since Phase 2 every event is published from an approved draft
 * (fromDraft(); manual entry is a manual draft approved at once, D-07).
 */
final readonly class PublishableEventData
{
    /** HH:MM or HH:MM:SS, 00:00 to 23:59:59. */
    private const TIME_PATTERN = '/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/';

    /**
     * @param  list<string>|null  $excludedDates  London dates (Y-m-d) a series
     *                                            skips (ICS EXDATE, D-19). Null means "leave the event's
     *                                            stored exclusions untouched": the edit form has no
     *                                            such field, so fromForm() gives null and an admin edit
     *                                            never wipes a published series' EXDATEs (conflict C2).
     */
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
        public ?string $organiserWebsite = null,
        // D-03: whether residents may see organiserContact. Admin entry
        // defaults to true; submissions arrive false and keep it.
        public bool $contactPublic = true,
        public ?string $sourceUrl = null,
        public ?string $sourceNote = null,
        public string $timezone = 'Europe/London',
        public RepeatPattern $repeats = RepeatPattern::None,
        public RepeatEnds $repeatEnds = RepeatEnds::Never,
        public ?string $repeatUntil = null,
        public ?int $repeatCount = null,
        public ?array $excludedDates = null,
    ) {
        // Validated here, not only in the form: Phase 2's approval path does
        // not go through Filament (T-01-17, WR-02). Mirrors the form's rules:
        // non-blank title and description; real Y-m-d start, end and last
        // dates (round-trip checked, so 2026-02-30 fails); HH:MM[:SS] times;
        // a start time unless all-day; end date and last date not before the
        // start date; a same-day end time different from the start time; a
        // source URL, an organiser contact or a website. Then the
        // recurrence must be single-day and fit the start date.
        $this->assertValid();

        if ($this->isRecurring()) {
            if ($this->endDate !== null && $this->endDate !== $this->startDate) {
                throw new InvalidArgumentException("Repeating events can't span several days.");
            }

            if (! $this->recurrence()->isValidFor($this->startLocal())) {
                throw new InvalidArgumentException("The repeat option [{$this->repeats->value}] does not fit the start date {$this->startDate}.");
            }
        }
    }

    private function assertValid(): void
    {
        if (trim($this->title) === '') {
            throw new InvalidArgumentException('An event needs a title.');
        }

        if (trim($this->description) === '') {
            throw new InvalidArgumentException('An event needs a description.');
        }

        if (! self::isRealDate($this->startDate, $this->timezone)) {
            throw new InvalidArgumentException("Invalid start date [{$this->startDate}].");
        }

        if ($this->endDate !== null && ! self::isRealDate($this->endDate, $this->timezone)) {
            throw new InvalidArgumentException("Invalid end date [{$this->endDate}].");
        }

        if ($this->repeatUntil !== null && ! self::isRealDate($this->repeatUntil, $this->timezone)) {
            throw new InvalidArgumentException("Invalid last date [{$this->repeatUntil}].");
        }

        if ($this->startTime !== null && preg_match(self::TIME_PATTERN, $this->startTime) !== 1) {
            throw new InvalidArgumentException("Invalid start time [{$this->startTime}].");
        }

        if ($this->endTime !== null && preg_match(self::TIME_PATTERN, $this->endTime) !== 1) {
            throw new InvalidArgumentException("Invalid end time [{$this->endTime}].");
        }

        if (! $this->allDay && $this->startTime === null) {
            throw new InvalidArgumentException('A timed event needs a start time.');
        }

        // Real Y-m-d strings compare correctly as strings.
        if ($this->endDate !== null && $this->endDate < $this->startDate) {
            throw new InvalidArgumentException('The end date cannot be before the start date.');
        }

        // Review CR-02: EventTiming reads end <= start as overnight, so a
        // same-day end equal to the start would publish as a 24-hour event.
        // (A timed event has a start time: checked above.)
        if (! $this->allDay && $this->endTime !== null
            && ($this->endDate === null || $this->endDate === $this->startDate)
            && self::sameClockTime($this->startTime, $this->endTime)) {
            throw new InvalidArgumentException('The end time must be different from the start time.');
        }

        foreach ($this->excludedDates ?? [] as $excluded) {
            if (! self::isRealDate($excluded, $this->timezone)) {
                throw new InvalidArgumentException("Invalid excluded date [{$excluded}].");
            }
        }

        if ($this->isRecurring() && $this->repeatEnds === RepeatEnds::OnDate
            && $this->repeatUntil !== null && $this->repeatUntil < $this->startDate) {
            throw new InvalidArgumentException('The last date cannot be before the start date.');
        }

        if (self::nullableString($this->sourceUrl) === null
            && self::nullableString($this->organiserContact) === null
            && self::nullableString($this->organiserWebsite) === null) {
            throw new InvalidArgumentException('Add a source URL, an organiser contact or a website.');
        }
    }

    /**
     * Whether two wall-clock times are the same hour and minute (HH:MM and
     * HH:MM:SS compare equal; seconds are never entered).
     */
    public static function sameClockTime(string $first, string $second): bool
    {
        return substr(trim($first), 0, 5) === substr(trim($second), 0, 5);
    }

    /**
     * A real calendar date: Carbon overflows impossible dates (2026-02-30
     * becomes 2 March), so the parsed value must format back to the input.
     */
    private static function isRealDate(string $value, string $timezone): bool
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        } catch (Throwable) {
            return false;
        }

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value;
    }

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
            organiserWebsite: self::nullableString($data['organiser_website'] ?? null),
            contactPublic: (bool) ($data['contact_public'] ?? true),
            sourceUrl: self::nullableString($data['source_url'] ?? null),
            sourceNote: self::nullableString($data['source_note'] ?? null),
            timezone: (string) config('calendar.timezone', 'Europe/London'),
            repeats: RepeatPattern::from((string) ($data['repeats'] ?? RepeatPattern::None->value)),
            repeatEnds: RepeatEnds::from((string) ($data['repeat_ends'] ?? RepeatEnds::Never->value)),
            repeatUntil: self::nullableDate($data['repeat_until'] ?? null),
            repeatCount: self::nullableInt($data['repeat_count'] ?? null),
        );
    }

    /**
     * The publishable data for an approved draft (ApproveDraft).
     *
     * Throws InvalidArgumentException with a plain-English message when the
     * draft lacks what publishing needs (no category, no venue, no start
     * date, a repeat rule the form cannot represent, or any constructor
     * invariant); ApproveDraft turns it into a DraftNotPublishable blocker.
     * The stored rule must round-trip exactly for the draft's own start date
     * and all_day (WR-10), so the event gets exactly the draft's rule.
     *
     * @throws InvalidArgumentException
     */
    public static function fromDraft(EventDraft $draft): self
    {
        if ($draft->category_id === null) {
            throw new InvalidArgumentException('Pick a category.');
        }

        if ($draft->venue_id === null) {
            throw new InvalidArgumentException('Pick or create a venue.');
        }

        if ($draft->start_date === null) {
            throw new InvalidArgumentException('Add a start date.');
        }

        $startDate = $draft->start_date->format('Y-m-d');
        $startLocal = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $startDate.' '.($draft->start_time ?? '00:00:00'), $draft->timezone);

        if (! $startLocal instanceof CarbonImmutable) {
            throw new InvalidArgumentException("Invalid start [{$startDate} {$draft->start_time}].");
        }

        $recurrence = ManualRecurrence::tryFromStoredRule($draft->recurrence_rule, $startLocal, $draft->all_day);

        if ($recurrence === null) {
            throw new InvalidArgumentException("This repeat rule can't be published from the form.");
        }

        return new self(
            title: trim($draft->title),
            description: trim((string) $draft->description),
            categoryId: $draft->category_id,
            venueId: $draft->venue_id,
            startDate: $startDate,
            startTime: $draft->all_day ? null : $draft->start_time,
            endDate: $draft->end_date?->format('Y-m-d'),
            endTime: $draft->all_day ? null : $draft->end_time,
            allDay: $draft->all_day,
            familyFriendly: $draft->family_friendly,
            priceType: $draft->price_type,
            priceText: self::nullableString($draft->price_text),
            organiserName: self::nullableString($draft->organiser_name),
            organiserContact: self::nullableString($draft->organiser_contact),
            // D-03: the organiser's visibility choice is carried to the event.
            organiserWebsite: self::nullableString($draft->organiser_website),
            contactPublic: $draft->contact_public,
            sourceUrl: self::nullableString($draft->source_url),
            sourceNote: self::nullableString($draft->source_note),
            timezone: $draft->timezone,
            repeats: $recurrence->pattern,
            repeatEnds: $recurrence->ends,
            repeatUntil: $recurrence->untilDate,
            repeatCount: $recurrence->count,
            excludedDates: self::sortedDates($draft->excluded_dates ?? []),
        );
    }

    /**
     * Unique and sorted (real Y-m-d strings sort chronologically); the
     * constructor refuses anything that is not a real date.
     *
     * @param  array<array-key, string>  $dates
     * @return list<string>
     */
    private static function sortedDates(array $dates): array
    {
        $dates = array_values(array_unique($dates));
        sort($dates);

        return $dates;
    }

    /**
     * Form state for an existing event: its attributes plus the Repeats/Ends
     * fields recovered from the stored recurrence_rule.
     *
     * A stored rule the form cannot represent exactly (round trip, WR-10;
     * see editableRecurrence()) falls back to the "Does not repeat" /
     * "Never" state instead of throwing or showing the start date's weekday.
     * That state is display-only: EditEvent makes the form read-only and
     * refuses to save, and UpdatePublishedEvent refuses the edit, so it can
     * never overwrite the stored rule.
     *
     * @return array<string, mixed>
     */
    public static function fromEvent(Event $event): array
    {
        $attributes = $event->attributesToArray();
        $attributes['start_date'] = $event->start_date->format('Y-m-d');
        $attributes['end_date'] = $event->end_date?->format('Y-m-d');
        $attributes['organiser_website'] = $event->organiser_website;
        $attributes['contact_public'] = $event->contact_public;

        $recurrence = self::editableRecurrence($event) ?? new ManualRecurrence;

        return array_merge($attributes, $recurrence->toFormState());
    }

    /**
     * The event's stored rule as a recurrence the form can edit, or null
     * when it does not round-trip exactly for the event's own start date and
     * all_day (WR-10). One-off events give "Does not repeat". The single
     * check behind UpdatePublishedEvent::canEdit(), fromEvent() and the
     * events table's Repeats label, so they cannot disagree.
     */
    public static function editableRecurrence(Event $event): ?ManualRecurrence
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $event->start_date->format('Y-m-d'), $event->timezone);

        if (! $start instanceof CarbonImmutable) {
            return null;
        }

        return ManualRecurrence::tryFromStoredRule($event->recurrence_rule, $start, $event->all_day);
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

        $attributes = [
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
            'organiser_website' => $this->organiserWebsite,
            'contact_public' => $this->contactPublic,
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

        // C2: written only when the caller knows them (fromDraft); null
        // (an admin edit) leaves the stored exclusions as they are. A
        // one-off has nothing to exclude.
        if ($this->excludedDates !== null) {
            $attributes['excluded_dates'] = $this->isRecurring() ? $this->excludedDates : [];
        }

        return $attributes;
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
