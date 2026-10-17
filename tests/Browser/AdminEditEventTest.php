<?php

use App\Enums\OccurrenceStatus;
use App\Models\Event;
use App\Publishing\Actions\ChangeOccurrenceStatus;
use App\Publishing\Actions\UpdatePublishedEvent;
use Illuminate\Support\Facades\DB;
use Tests\Browser\UatFixtures as Uat;

use function Pest\Laravel\actingAs;

/*
 * Browser UAT F5 (single-date status), F6 (edit only the time), F7
 * (whole-event status) and F8 (repeat rules the form can't edit),
 * docs/uat/browser-uat.md. Starts from the F3 series, published through
 * PublishEvent.
 */
beforeEach(function () {
    freezeLondon('2026-10-20 10:00');
    Uat::seedCategories();
    $this->admin = Uat::admin();
    $this->event = Uat::quizNight($this->admin);
    $this->editUrl = '/admin/events/'.$this->event->getRouteKey().'/edit';

    actingAs($this->admin);
});

/**
 * Open the edit page and wait for the lazy Occurrences relation manager.
 */
function openEditPage(string $url, mixed $page = null): mixed
{
    $page = $page === null ? visit($url) : $page->navigate($url);
    $page->script('() => window.scrollTo(0, document.body.scrollHeight)');

    return $page->waitForText('2026-12-25');
}

/**
 * Click a row or header action, then its dialog's submit button (each
 * dialog names its own, e.g. "Hide event"; "Keep as is" cancels).
 */
function confirmAction(mixed $page, string $label, string $submit, ?string $row = null): mixed
{
    $scope = $row === null ? '' : 'tr:has-text("'.$row.'") >> ';

    return $page->click($scope.Uat::role('button', $label))
        ->assertPresent(Uat::role('button', 'Keep as is'))
        ->click(Uat::role('button', $submit));
}

/**
 * @return array<string, array{string, string}> recurrence_key => [starts (UTC), status]
 */
function storedOccurrences(Event $event): array
{
    $rows = DB::select(
        "select recurrence_key, to_char(starts_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as starts, status
         from event_occurrences where event_id = ? order by starts_at",
        [$event->id],
    );

    return collect($rows)->mapWithKeys(fn (object $row): array => [$row->recurrence_key => [$row->starts, $row->status]])->all();
}

it('F5: cancels, hides and restores single dates', function () {
    $page = openEditPage($this->editUrl);

    $page->click('tr:has-text("2026-12-25") >> '.Uat::role('button', 'Cancel this date'))
        ->waitForText('Cancel this date?')
        ->assertSee('This date stays on the public calendar with a "Cancelled" label until it has passed.')
        ->click(Uat::role('button', 'Cancel date'))
        ->waitForText('Date cancelled')
        ->assertSeeIn('tr:has-text("2026-12-25")', 'Cancelled')
        ->assertPresent('tr:has-text("2026-12-25") >> '.Uat::role('button', 'Restore this date'));

    $page->navigate('/')
        ->assertSeeIn('article:has(time[datetime^="2026-12-25"])', 'Cancelled');

    $page = openEditPage($this->editUrl, $page);
    $page->click('tr:has-text("2026-11-27") >> '.Uat::role('button', 'Hide this date'))
        ->waitForText('This date disappears from the public calendar. You can restore it at any time.')
        ->click(Uat::role('button', 'Hide date'))
        ->waitForText('Date hidden')
        ->assertSeeIn('tr:has-text("2026-11-27")', 'Hidden');

    $page->navigate('/')
        ->assertNotPresent('time[datetime^="2026-11-27"]')
        ->assertPresent('time[datetime^="2026-10-30"]');

    $page = openEditPage($this->editUrl, $page);
    $page->click('tr:has-text("2026-11-27") >> '.Uat::role('button', 'Restore this date'))
        ->waitForText('This date goes back on the public calendar without a "Cancelled" label.')
        ->click(Uat::role('button', 'Restore date'))
        ->waitForText('Date restored')
        ->assertSeeIn('tr:has-text("2026-11-27")', 'Scheduled');

    $page->navigate('/')
        ->assertPresent('time[datetime^="2026-11-27"]')
        ->assertDontSeeIn('article:has(time[datetime^="2026-11-27"])', 'Cancelled')
        ->assertNoJavaScriptErrors();

    expect(storedOccurrences($this->event))->toBe([
        '2026-10-30' => ['2026-10-30 19:30', 'scheduled'],
        '2026-11-27' => ['2026-11-27 19:30', 'scheduled'],
        '2026-12-25' => ['2026-12-25 19:30', 'cancelled'],
    ]);
});

it('F6: moves every date when only the time changes and keeps date statuses', function () {
    $page = openEditPage($this->editUrl);
    confirmAction($page, 'Cancel this date', 'Cancel date', '2026-12-25')->waitForText('Date cancelled');

    // WR-06 on a series: it cannot be switched to "Does not repeat".
    expect($page->script('() => [...document.getElementById("form.repeats").options].map((o) => o.textContent.trim())'))
        ->not->toContain('Does not repeat');
    $page->assertSee('To stop it repeating, set a last date or hide it.');

    $page->fill('[id="form.start_time"]', '20:00')
        ->click(Uat::role('button', 'Save changes'))
        ->waitForText('Saved')
        ->waitForText('Fri 30 Oct, 8pm')
        // The Occurrences table re-renders after a save, without a reload.
        ->waitForText('Fri 30 Oct 2026, 20:00');

    expect(storedOccurrences($this->event))->toBe([
        '2026-10-30' => ['2026-10-30 20:00', 'scheduled'],
        '2026-11-27' => ['2026-11-27 20:00', 'scheduled'],
        '2026-12-25' => ['2026-12-25 20:00', 'cancelled'],
    ]);

    $page->navigate('/')
        ->assertSeeIn('article:has(time[datetime^="2026-10-30"])', 'Fri 30 Oct, 8pm')
        ->assertSeeIn('article:has(time[datetime^="2026-12-25"])', 'Cancelled');

    // Still right after a reload.
    openEditPage($this->editUrl, $page)
        ->assertSeeIn('tr:has-text("2026-10-30")', 'Fri 30 Oct 2026, 20:00')
        ->assertNoJavaScriptErrors();
});

it('F6: locks Repeats on a one-off event (WR-06)', function () {
    $oneOff = Uat::publish($this->admin, ['title' => 'UAT Jumble Sale', 'startDate' => '2026-10-31', 'startTime' => '10:00:00']);

    visit('/admin/events/'.$oneOff->getRouteKey().'/edit')
        ->waitForText('UAT Jumble Sale')
        ->assertDisabled('[id="form.repeats"]')
        ->assertSee("To make this repeat, hide it and create a new repeating event. The hidden event's page will stop working and the new event gets its own web address.");
});

it('F7: hides, shows, cancels and reinstates the whole event from the events table', function () {
    app(ChangeOccurrenceStatus::class)(
        $this->event->occurrences()->where('recurrence_key', '2026-12-25')->sole(),
        OccurrenceStatus::Cancelled,
        $this->admin,
    );
    $row = 'UAT Quiz Night';
    $eventPage = '/events/'.$this->event->slug;

    $page = visit('/admin/events')->waitForText($row);
    $page->assertNotPresent('internal:role=button[name=/delete/i]');

    confirmAction($page, 'Hide from calendar', 'Hide event', $row)
        ->waitForText('Event hidden')
        ->assertSeeIn('tr:has-text("UAT Quiz Night")', 'Hidden')
        ->assertPresent('tr:has-text("UAT Quiz Night") >> '.Uat::role('button', 'Show on calendar'))
        ->assertNotPresent('tr:has-text("UAT Quiz Night") >> '.Uat::role('button', 'Mark as cancelled'));
    $page->navigate('/')->assertDontSee('UAT Quiz Night');
    $page->navigate($eventPage)->assertTitleContains('Not Found');

    $page->navigate('/admin/events')->waitForText($row);
    confirmAction($page, 'Show on calendar', 'Show event', $row)
        ->waitForText('Event shown on the calendar')
        ->assertSeeIn('tr:has-text("UAT Quiz Night")', 'Published');
    $page->navigate('/')->assertCount('main >> article:has-text("UAT Quiz Night")', 3);

    $page->navigate('/admin/events')->waitForText($row);
    confirmAction($page, 'Mark as cancelled', 'Cancel event', $row)
        ->waitForText('Event marked as cancelled')
        ->assertSeeIn('tr:has-text("UAT Quiz Night")', 'Cancelled')
        ->assertPresent('tr:has-text("UAT Quiz Night") >> '.Uat::role('button', 'Reinstate'))
        ->assertNotPresent('tr:has-text("UAT Quiz Night") >> '.Uat::role('button', 'Hide from calendar'));
    $page->navigate('/')->assertCount('main >> article:has-text("UAT Quiz Night"):has-text("Cancelled")', 3);

    $page->navigate('/admin/events')->waitForText($row);
    confirmAction($page, 'Reinstate', 'Reinstate event', $row)
        ->waitForText('Event reinstated')
        ->assertSeeIn('tr:has-text("UAT Quiz Night")', 'Published');
    // Only the date cancelled on its own still says Cancelled.
    $page->navigate('/')
        ->assertCount('main >> article:has-text("UAT Quiz Night"):has-text("Cancelled")', 1)
        ->assertSeeIn('article:has(time[datetime^="2026-12-25"])', 'Cancelled')
        ->assertNoJavaScriptErrors();
});

it('F7: hides and cancels from the edit page header', function () {
    $page = visit($this->editUrl)->waitForText('Hide from calendar');
    $page->assertNotPresent('internal:role=button[name=/delete/i]');

    confirmAction($page, 'Hide from calendar', 'Hide event')
        ->waitForText('Event hidden')
        ->waitForText('Show on calendar');
    confirmAction($page, 'Show on calendar', 'Show event')
        ->waitForText('Event shown on the calendar');
    confirmAction($page, 'Mark as cancelled', 'Cancel event')
        ->waitForText('Event marked as cancelled')
        ->waitForText('Reinstate')
        ->assertNoJavaScriptErrors();

    expect($this->event->refresh()->status->value)->toBe('cancelled');
});

it('F8: shows rules the form cannot edit read-only, with status actions still available', function (string $rule) {
    DB::table('events')->where('id', $this->event->id)->update(['recurrence_rule' => $rule]);
    $before = storedOccurrences($this->event);

    visit('/admin/events')
        ->waitForText('UAT Quiz Night')
        ->assertSeeIn('tr:has-text("UAT Quiz Night")', 'Repeats')
        ->assertDontSeeIn('tr:has-text("UAT Quiz Night")', 'Monthly on the last Friday');

    $page = visit($this->editUrl)
        ->waitForText(UpdatePublishedEvent::UNSUPPORTED_RULE_REFUSED)
        ->assertSee($rule)
        ->assertDisabled('[id="form.title"]')
        ->assertNotPresent(Uat::role('button', 'Save changes'))
        ->assertPresent(Uat::role('button', 'Hide from calendar'))
        ->assertPresent(Uat::role('button', 'Mark as cancelled'))
        ->assertNoJavaScriptErrors();

    // The read-only Repeats select never pretends to be an editable pattern.
    expect($page->script('() => { const s = document.getElementById("form.repeats"); return s.options[s.selectedIndex].textContent.trim(); }'))
        ->toBe("Custom rule (can't be edited here)");

    expect(storedOccurrences($this->event))->toBe($before);
})->with([
    'weekly on Monday' => 'FREQ=WEEKLY;BYDAY=MO',
    'daily' => 'FREQ=DAILY',
]);
