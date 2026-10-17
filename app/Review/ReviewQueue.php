<?php

namespace App\Review;

use App\Enums\ReviewStatus;
use App\Models\EventDraft;
use Illuminate\Database\Eloquent\Builder;

/**
 * D-09: the one ordering behind the queue table and Approve & next.
 *
 * Soonest event first: drafts with a start date before those without, then
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
     * Ordering expression => keyset expression (nulls replaced inside
     * their flag group only). All ascending.
     */
    private const SORT_KEY = [
        '(start_date is null)' => '(start_date is null)',
        'start_date' => "coalesce(start_date, date '9999-12-31')",
        '(start_time is not null)' => '(start_time is not null)',
        'start_time' => "coalesce(start_time, time '00:00')",
        'created_at' => 'created_at',
        'id' => 'id',
    ];

    /**
     * Order any event_drafts query by the D-09 sort key.
     *
     * @param  Builder<EventDraft>  $query
     * @return Builder<EventDraft>
     */
    public static function applyOrder(Builder $query, string $direction = 'asc'): Builder
    {
        $direction = $direction === 'desc' ? 'desc' : 'asc';

        foreach (array_keys(self::SORT_KEY) as $expression) {
            if (str_starts_with($expression, '(')) {
                $query->orderByRaw("{$expression} {$direction}");
            } else {
                // Plain columns via orderBy, so Filament sees the key sort
                // and does not append a second "order by id".
                $query->orderBy($query->qualifyColumn($expression), $direction);
            }
        }

        return $query;
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
        return self::applyOrder($this->after($current, '>'))->first();
    }

    /**
     * The pending draft before $current in queue order, or null at the start.
     */
    public function previous(EventDraft $current): ?EventDraft
    {
        return self::applyOrder($this->after($current, '<'), 'desc')->first();
    }

    /**
     * @return Builder<EventDraft>
     */
    private function pending(): Builder
    {
        return EventDraft::query()->where('review_status', ReviewStatus::PendingReview);
    }

    /**
     * Row-value keyset over SORT_KEY: (k1, …, k6) > (the same expressions
     * evaluated on $current's row). Only the draft id is bound.
     *
     * @param  '>'|'<'  $operator
     * @return Builder<EventDraft>
     */
    private function after(EventDraft $current, string $operator): Builder
    {
        $table = $current->getTable();
        $outer = implode(', ', array_map(fn (string $e): string => self::qualify($e, $table), self::SORT_KEY));
        $inner = implode(', ', array_map(fn (string $e): string => self::qualify($e, 'current_draft'), self::SORT_KEY));

        return $this->pending()->whereRaw(
            "({$outer}) {$operator} (select {$inner} from {$table} as current_draft where current_draft.id = ?)",
            [$current->getKey()],
        );
    }

    /**
     * Prefix the bare column names in a SORT_KEY expression with $alias.
     */
    private static function qualify(string $expression, string $alias): string
    {
        return (string) preg_replace('/\b(start_date|start_time|created_at|id)\b/', "{$alias}.$1", $expression);
    }
}
