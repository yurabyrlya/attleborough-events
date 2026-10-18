<?php

use App\Enums\DraftKind;
use App\Ingestion\Data\ChangeVerdict;
use App\Ingestion\Data\NormalisedFacts;
use App\Ingestion\Pipeline\ChangeDetector;
use App\Ingestion\Pipeline\ContentHasher;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\EventOccurrence;
use App\Models\EventSource;
use App\Models\SourceItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/*
 * ChangeDetector verdicts (03-09, PIPE-06/07, D-06, D-23) on unsaved models:
 * relations are preloaded, so nothing touches the database.
 */

const DETECTOR_NOW = '2026-10-17 06:00:00';

function detectorFacts(array $overrides = []): NormalisedFacts
{
    return new NormalisedFacts(...array_merge([
        'title' => 'Wednesday Folk Club',
        'allDay' => false,
        'startDate' => '2026-10-21',
        'startTime' => '19:00',
        'endTime' => '21:00',
        'venueName' => 'Town Hall',
        'venueAddress' => 'Queens Square, Attleborough',
        'description' => 'Bring an instrument.',
        'nextInstanceStartUtc' => CarbonImmutable::parse('2026-10-21 18:00:00', 'UTC'),
    ], $overrides));
}

function detectorHash(NormalisedFacts $facts): string
{
    return app(ContentHasher::class)->contentHash($facts);
}

/**
 * A source item whose stored content (and, when given, baseline) are $stored
 * and $accepted.
 */
function detectorItem(NormalisedFacts $stored, ?NormalisedFacts $accepted = null, ?string $outcome = null): SourceItem
{
    return (new SourceItem)->forceFill([
        'id' => 7,
        'content_hash' => detectorHash($stored),
        'payload' => $stored->toPayload(),
        'accepted_hash' => $accepted !== null ? detectorHash($accepted) : null,
        'accepted_payload' => $accepted?->toPayload(),
        'review_outcome' => $outcome ?? ($accepted !== null ? 'approved' : 'pending'),
    ]);
}

/**
 * A link to a published event built from $facts, with its next occurrence
 * and occurrences preloaded.
 *
 * @param  list<string>  $occurrenceKeys
 */
function detectorLink(NormalisedFacts $facts, array $occurrenceKeys = []): EventSource
{
    $event = (new Event)->forceFill([
        'id' => 42,
        'title' => $facts->title,
        'timezone' => 'Europe/London',
        'all_day' => $facts->allDay,
        'start_date' => $facts->startDate,
        'start_time' => $facts->startTime.':00',
        'end_date' => $facts->endDate,
        'end_time' => $facts->endTime !== null ? $facts->endTime.':00' : null,
        'recurrence_rule' => $facts->rrule,
        'excluded_dates' => [],
        'status' => 'published',
        'price_type' => 'unknown',
        'family_friendly' => false,
        'contact_public' => false,
    ]);

    $occurrences = new Collection(array_map(fn (string $key): EventOccurrence => (new EventOccurrence)->forceFill([
        'recurrence_key' => $key,
        'starts_at' => CarbonImmutable::parse($key.' 19:00', 'Europe/London')->utc(),
    ]), $occurrenceKeys));

    $event->setRelation('venue', null);
    $event->setRelation('occurrences', $occurrences);
    $event->setRelation('nextOccurrence', $occurrences->first());

    return (new EventSource)->setRelation('event', $event);
}

function detectorPending(DraftKind $kind, array $diff = []): EventDraft
{
    return (new EventDraft)->forceFill(['id' => 9, 'kind' => $kind, 'diff' => $diff === [] ? null : $diff]);
}

function detectorDetect(SourceItem $item, NormalisedFacts $facts, ?EventDraft $pending = null, ?EventSource $link = null): ChangeVerdict
{
    return app(ChangeDetector::class)->detect($item, $facts, detectorHash($facts), $pending, $link, CarbonImmutable::parse(DETECTOR_NOW, 'Europe/London'));
}

it('redrafts an undecided item that has no baseline and no draft', function () {
    $facts = detectorFacts();

    expect(detectorDetect(detectorItem($facts), $facts)->type)->toBe(ChangeVerdict::REDRAFT);
});

it('replaces a pending new draft when the facts changed and leaves it when they did not', function () {
    $stored = detectorFacts();
    $moved = detectorFacts(['startTime' => '19:30']);
    $pending = detectorPending(DraftKind::New);

    expect(detectorDetect(detectorItem($stored), $moved, $pending)->type)->toBe(ChangeVerdict::REPLACE)
        ->and(detectorDetect(detectorItem($stored), detectorFacts(['description' => 'New words.']), $pending)->type)->toBe(ChangeVerdict::NONE)
        ->and(detectorDetect(detectorItem($stored), detectorFacts(['cancelled' => true]), $pending)->type)->toBe(ChangeVerdict::SUPERSEDE);
});

it('is quiet when a linked item still equals its baseline', function () {
    $facts = detectorFacts();
    $reworded = detectorFacts(['description' => 'Bring a friend.', 'url' => 'https://example.org/new']);

    expect(detectorDetect(detectorItem($facts, $facts), $reworded, null, detectorLink($facts))->type)->toBe(ChangeVerdict::QUIET);
});

it('cancels the whole event when the master becomes cancelled', function () {
    $facts = detectorFacts();
    $verdict = detectorDetect(detectorItem($facts, $facts), detectorFacts(['cancelled' => true, 'startTime' => '20:00']), null, detectorLink($facts, ['2026-10-21']));

    expect($verdict->type)->toBe(ChangeVerdict::CANCEL)
        ->and($verdict->diff)->toBe(['cancelled' => ['published' => false, 'before' => false, 'now' => true]])
        ->and($verdict->targetKeys)->toBe([])
        ->and($verdict->relevantStartsAt?->format('Y-m-d H:i'))->toBe('2026-10-21 18:00');
});

it('cancels newly cancelled future dates in ascending order with the earliest as target', function () {
    $series = detectorFacts(['rrule' => 'FREQ=WEEKLY;BYDAY=WE;COUNT=8', 'startDate' => '2026-10-14']);
    $cancelled = detectorFacts(['rrule' => 'FREQ=WEEKLY;BYDAY=WE;COUNT=8', 'startDate' => '2026-10-14', 'cancelledKeys' => ['2026-11-18', '2026-10-14', '2026-11-04']]);
    $verdict = detectorDetect(detectorItem($series, $series), $cancelled, null, detectorLink($series, ['2026-11-04']));

    // 14 Oct is already past: no draft for it.
    expect($verdict->type)->toBe(ChangeVerdict::CANCEL)
        ->and($verdict->diff)->toBe(['cancelled_keys' => ['2026-11-04', '2026-11-18']])
        ->and($verdict->targetKeys)->toBe(['2026-11-04', '2026-11-18'])
        ->and($verdict->relevantStartsAt?->format('Y-m-d H:i'))->toBe('2026-11-04 19:00');
});

it('raises an update with the changed D-06 fields in the fixed order', function () {
    $before = detectorFacts();
    $now = detectorFacts(['venueName' => 'Connaught Hall', 'venueAddress' => null, 'title' => 'Folk Club', 'endTime' => '22:00', 'description' => 'Other words.']);
    $verdict = detectorDetect(detectorItem($before, $before), $now, null, detectorLink($before, ['2026-10-21']));

    expect($verdict->type)->toBe(ChangeVerdict::UPDATE)
        ->and(array_keys($verdict->diff))->toBe(['title', 'end_time', 'venue'])
        ->and($verdict->diff['title'])->toBe(['published' => 'Wednesday Folk Club', 'before' => 'Wednesday Folk Club', 'now' => 'Folk Club'])
        ->and($verdict->diff['end_time'])->toBe(['published' => '21:00', 'before' => '21:00', 'now' => '22:00'])
        ->and($verdict->diff['venue'])->toBe(['published' => null, 'before' => 'Town Hall, Queens Square, Attleborough', 'now' => 'Connaught Hall']);
});

it('treats only case and spacing changes to the title as no change', function () {
    expect(ChangeDetector::changedFields(detectorFacts(), detectorFacts(['title' => '  wednesday   FOLK club'])))->toBe([]);
});

it('keeps a rejected item rejected until its facts change', function () {
    $facts = detectorFacts();

    expect(detectorDetect(detectorItem($facts, $facts, 'rejected'), detectorFacts(['description' => 'Reworded.']))->type)->toBe(ChangeVerdict::QUIET)
        ->and(detectorDetect(detectorItem($facts, $facts, 'rejected'), detectorFacts(['startTime' => '18:00']))->type)->toBe(ChangeVerdict::REDRAFT)
        ->and(detectorDetect(detectorItem($facts, $facts, 'rejected'), detectorFacts(['cancelled' => true]))->type)->toBe(ChangeVerdict::NONE);
});

it('creates nothing for a cancelled item nobody approved', function () {
    $facts = detectorFacts(['cancelled' => true]);

    expect(detectorDetect(detectorItem($facts), $facts)->type)->toBe(ChangeVerdict::NONE);
});

it('supersedes a pending update when the source goes back to the baseline', function () {
    $facts = detectorFacts();
    $pending = detectorPending(DraftKind::Update, ['start_time' => ['published' => '19:00', 'before' => '19:00', 'now' => '20:00']]);

    expect(detectorDetect(detectorItem(detectorFacts(['startTime' => '20:00']), $facts), $facts, $pending, detectorLink($facts))->type)->toBe(ChangeVerdict::SUPERSEDE);
});

it('folds a new verdict into the pending update or cancellation', function () {
    $facts = detectorFacts();
    $link = detectorLink($facts, ['2026-10-21']);
    $item = detectorItem(detectorFacts(['startTime' => '20:00']), $facts);
    $twenty = detectorFacts(['startTime' => '20:00']);
    $sameDiff = detectorDetect($item, $twenty, null, $link)->diff;

    expect(detectorDetect($item, $twenty, detectorPending(DraftKind::Update, $sameDiff), $link)->type)->toBe(ChangeVerdict::NONE)
        ->and(detectorDetect($item, detectorFacts(['startTime' => '20:30']), detectorPending(DraftKind::Update, $sameDiff), $link)->type)->toBe(ChangeVerdict::REPLACE)
        ->and(detectorDetect($item, $twenty, detectorPending(DraftKind::Cancellation, ['cancelled_keys' => ['2026-10-28']]), $link)->type)->toBe(ChangeVerdict::NONE)
        ->and(detectorDetect($item, detectorFacts(['cancelled' => true]), detectorPending(DraftKind::Update, $sameDiff), $link)->type)->toBe(ChangeVerdict::CANCEL);
});

it('raises a missing cancellation only for a linked item without a pending cancellation', function () {
    $facts = detectorFacts();
    $detector = app(ChangeDetector::class);
    $item = detectorItem($facts, $facts);
    $missing = $detector->missing($item, null, detectorLink($facts, ['2026-10-21']));

    expect($missing->type)->toBe(ChangeVerdict::CANCEL)
        ->and($missing->diff)->toBe(['status' => ['published' => 'published', 'before' => 'active', 'now' => 'missing']])
        ->and($detector->missing($item, detectorPending(DraftKind::New), null)->type)->toBe(ChangeVerdict::SUPERSEDE)
        ->and($detector->missing($item, detectorPending(DraftKind::Cancellation, ['cancelled' => []]), detectorLink($facts))->type)->toBe(ChangeVerdict::NONE)
        ->and($detector->missing($item, null, null)->type)->toBe(ChangeVerdict::NONE);
});
