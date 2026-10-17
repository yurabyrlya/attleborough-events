<?php

use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Browser\UatFixtures as Uat;

use function Pest\Laravel\actingAs;

/*
 * Browser UAT F0 (log in), F3 (create a recurring series) and F4 (stored
 * data and public listing), docs/uat/browser-uat.md.
 */
beforeEach(function () {
    freezeLondon('2026-10-20 10:00');
    Uat::seedCategories();
    Uat::venue();
    $this->admin = Uat::admin();
});

/**
 * Pick a date in a Filament date picker panel (not a native input) through
 * its Year and Month controls, so it works whatever today's date is in the
 * browser.
 */
function pickDate(mixed $page, string $field, string $date): mixed
{
    $day = CarbonImmutable::parse($date);
    $panel = '[id="form.'.$field.'-panel"]';

    return $page->click('[id="form.'.$field.'"]')
        ->fill($panel.' >> input[aria-label="Year"]', $day->format('Y'))
        ->select($panel.' >> select[aria-label="Month"]', $day->format('F'))
        ->click($panel.' >> '.Uat::role('gridcell', $day->format('l, j F Y')))
        ->keys('[id="form.'.$field.'"]', 'Escape');
}

/**
 * @return list<string>
 */
function repeatsOptions(mixed $page): array
{
    return $page->script('() => [...document.getElementById("form.repeats").options].map((o) => o.textContent.trim())');
}

it('F0: logs the admin in to the panel', function () {
    $page = visit('/admin/login')
        ->fill(Uat::label('Email address'), $this->admin->email)
        ->fill('[id="form.password"]', Uat::PASSWORD);

    Uat::settleFields($page, ['form.email' => $this->admin->email, 'form.password' => Uat::PASSWORD])
        ->click(Uat::role('button', 'Sign in'))
        ->waitForText('Dashboard')
        ->assertPathIs('/admin')
        ->assertPresent(Uat::role('link', 'Events'))
        ->assertPresent(Uat::role('link', 'System health'))
        ->assertNoJavaScriptErrors();
});

it('F3 + F4: creates a monthly series that the public calendar lists date by date', function () {
    actingAs($this->admin);

    $page = visit('/admin/events/create');

    $page->fill(Uat::label('Title'), 'UAT Quiz Night')
        ->fill(Uat::label('Description'), 'Monthly pub quiz. Teams of up to six.')
        ->select('[id="form.category_id"]', 'Community');

    // Before a start date, Repeats offers only "Does not repeat".
    expect(repeatsOptions($page))->toBe(['Does not repeat']);

    pickDate($page, 'start_date', '2026-10-30')
        ->fill('[id="form.start_time"]', '19:30')
        ->fill('[id="form.end_time"]', '22:00');
    Uat::waitUntil($page, 'document.getElementById("form.repeats").options.length > 1');

    // Fri 30 Oct is the 5th Friday: no "5th Friday" option, because not every month has one.
    expect(repeatsOptions($page))->toBe(['Does not repeat', 'Weekly on Friday', 'Monthly on the last Friday']);

    $page->select('[id="form.repeats"]', 'Monthly on the last Friday')
        ->waitForText('Next dates')
        // Same local time after the 25 Oct clock change.
        ->waitForText('Fri 30 Oct, 7:30pm')
        ->assertSee('Fri 27 Nov, 7:30pm')
        ->assertSee('Fri 25 Dec, 7:30pm');

    $page->click(Uat::role('radio', 'On a date'))
        ->waitForText('Last date');
    pickDate($page, 'repeat_until', '2027-03-31');

    $page->click('[id="form.venue_id"]')
        ->click(Uat::role('option', 'UAT Town Hall'))
        ->click(Uat::role('radio', 'Paid'))
        ->fill('[id="form.price_text"]', '£2 per person')
        ->fill('[id="form.organiser_name"]', 'The Griffin')
        ->fill('[id="form.organiser_contact"]', 'quiz@example.test')
        ->click('[id="key-bindings-1"]')
        ->waitForText('Created')
        ->assertPathIs('/admin/events')
        ->waitForText('UAT Quiz Night')
        ->assertSeeIn('tr:has-text("UAT Quiz Night")', 'Community')
        ->assertSeeIn('tr:has-text("UAT Quiz Night")', 'Fri 30 Oct 2026, 19:30')
        ->assertSeeIn('tr:has-text("UAT Quiz Night")', 'Monthly on the last Friday')
        ->assertSeeIn('tr:has-text("UAT Quiz Night")', 'Published')
        ->assertPresent('tr:has-text("UAT Quiz Night") >> '.Uat::role('link', 'Edit'))
        ->assertPresent('tr:has-text("UAT Quiz Night") >> '.Uat::role('button', 'Hide from calendar'))
        ->assertPresent('tr:has-text("UAT Quiz Night") >> '.Uat::role('button', 'Mark as cancelled'))
        ->assertNoJavaScriptErrors();

    // F4: stored rule, London-date keys and UTC instants inside the 90-day horizon.
    $event = Event::query()->where('title', 'UAT Quiz Night')->sole();
    expect($event->recurrence_rule)->toBe('FREQ=MONTHLY;BYDAY=-1FR;UNTIL=20270331T225959Z');

    $occurrences = DB::select(
        "select recurrence_key, to_char(starts_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as starts, status
         from event_occurrences where event_id = ? order by starts_at",
        [$event->id],
    );
    expect(array_map(fn (object $row): array => (array) $row, $occurrences))->toBe([
        ['recurrence_key' => '2026-10-30', 'starts' => '2026-10-30 19:30', 'status' => 'scheduled'],
        ['recurrence_key' => '2026-11-27', 'starts' => '2026-11-27 19:30', 'status' => 'scheduled'],
        ['recurrence_key' => '2026-12-25', 'starts' => '2026-12-25 19:30', 'status' => 'scheduled'],
    ]);

    $page->navigate('/')
        ->assertCount('main >> article:has-text("UAT Quiz Night")', 3)
        ->assertSeeIn('section:has(#day-2026-10-30)', 'UAT Quiz Night')
        ->assertSeeIn('section:has(#day-2026-11-27)', 'UAT Quiz Night')
        ->assertSeeIn('section:has(#day-2026-12-25)', 'UAT Quiz Night')
        ->assertSee('Fri 27 Nov, 7:30pm');
});
