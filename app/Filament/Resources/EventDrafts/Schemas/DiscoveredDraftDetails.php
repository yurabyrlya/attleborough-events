<?php

namespace App\Filament\Resources\EventDrafts\Schemas;

use App\Enums\DraftKind;
use App\Enums\PriceType;
use App\Enums\ReviewStatus;
use App\Filament\Resources\EventDrafts\EventDraftResource;
use App\Filament\Resources\Events\EventResource;
use App\Ingestion\Data\DuplicateMatch;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\EventOccurrence;
use App\Support\LondonFormatter;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * What the review page shows for a discovered draft, as plain strings for
 * escaped Blade output (T-03-36):
 *
 * - diffRows(): an update draft's diff ({field: {published, before, now}},
 *   03-09, read in the D-06 order the SourceDiff cast restores) as one row
 *   per field with London-formatted dates ("Sun 25 Oct") and times ("10am",
 *   "7:30pm"), for the three-column table (REV-06, D-07);
 * - cancelledDates(): a cancellation draft's dates, or "The whole event"
 *   (REV-07, D-08);
 * - sourceCancelledDates(): the dates a new series draft's source marks
 *   cancelled (raw_payload cancelled_keys, which ApproveDraft publishes as
 *   Cancelled);
 * - duplicate(): the suspected match side by side with the draft (D-13,
 *   PIPE-05), for a published event (duplicate_of_event_id) or another
 *   pending draft (duplicate_of_draft_id), with the reason in words.
 *
 * Feed values are never trusted to be well formed: anything that does not
 * parse as the expected date or time is shown as the text it is.
 */
final class DiscoveredDraftDetails
{
    public const WHOLE_EVENT = 'The whole event';

    public const NOT_LISTED = 'The whole event (no longer listed by the source)';

    private const LABELS = [
        'title' => 'Title',
        'all_day' => 'All day',
        'start_date' => 'Start date',
        'start_time' => 'Start time',
        'end_date' => 'End date',
        'end_time' => 'End time',
        'venue' => 'Venue',
        'price_type' => 'Price',
        'family_friendly' => 'Family friendly',
        'cancelled' => 'Cancelled',
        'rrule' => 'Repeats',
        'excluded_dates' => 'Skipped dates',
        'moved_overrides' => 'Moved dates',
        'extra_dates' => 'Extra dates',
    ];

    /**
     * One row per changed field, in diff order.
     *
     * @return list<array{field: string, label: string, published: string, before: string, now: string}>
     */
    public static function diffRows(EventDraft $draft): array
    {
        if ($draft->kind !== DraftKind::Update) {
            return [];
        }

        $rows = [];

        foreach ($draft->diff ?? [] as $field => $change) {
            if (! is_array($change) || ! array_key_exists('now', $change)) {
                continue;
            }

            $rows[] = [
                'field' => (string) $field,
                'label' => self::LABELS[$field] ?? ucfirst(str_replace('_', ' ', (string) $field)),
                'published' => self::value((string) $field, $change['published'] ?? null),
                'before' => self::value((string) $field, $change['before'] ?? null),
                'now' => self::value((string) $field, $change['now']),
            ];
        }

        return $rows;
    }

    /**
     * The dates a cancellation draft cancels ("Wed 4 Nov"), or one line
     * naming the whole event.
     *
     * @return list<string>
     */
    public static function cancelledDates(EventDraft $draft): array
    {
        if ($draft->kind !== DraftKind::Cancellation) {
            return [];
        }

        $diff = $draft->diff ?? [];

        if (isset($diff['cancelled_keys']) && is_array($diff['cancelled_keys']) && $diff['cancelled_keys'] !== []) {
            return self::dates($diff['cancelled_keys']);
        }

        if (isset($diff['status'])) {
            return [self::NOT_LISTED];
        }

        return [self::WHOLE_EVENT];
    }

    /**
     * The dates a new draft's source marks cancelled, formatted.
     *
     * @return list<string>
     */
    public static function sourceCancelledDates(EventDraft $draft): array
    {
        if ($draft->kind !== DraftKind::New) {
            return [];
        }

        $keys = $draft->raw_payload['cancelled_keys'] ?? [];

        return is_array($keys) ? self::dates($keys) : [];
    }

    /**
     * The draft and its suspected match side by side, or null when the
     * draft has no duplicate warning.
     *
     * @return array{
     *     reason: string,
     *     matchHeading: string,
     *     linkLabel: string,
     *     linkUrl: string,
     *     rows: list<array{label: string, draft: string, match: string}>
     * }|null
     */
    public static function duplicate(EventDraft $draft): ?array
    {
        $event = $draft->duplicate_of_event_id !== null ? $draft->duplicateOf : null;
        $other = $event === null && $draft->duplicate_of_draft_id !== null ? $draft->duplicateOfDraft : null;

        if ($event === null && $other === null) {
            return null;
        }

        $mine = self::draftFacts($draft);

        if ($event instanceof Event) {
            $theirs = self::eventFacts($event, $draft->start_date?->format('Y-m-d'));
            $heading = 'Published event';
            $linkLabel = 'Open the published event';
            $linkUrl = EventResource::getUrl('edit', ['record' => $event]);
        } else {
            $theirs = self::draftFacts($other);
            $heading = $other->review_status === ReviewStatus::PendingReview ? 'Pending draft '.$other->reference : 'Draft '.$other->reference;
            $linkLabel = 'Open draft '.$other->reference;
            $linkUrl = EventDraftResource::getUrl('review', ['record' => $other]);
        }

        $rows = [];

        foreach (['date' => 'Date', 'time' => 'Time', 'venue' => 'Venue', 'title' => 'Title'] as $key => $label) {
            $rows[] = ['label' => $label, 'draft' => $mine[$key], 'match' => $theirs[$key]];
        }

        return [
            'reason' => self::duplicateReason($draft),
            'matchHeading' => $heading,
            'linkLabel' => $linkLabel,
            'linkUrl' => $linkUrl,
            'rows' => $rows,
        ];
    }

    /**
     * Why the draft was flagged: "Same web address", or "Same date, similar
     * venue and title (similarity 0.78)".
     */
    public static function duplicateReason(EventDraft $draft): string
    {
        if ($draft->duplicate_reason === DuplicateMatch::URL) {
            return 'Same web address';
        }

        if ($draft->duplicate_reason === DuplicateMatch::FUZZY) {
            $score = DuplicateMatch::formatScore($draft->duplicate_score);

            return 'Same date, similar venue and title'.($score !== null ? " (similarity {$score})" : '');
        }

        return 'Looks like the same event';
    }

    /**
     * The title of whatever the draft is flagged against, or null.
     */
    public static function duplicateTitle(EventDraft $draft): ?string
    {
        if ($draft->duplicate_of_event_id !== null) {
            return $draft->duplicateOf?->title;
        }

        return $draft->duplicate_of_draft_id !== null ? $draft->duplicateOfDraft?->title : null;
    }

    /**
     * Where the duplicate warning's match lives in the admin, or null.
     */
    public static function duplicateUrl(EventDraft $draft): ?string
    {
        if ($draft->duplicate_of_event_id !== null) {
            // Whatever the event's status: hidden and cancelled events are
            // still reachable on their admin page.
            return EventResource::getUrl('edit', ['record' => $draft->duplicate_of_event_id]);
        }

        return $draft->duplicate_of_draft_id !== null
            ? EventDraftResource::getUrl('review', ['record' => $draft->duplicate_of_draft_id])
            : null;
    }

    /**
     * @return array{date: string, time: string, venue: string, title: string}
     */
    private static function draftFacts(EventDraft $draft): array
    {
        return [
            'date' => $draft->start_date !== null ? self::date($draft->start_date->format('Y-m-d')) : 'Not given',
            'time' => $draft->all_day ? 'All day' : ($draft->start_time !== null ? self::time($draft->start_time) : 'Not given'),
            'venue' => $draft->venue->name ?? (trim((string) $draft->venue_name) !== '' ? (string) $draft->venue_name : 'Not given'),
            'title' => $draft->title,
        ];
    }

    /**
     * The event on the draft's date when it has an occurrence then (the
     * detector compares occurrences), otherwise its own start.
     *
     * @return array{date: string, time: string, venue: string, title: string}
     */
    private static function eventFacts(Event $event, ?string $onDate): array
    {
        $occurrence = $onDate === null ? null : EventOccurrence::query()
            ->where('event_id', $event->id)
            ->where('recurrence_key', $onDate)
            ->first();

        if ($occurrence instanceof EventOccurrence) {
            $start = LondonFormatter::local($occurrence->starts_at);
            $date = LondonFormatter::shortDate($start);
            $time = $event->all_day ? 'All day' : LondonFormatter::time($start);
        } else {
            $date = self::date($event->start_date->format('Y-m-d'));
            $time = $event->all_day ? 'All day' : self::time($event->start_time);
        }

        return [
            'date' => $date,
            'time' => $time,
            'venue' => $event->venue->name ?? 'Not given',
            'title' => $event->title,
        ];
    }

    /**
     * A Y-m-d date as "Wed 4 Nov" (year added outside the current London
     * year), or the text itself when it is not one.
     */
    public static function date(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return self::text($value);
        }

        try {
            // Carbon 3 throws on a format mismatch; the regex above only
            // admits the shape, not a real calendar date.
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, LondonFormatter::TZ);
        } catch (Throwable) {
            return $value;
        }

        return $date instanceof CarbonImmutable ? LondonFormatter::shortDate($date) : $value;
    }

    /**
     * An H:i wall-clock time as "10am" / "7:30pm", or the text itself.
     */
    public static function time(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value) !== 1) {
            return self::text($value);
        }

        try {
            $clock = CarbonImmutable::createFromFormat('!H:i', substr($value, 0, 5), LondonFormatter::TZ);
        } catch (Throwable) {
            return $value;
        }

        return $clock instanceof CarbonImmutable ? str_replace(':00', '', $clock->format('g:ia')) : $value;
    }

    /**
     * @param  array<mixed>  $keys
     * @return list<string>
     */
    private static function dates(array $keys): array
    {
        return array_values(array_map(self::date(...), $keys));
    }

    private static function value(string $field, mixed $value): string
    {
        return match ($field) {
            'start_date', 'end_date' => self::date($value),
            'start_time', 'end_time' => self::time($value),
            'all_day', 'family_friendly', 'cancelled' => self::yesNo($value),
            'price_type' => is_string($value) ? (PriceType::tryFrom($value)?->getLabel() ?? $value) : self::text($value),
            'excluded_dates', 'extra_dates' => is_array($value) ? self::listText(self::dates($value)) : self::text($value),
            default => self::text($value),
        };
    }

    private static function yesNo(mixed $value): string
    {
        return match ($value) {
            true => 'Yes',
            false => 'No',
            default => self::text($value),
        };
    }

    /**
     * @param  list<string>  $items
     */
    private static function listText(array $items): string
    {
        return $items === [] ? 'None' : implode(', ', $items);
    }

    private static function text(mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return 'None';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_array($value) && array_is_list($value) && array_filter($value, is_string(...)) === $value) {
            return implode(', ', $value);
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
