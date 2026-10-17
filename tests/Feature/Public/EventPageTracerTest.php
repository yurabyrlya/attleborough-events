<?php

use App\Calendar\OccurrenceGenerator;
use App\Enums\EventOrigin;
use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\RepeatPattern;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\ChangeEventStatus;
use App\Publishing\Actions\ChangeOccurrenceStatus;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Actions\UpdatePublishedEvent;
use App\Publishing\Data\PublishableEventData;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Sabre\VObject\Reader;

use function Pest\Laravel\get;

/*
 * Tracer (01-08 Task 1): a card on / links to /events/{slug}; the page lists
 * the event's dates; one date downloads as an .ics with a stable UID. Events
 * are published through the real PublishEvent action so the slug comes from
 * EventSlugger, and statuses change only through the 01-05 actions.
 */
beforeEach(function () {
    freezeLondon('2026-10-16 12:00');

    $this->admin = User::factory()->create(['email' => config('app.admin_email')]);
    $this->base = [
        'title' => 'Quiz Night',
        'description' => 'General knowledge quiz, teams of up to six.',
        'categoryId' => Category::factory()->create()->id,
        'venueId' => Venue::factory()->create(['name' => 'Connaught Hall'])->id,
        'startDate' => '2026-10-23',
        'startTime' => '19:30:00',
        'endTime' => '22:00:00',
        'organiserContact' => 'organiser@example.test',
    ];
});

function tracerPublish(array $base, array $overrides = []): Event
{
    return app(PublishEvent::class)(
        new PublishableEventData(...array_merge($base, $overrides)),
        test()->admin,
        EventOrigin::Manual,
    );
}

function tracerWeekly(array $base, array $overrides = []): Event
{
    return tracerPublish($base, array_merge([
        'title' => 'Coffee Morning',
        'startTime' => '10:00:00',
        'endTime' => null,
        'repeats' => RepeatPattern::Weekly,
    ], $overrides));
}

function icsLine(TestResponse $response, string $name): ?string
{
    preg_match('/^'.preg_quote($name, '/').'[:;].*$/m', (string) $response->getContent(), $m);

    return isset($m[0]) ? rtrim($m[0], "\r") : null;
}

it('links each card title on / to the event page', function () {
    tracerPublish($this->base);

    $cards = cardsIn(get('/')->assertOk());

    expect($cards)->toHaveCount(1)
        ->and($cards[0])->toMatch('#<h3[^>]*>\s*<a[^>]*href="/events/quiz-night"[^>]*>\s*Quiz Night\s*</a>#s');
});

it('shows the event page with its date, description and an add-to-calendar link', function () {
    tracerPublish($this->base);

    get('/events/quiz-night')
        ->assertOk()
        ->assertSee('Quiz Night')
        ->assertSee('<time datetime="2026-10-23T19:30:00+01:00"', false)
        ->assertSee('General knowledge quiz, teams of up to six.')
        ->assertSee('href="/events/quiz-night/2026-10-23.ics"', false);
});

it('downloads one date as a valid single-event .ics with a stable UID and UTC times', function () {
    $event = tracerPublish($this->base);

    $response = get('/events/quiz-night/2026-10-23.ics')->assertOk();
    $body = (string) $response->getContent();

    expect($response->headers->get('Content-Type'))->toStartWith('text/calendar; charset=utf-8')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="quiz-night-2026-10-23.ics"')
        ->and($response->headers->get('X-Robots-Tag'))->toContain('noindex')
        ->and($body)->toContain('BEGIN:VCALENDAR')
        ->and(substr_count($body, 'BEGIN:VEVENT'))->toBe(1)
        ->and($body)->toContain("UID:{$event->id}@whatsonattleborough.co.uk")
        ->and($body)->toContain('DTSTART:20261023T183000Z')
        ->and($body)->toContain('DTEND:20261023T210000Z')
        ->and($body)->toContain('STATUS:CONFIRMED');

    $errors = array_filter(
        Reader::read($body)->validate(),
        // sabre levels: 1 repaired, 2 warning, 3 error.
        fn (array $message): bool => $message['level'] >= 2,
    );

    expect($errors)->toBe([]);
});

it('gives each date of a series its own UID keyed by date, in UTC across the clock change', function () {
    $event = tracerWeekly($this->base);

    $first = get('/events/coffee-morning/2026-10-23.ics')->assertOk();
    $second = get('/events/coffee-morning/2026-10-30.ics')->assertOk();

    expect(icsLine($first, 'DTSTART'))->toBe('DTSTART:20261023T090000Z')
        ->and(icsLine($second, 'UID'))->toBe("UID:{$event->id}-2026-10-30@whatsonattleborough.co.uk")
        ->and(icsLine($second, 'DTSTART'))->toBe('DTSTART:20261030T100000Z');
});

it('keeps the UID through an edit and a re-sync while the time moves and SEQUENCE never falls', function () {
    $event = tracerWeekly($this->base);
    $before = get('/events/coffee-morning/2026-10-30.ics')->assertOk();

    $this->travel(5)->minutes();
    app(UpdatePublishedEvent::class)(
        $event,
        new PublishableEventData(...array_merge($this->base, [
            'title' => 'Coffee Morning',
            'startTime' => '11:00:00',
            'endTime' => null,
            'repeats' => RepeatPattern::Weekly,
        ])),
        $this->admin,
    );
    app(OccurrenceGenerator::class)->sync($event->fresh());

    $after = get('/events/coffee-morning/2026-10-30.ics')->assertOk();
    $sequence = fn (TestResponse $r): int => (int) substr((string) icsLine($r, 'SEQUENCE'), strlen('SEQUENCE:'));

    expect(icsLine($after, 'UID'))->toBe(icsLine($before, 'UID'))
        ->and(icsLine($after, 'DTSTART'))->toBe('DTSTART:20261030T110000Z')
        ->and($sequence($after))->toBeGreaterThanOrEqual($sequence($before))
        ->and($sequence($before))->toBeGreaterThan(0);
});

it('gives a second event with the same title its own slug and turns an emoji-only title into "event"', function () {
    $first = tracerPublish($this->base, ['description' => 'The first quiz.']);
    $second = tracerPublish($this->base, ['description' => 'The second quiz.']);
    $emoji = tracerPublish($this->base, ['title' => '🎉🎉']);

    expect($first->slug)->toBe('quiz-night')
        ->and($second->slug)->toBe('quiz-night-2')
        ->and($emoji->slug)->toBe('event');

    get('/events/quiz-night')->assertOk()->assertSee('The first quiz.')->assertDontSee('The second quiz.');
    get('/events/quiz-night-2')->assertOk()->assertSee('The second quiz.')->assertDontSee('The first quiz.');
});

it('returns 404 for the page and every .ics of a hidden event', function () {
    $event = tracerPublish($this->base);
    app(ChangeEventStatus::class)($event, EventStatus::Hidden, $this->admin);

    get('/events/quiz-night')->assertNotFound();
    get('/events/quiz-night/2026-10-23.ics')->assertNotFound();
});

it('drops a hidden date from the page and 404s its .ics while the page stays up', function () {
    $event = tracerWeekly($this->base);
    $occurrence = EventOccurrence::where('event_id', $event->id)->where('recurrence_key', '2026-10-30')->firstOrFail();
    app(ChangeOccurrenceStatus::class)($occurrence, OccurrenceStatus::Hidden, $this->admin);

    get('/events/coffee-morning/2026-10-30.ics')->assertNotFound();
    get('/events/coffee-morning')
        ->assertOk()
        ->assertDontSee('datetime="2026-10-30T10:00:00+00:00"', false)
        ->assertSee('datetime="2026-11-06T10:00:00+00:00"', false);
});

it('marks a cancelled date in the .ics and on the page without an add link', function () {
    $event = tracerWeekly($this->base);
    $occurrence = EventOccurrence::where('event_id', $event->id)->where('recurrence_key', '2026-10-30')->firstOrFail();
    app(ChangeOccurrenceStatus::class)($occurrence, OccurrenceStatus::Cancelled, $this->admin);

    expect(icsLine(get('/events/coffee-morning/2026-10-30.ics')->assertOk(), 'STATUS'))->toBe('STATUS:CANCELLED');

    $page = (string) get('/events/coffee-morning')->assertOk()->getContent();
    preg_match('#<li\b[^>]*>(?:(?!</li>).)*datetime="2026-10-30T10:00:00\+00:00".*?</li>#s', $page, $item);

    expect($item[0] ?? '')->toContain('Cancelled')
        ->and($item[0] ?? '')->not->toContain('.ics')
        ->and($page)->toContain('href="/events/coffee-morning/2026-11-06.ics"');
});

it('returns 404, never 500, for unknown slugs, missing dates and impossible dates', function () {
    tracerPublish($this->base);

    get('/events/no-such-event')->assertNotFound();
    get('/events/quiz-night/2026-10-24.ics')->assertNotFound();
    get('/events/quiz-night/2026-13-45.ics')->assertNotFound();
    get('/events/')->assertNotFound();
});

it('backfills unique slugs in id order and then refuses duplicates', function () {
    $migration = require database_path('migrations/2026_10_17_000100_make_event_slugs_unique.php');
    $migration->down();

    $a = Event::factory()->create(['title' => 'Fair', 'slug' => 'fair']);
    $b = Event::factory()->create(['title' => 'Fair', 'slug' => 'fair']);
    $c = Event::factory()->create(['title' => '!!!', 'slug' => '']);

    $migration->up();

    expect(DB::table('events')->orderBy('id')->whereIn('id', [$a->id, $b->id, $c->id])->pluck('slug')->all())
        ->toBe(['fair', 'fair-2', 'event']);

    expect(fn () => Event::factory()->create(['slug' => 'fair']))->toThrow(QueryException::class);
});
