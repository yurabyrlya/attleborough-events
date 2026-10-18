<?php

namespace App\Review;

use App\Enums\DraftKind;
use App\Enums\ReviewStatus;
use App\Models\EventDraft;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * D-09: the one ordering behind the queue table and Approve & next.
 *
 * Urgent changes first (Phase 3 D-09): update and cancellation drafts whose
 * relevant_starts_at is less than 48 elapsed hours from now (URGENT_SQL,
 * mirrored by isUrgent() for the badge). The cutoff is bound from the app
 * clock (Carbon::now() + 48 hours, absolute, so a clock change cannot move
 * it), not PostgreSQL's now(): the order then follows the same clock as the
 * rest of the app, frozen time in tests included. One cutoff per query, so
 * the keyset's outer and inner expressions agree.
 *
 * Then soonest event first: drafts with a start date before those without, then
 * by start date; on the same date a draft with no start time comes before a
 * timed one (so it can never tie with 00:00), then by start time; ties by
 * oldest created, then id. In SQL terms: start_date nulls last, start_time
 * nulls first, created_at, id, all ascending.
 *
 * applyOrder() (used by the table's defaultSort and query()) and the
 * next()/previous() keysets are both built from SORT_KEY, so the table and
 * the queue navigation can never disagree. The keyset compares the null
 * flag before the coalesced value, so a placeholder only ever meets other
 * placeholders inside its own flag group.
 */
final class ReviewQueue
{
    /**
     * The D-09 urgency predicate; its one binding is urgentCutoff(). Never
     * null: a null relevant_starts_at makes the conjunction false.
     */
    public const URGENT_SQL = "(kind <> 'new' and relevant_starts_at is not null and relevant_starts_at < ?)";

    /**
     * How far ahead an update or cancellation counts as urgent, in elapsed
     * hours.
     */
    public const URGENT_HOURS = 48;

    /**
     * Ordering expression => keyset expression (nulls replaced inside
     * their flag group only). All ascending.
     */
    private const SORT_KEY = [
        // false (urgent) sorts before true.
        '(not '.self::URGENT_SQL.')' => '(not '.self::URGENT_SQL.')',
        '(start_date is null)' => '(start_date is null)',
        'start_date' => "coalesce(start_date, date '9999-12-31')",
        '(start_time is not null)' => '(start_time is not null)',
        'start_time' => "coalesce(start_time, time '00:00')",
        'created_at' => 'created_at',
        'id' => 'id',
    ];

    /**
     * Order any event_drafts query by the D-09 sort key, urgency judged at
     * $now (default: the app clock).
     *
     * @param  Builder<EventDraft>  $query
     * @return Builder<EventDraft>
     */
    public static function applyOrder(Builder $query, string $direction = 'asc', ?CarbonImmutable $now = null): Builder
    {
        $direction = $direction === 'desc' ? 'desc' : 'asc';
        $cutoff = self::urgentCutoff($now ?? CarbonImmutable::now());

        foreach (array_keys(self::SORT_KEY) as $expression) {
            if (str_starts_with($expression, '(')) {
                $query->orderByRaw("{$expression} {$direction}", self::bindingsFor($expression, $cutoff));
            } else {
                // Plain columns via orderBy, so Filament sees the key sort
                // and does not append a second "order by id".
                $query->orderBy($query->qualifyColumn($expression), $direction);
            }
        }

        return $query;
    }

    /**
     * Whether $draft is an urgent change at $now: the PHP mirror of
     * URGENT_SQL, for the Urgent badge.
     */
    public static function isUrgent(EventDraft $draft, CarbonImmutable $now): bool
    {
        return $draft->kind !== DraftKind::New
            && $draft->relevant_starts_at !== null
            && $draft->relevant_starts_at->lessThan(self::urgentCutoff($now));
    }

    /**
     * Limit $query to urgent changes at $now (URGENT_SQL).
     *
     * @param  Builder<EventDraft>  $query
     * @return Builder<EventDraft>
     */
    public static function whereUrgent(Builder $query, CarbonImmutable $now): Builder
    {
        return $query->whereRaw(self::URGENT_SQL, self::bindingsFor(self::URGENT_SQL, self::urgentCutoff($now)));
    }

    /**
     * $now plus URGENT_HOURS elapsed hours, in UTC (addHours on UTC never
     * meets a clock change).
     */
    public static function urgentCutoff(CarbonImmutable $now): CarbonImmutable
    {
        return $now->utc()->addHours(self::URGENT_HOURS);
    }

    /**
     * Pending drafts in queue order.
     *
     * @return Builder<EventDraft>
     */
    public function query(): Builder
    {
        return self::applyOrder($this->pending());
    }

    /**
     * The pending draft after $current in queue order, or null at the end.
     * $current may already be decided (just approved or rejected): its
     * position is read from the database, so the following pending draft is
     * returned. Null when $current no longer exists.
     */
    public function next(EventDraft $current): ?EventDraft
    {
        $now = CarbonImmutable::now();

        return self::applyOrder($this->after($current, '>', $now), 'asc', $now)->first();
    }

    /**
     * The pending draft before $current in queue order, or null at the start.
     */
    public function previous(EventDraft $current): ?EventDraft
    {
        $now = CarbonImmutable::now();

        return self::applyOrder($this->after($current, '<', $now), 'desc', $now)->first();
    }

    /**
     * @return Builder<EventDraft>
     */
    private function pending(): Builder
    {
        return EventDraft::query()->where('review_status', ReviewStatus::PendingReview);
    }

    /**
     * Row-value keyset over SORT_KEY: (k1, …, k7) > (the same expressions
     * evaluated on $current's row). Binds the urgency cutoff (once per side) and the draft id.
     *
     * @param  '>'|'<'  $operator
     * @return Builder<EventDraft>
     */
    private function after(EventDraft $current, string $operator, CarbonImmutable $now): Builder
    {
        $table = $current->getTable();
        $outer = implode(', ', array_map(fn (string $e): string => self::qualify($e, $table), self::SORT_KEY));
        $inner = implode(', ', array_map(fn (string $e): string => self::qualify($e, 'current_draft'), self::SORT_KEY));
        $keyBindings = self::bindingsFor(implode(', ', self::SORT_KEY), self::urgentCutoff($now));

        return $this->pending()->whereRaw(
            "({$outer}) {$operator} (select {$inner} from {$table} as current_draft where current_draft.id = ?)",
            [...$keyBindings, ...$keyBindings, $current->getKey()],
        );
    }

    /**
     * Prefix the bare column names in a SORT_KEY expression with $alias
     * (RESEARCH F6: every column a key uses must be listed, or the keyset
     * compares the outer row with itself).
     */
    private static function qualify(string $expression, string $alias): string
    {
        return (string) preg_replace('/\b(start_date|start_time|created_at|id|kind|relevant_starts_at)\b/', "{$alias}.$1", $expression);
    }

    /**
     * The urgency cutoff once per placeholder in $expression (only
     * URGENT_SQL has one).
     *
     * @return list<string>
     */
    private static function bindingsFor(string $expression, CarbonImmutable $cutoff): array
    {
        return array_fill(0, substr_count($expression, '?'), $cutoff->format('Y-m-d H:i:s.uP'));
    }
}
