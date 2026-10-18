<?php

namespace App\Ingestion\Pipeline;

use App\Ingestion\CanonicalUrl;
use App\Ingestion\Data\DuplicateMatch;
use App\Ingestion\Data\NormalisedFacts;
use App\Models\Source;
use App\Models\SourceItem;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Finds the likely duplicate of an ingested item that would become a new
 * draft (PIPE-05, RESEARCH Pattern 6). Candidates are published events,
 * compared by occurrence, and pending new drafts from other sources or
 * origins.
 *
 * 1. Same canonical URL (CanonicalUrl) as an event's source_url, one of its
 *    event_sources item URLs, or a pending draft's source_url → reason
 *    'url', score 1.0, whatever the dates.
 * 2. Otherwise 'fuzzy', scored by the title similarity, when all hold:
 *    - same London date (occurrence recurrence_key / draft start_date);
 *    - starts at most ingest.duplicate.start_tolerance_minutes apart (60,
 *      inclusive), or both all-day;
 *    - same venue_id, or pg_trgm venue-name similarity ≥
 *      ingest.duplicate.venue_threshold (0.3);
 *    - pg_trgm title similarity ≥ ingest.duplicate.title_threshold (0.3,
 *      inclusive).
 *    An item with neither a venue_id nor a venue name never matches
 *    fuzzily; an item without a URL is checked by the fuzzy rule only.
 *
 * Never candidates: anything from the same source (same source + other
 * external id = a different item, Pitfall 6; for events, any event the
 * source is already linked to through event_sources), hidden events and
 * hidden occurrences, and pairs the admin marked "Not a duplicate"
 * (duplicate_decisions, D-13).
 *
 * Ranking: score descending, then events before drafts, then id
 * ascending; the first is returned. A URL match outranks any fuzzy match.
 *
 * Read-only. Feed text reaches SQL only as bound parameters, and the
 * similarity is computed with similarity(lower(a), lower(?)) compared with
 * an explicit real threshold, never the % operator (which depends on the
 * session's pg_trgm.similarity_threshold). The only SQL chosen in PHP is
 * one of two constant time-guard fragments.
 */
final class DuplicateDetector
{
    private const string TZ = 'Europe/London';

    public function find(NormalisedFacts $facts, Source $source, ?SourceItem $item, ?int $venueId): ?DuplicateMatch
    {
        return $this->byUrl($facts, $source, $item) ?? $this->fuzzy($facts, $source, $item, $venueId);
    }

    private function byUrl(NormalisedFacts $facts, Source $source, ?SourceItem $item): ?DuplicateMatch
    {
        $canonical = CanonicalUrl::of($facts->url);

        if ($canonical === null) {
            return null;
        }

        // Prefilter on the host and path (lower-cased) in SQL; the exact
        // comparison runs on the canonical forms in PHP.
        $parts = parse_url($canonical);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = strtolower((string) ($parts['path'] ?? '/'));
        $itemId = $item?->id;

        $events = DB::select(
            "select e.id, e.title, v.name as venue_name, e.source_url, es.item_url,
                    (select min(o.starts_at) from event_occurrences o where o.event_id = e.id and o.status <> 'hidden') as starts_at
             from events e
             join venues v on v.id = e.venue_id
             left join event_sources es on es.event_id = e.id
             where e.status <> 'hidden'
               and (
                    (e.source_url is not null and position(? in lower(e.source_url)) > 0 and position(? in lower(e.source_url)) > 0)
                 or (es.item_url is not null and position(? in lower(es.item_url)) > 0 and position(? in lower(es.item_url)) > 0)
               )
               and not exists (select 1 from event_sources same where same.event_id = e.id and same.source_id = ?)
               and not exists (select 1 from duplicate_decisions d where d.decision = 'not_duplicate' and d.source_item_id = ? and d.candidate_event_id = e.id)
             order by e.id",
            [$host, $path, $host, $path, $source->id, $itemId],
        );

        foreach ($events as $row) {
            if (CanonicalUrl::of($row->source_url) === $canonical || CanonicalUrl::of($row->item_url) === $canonical) {
                return new DuplicateMatch((int) $row->id, null, DuplicateMatch::URL, 1.0, self::instant($row->starts_at), $row->venue_name, (string) $row->title);
            }
        }

        $drafts = DB::select(
            "select d.id, d.title, d.source_url, coalesce(dv.name, d.venue_name) as venue_name, d.all_day,
                    d.start_date, d.start_time
             from event_drafts d
             left join venues dv on dv.id = d.venue_id
             where d.review_status = 'pending_review' and d.kind = 'new'
               and d.source_id is distinct from ?
               and d.source_url is not null
               and position(? in lower(d.source_url)) > 0 and position(? in lower(d.source_url)) > 0
               and not exists (select 1 from duplicate_decisions dd where dd.decision = 'not_duplicate' and dd.source_item_id = ? and dd.candidate_draft_id = d.id)
             order by d.id",
            [$source->id, $host, $path, $itemId],
        );

        foreach ($drafts as $row) {
            if (CanonicalUrl::of($row->source_url) === $canonical) {
                return new DuplicateMatch(null, (int) $row->id, DuplicateMatch::URL, 1.0, self::draftStart($row), $row->venue_name, (string) $row->title);
            }
        }

        return null;
    }

    private function fuzzy(NormalisedFacts $facts, Source $source, ?SourceItem $item, ?int $venueId): ?DuplicateMatch
    {
        $title = trim($facts->title);
        $venueName = self::clean($facts->venueName)
            ?? ($venueId !== null ? Venue::query()->whereKey($venueId)->value('name') : null);

        if ($title === '' || ($venueId === null && $venueName === null)) {
            return null;
        }

        [$londonDate, $startsUtc] = self::when($facts);

        if ($londonDate === null || (! $facts->allDay && $startsUtc === null)) {
            return null;
        }

        $titleThreshold = (float) config('ingest.duplicate.title_threshold', 0.3);
        $venueThreshold = (float) config('ingest.duplicate.venue_threshold', 0.3);
        $toleranceSeconds = 60 * (int) config('ingest.duplicate.start_tolerance_minutes', 60);
        $startsAt = $startsUtc?->format('Y-m-d H:i:s').'+00';
        $itemId = $item?->id;

        // Constant fragments only: which time guard applies.
        $eventTime = $facts->allDay
            ? 'e.all_day'
            : 'not e.all_day and abs(extract(epoch from (o.starts_at - cast(? as timestamptz)))) <= ?';
        $eventTimeBindings = $facts->allDay ? [] : [$startsAt, $toleranceSeconds];

        $event = DB::selectOne(
            "select e.id, e.title, v.name as venue_name, o.starts_at,
                    similarity(lower(e.title), lower(?)) as score
             from event_occurrences o
             join events e on e.id = o.event_id
             join venues v on v.id = e.venue_id
             where o.recurrence_key = ?
               and o.status <> 'hidden' and e.status <> 'hidden'
               and ({$eventTime})
               and (e.venue_id = ? or similarity(lower(v.name), lower(?)) >= cast(? as real))
               and similarity(lower(e.title), lower(?)) >= cast(? as real)
               and not exists (select 1 from event_sources same where same.event_id = e.id and same.source_id = ?)
               and not exists (select 1 from duplicate_decisions d where d.decision = 'not_duplicate' and d.source_item_id = ? and d.candidate_event_id = e.id)
             order by score desc, e.id, o.starts_at
             limit 1",
            [
                $title,
                $londonDate,
                ...$eventTimeBindings,
                $venueId, $venueName, $venueThreshold,
                $title, $titleThreshold,
                $source->id,
                $itemId,
            ],
        );

        $draftTime = $facts->allDay
            ? 'd.all_day'
            : 'not d.all_day and d.start_time is not null and abs(extract(epoch from (((d.start_date + d.start_time) at time zone ?) - cast(? as timestamptz)))) <= ?';
        $draftTimeBindings = $facts->allDay ? [] : [self::TZ, $startsAt, $toleranceSeconds];

        $draft = DB::selectOne(
            "select d.id, d.title, coalesce(dv.name, d.venue_name) as venue_name, d.all_day, d.start_date, d.start_time,
                    similarity(lower(d.title), lower(?)) as score
             from event_drafts d
             left join venues dv on dv.id = d.venue_id
             where d.review_status = 'pending_review' and d.kind = 'new'
               and d.source_id is distinct from ?
               and d.start_date = cast(? as date)
               and ({$draftTime})
               and (d.venue_id = ? or similarity(lower(coalesce(dv.name, d.venue_name)), lower(?)) >= cast(? as real))
               and similarity(lower(d.title), lower(?)) >= cast(? as real)
               and not exists (select 1 from duplicate_decisions dd where dd.decision = 'not_duplicate' and dd.source_item_id = ? and dd.candidate_draft_id = d.id)
             order by score desc, d.id
             limit 1",
            [
                $title,
                $source->id,
                $londonDate,
                ...$draftTimeBindings,
                $venueId, $venueName, $venueThreshold,
                $title, $titleThreshold,
                $itemId,
            ],
        );

        // Events win a tie: a published event is the more useful match.
        if ($event !== null && ($draft === null || (float) $event->score >= (float) $draft->score)) {
            return new DuplicateMatch((int) $event->id, null, DuplicateMatch::FUZZY, (float) $event->score, self::instant($event->starts_at), $event->venue_name, (string) $event->title);
        }

        if ($draft !== null) {
            return new DuplicateMatch(null, (int) $draft->id, DuplicateMatch::FUZZY, (float) $draft->score, self::draftStart($draft), $draft->venue_name, (string) $draft->title);
        }

        return null;
    }

    /**
     * The item's London date and UTC start: the next instance for a series
     * (nextInstanceStartUtc), else its start date and time. The start is
     * null for all-day items and for a time that does not parse.
     *
     * @return array{0: string|null, 1: CarbonImmutable|null}
     */
    private static function when(NormalisedFacts $facts): array
    {
        if ($facts->nextInstanceStartUtc !== null) {
            $local = $facts->nextInstanceStartUtc->setTimezone(self::TZ);

            return [$local->toDateString(), $facts->allDay ? null : $facts->nextInstanceStartUtc];
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $facts->startDate) !== 1) {
            return [null, null];
        }

        if ($facts->allDay) {
            return [$facts->startDate, null];
        }

        if ($facts->startTime === null || preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $facts->startTime) !== 1) {
            return [$facts->startDate, null];
        }

        try {
            return [$facts->startDate, CarbonImmutable::parse($facts->startDate.' '.$facts->startTime, self::TZ)->utc()];
        } catch (Throwable) {
            return [$facts->startDate, null];
        }
    }

    private static function instant(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->utc() : null;
    }

    /**
     * A draft row's start as a UTC instant (London wall clock), or null.
     */
    private static function draftStart(object $row): ?CarbonImmutable
    {
        $date = $row->start_date ?? null;
        $time = $row->start_time ?? null;

        if (! is_string($date) || $date === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(substr($date, 0, 10).' '.(is_string($time) && ! $row->all_day ? $time : '00:00:00'), self::TZ)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private static function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
