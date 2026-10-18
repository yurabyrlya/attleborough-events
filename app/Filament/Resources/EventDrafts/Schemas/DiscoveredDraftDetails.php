<?php

namespace App\Filament\Resources\EventDrafts\Schemas;

use App\Enums\DraftKind;
use App\Enums\PriceType;
use App\Models\EventDraft;
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
 *   Cancelled).
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
