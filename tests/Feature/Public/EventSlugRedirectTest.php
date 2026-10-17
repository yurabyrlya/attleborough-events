<?php

use App\Enums\EventOrigin;
use App\Enums\EventStatus;
use App\Models\Category;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\ChangeEventStatus;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Actions\UpdatePublishedEvent;
use App\Publishing\Data\PublishableEventData;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\get;

/*
 * EVT-02 slug history: old and uppercase slugs answer one 301 to the current
 * slug (Location from config('app.url')); history is never reused by another
 * event; hidden events 404 on every slug.
 */
beforeEach(function () {
    freezeLondon('2026-10-16 12:00');

    $this->admin = User::factory()->create(['email' => config('app.admin_email')]);
    $this->base = [
        'title' => 'Quiz Night',
        'description' => 'General knowledge quiz.',
        'categoryId' => Category::factory()->create()->id,
        'venueId' => Venue::factory()->create()->id,
        'startDate' => '2026-10-23',
        'startTime' => '19:30:00',
        'organiserContact' => 'organiser@example.test',
    ];
    $this->appUrl = rtrim((string) config('app.url'), '/');
    $this->event = app(PublishEvent::class)(new PublishableEventData(...$this->base), $this->admin, EventOrigin::Manual);
});

function redirectRename(Event $event, array $base, string $title): Event
{
    return app(UpdatePublishedEvent::class)(
        $event,
        new PublishableEventData(...array_merge($base, ['title' => $title])),
        test()->admin,
    );
}

it('sends an old slug to the current one with a 301 after a rename', function () {
    redirectRename($this->event, $this->base, 'Big Quiz Night');

    get('/events/quiz-night')->assertStatus(301)->assertHeader('Location', $this->appUrl.'/events/big-quiz-night');
    get('/events/big-quiz-night')->assertOk();
});

it('redirects every old slug in one hop after several renames', function () {
    $event = redirectRename($this->event, $this->base, 'Big Quiz Night');
    redirectRename($event, $this->base, 'Bigger Quiz');

    get('/events/quiz-night')->assertStatus(301)->assertHeader('Location', $this->appUrl.'/events/bigger-quiz');
    get('/events/big-quiz-night')->assertStatus(301)->assertHeader('Location', $this->appUrl.'/events/bigger-quiz');
});

it('makes an old slug current again when the title is renamed back', function () {
    $event = redirectRename($this->event, $this->base, 'Big Quiz Night');
    $event = redirectRename($event, $this->base, 'Quiz Night');

    expect($event->slug)->toBe('quiz-night');
    get('/events/quiz-night')->assertOk();
    get('/events/big-quiz-night')->assertStatus(301)->assertHeader('Location', $this->appUrl.'/events/quiz-night');

    expect(DB::table('event_slugs')->where('slug', 'quiz-night')->exists())->toBeFalse()
        ->and(DB::table('event_slugs')->where('event_id', $event->id)->pluck('slug')->all())->toBe(['big-quiz-night']);
});

it('never gives a new event a slug held in another event\'s history', function () {
    redirectRename($this->event, $this->base, 'Big Quiz Night');

    $newcomer = app(PublishEvent::class)(new PublishableEventData(...$this->base), $this->admin, EventOrigin::Manual);

    expect($newcomer->slug)->toBe('quiz-night-2');
    get('/events/quiz-night')->assertStatus(301)->assertHeader('Location', $this->appUrl.'/events/big-quiz-night');
});

it('sends an uppercase slug to the lowercase canonical URL', function () {
    get('/events/Quiz-Night')->assertStatus(301)->assertHeader('Location', $this->appUrl.'/events/quiz-night');
});

it('drops the query string and ignores the Host header in the redirect', function () {
    redirectRename($this->event, $this->base, 'Big Quiz Night');

    $this->withHeader('Host', 'evil.example')
        ->get('/events/quiz-night?utm_source=x')
        ->assertStatus(301)
        ->assertHeader('Location', $this->appUrl.'/events/big-quiz-night');
});

it('returns 404 for percent-encoded, non-ASCII and over-long slugs', function () {
    get('/events/f%C3%AAte')->assertNotFound();
    get('/events/'.str_repeat('a', 121))->assertNotFound();
    get('/events/quiz%20night')->assertNotFound();
});

it('sends an old-slug .ics URL to the current slug\'s .ics URL', function () {
    redirectRename($this->event, $this->base, 'Big Quiz Night');

    get('/events/quiz-night/2026-10-23.ics')
        ->assertStatus(301)
        ->assertHeader('Location', $this->appUrl.'/events/big-quiz-night/2026-10-23.ics');
    get('/events/QUIZ-NIGHT/2026-10-23.ics')
        ->assertStatus(301)
        ->assertHeader('Location', $this->appUrl.'/events/big-quiz-night/2026-10-23.ics');
});

it('returns 404 on an old slug of a hidden event instead of redirecting', function () {
    $event = redirectRename($this->event, $this->base, 'Big Quiz Night');
    app(ChangeEventStatus::class)($event, EventStatus::Hidden, $this->admin);

    get('/events/quiz-night')->assertNotFound();
    get('/events/Big-Quiz-Night')->assertNotFound();
    get('/events/quiz-night/2026-10-23.ics')->assertNotFound();
});
