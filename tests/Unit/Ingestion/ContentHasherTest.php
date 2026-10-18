<?php

use App\Enums\IdentityStrategy;
use App\Enums\PriceType;
use App\Ingestion\Data\NormalisedFacts;
use App\Ingestion\Ics\IcsExtractor;
use App\Ingestion\Pipeline\ContentHasher;
use App\Models\Source;

/*
 * The two hashes (RESEARCH Pattern 4, D-06): raw_hash ignores fetch churn;
 * content_hash covers only the facts residents rely on, in a fixed key order,
 * and is the same whether computed from facts or from a stored payload.
 */

function hasherBaseFacts(): NormalisedFacts
{
    return new NormalisedFacts(
        title: 'Wednesday Folk Club',
        allDay: false,
        startDate: '2026-10-14',
        startTime: '19:00',
        endDate: null,
        endTime: '21:00',
        venueName: 'Town Hall',
        venueAddress: 'Queens Square, Attleborough, NR17 2AF',
        postcode: 'NR17 2AF',
        url: 'https://example.org/folk',
        categories: ['Music'],
        description: 'Bring an instrument.',
        organiser: ['name' => 'A Person', 'email' => 'redacted@example.invalid'],
        cancelled: false,
        rrule: 'FREQ=WEEKLY;BYDAY=WE;COUNT=6',
        excludedDates: ['2026-10-21'],
        cancelledKeys: [],
        movedOverrides: [],
        extraDates: [],
    );
}

/**
 * @param  array<string, mixed>  $changes  payload keys to override
 */
function hasherChanged(array $changes): NormalisedFacts
{
    return NormalisedFacts::fromPayload(array_merge(hasherBaseFacts()->toPayload(), $changes));
}

function hasherHash(NormalisedFacts $facts): string
{
    return app(ContentHasher::class)->contentHash($facts);
}

it('keeps the content hash when only quiet fields change', function (array $changes) {
    expect(hasherHash(hasherChanged($changes)))->toBe(hasherHash(hasherBaseFacts()));
})->with([
    'description' => [['description' => 'Bring an instrument and a friend.']],
    'url' => [['url' => 'https://example.org/folk-club']],
    'categories' => [['categories' => ['Music', 'Folk']]],
    'organiser' => [['organiser' => ['name' => 'Someone Else', 'email' => null]]],
    'next instance' => [['next_instance_start_utc' => '2026-10-28T19:00:00Z']],
    'postcode spacing' => [['postcode' => 'NR172AF']],
]);

it('changes the content hash when a fact residents rely on changes', function (array $changes) {
    expect(hasherHash(hasherChanged($changes)))->not->toBe(hasherHash(hasherBaseFacts()));
})->with([
    'title' => [['title' => 'Wednesday Folk Night']],
    'start date' => [['start_date' => '2026-10-15']],
    'start time' => [['start_time' => '19:30']],
    'end time' => [['end_time' => '22:00']],
    'end date' => [['end_date' => '2026-10-15']],
    'all day' => [['all_day' => true]],
    'venue name' => [['venue_name' => 'Church Building']],
    'venue address' => [['venue_address' => 'Station Road, Attleborough']],
    'price' => [['price_type' => PriceType::Free->value]],
    'family friendly' => [['family_friendly' => true]],
    'cancelled' => [['cancelled' => true]],
    'rrule' => [['rrule' => 'FREQ=WEEKLY;BYDAY=WE;COUNT=8']],
    'excluded dates' => [['excluded_dates' => ['2026-10-21', '2026-11-04']]],
    'cancelled keys' => [['cancelled_keys' => ['2026-11-04']]],
    'moved override' => [['moved_overrides' => [['key' => '2026-10-28', 'start' => '2026-10-28 19:30', 'end' => '2026-10-28 21:30']]]],
    'extra date' => [['extra_dates' => ['2026-11-05']]],
]);

it('ignores case and whitespace in title and venue', function () {
    expect(hasherHash(hasherChanged(['title' => '  wednesday   FOLK club ', 'venue_name' => 'TOWN  HALL'])))
        ->toBe(hasherHash(hasherBaseFacts()));
});

it('hashes in a fixed key order regardless of argument or list order', function () {
    $reordered = new NormalisedFacts(
        extraDates: [],
        movedOverrides: [],
        cancelledKeys: [],
        excludedDates: ['2026-10-21'],
        rrule: 'FREQ=WEEKLY;BYDAY=WE;COUNT=6',
        cancelled: false,
        venueAddress: 'Queens Square, Attleborough, NR17 2AF',
        venueName: 'Town Hall',
        endTime: '21:00',
        endDate: null,
        startTime: '19:00',
        startDate: '2026-10-14',
        allDay: false,
        title: 'Wednesday Folk Club',
    );

    expect(hasherHash($reordered))->toBe(hasherHash(hasherBaseFacts()));

    $a = hasherChanged(['excluded_dates' => ['2026-11-04', '2026-10-21'], 'cancelled_keys' => ['2026-11-11', '2026-10-28']]);
    $b = hasherChanged(['excluded_dates' => ['2026-10-21', '2026-11-04'], 'cancelled_keys' => ['2026-10-28', '2026-11-11']]);

    expect(hasherHash($a))->toBe(hasherHash($b))
        ->and($a->excludedDates)->toBe(['2026-10-21', '2026-11-04']);
});

it('changes the content hash when an override moves again or an RDATE appears', function () {
    $source = Source::factory()->withIdentity('uid')->make();
    $extract = fn (string $body) => collect(app(IcsExtractor::class)->extract($body, $source)->items)->keyBy('externalId');
    $body = icsFixture('series.ics');

    $original = $extract($body);
    $movedLater = $extract(str_replace('DTSTART;TZID=Europe/London:20261028T193000', 'DTSTART;TZID=Europe/London:20261028T194500', $body));
    $withoutRdate = $extract(str_replace("RDATE;TZID=Europe/London:20261105T100000\r\n", '', $body));

    expect($movedLater['s-moved@synthetic.invalid']->contentHash)->not->toBe($original['s-moved@synthetic.invalid']->contentHash)
        ->and($withoutRdate['s-rdate@synthetic.invalid']->contentHash)->not->toBe($original['s-rdate@synthetic.invalid']->contentHash)
        ->and($withoutRdate['s-rdate@synthetic.invalid']->parseErrors)->toBe([])
        // Untouched items keep their hashes.
        ->and($movedLater['s-exdate@synthetic.invalid']->contentHash)->toBe($original['s-exdate@synthetic.invalid']->contentHash);
});

it('gives the same content hash from facts and from the stored payload for every fixture item', function (string $fixture, string $identity) {
    $body = str_contains($fixture, '/') ? sourceFixture($fixture) : icsFixture($fixture);
    $source = Source::factory()->withIdentity($identity)->make();
    $hasher = app(ContentHasher::class);
    $checked = 0;

    foreach (app(IcsExtractor::class)->extract($body, $source)->items as $item) {
        if ($item->facts === null) {
            continue;
        }

        // Through JSON, as source_items.payload (jsonb) stores it.
        $stored = json_decode(json_encode($item->facts->toPayload(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        expect($hasher->contentHashFromPayload($stored))->toBe($hasher->contentHash($item->facts))
            ->and($item->contentHash)->toBe($hasher->contentHash($item->facts))
            ->and(NormalisedFacts::fromPayload($stored)->toPayload())->toBe($item->facts->toPayload());

        $checked++;
    }

    expect($checked)->toBeGreaterThan(0);
})->with([
    'baptist church' => ['attleborough-baptist-church/2026-10-17-ical.ics', 'uid'],
    'town council' => ['attleborough-town-council/2026-10-17-ical.ics', 'tec_post_id'],
    'academy' => ['attleborough-academy/2026-10-17-ical.ics', 'summary_start'],
    'series' => ['series.ics', 'uid'],
    'edge cases' => ['edge-cases.ics', 'uid'],
]);

it('strips volatile lines from the raw hash, and the UID unless it is the identity', function () {
    $hasher = app(ContentHasher::class);
    $fragment = "BEGIN:VEVENT\r\nUID:1-2-3@x\r\nDTSTAMP:20261017T050000Z\r\nCREATED:20261001T000000Z\r\nLAST-MODIFIED:20261002T000000Z\r\nSEQUENCE:1\r\nSUMMARY:Quiz\r\nEND:VEVENT\r\n";
    $churned = "BEGIN:VEVENT\r\nUID:1-9-9@x\r\nDTSTAMP:20261018T050000Z\r\nCREATED:20261003T000000Z\r\nLAST-MODIFIED:20261004T000000Z\r\nSEQUENCE:2\r\nSUMMARY:Quiz\r\nEND:VEVENT\r\n";
    $folded = "BEGIN:VEVENT\r\nUID:1-2-3@x\r\nSUMMARY:Qu\r\n iz\r\nEND:VEVENT\r\n";

    expect($hasher->rawHash($churned, IdentityStrategy::TecPostId))->toBe($hasher->rawHash($fragment, IdentityStrategy::TecPostId))
        ->and($hasher->rawHash($churned, IdentityStrategy::Uid))->not->toBe($hasher->rawHash($fragment, IdentityStrategy::Uid))
        ->and($hasher->rawHash($folded, IdentityStrategy::Uid))->toBe($hasher->rawHash($fragment, IdentityStrategy::Uid))
        ->and($hasher->rawHash(str_replace('Quiz', 'Bingo', $fragment), IdentityStrategy::Uid))->not->toBe($hasher->rawHash($fragment, IdentityStrategy::Uid));
});
