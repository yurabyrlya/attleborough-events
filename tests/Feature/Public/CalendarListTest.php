<?php

use App\Enums\EventOrigin;
use App\Models\Category;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Data\PublishableEventData;
use Carbon\CarbonImmutable;

use function Pest\Laravel\get;

function publishForList(string $title, array $overrides = []): void
{
    $admin = User::firstWhere('email', 'admin@example.test')
        ?? User::factory()->create(['email' => 'admin@example.test']);

    app(PublishEvent::class)(new PublishableEventData(...array_merge([
        'title' => $title,
        'description' => 'Details.',
        'categoryId' => (Category::first() ?? Category::factory()->create())->id,
        'venueId' => (Venue::first() ?? Venue::factory()->create(['name' => 'Town Hall']))->id,
        'startDate' => '2026-10-24',
        'startTime' => '19:30:00',
        'organiserContact' => 'org@example.test',
    ], $overrides)), $admin, EventOrigin::Manual);
}

it('renders an empty state with 200 when nothing is published', function () {
    get('/')
        ->assertOk()
        ->assertSee('Nothing on yet. Check back soon.');
});

it('does not list an occurrence whose expires_at equals now', function () {
    publishForList('Quiz Night'); // 19:30 BST, no end -> expires 22:30 BST

    $this->travelTo(CarbonImmutable::parse('2026-10-24 22:30:00', 'Europe/London'));

    get('/')->assertOk()->assertDontSee('Quiz Night')->assertSee('Nothing on yet.');
});

it('lists an occurrence expiring one second after now', function () {
    publishForList('Quiz Night');

    $this->travelTo(CarbonImmutable::parse('2026-10-24 22:29:59', 'Europe/London'));

    get('/')->assertOk()->assertSee('Quiz Night');
});

it('orders occurrences with an identical start by id', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Europe/London'));

    publishForList('Zebra Crossing Talk');
    publishForList('Apple Day');

    get('/')->assertOk()->assertSeeInOrder(['Zebra Crossing Talk', 'Apple Day']);
});

it('groups an ongoing multi-day occurrence that started yesterday under Today', function () {
    publishForList('Halloween Trail', [
        'startDate' => '2026-10-31',
        'endDate' => '2026-11-01',
        'startTime' => null,
        'allDay' => true,
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00', 'Europe/London'));

    get('/')
        ->assertOk()
        ->assertSeeInOrder(['Today', 'Halloween Trail', 'All day until Sun 1 Nov'])
        ->assertDontSee('Saturday 31 October');
});

it('labels a single all-day occurrence "All day" under its date heading', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Europe/London'));

    publishForList('Craft Market', ['startDate' => '2026-10-25', 'startTime' => null, 'allDay' => true]);

    get('/')
        ->assertOk()
        ->assertSeeInOrder(['Sunday 25 October', 'Craft Market', 'All day'])
        ->assertDontSee('All day until');
});

it('uses Today and Tomorrow headings and drops :00 from whole-hour times', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 09:00', 'Europe/London'));

    publishForList('Coffee Morning', ['startDate' => '2026-10-23', 'startTime' => '10:00:00']);
    publishForList('Bingo', ['startDate' => '2026-10-24', 'startTime' => '19:00:00']);

    get('/')
        ->assertOk()
        ->assertSeeInOrder(['Today', 'Coffee Morning', 'Fri 23 Oct, 10am', 'Tomorrow', 'Bingo', 'Sat 24 Oct, 7pm']);
});
