<?php

namespace App\Console\Commands;

use App\Jobs\OpsDrillJob;
use App\Models\FailedJob;
use App\Models\Heartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Local (and, from Phase 5, production) proof that failures are visible and
 * retryable (OPS-04): queues OpsDrillJob, which fails for --fail-for seconds.
 */
class OpsDrill extends Command
{
    protected $signature = 'ops:drill {--fail-for=60 : Seconds the job keeps failing} {--status : Report drill state instead of queueing}';

    protected $description = 'Queue a deliberately failing job (or report drill state)';

    public function handle(): int
    {
        if ($this->option('status')) {
            return $this->status();
        }

        $max = (int) config('ops.drill_max_fail_seconds');
        $raw = (string) $this->option('fail-for');
        $seconds = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 10, 'max_range' => $max]]);

        if ($seconds === false) {
            $this->error("--fail-for must be a whole number of seconds between 10 and {$max}.");

            return self::FAILURE;
        }

        $until = CarbonImmutable::now()->utc()->addSeconds($seconds);

        dispatch(new OpsDrillJob($until->toIso8601String()))->onConnection('database')->onQueue('default');

        $london = $until->setTimezone((string) config('calendar.timezone'))->format('H:i:s');
        $this->info("Drill job queued; it fails until {$london}");

        return self::SUCCESS;
    }

    private function status(): int
    {
        $uuids = FailedJob::query()
            ->whereRaw("(payload::jsonb)->>'displayName' = ?", [OpsDrillJob::class])
            ->orderBy('id')
            ->pluck('uuid')
            ->all();

        $lastSuccess = Heartbeat::find(Heartbeat::DRILL)?->last_seen_at->utc()->toIso8601String() ?? 'never';

        $this->line('failed='.count($uuids).' uuids='.implode(',', $uuids).' last_success='.$lastSuccess);

        return self::SUCCESS;
    }
}
