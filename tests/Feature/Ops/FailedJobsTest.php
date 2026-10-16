<?php

use App\Filament\Pages\SystemHealth;
use App\Jobs\OpsDrillJob;
use App\Models\FailedJob;
use App\Models\Heartbeat;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\Feature\Ops\OpsFixtures;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

const ALREADY_HANDLED = 'This job was already retried or deleted. Refresh the page.';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20T09:00:00Z'));
    $this->admin = OpsFixtures::admin();
    actingAs($this->admin);

    $this->warnings = collect();
    Log::listen(function (MessageLogged $message) {
        if ($message->level === 'warning') {
            $this->warnings->push($message);
        }
    });
});

// Livewire keeps "a component rendered this request" in a static that
// outlives the test; reset it so later public-page tests see no injected script.
afterEach(fn () => Livewire::flushState());

it('runs the drill end to end: fails into failed_jobs, is listed, retried after its window and then succeeds', function () {
    $this->artisan('ops:drill', ['--fail-for' => 60])
        ->expectsOutputToContain('Drill job queued; it fails until')
        ->assertExitCode(0);
    expect(DB::table('jobs')->count())->toBe(1);

    OpsFixtures::workerPass();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(FailedJob::count())->toBe(1);
    $failed = FailedJob::sole();
    expect($failed->exception)->toContain('Deliberate ops drill failure')
        ->and($failed->jobName())->toBe('OpsDrillJob');

    livewire(SystemHealth::class)
        ->assertCanSeeTableRecords([$failed])
        ->assertSee('OpsDrillJob')
        ->assertSee('default');

    $this->travel(61)->seconds();

    livewire(SystemHealth::class)
        ->callAction(TestAction::make('retry')->table($failed))
        ->assertNotified('Sent back to the queue');

    expect(FailedJob::count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1);

    $warning = $this->warnings->firstWhere('message', 'ops: failed job retried');
    expect($warning)->not->toBeNull()
        ->and($warning->context)->toBe(['uuid' => $failed->uuid, 'admin_id' => $this->admin->id]);

    OpsFixtures::workerPass();

    expect(Heartbeat::find(Heartbeat::DRILL))->not->toBeNull()
        ->and(FailedJob::count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('deletes a failed job through the Delete action and logs only the uuid and admin id', function () {
    $id = OpsFixtures::failedJob();
    $failed = FailedJob::findOrFail($id);

    livewire(SystemHealth::class)
        ->callAction(TestAction::make('delete')->table($failed))
        ->assertNotified('Failed job deleted');

    expect(FailedJob::count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);

    $warning = $this->warnings->firstWhere('message', 'ops: failed job deleted');
    expect($warning)->not->toBeNull()
        ->and($warning->context)->toBe(['uuid' => $failed->uuid, 'admin_id' => $this->admin->id]);
});

it('tells the admin a job was already handled when Retry or Delete runs on a row that is gone, and changes nothing', function (string $action) {
    $stale = FailedJob::findOrFail(OpsFixtures::failedJob(120));
    $other = OpsFixtures::failedJob(60);
    $page = livewire(SystemHealth::class);

    DB::table('failed_jobs')->where('id', $stale->id)->delete();

    $page->callAction(TestAction::make($action)->table($stale))
        ->assertNotified(ALREADY_HANDLED);

    expect(DB::table('failed_jobs')->pluck('id')->all())->toBe([$other])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($this->warnings)->toBeEmpty();
})->with(['retry', 'delete']);

it('lists failed jobs newest first with ties broken by id, and says No failed jobs when there are none', function () {
    livewire(SystemHealth::class)->assertSee('No failed jobs');

    $oldest = OpsFixtures::failedJob(600);
    $tieLow = OpsFixtures::failedJob(60);
    $tieHigh = OpsFixtures::failedJob(60);

    livewire(SystemHealth::class)
        ->assertCanSeeTableRecords(collect([$tieHigh, $tieLow, $oldest])->map(fn (int $id) => FailedJob::findOrFail($id)), inOrder: true)
        ->assertDontSee('No failed jobs');
});

it('never renders the failed job payload and shows only the first exception line cut to 200 characters', function () {
    $firstLine = 'RuntimeException: '.str_repeat('x', 182).'TAIL-BEYOND-200';
    OpsFixtures::failedJob(
        exception: $firstLine."\nSECOND-LINE-MARKER #0 /app/Secret.php(1)",
        payloadExtra: ['data' => ['commandName' => 'App\\Jobs\\QueueHeartbeat', 'serializedCommand' => 'O:8:"PAYLOAD-SECRET-MARKER"']],
    );

    $html = (string) $this->get('/admin/system-health')->assertOk()->getContent();

    expect($html)->not->toContain('serializedCommand')
        ->and($html)->not->toContain('PAYLOAD-SECRET-MARKER')
        ->and($html)->not->toContain('SECOND-LINE-MARKER')
        ->and($html)->not->toContain('TAIL-BEYOND-200')
        ->and($html)->toContain(mb_substr($firstLine, 0, 200))
        ->and(FailedJob::sole()->exceptionSummary())->toBe(mb_substr($firstLine, 0, 200));
});

it('reports the drill state with ops:drill --status: failed=1 with the uuid, then failed=0 with last_success', function () {
    $this->artisan('ops:drill', ['--status' => true])
        ->expectsOutput('failed=0 uuids= last_success=never')
        ->assertExitCode(0);

    Artisan::call('ops:drill', ['--fail-for' => 10]);
    OpsFixtures::workerPass();
    $uuid = FailedJob::sole()->uuid;

    $this->artisan('ops:drill', ['--status' => true])
        ->expectsOutput("failed=1 uuids={$uuid} last_success=never")
        ->assertExitCode(0);

    $this->travel(11)->seconds();
    Artisan::call('queue:retry', ['id' => [$uuid]]);
    OpsFixtures::workerPass();

    $this->artisan('ops:drill', ['--status' => true])
        ->expectsOutput('failed=0 uuids= last_success=2026-10-20T09:00:11+00:00')
        ->assertExitCode(0);
});

it('refuses an ops:drill --fail-for outside 10 to 3600 seconds', function (string $seconds) {
    $this->artisan('ops:drill', ['--fail-for' => $seconds])->assertExitCode(1);

    expect(DB::table('jobs')->count())->toBe(0);
})->with(['9', '3601', 'abc']);

it('gives OpsDrillJob a single try so it fails at once', function () {
    expect((new OpsDrillJob(now()->toIso8601String()))->tries)->toBe(1);
});
