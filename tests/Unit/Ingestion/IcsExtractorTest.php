<?php

use App\Enums\IdentityStrategy;
use App\Ingestion\Data\ExtractedItem;
use App\Ingestion\Data\ExtractionResult;
use App\Ingestion\Ics\IcsExtractor;
use App\Ingestion\Pipeline\ContentHasher;
use App\Ingestion\Pipeline\WindowClassifier;
use App\Models\Source;
use Carbon\CarbonImmutable;

/*
 * ICS → normalised, hashed, window-classified items (EXT-01, EXT-07,
 * PIPE-01 classification). Pure: Source::factory()->make(), no database,
 * no network. Frozen at Sat 17 Oct 2026 06:00 London unless a test says
 * otherwise; the 90-day window then ends at Fri 15 Jan 2027 06:00 London.
 */

const INGEST_BAPTIST_ICS = 'attleborough-baptist-church/2026-10-17-ical.ics';
const INGEST_TOWN_COUNCIL_ICS = 'attleborough-town-council/2026-10-17-ical.ics';
const INGEST_ACADEMY_ICS = 'attleborough-academy/2026-10-17-ical.ics';

function ingestExtract(string $body, string $identity = 'uid', array $attributes = []): ExtractionResult
{
    $source = Source::factory()->withIdentity($identity)->make($attributes);

    return app(IcsExtractor::class)->extract($body, $source);
}

/**
 * @return array<string, ExtractedItem>
 */
function ingestItemsById(ExtractionResult $result): array
{
    $items = [];

    foreach ($result->items as $item) {
        $items[$item->externalId] = $item;
    }

    return $items;
}

function ingestClassify(ExtractedItem $item): string
{
    expect($item->facts)->not->toBeNull();

    return app(WindowClassifier::class)->classify($item->facts, CarbonImmutable::now('Europe/London'));
}

beforeEach(function () {
    freezeLondon('2026-10-17 06:00');
});

it('turns the Baptist Church feed into 64 hashed items with London wall-clock times', function () {
    $result = ingestExtract(sourceFixture(INGEST_BAPTIST_ICS));

    expect($result->failed())->toBeFalse()
        ->and($result->eventCount)->toBe(64)
        ->and($result->items)->toHaveCount(64);

    $items = ingestItemsById($result);
    $service = $items['E5936476@UK.endis.com'];

    // DTSTART:20261025T103000Z is 10:30 GMT on the day BST ends.
    expect($service->facts->startDate)->toBe('2026-10-25')
        ->and($service->facts->startTime)->toBe('10:30')
        ->and($service->facts->endDate)->toBeNull()
        ->and($service->facts->endTime)->toBe('12:00')
        ->and($service->facts->nextInstanceStartUtc->format('Y-m-d H:i:s'))->toBe('2026-10-25 10:30:00')
        ->and($service->facts->nextInstanceStartUtc->getTimezone()->getName())->toBeIn(['UTC', '+00:00'])
        ->and($service->parseErrors)->toBe([]);

    // Sun 18 Oct 09:30Z is 10:30 BST.
    expect($items['E5936475@UK.endis.com']->facts->startTime)->toBe('10:30');

    foreach ($result->items as $item) {
        expect($item->rawHash)->toMatch('/^[0-9a-f]{64}$/')
            ->and($item->contentHash)->toMatch('/^[0-9a-f]{64}$/')
            ->and($item->facts)->not->toBeNull();
    }
});

it('normalises the first item facts and keeps the description in full', function () {
    $items = ingestItemsById(ingestExtract(sourceFixture(INGEST_BAPTIST_ICS)));
    $bibleSchool = $items['E5834457@UK.endis.com']->facts;

    expect($bibleSchool->title)->toBe('Evening Bible School')
        ->and($bibleSchool->venueName)->toBe('Church Building')
        ->and($bibleSchool->venueAddress)->toBeNull()
        ->and($bibleSchool->description)->toBe('Working through the Bible book by book over two years, using animated video followed by teaching and discussion. Hot drinks and a bun served.')
        ->and($bibleSchool->url)->toBe('https://www.attleborough.church/Calendar/Month.aspx?show_event=5834457')
        ->and($bibleSchool->priceType->value)->toBe('unknown')
        ->and($bibleSchool->familyFriendly)->toBeFalse()
        ->and($bibleSchool->cancelled)->toBeFalse()
        ->and($bibleSchool->rrule)->toBeNull()
        ->and($bibleSchool->organiser)->toBeNull();

    // Empty DESCRIPTION becomes null; "&" survives entity decoding.
    expect($items['E5936475@UK.endis.com']->facts->description)->toBeNull()
        ->and($items['E5936230@UK.endis.com']->facts->title)->toBe('Bible Study & Prayer');
});

it('decodes entities, strips markup and collapses whitespace in titles', function () {
    $body = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:t1\r\nDTSTART:20261120T190000Z\r\n"
        ."SUMMARY:<b>Quiz</b>  &amp;\\n  Chips&nbsp;Night\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    expect(ingestExtract($body)->items[0]->facts->title)->toBe('Quiz & Chips Night');
});

it('classifies items against the 90-day window', function () {
    $result = ingestExtract(sourceFixture(INGEST_BAPTIST_ICS));
    $windowEnd = CarbonImmutable::parse('2027-01-15 06:00', 'Europe/London');

    $classes = [];

    foreach ($result->items as $item) {
        $class = ingestClassify($item);
        $classes[$class] = ($classes[$class] ?? 0) + 1;

        expect($class)->toBe($item->facts->nextInstanceStartUtc->greaterThanOrEqualTo($windowEnd) ? 'far_future' : 'in_window');
    }

    expect($classes['in_window'])->toBeGreaterThan(0)
        ->and($classes['far_future'])->toBeGreaterThan(0)
        ->and($classes)->not->toHaveKey('past');
});

it('gives identical hashes for the same body and ignores DTSTAMP in the raw hash', function () {
    $body = sourceFixture(INGEST_BAPTIST_ICS);
    $first = ingestExtract($body);
    $second = ingestExtract($body);

    expect(array_map(fn (ExtractedItem $i) => [$i->externalId, $i->rawHash, $i->contentHash], $second->items))
        ->toBe(array_map(fn (ExtractedItem $i) => [$i->externalId, $i->rawHash, $i->contentHash], $first->items));

    $stamped = str_replace("UID:E5936476@UK.endis.com\r\n", "UID:E5936476@UK.endis.com\r\nDTSTAMP:20261017T060000Z\r\n", $body);
    $restamped = str_replace('DTSTAMP:20261017T060000Z', 'DTSTAMP:20261018T050000Z', $stamped);

    $a = ingestItemsById(ingestExtract($stamped))['E5936476@UK.endis.com'];
    $b = ingestItemsById(ingestExtract($restamped))['E5936476@UK.endis.com'];

    expect($a->rawFragment)->not->toBe($b->rawFragment)
        ->and($a->rawHash)->toBe($b->rawHash)
        ->and($a->rawHash)->toBe(ingestItemsById($first)['E5936476@UK.endis.com']->rawHash)
        ->and(app(ContentHasher::class)->rawHash($a->rawFragment, IdentityStrategy::Uid))->toBe($a->rawHash);
});

/*
 * Real feeds with their identity strategies (F4, Pitfall 4).
 */

it('keys the Town Council feed by TEC post id with London times across BST end', function () {
    $result = ingestExtract(sourceFixture(INGEST_TOWN_COUNCIL_ICS), 'tec_post_id');
    $items = ingestItemsById($result);

    expect($result->items)->toHaveCount(30)
        ->and(array_map(fn (ExtractedItem $i) => $i->externalId, $result->items))->each->toMatch('/^\d+$/');

    // DTSTART;TZID=Europe/London:20261024T120000 is 12:00 BST = 11:00 UTC.
    $football = $items['10006774']->facts;

    expect($football->startDate)->toBe('2026-10-24')
        ->and($football->startTime)->toBe('12:00')
        ->and($football->endTime)->toBe('20:00')
        ->and($football->nextInstanceStartUtc->format('Y-m-d H:i'))->toBe('2026-10-24 11:00')
        ->and($football->venueName)->toBe('Recreation Ground')
        ->and($football->venueAddress)->toBe('Station Road, Attleborough, NR17 2AS')
        ->and($football->postcode)->toBe('NR17 2AS')
        ->and($football->categories)->toBe(['Attleborough Town Football & Social Club']);

    // Feed organisers stay in the internal facts only (D-17).
    expect($items['10003892']->facts->organiser)->toBe(['name' => '[name removed]', 'email' => 'redacted@example.invalid']);
});

it('keeps the TEC identity when the source moves a session by an hour', function () {
    $body = sourceFixture(INGEST_TOWN_COUNCIL_ICS);
    $moved = str_replace(
        "DTSTART;TZID=Europe/London:20261018T100000\r\nDTEND;TZID=Europe/London:20261018T110000",
        "DTSTART;TZID=Europe/London:20261018T110000\r\nDTEND;TZID=Europe/London:20261018T120000",
        $body,
    );
    $moved = str_replace('10003892-1792317600-1792321200@', '10003892-1792321200-1792324800@', $moved);

    expect($moved)->not->toBe($body);

    $before = ingestItemsById(ingestExtract($body, 'tec_post_id'));
    $after = ingestItemsById(ingestExtract($moved, 'tec_post_id'));

    expect(array_keys($after))->toEqualCanonicalizing(array_keys($before))
        ->and($after['10003892']->facts->startTime)->toBe('11:00')
        ->and($after['10003892']->contentHash)->not->toBe($before['10003892']->contentHash)
        ->and($after['10003892']->rawHash)->not->toBe($before['10003892']->rawHash);
});

it('keys the Academy feed by title and start so churning UIDs and DTSTAMPs do not matter', function () {
    $body = sourceFixture(INGEST_ACADEMY_ICS);
    $churned = (string) preg_replace('/^UID:(\d+)1792262071\r$/m', "UID:\${1}1792348471\r", $body);
    $churned = str_replace('DTSTAMP:20261017T183431Z', 'DTSTAMP:20261018T183431Z', $churned);

    expect($churned)->not->toBe($body);

    $first = ingestExtract($body, 'summary_start');
    $second = ingestExtract($churned, 'summary_start');
    $signature = fn (ExtractionResult $r) => array_map(fn (ExtractedItem $i) => [$i->externalId, $i->rawHash, $i->contentHash], $r->items);

    expect($first->items)->toHaveCount(59)
        ->and($signature($second))->toBe($signature($first));

    $halfTerm = ingestItemsById($first)['20261019|autumn half-term holiday']->facts;

    // DTEND;VALUE=DATE:20261031 is exclusive: the holiday's last day is Fri 30 Oct.
    expect($halfTerm->allDay)->toBeTrue()
        ->and($halfTerm->startDate)->toBe('2026-10-19')
        ->and($halfTerm->endDate)->toBe('2026-10-30')
        ->and($halfTerm->startTime)->toBeNull()
        ->and($halfTerm->endTime)->toBeNull();

    // Timed across two days keeps its end date.
    $parents = ingestItemsById($first)["20261209T160000|year 11 parents' evening (online)"]->facts;

    expect($parents->endDate)->toBe('2026-12-10')->and($parents->endTime)->toBe('18:00');
});

/*
 * D-19 recurrence shapes (synthetic series.ics, F1).
 */

it('keeps an RRULE with an EXDATE as one publishable series', function () {
    $item = ingestItemsById(ingestExtract(icsFixture('series.ics')))['s-exdate@synthetic.invalid'];

    expect($item->parseErrors)->toBe([])
        ->and($item->facts->rrule)->toBe('FREQ=WEEKLY;BYDAY=WE;COUNT=6')
        ->and($item->facts->excludedDates)->toBe(['2026-10-21'])
        ->and($item->facts->startDate)->toBe('2026-10-14')
        ->and($item->facts->startTime)->toBe('19:00')
        ->and($item->facts->postcode)->toBe('NR17 2AF')
        // 21 Oct is excluded, so the next instance is Wed 28 Oct: 19:00 GMT = 19:00 UTC.
        ->and($item->facts->nextInstanceStartUtc->format('Y-m-d H:i'))->toBe('2026-10-28 19:00');
});

it('turns a cancelled override into a cancelled key without a parse error', function () {
    $result = ingestExtract(icsFixture('series.ics'));
    $item = ingestItemsById($result)['s-cancelled@synthetic.invalid'];

    expect($item->parseErrors)->toBe([])
        ->and($item->facts->cancelledKeys)->toBe(['2026-11-04'])
        ->and($item->facts->cancelled)->toBeFalse()
        ->and($item->facts->rrule)->toBe('FREQ=WEEKLY;BYDAY=WE;COUNT=6')
        ->and(substr_count($item->rawFragment, 'BEGIN:VEVENT'))->toBe(2);
});

it('makes a moved override a parse error naming the date and keeps the facts', function () {
    $item = ingestItemsById(ingestExtract(icsFixture('series.ics')))['s-moved@synthetic.invalid'];

    expect($item->facts)->not->toBeNull()
        ->and($item->parseErrors)->toHaveCount(1)
        ->and($item->parseErrors[0])->toContain('Wed 28 Oct')->toContain('7:30pm')
        ->and($item->facts->movedOverrides)->toBe([['key' => '2026-10-28', 'start' => '2026-10-28 19:30', 'end' => '2026-10-28 21:30']])
        ->and($item->facts->rrule)->toBe('FREQ=WEEKLY;BYDAY=WE;COUNT=6');
});

it('makes an RDATE a parse error and records the extra date', function () {
    $item = ingestItemsById(ingestExtract(icsFixture('series.ics')))['s-rdate@synthetic.invalid'];

    expect($item->facts)->not->toBeNull()
        ->and($item->facts->extraDates)->toBe(['2026-11-05'])
        ->and($item->parseErrors)->toHaveCount(1)
        ->and($item->parseErrors[0])->toContain('Thu 5 Nov');
});

it('makes a rule the form cannot represent a parse error', function () {
    $item = ingestItemsById(ingestExtract(icsFixture('series.ics')))['s-daily@synthetic.invalid'];

    expect($item->facts)->not->toBeNull()
        ->and($item->facts->rrule)->toBe('FREQ=DAILY;INTERVAL=2;COUNT=10')
        ->and($item->parseErrors)->toHaveCount(1)
        ->and($item->parseErrors[0])->toStartWith("This repeat rule can't be published from the form");
});

it('rebuilds publishable feed rules in the exact shape the form stores', function (string $uid, string $rule) {
    $item = ingestItemsById(ingestExtract(icsFixture('series.ics')))[$uid];

    expect($item->parseErrors)->toBe([])
        ->and($item->facts->rrule)->toBe($rule);
})->with([
    'reordered parts, WKST and a timed UNTIL' => ['s-until@synthetic.invalid', 'FREQ=WEEKLY;BYDAY=TH;UNTIL=20261203T235959Z'],
    'WEEKLY without BYDAY repeats on the start day' => ['s-implicit-day@synthetic.invalid', 'FREQ=WEEKLY;BYDAY=SA;COUNT=4'],
]);

/*
 * Time zones and DST (EXT-07, synthetic edge-cases.ics).
 */

it('reads floating times in the source zone, including the repeated hour', function () {
    $items = ingestItemsById(ingestExtract(icsFixture('edge-cases.ics')));

    expect($items['e-floating@synthetic.invalid']->facts->nextInstanceStartUtc->format('Y-m-d H:i'))->toBe('2026-10-25 10:30')
        ->and($items['e-floating@synthetic.invalid']->facts->startTime)->toBe('10:30');

    $repeated = $items['e-repeated-hour@synthetic.invalid']->facts;

    expect($repeated->nextInstanceStartUtc->equalTo(CarbonImmutable::parse('2026-10-25 01:30', 'Europe/London')->utc()))->toBeTrue()
        ->and($repeated->startDate)->toBe('2026-10-25')
        ->and($repeated->startTime)->toBe('01:30')
        ->and($repeated->endTime)->toBeNull();
});

it('uses the source default zone for floating times', function () {
    $item = ingestItemsById(ingestExtract(icsFixture('edge-cases.ics'), 'uid', ['default_timezone' => 'America/New_York']))['e-floating@synthetic.invalid'];

    // 10:30 in New York (EDT until 1 Nov) is 14:30 UTC, 14:30 London (GMT).
    expect($item->facts->nextInstanceStartUtc->format('Y-m-d H:i'))->toBe('2026-10-25 14:30')
        ->and($item->facts->startTime)->toBe('14:30');
});

it('refuses an unknown TZID instead of guessing UTC', function () {
    $item = ingestItemsById(ingestExtract(icsFixture('edge-cases.ics')))['e-unknown-tz@synthetic.invalid'];

    expect($item->facts)->toBeNull()
        ->and($item->contentHash)->toBeNull()
        ->and($item->parseErrors)->toBe(['Unknown time zone "Made Up/Zone"'])
        ->and($item->rawHash)->toMatch('/^[0-9a-f]{64}$/');
});

it('maps GMT Standard Time to London and flags Greenwich Standard Time', function () {
    $items = ingestItemsById(ingestExtract(icsFixture('edge-cases.ics')));
    $outlook = $items['e-gmt-standard@synthetic.invalid'];

    // 19:00 BST on Sat 24 Oct = 18:00 UTC.
    expect($outlook->parseErrors)->toBe([])
        ->and($outlook->facts->startTime)->toBe('19:00')
        ->and($outlook->facts->nextInstanceStartUtc->format('Y-m-d H:i'))->toBe('2026-10-24 18:00');

    $greenwich = $items['e-greenwich@synthetic.invalid'];

    expect($greenwich->facts)->not->toBeNull()
        ->and($greenwich->parseErrors)->toHaveCount(1)
        ->and($greenwich->parseErrors[0])->toContain('Greenwich Standard Time')->toContain('Check the times');
});

it('resolves DTSTART and DTEND in their own zones and refuses an end before the start', function () {
    $items = ingestItemsById(ingestExtract(icsFixture('edge-cases.ics')));

    // 16:00 New York (EST) = 21:00 UTC = 21:00 London.
    expect($items['e-mixed-tz-ok@synthetic.invalid']->facts->endTime)->toBe('21:00')
        ->and($items['e-mixed-tz-ok@synthetic.invalid']->parseErrors)->toBe([]);

    // 13:00 New York = 18:00 UTC, before the 19:00 London start.
    expect($items['e-mixed-tz-bad@synthetic.invalid']->facts)->toBeNull()
        ->and($items['e-mixed-tz-bad@synthetic.invalid']->parseErrors[0])->toContain('end time is before the start time');
});

it('handles all-day spans, missing ends and cancellations', function () {
    $items = ingestItemsById(ingestExtract(icsFixture('edge-cases.ics')));

    expect($items['e-all-day@synthetic.invalid']->facts)
        ->allDay->toBeTrue()
        ->startDate->toBe('2026-11-14')
        ->endDate->toBe('2026-11-15');

    expect($items['e-no-end@synthetic.invalid']->facts)
        ->startTime->toBe('14:00')
        ->endDate->toBeNull()
        ->endTime->toBeNull();

    expect($items['e-cancelled@synthetic.invalid']->facts->cancelled)->toBeTrue();
});

/*
 * Window edges (PIPE-01 classification, EXT-01 adjacency).
 */

it('classifies past items and the exact window end', function () {
    $items = ingestItemsById(ingestExtract(icsFixture('edge-cases.ics')));

    expect(ingestClassify($items['e-past@synthetic.invalid']))->toBe('past')
        ->and(ingestClassify($items['e-window-end@synthetic.invalid']))->toBe('far_future')
        ->and(ingestClassify($items['e-window-inside@synthetic.invalid']))->toBe('in_window')
        ->and(ingestClassify($items['e-all-day@synthetic.invalid']))->toBe('in_window');
});

it('stops showing an all-day event at the start of its exclusive DTEND date', function () {
    $item = ingestItemsById(ingestExtract(icsFixture('edge-cases.ics')))['e-all-day@synthetic.invalid'];
    $classifier = app(WindowClassifier::class);

    expect($classifier->classify($item->facts, CarbonImmutable::parse('2026-11-15 23:59', 'Europe/London')))->toBe('in_window')
        ->and($classifier->classify($item->facts, CarbonImmutable::parse('2026-11-16 00:00', 'Europe/London')))->toBe('past');
});

it('classifies series by their remaining instances', function () {
    $items = ingestItemsById(ingestExtract(icsFixture('series.ics')));
    $classifier = app(WindowClassifier::class);
    $folk = $items['s-exdate@synthetic.invalid']->facts;

    // The last of six Wednesdays is 18 Nov (19:00-21:00 GMT).
    expect($classifier->classify($folk, CarbonImmutable::parse('2026-11-18 20:59', 'Europe/London')))->toBe('in_window')
        ->and($classifier->classify($folk, CarbonImmutable::parse('2026-11-18 21:00', 'Europe/London')))->toBe('past')
        // Before the series starts and more than 90 days ahead of it.
        ->and($classifier->classify($folk, CarbonImmutable::parse('2026-07-15 19:00', 'Europe/London')))->toBe('far_future');
});

it('expires a timed instance without an end three hours after its start', function () {
    $item = ingestItemsById(ingestExtract(icsFixture('edge-cases.ics')))['e-no-end@synthetic.invalid'];
    $classifier = app(WindowClassifier::class);

    expect($classifier->classify($item->facts, CarbonImmutable::parse('2026-11-21 16:59', 'Europe/London')))->toBe('in_window')
        ->and($classifier->classify($item->facts, CarbonImmutable::parse('2026-11-21 17:00', 'Europe/London')))->toBe('past');
});

/*
 * Fatal errors, empty feeds and ordering (EXT-01 empty / ordering).
 */

it('returns no items and no error for an empty calendar', function () {
    $result = ingestExtract("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//x//EN\r\nEND:VCALENDAR\r\n");

    expect($result->failed())->toBeFalse()
        ->and($result->items)->toBe([])
        ->and($result->eventCount)->toBe(0);
});

it('fails the extraction for a body sabre cannot read', function (string $body) {
    $result = ingestExtract($body);

    expect($result->failed())->toBeTrue()
        ->and($result->items)->toBe([])
        ->and($result->fatalError)->toContain('calendar');
})->with([
    'html' => ['<html>'],
    'empty' => [''],
    'truncated calendar' => ["BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:x\r\n"],
    'vcard' => ["BEGIN:VCARD\r\nVERSION:4.0\r\nFN:Someone\r\nEND:VCARD\r\n"],
]);

it('fails the extraction when the feed has more events than ingest.max_events', function () {
    config(['ingest.max_events' => 5000]);
    $event = "BEGIN:VEVENT\r\nUID:%d@synthetic.invalid\r\nDTSTART:20261120T190000Z\r\nSUMMARY:Item %d\r\nEND:VEVENT\r\n";
    $events = implode('', array_map(fn (int $n) => sprintf($event, $n, $n), range(1, 5001)));

    $result = ingestExtract("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n{$events}END:VCALENDAR\r\n");

    expect($result->failed())->toBeTrue()
        ->and($result->eventCount)->toBe(5001)
        ->and($result->fatalError)->toContain('5001');
});

it('returns items sorted by next start then external id whatever the VEVENT order', function (string $fixture, string $identity) {
    $body = str_contains($fixture, '/') ? sourceFixture($fixture) : icsFixture($fixture);

    preg_match_all('/BEGIN:VEVENT\r\n.*?END:VEVENT\r\n/s', $body, $m);
    $blocks = $m[0];
    $shuffled = array_reverse($blocks);
    [$shuffled[0], $shuffled[count($shuffled) - 1]] = [$shuffled[count($shuffled) - 1], $shuffled[0]];
    $reordered = str_replace(implode('', $blocks), implode('', $shuffled), $body);

    expect($reordered)->not->toBe($body);

    $signature = fn (ExtractionResult $r) => array_map(fn (ExtractedItem $i) => [$i->externalId, $i->rawHash, $i->contentHash, $i->parseErrors], $r->items);
    $original = ingestExtract($body, $identity);

    expect($signature(ingestExtract($reordered, $identity)))->toBe($signature($original));

    $keys = array_map(fn (ExtractedItem $i) => [$i->facts?->nextInstanceStartUtc?->getTimestamp() ?? PHP_INT_MAX, $i->externalId], $original->items);
    $sorted = $keys;
    sort($sorted);

    expect($keys)->toBe($sorted);
})->with([
    'town council' => [INGEST_TOWN_COUNCIL_ICS, 'tec_post_id'],
    'academy' => [INGEST_ACADEMY_ICS, 'summary_start'],
    'series with overrides' => ['series.ics', 'uid'],
    'edge cases' => ['edge-cases.ics', 'uid'],
]);
