<?php

namespace Tests\Feature\Ops;

use App\Models\User;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

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
