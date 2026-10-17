<?php

use App\Models\Heartbeat;
use Illuminate\Support\Facades\DB;
use Tests\Browser\UatFixtures as Uat;
use Tests\Feature\Ops\OpsFixtures;

use function Pest\Laravel\actingAs;

/*
 * Browser UAT F10 (System health page), docs/uat/browser-uat.md. The live
 * worker drill (schedule:work, queue:work, ops:drill) stays manual; this
 * covers what the admin sees: stale heartbeats, the failed-jobs badge and
 * the Retry / Delete row actions.
 */
beforeEach(function () {
    freezeLondon('2026-10-20 10:00');
    actingAs(Uat::admin());
});

it('F10: shows stale workers in words', function () {
    OpsFixtures::beatAgo(Heartbeat::SCHEDULER, 3600);
    OpsFixtures::beatAgo(Heartbeat::QUEUE, 3600, ['latency_seconds' => 1]);

    visit('/admin/system-health')
        ->waitForText('System health')
        ->assertPresent('section:has-text("Scheduler") >> .fi-badge-label:text-is("Stale")')
        ->assertPresent('section:has-text("Queue worker") >> .fi-badge-label:text-is("Stale")')
        ->assertSee('Expected every minute; stale after 3 minutes')
        ->assertSee('Last seen')
        ->assertSee('Expected every 5 minutes')
        ->assertSee('No failed jobs')
        ->assertNoJavaScriptErrors();
});

/**
 * JS expression: the System health sidebar badge shows $count (null = no badge).
 */
function failedJobsBadgeIs(?string $count): string
{
    $badge = '[...document.querySelectorAll(".fi-sidebar-item")].find((i) => i.textContent.includes("System health"))?.querySelector(".fi-sidebar-item-badge-ctn")';

    return $count === null
        ? '!'.$badge
        : '('.$badge.'?.textContent.trim() === '.json_encode($count).')';
}

it('F10: retries and deletes failed jobs from the page', function () {
    OpsFixtures::beatAgo(Heartbeat::SCHEDULER, 10);
    OpsFixtures::beatAgo(Heartbeat::QUEUE, 10, ['latency_seconds' => 1]);
    OpsFixtures::failedJob(secondsAgo: 120, displayName: 'App\\Jobs\\OpsDrillJob', exception: "RuntimeException: Drill failure one\n#0 /app/Foo.php(10): bar()");
    OpsFixtures::failedJob(secondsAgo: 60, displayName: 'App\\Jobs\\OpsDrillJob', exception: "RuntimeException: Drill failure two\n#0 /app/Foo.php(10): bar()");

    $page = visit('/admin/system-health')->waitForText('Failed jobs');

    $page->assertCount('tr:has-text("OpsDrillJob")', 2)
        ->assertSee('RuntimeException: Drill failure one')
        ->assertDontSee('/app/Foo.php')
        // Navigation badge with the failed job count.
        ->assertSeeIn('a:has-text("System health")', '2');

    $page->click('tr:has-text("Drill failure one") >> '.Uat::role('button', 'Retry'))
        ->waitForText('Send this job back to the queue. The worker runs it again within a few seconds.')
        ->click(Uat::role('button', 'Confirm'))
        ->waitForText('Sent back to the queue');
    // The sidebar badge follows the count without a reload (refresh-sidebar).
    Uat::waitUntil($page, failedJobsBadgeIs('1'));

    $page->click('tr:has-text("Drill failure two") >> '.Uat::role('button', 'Delete'))
        ->waitForText('This removes the record of the failure. It cannot be undone.')
        ->click(Uat::role('button', 'Confirm'))
        ->waitForText('Failed job deleted')
        ->waitForText('No failed jobs')
        ->assertNoJavaScriptErrors();
    Uat::waitUntil($page, failedJobsBadgeIs(null));

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1);
});
