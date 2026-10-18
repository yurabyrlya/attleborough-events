<?php

namespace App\Ops;

use App\Enums\HealthState;
use App\Enums\SourceHealth;
use App\Http\Requests\StoreSubmissionRequest;
use App\Models\FailedJob;
use App\Models\Heartbeat;
use App\Models\Source;
use App\Support\LondonFormatter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\DB;

/**
 * The single source of health states for the System health page, its nav
 * badge and ops:health (OPS-04). Reads the heartbeats, jobs and failed_jobs
 * tables only, never the cache store. Every query is a single cheap lookup
 * or aggregate. The spam check reads config only.
 */
class HealthReport
{
    /** Check intervals without a success before an active source counts as not checked (WR-05). */
    public const int STALE_AFTER_INTERVALS = 2;

    public function scheduler(): HealthCheck
    {
        return $this->freshness(
            key: 'scheduler',
            title: 'Scheduler',
            heartbeat: Heartbeat::find(Heartbeat::SCHEDULER),
            staleAfter: (int) config('ops.scheduler_stale_after'),
            expectation: 'Expected every minute; stale after 3 minutes',
        );
    }

    public function queue(): HealthCheck
    {
        $waiting = $this->pendingJobs();
        $detail = "Jobs waiting: {$waiting}";

        if ($waiting > 0 && ($oldest = $this->oldestPendingAgeSeconds()) !== null) {
            $detail .= ', oldest waiting '.CarbonInterval::seconds($oldest)->cascade()->forHumans(['parts' => 2]);
        }

        return $this->freshness(
            key: 'queue',
            title: 'Queue worker',
            heartbeat: Heartbeat::find(Heartbeat::QUEUE),
            staleAfter: (int) config('ops.queue_stale_after'),
            expectation: 'Expected every 5 minutes; stale after 10 minutes',
            detail: $detail,
        );
    }

    public function calendarSync(): HealthCheck
    {
        $expectation = 'Runs daily at 03:10 (UK time)';
        $heartbeat = Heartbeat::find(Heartbeat::CALENDAR_SYNC);

        if ($heartbeat === null) {
            return new HealthCheck('calendar-sync', 'Daily calendar sync', HealthState::NotRunYet, null, null, $expectation);
        }

        $meta = $heartbeat->meta ?? [];
        $synced = (int) ($meta['synced'] ?? 0);
        $failed = (int) ($meta['failed'] ?? 0);
        $failedIds = array_map('intval', (array) ($meta['failed_ids'] ?? []));

        $detail = "{$synced} series synced, {$failed} failed";
        if ($failedIds !== []) {
            $detail .= ' (event ids: '.implode(', ', $failedIds).')';
        }

        $age = $this->ageOf($heartbeat->last_seen_at);

        $state = match (true) {
            $age > (int) config('ops.calendar_sync_stale_after') => HealthState::Stale,
            $failed > 0 => HealthState::Failed,
            default => HealthState::Ok,
        };

        return new HealthCheck('calendar-sync', 'Daily calendar sync', $state, $heartbeat->last_seen_at, $age, $expectation, $detail);
    }

    /**
     * T-02-31: a production /submit without both Turnstile keys is resting
     * (02-09), so the public form silently stops taking events. Uses the
     * same rule as the form so the two can never disagree.
     */
    public function spamCheck(): HealthCheck
    {
        $expectation = 'Turnstile keys are set in production';

        if (! app()->isProduction()) {
            return new HealthCheck('spam-check', 'Submission spam check', HealthState::Ok, null, null, $expectation, 'Not checked outside production');
        }

        if (StoreSubmissionRequest::formIsResting()) {
            return new HealthCheck('spam-check', 'Submission spam check', HealthState::Failed, null, null, $expectation, 'Turnstile keys missing: the public form is resting');
        }

        return new HealthCheck('spam-check', 'Submission spam check', HealthState::Ok, null, null, $expectation);
    }

    /**
     * Crawled sources (SRC-08): OK when every active source is healthy and
     * none has a structure alert, otherwise Stale with the counts of
     * degraded, unhealthy (paused) and structure-alert sources. Not part of
     * all(): a remote site's failure is not an app outage, so ops:health
     * prints this line as a warning and its exit code ignores it (T-03-21).
     * One aggregate query; "last seen" is the latest active source check.
     *
     * WR-05: blocked runs never move health, so a source that robots.txt or
     * a bot wall blocks on every check (or whose runs are always abandoned
     * in the queue) would stay "healthy" while its events go stale. So an
     * active source also counts as not checked when its last success is
     * older than STALE_AFTER_INTERVALS check intervals plus the schedule
     * jitter, or when it has never succeeded and its first live run is
     * that old.
     */
    public function sources(): HealthCheck
    {
        $expectation = 'Every active source is healthy and was checked successfully within two check intervals';

        // Per source: STALE_AFTER_INTERVALS × (1 day, or 7 for weekly) + the jitter, before "now" (bound, so frozen test time applies).
        $cutoff = "(?::timestamptz - (case when check_frequency = 'weekly' then 7 else 1 end) * ".self::STALE_AFTER_INTERVALS." * interval '1 day' - ? * interval '1 minute')";
        $now = CarbonImmutable::now()->utc()->format('Y-m-d H:i:sP');
        $jitter = max(1, (int) config('ingest.schedule.jitter_minutes', 20));

        $row = Source::query()
            ->where('is_active', true)
            ->selectRaw('count(*) as active')
            ->selectRaw('count(*) filter (where health = ?) as degraded', [SourceHealth::Degraded->value])
            ->selectRaw('count(*) filter (where health = ?) as unhealthy', [SourceHealth::Unhealthy->value])
            ->selectRaw('count(*) filter (where structure_alert_at is not null) as structure_alerts')
            ->selectRaw("count(*) filter (where last_success_at < {$cutoff}) as not_checked", [$now, $jitter])
            ->selectRaw("min(last_success_at) filter (where last_success_at < {$cutoff}) as not_checked_since", [$now, $jitter])
            ->selectRaw("count(*) filter (where last_success_at is null and exists (
                select 1 from source_runs r where r.source_id = sources.id and r.mode = 'live' and coalesce(r.queued_at, r.created_at) < {$cutoff}
            )) as never_checked", [$now, $jitter])
            ->selectRaw('max(last_run_at) as last_run_at')
            ->toBase()
            ->first();

        $active = (int) ($row->active ?? 0);
        $lastRunAt = isset($row->last_run_at) ? CarbonImmutable::parse((string) $row->last_run_at)->utc() : null;
        $age = $lastRunAt !== null ? $this->ageOf($lastRunAt) : null;

        $problems = array_filter([
            (int) $row->degraded > 0 ? "{$row->degraded} degraded" : null,
            (int) $row->unhealthy > 0 ? "{$row->unhealthy} unhealthy (paused)" : null,
            (int) $row->structure_alerts > 0
                ? $row->structure_alerts.' possible structure '.((int) $row->structure_alerts === 1 ? 'change' : 'changes')
                : null,
            $this->notCheckedSince((int) $row->not_checked, $row->not_checked_since ?? null),
            (int) $row->never_checked > 0 ? "{$row->never_checked} never checked successfully" : null,
        ]);

        if ($problems !== []) {
            return new HealthCheck('sources', 'Sources', HealthState::Stale, $lastRunAt, $age, $expectation, implode(', ', $problems));
        }

        $detail = match ($active) {
            0 => 'No active sources',
            1 => '1 active source, all healthy',
            default => "{$active} active sources, all healthy",
        };

        return new HealthCheck('sources', 'Sources', HealthState::Ok, $lastRunAt, $age, $expectation, $detail);
    }

    /**
     * "1 not checked successfully since Sat 10 Oct" / "2 not checked
     * successfully, the oldest since Sat 10 Oct", or null for none.
     */
    private function notCheckedSince(int $count, mixed $oldest): ?string
    {
        if ($count === 0 || $oldest === null) {
            return null;
        }

        $since = LondonFormatter::shortDate(CarbonImmutable::parse((string) $oldest)->utc());

        return $count === 1
            ? "1 not checked successfully since {$since}"
            : "{$count} not checked successfully, the oldest since {$since}";
    }

    public function failedJobCount(): int
    {
        return FailedJob::query()->count();
    }

    public function pendingJobs(): int
    {
        return DB::table('jobs')->count();
    }

    /**
     * Age in seconds of the oldest row in jobs, or null when it is empty.
     */
    public function oldestPendingAgeSeconds(): ?int
    {
        $oldest = DB::table('jobs')->min('created_at');

        return $oldest === null ? null : max(0, CarbonImmutable::now()->getTimestamp() - (int) $oldest);
    }

    /**
     * @return list<HealthCheck>
     */
    public function all(): array
    {
        return [$this->scheduler(), $this->queue(), $this->calendarSync(), $this->spamCheck()];
    }

    /**
     * Age of a heartbeat in whole seconds. A future timestamp (clock step)
     * counts as 0, never negative.
     */
    private function ageOf(CarbonImmutable $lastSeenAt): int
    {
        return max(0, CarbonImmutable::now()->getTimestamp() - $lastSeenAt->getTimestamp());
    }

    private function freshness(string $key, string $title, ?Heartbeat $heartbeat, int $staleAfter, string $expectation, ?string $detail = null): HealthCheck
    {
        if ($heartbeat === null) {
            return new HealthCheck($key, $title, HealthState::NeverSeen, null, null, $expectation, $detail);
        }

        $age = $this->ageOf($heartbeat->last_seen_at);

        return new HealthCheck(
            $key,
            $title,
            $age > $staleAfter ? HealthState::Stale : HealthState::Ok,
            $heartbeat->last_seen_at,
            $age,
            $expectation,
            $detail,
        );
    }
}
