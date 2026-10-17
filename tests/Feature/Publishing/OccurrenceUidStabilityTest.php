<?php

use App\Enums\EventOrigin;
use App\Enums\RepeatPattern;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Actions\UpdatePublishedEvent;
use App\Publishing\Data\PublishableEventData;

use function Pest\Laravel\get;

/*
 * WR-06: OccurrenceUid promises a UID never changes for the same occurrence.
 * One-off events use "{id}@domain" and series instances "{id}-{key}@domain",
 * so an event must never switch between one-off and repeating. Allowed edits
 * keep the UID byte-identical over real .ics downloads.
 */
beforeEach(function () {
    freezeLondon('2026-10-16 12:00');

    $this->admin = User::factory()->create(['email' => config('app.admin_email')]);
    $this->base = [
        'title' => 'Quiz Night',
        'description' => 'General knowledge quiz, teams of up to six.',
        'categoryId' => Category::factory()->create()->id,
        'venueId' => Venue::factory()->create()->id,
        'startDate' => '2026-10-23',
        'startTime' => '19:30:00',
        'organiserContact' => 'organiser@example.test',
    ];
    $this->domain = (string) config('site.domain');

    $this->publish = fn (array $overrides = []): Event => app(PublishEvent::class)(
        new PublishableEventData(...array_merge($this->base, $overrides)),
        $this->admin,
        EventOrigin::Manual,
    );

    $this->update = fn (Event $event, array $overrides = []): Event => app(UpdatePublishedEvent::class)(
        $event,
        new PublishableEventData(...array_merge($this->base, $overrides)),
        $this->admin,
    );

    $this->series = fn (): Event => ($this->publish)([
        'title' => 'Coffee Morning',
        'startTime' => '10:00:00',
        'repeats' => RepeatPattern::Weekly,
    ]);
});

function uidLineOf(string $url): ?string
{
    $body = (string) get($url)->assertOk()->getContent();
    preg_match('/^UID:.*$/m', $body, $m);

    return isset($m[0]) ? rtrim($m[0], "\r") : null;
}

it('keeps a one-off event\'s UID when its start time is edited', function () {
    $event = ($this->publish)();
    $before = uidLineOf('/events/quiz-night/2026-10-23.ics');

    ($this->update)($event, ['startTime' => '20:00:00']);

    expect($before)->toBe("UID:{$event->id}@{$this->domain}")
        ->and(uidLineOf('/events/quiz-night/2026-10-23.ics'))->toBe($before);
});

it('refuses to turn a one-off event into a repeating one and changes nothing', function () {
    $event = ($this->publish)();
    $before = uidLineOf('/events/quiz-night/2026-10-23.ics');
    $count = EventOccurrence::query()->where('event_id', $event->id)->count();

    expect(fn () => ($this->update)($event, ['repeats' => RepeatPattern::Weekly]))
        ->toThrow(InvalidArgumentException::class, "can't switch between one-off and repeating");

    expect($event->fresh()->recurrence_rule)->toBeNull()
        ->and(EventOccurrence::query()->where('event_id', $event->id)->count())->toBe($count)
        ->and(uidLineOf('/events/quiz-night/2026-10-23.ics'))->toBe($before);
});

it('keeps a series instance\'s UID when the series start time is edited', function () {
    $event = ($this->series)();
    $before = uidLineOf('/events/coffee-morning/2026-10-30.ics');

    ($this->update)($event, ['title' => 'Coffee Morning', 'startTime' => '11:00:00', 'repeats' => RepeatPattern::Weekly]);

    expect($before)->toBe("UID:{$event->id}-2026-10-30@{$this->domain}")
        ->and(uidLineOf('/events/coffee-morning/2026-10-30.ics'))->toBe($before);
});

it('refuses to turn a series into a one-off event and changes nothing', function () {
    $event = ($this->series)();
    $rule = $event->recurrence_rule;
    $before = uidLineOf('/events/coffee-morning/2026-10-30.ics');

    expect(fn () => ($this->update)($event, ['title' => 'Coffee Morning', 'startTime' => '10:00:00', 'repeats' => RepeatPattern::None]))
        ->toThrow(InvalidArgumentException::class, "can't switch between one-off and repeating");

    expect($event->fresh()->recurrence_rule)->toBe($rule)
        ->and(uidLineOf('/events/coffee-morning/2026-10-30.ics'))->toBe($before);
});

it('still allows switching between repeating patterns', function () {
    $event = ($this->series)();

    // Fri 23 Oct 2026 is the fourth Friday (the last is the 30th).
    $updated = ($this->update)($event, ['title' => 'Coffee Morning', 'startTime' => '10:00:00', 'repeats' => RepeatPattern::MonthlyNth]);

    expect($updated->recurrence_rule)->toBe('FREQ=MONTHLY;BYDAY=4FR');
});
