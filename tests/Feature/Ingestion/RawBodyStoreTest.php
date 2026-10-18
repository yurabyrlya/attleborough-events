<?php

use App\Ingestion\RawBodyStore;
use Illuminate\Support\Facades\Storage;

/*
 * Raw fetched bodies, gzip on the private local disk (A10), paths from
 * integer ids only (T-03-09).
 */

beforeEach(function () {
    Storage::fake('local');
});

it('writes the body gzip-compressed under ingest/raw/{source}/{run}.gz and reads it back', function () {
    $store = new RawBodyStore;
    $body = sourceFixture('attleborough-baptist-church/2026-10-17-ical.ics');

    $path = $store->put(7, 42, $body);

    expect($path)->toBe('ingest/raw/7/42.gz')
        ->and($store->pathFor(7, 42))->toBe('ingest/raw/7/42.gz');

    Storage::disk('local')->assertExists('ingest/raw/7/42.gz');

    $stored = Storage::disk('local')->get('ingest/raw/7/42.gz');

    expect(substr((string) $stored, 0, 2))->toBe("\x1f\x8b")
        ->and(strlen((string) $stored))->toBeLessThan(strlen($body))
        ->and(gzdecode((string) $stored))->toBe($body)
        ->and($store->get(7, 42))->toBe($body);
});

it('keeps binary bodies byte for byte', function () {
    $store = new RawBodyStore;
    $body = "\xEF\xBB\xBFBEGIN:VCALENDAR\r\n\x00\xFF caf\xC3\xA9\r\nEND:VCALENDAR\r\n";

    $store->put(1, 1, $body);

    expect($store->get(1, 1))->toBe($body);
});

it('returns null for a body that was never stored', function () {
    expect((new RawBodyStore)->get(7, 99))->toBeNull();
});

it('refuses ids that are not positive', function (int $sourceId, int $runId) {
    expect(fn () => (new RawBodyStore)->put($sourceId, $runId, 'x'))->toThrow(RuntimeException::class);
})->with([[0, 1], [1, 0], [-1, 5]]);

it('lives on the private local disk', function () {
    expect(config('filesystems.disks.local.root'))->toBe(storage_path('app/private'));
});

it('prunes only bodies older than the retention period', function () {
    freezeLondon('2026-10-18 12:00:00');
    $store = new RawBodyStore;

    $store->put(7, 1, 'old');
    $store->put(7, 2, 'edge');
    $store->put(8, 3, 'new');
    Storage::disk('local')->put('ingest/raw/notes.txt', 'not a body');

    touch(Storage::disk('local')->path('ingest/raw/7/1.gz'), now()->subDays(31)->getTimestamp());
    touch(Storage::disk('local')->path('ingest/raw/7/2.gz'), now()->subDays(29)->getTimestamp());
    touch(Storage::disk('local')->path('ingest/raw/notes.txt'), now()->subDays(90)->getTimestamp());

    expect($store->prune(30))->toBe(1);

    Storage::disk('local')->assertMissing('ingest/raw/7/1.gz');
    Storage::disk('local')->assertExists('ingest/raw/7/2.gz');
    Storage::disk('local')->assertExists('ingest/raw/8/3.gz');
    Storage::disk('local')->assertExists('ingest/raw/notes.txt');
});
