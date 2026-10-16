<?php

use App\Jobs\QueueHeartbeat;
use App\Models\Heartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Ops\OpsFixtures;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20T09:00:00Z'));
});

it('queues QueueHeartbeat on the database connection from the schedule, and only the worker writes the queue heartbeat', function () {
    $event = OpsFixtures::scheduledByDescription(QueueHeartbeat::class);
    expect($event)->not->toBeNull();

    $event->run(app());

    expect(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->value('queue'))->toBe('default')
        ->and(Heartbeat::find(Heartbeat::QUEUE))->toBeNull();

    $this->travel(7)->seconds();
    OpsFixtures::workerPass();

    $beat = Heartbeat::find(Heartbeat::QUEUE);
    expect($beat)->not->toBeNull()
        ->and($beat->last_seen_at->toIso8601String())->toBe('2026-10-20T09:00:07+00:00')
        ->and($beat->meta)->toHaveKeys(['queued_at', 'latency_seconds'])
        ->and($beat->meta['latency_seconds'])->toBeGreaterThanOrEqual(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('schedules QueueHeartbeat every five minutes and prunes failed jobs older than 30 days daily', function () {
    $heartbeat = OpsFixtures::scheduledByDescription(QueueHeartbeat::class);
    $prune = collect(OpsFixtures::scheduled())
        ->filter(fn ($e) => str_contains((string) $e->command, 'queue:prune-failed --hours=720'));

    expect($heartbeat?->expression)->toBe('*/5 * * * *')
        ->and($prune)->toHaveCount(1)
        ->and($prune->first()->expression)->toBe('0 0 * * *');
});

it('keeps at most one waiting QueueHeartbeat when no worker runs', function () {
    $event = OpsFixtures::scheduledByDescription(QueueHeartbeat::class);
    expect($event)->not->toBeNull();

    $event->run(app());
    $event->run(app());
    dispatch(new QueueHeartbeat);

    expect(DB::table('jobs')->count())->toBe(1);

    $job = new QueueHeartbeat;
    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueFor)->toBe(3600);
});
