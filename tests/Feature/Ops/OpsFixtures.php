<?php

namespace Tests\Feature\Ops;

use App\Models\User;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shared fixtures for the OPS-04 tests (kept here, not in tests/Pest.php).
 */
final class OpsFixtures
{
    public static function admin(): User
    {
        return User::factory()->create(['email' => config('app.admin_email')]);
    }

    /**
     * Write a heartbeat row directly, $secondsAgo before the frozen now
     * (negative = in the future).
     *
     * @param  array<string, mixed>|null  $meta
     */
    public static function beatAgo(string $name, int $secondsAgo, ?array $meta = null): void
    {
        DB::table('heartbeats')->updateOrInsert(['name' => $name], [
            'last_seen_at' => now()->utc()->subSeconds($secondsAgo)->format('Y-m-d H:i:s'),
            'meta' => $meta === null ? null : json_encode($meta),
        ]);
    }

    /**
     * The scheduled events, bootstrapping the console kernel so
     * routes/console.php is loaded.
     *
     * @return list<ScheduledEvent>
     */
    public static function scheduled(): array
    {
        app(Kernel::class)->bootstrap();

        return array_values(app(Schedule::class)->events());
    }

    /**
     * Insert a failed_jobs row directly (test fixture). Returns its id.
     *
     * @param  array<string, mixed>  $payloadExtra
     */
    public static function failedJob(
        int $secondsAgo = 60,
        string $displayName = 'App\\Jobs\\QueueHeartbeat',
        string $exception = "RuntimeException: Something broke\n#0 /app/Foo.php(10): bar()",
        array $payloadExtra = [],
    ): int {
        return (int) DB::table('failed_jobs')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => $displayName, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call'] + $payloadExtra),
            'exception' => $exception,
            'failed_at' => now()->utc()->subSeconds($secondsAgo)->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Run the in-process database worker until the queue is empty.
     */
    public static function workerPass(): void
    {
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true]);
    }

    public static function scheduledByDescription(string $description): ?ScheduledEvent
    {
        foreach (self::scheduled() as $event) {
            if ($event->description === $description) {
                return $event;
            }
        }

        return null;
    }
}
