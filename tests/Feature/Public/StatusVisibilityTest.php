<?php

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Publishing\Actions\ChangeEventStatus;
use App\Publishing\Actions\ChangeOccurrenceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

use function Pest\Laravel\get;

/*
 * Public effect of admin status (ADM-03 + CAL-07): hidden disappears from
 * every window and filter; cancelled stays listed with exactly one text
 * "Cancelled" badge until it expires. Status is changed ONLY through the real
 * domain actions. Badges are asserted by text, never by CSS class.
 */
beforeEach(function () {
    freezeLondon('2026-10-28 12:00');

    $this->admin = User::factory()->create(['email' => config('app.admin_email')]);
    $this->music = Category::factory()->create(['name' => 'Music', 'slug' => 'music', 'sort' => 1]);

    $this->sat = makeOccurrence([
        'title' => 'Brass Band Picnic',
        'category_id' => $this->music->id,
        'price_type' => PriceType::Free,
        'family_friendly' => true,
    ], '2026-10-31 10:00');
    $this->event = $this->sat->event;

    $sunStarts = CarbonImmutable::parse('2026-11-01 14:00', 'Europe/London');
    $this->sun = EventOccurrence::forceCreate([
        'event_id' => $this->event->id,
        'recurrence_key' => '2026-11-01',
        'starts_at' => $sunStarts->utc(),
        'expires_at' => $sunStarts->addHours(3)->utc(),
        'status' => 'scheduled',
    ]);

    // Every URL on which both occurrences match (Wed 28 Oct: today matches neither).
    $this->urls = ['/', '/?when=weekend', '/?when=week', '/?category=music', '/?free=1', '/?family=1'];

    $this->satLabel = 'Sat 31 Oct, 10am';
    $this->sunLabel = 'Sun 1 Nov, 2pm';

    $this->cardOn = function (string $uri, string $label): ?string {
        foreach (cardsIn(get($uri)->assertOk()) as $card) {
            if (str_contains($card, $label)) {
                return $card;
            }
        }

        return null;
    };

    $this->titlesOn = fn (string $uri): array => array_map(
        fn (string $card): string => html_entity_decode(trim(Str::before(Str::after($card, '<h3>'), '<'))),
        cardsIn(get($uri)->assertOk()),
    );

    $this->setEvent = fn (EventStatus $to, ?Event $event = null): Event => app(ChangeEventStatus::class)($event ?? $this->event, $to, $this->admin);
    $this->setOccurrence = fn (EventOccurrence $o, OccurrenceStatus $to): EventOccurrence => app(ChangeOccurrenceStatus::class)($o, $to, $this->admin);
});

it('removes a hidden date from the list, every window and every filter, and brings it back on restore', function () {
    ($this->setOccurrence)($this->sat, OccurrenceStatus::Hidden);

    foreach ($this->urls as $uri) {
        expect(($this->cardOn)($uri, $this->satLabel))->toBeNull("Hidden Sat card on {$uri}")
            ->and(($this->cardOn)($uri, $this->sunLabel))->not->toBeNull("Sun card missing on {$uri}");
    }

    expect(($this->cardOn)('/?when=today', $this->satLabel))->toBeNull()
        ->and(($this->cardOn)('/?when=today', $this->sunLabel))->toBeNull();

    ($this->setOccurrence)($this->sat->fresh(), OccurrenceStatus::Scheduled);

    foreach ($this->urls as $uri) {
        expect(($this->cardOn)($uri, $this->satLabel))->not->toBeNull("Restored Sat card missing on {$uri}");
    }

    expect(($this->cardOn)('/?when=today', $this->satLabel))->toBeNull();
});

it('hides every date of a hidden event and shows them again with their own statuses', function () {
    ($this->setOccurrence)($this->sun, OccurrenceStatus::Cancelled);
    ($this->setEvent)(EventStatus::Hidden);

    foreach ($this->urls as $uri) {
        expect(($this->cardOn)($uri, $this->satLabel))->toBeNull("Sat card of hidden event on {$uri}")
            ->and(($this->cardOn)($uri, $this->sunLabel))->toBeNull("Sun card of hidden event on {$uri}");
    }

    ($this->setEvent)(EventStatus::Published, $this->event->fresh());

    foreach ($this->urls as $uri) {
        $sat = ($this->cardOn)($uri, $this->satLabel);
        $sun = ($this->cardOn)($uri, $this->sunLabel);

        expect($sat)->not->toBeNull()->not->toContain('Cancelled')
            ->and($sun)->not->toBeNull()->toContain('Cancelled');
    }
});

it('badges every date of a cancelled event once, and reinstating clears all but individually cancelled dates', function () {
    ($this->setOccurrence)($this->sun, OccurrenceStatus::Cancelled);
    ($this->setEvent)(EventStatus::Cancelled);

    foreach ($this->urls as $uri) {
        $sat = (string) ($this->cardOn)($uri, $this->satLabel);
        $sun = (string) ($this->cardOn)($uri, $this->sunLabel);

        expect(substr_count($sat, 'Cancelled'))->toBe(1, "Sat badge count on {$uri}")
            ->and(substr_count($sun, 'Cancelled'))->toBe(1, "Sun badge count on {$uri}");
    }

    ($this->setEvent)(EventStatus::Published, $this->event->fresh());

    foreach ($this->urls as $uri) {
        expect(($this->cardOn)($uri, $this->satLabel))->not->toBeNull()->not->toContain('Cancelled')
            ->and(($this->cardOn)($uri, $this->sunLabel))->toContain('Cancelled');
    }
});

it('badges both dates of a cancelled event and clears both on reinstate when none was cancelled individually', function () {
    ($this->setEvent)(EventStatus::Cancelled);

    foreach ($this->urls as $uri) {
        expect(($this->cardOn)($uri, $this->satLabel))->toContain('Cancelled')
            ->and(($this->cardOn)($uri, $this->sunLabel))->toContain('Cancelled');
    }

    ($this->setEvent)(EventStatus::Published, $this->event->fresh());

    foreach ($this->urls as $uri) {
        expect(($this->cardOn)($uri, $this->satLabel))->not->toContain('Cancelled')
            ->and(($this->cardOn)($uri, $this->sunLabel))->not->toContain('Cancelled');
    }
});

it('does not list a hidden date under a cancelled event', function () {
    ($this->setOccurrence)($this->sat, OccurrenceStatus::Hidden);
    ($this->setEvent)(EventStatus::Cancelled);

    foreach ($this->urls as $uri) {
        expect(($this->cardOn)($uri, $this->satLabel))->toBeNull("Hidden date under cancelled event on {$uri}")
            ->and(($this->cardOn)($uri, $this->sunLabel))->toContain('Cancelled');
    }
});

it('does not list a cancelled date under a hidden event', function () {
    ($this->setOccurrence)($this->sat, OccurrenceStatus::Cancelled);
    ($this->setEvent)(EventStatus::Hidden);

    foreach ($this->urls as $uri) {
        expect(($this->cardOn)($uri, $this->satLabel))->toBeNull("Cancelled date under hidden event on {$uri}");
    }
});

it('lists an all-day date of a cancelled event with one badge until 23:59:59 and drops it at midnight', function () {
    $allDay = makeOccurrence(
        ['title' => 'Bonfire Supper', 'all_day' => true, 'start_time' => null],
        '2026-10-31 00:00',
        null,
        '2026-11-01 00:00',
    );
    ($this->setEvent)(EventStatus::Cancelled, $allDay->event);

    freezeLondon('2026-10-31 23:59:59');
    $html = (string) get('/')->assertOk()->getContent();

    expect($html)->toContain('Bonfire Supper')
        ->and(substr_count($html, 'Cancelled'))->toBe(1);

    freezeLondon('2026-11-01 00:00:00');
    get('/')->assertOk()->assertDontSee('Bonfire Supper');
});

it('keeps starts_at then id order when one of two same-time events is cancelled, badging only that card', function () {
    $first = makeOccurrence(['title' => 'Apple Day'], '2026-10-31 11:00');
    $second = makeOccurrence(['title' => 'Bake Sale'], '2026-10-31 11:00');

    expect($second->id)->toBeGreaterThan($first->id);

    $before = ($this->titlesOn)('/');
    ($this->setEvent)(EventStatus::Cancelled, $second->event);
    $after = ($this->titlesOn)('/');

    expect($after)->toBe($before)
        ->and(array_search('Apple Day', $after, true))->toBeLessThan(array_search('Bake Sale', $after, true))
        ->and(($this->cardOn)('/', 'Apple Day'))->not->toContain('Cancelled')
        ->and(substr_count((string) ($this->cardOn)('/', 'Bake Sale'), 'Cancelled'))->toBe(1);
});

it('puts a hidden then restored date back in the same position', function () {
    makeOccurrence(['title' => 'Apple Day'], '2026-10-31 11:00');
    makeOccurrence(['title' => 'Coffee Morning'], '2026-10-30 09:00');

    $before = ($this->titlesOn)('/');

    ($this->setOccurrence)($this->sat, OccurrenceStatus::Hidden);
    expect(($this->cardOn)('/', $this->satLabel))->toBeNull()
        ->and(($this->titlesOn)('/'))->toHaveCount(count($before) - 1);

    ($this->setOccurrence)($this->sat->fresh(), OccurrenceStatus::Scheduled);

    expect(($this->titlesOn)('/'))->toBe($before);
});
