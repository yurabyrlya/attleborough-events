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
