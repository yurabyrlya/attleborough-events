<?php

use App\Enums\OccurrenceStatus;
use App\Models\EventDraft;
use App\Models\EventOccurrence;
use App\Models\Source;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/*
 * Deferred item 1 from 03-08, resolved in 03-09: a series' cancelled date
 * beyond calendar.horizon_days has no occurrence at approval. It is kept on
 * the event (events.cancelled_dates), so when the nightly sync reaches that
 * date the row is INSERTED as Cancelled (sync still never changes the
 * status of an existing row).
 */

function ingestFarCancelledSeries(): string
{
    return implode("\r\n", [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//What\'s On Attleborough//Horizon test//EN',
        'BEGIN:VEVENT', 'UID:far@synthetic.invalid', 'DTSTAMP:20261017T050000Z', 'SUMMARY:Wednesday Whist Drive',
        'DTSTART;TZID=Europe/London:20261021T190000', 'DTEND;TZID=Europe/London:20261021T210000',
        'RRULE:FREQ=WEEKLY;BYDAY=WE;COUNT=20', 'LOCATION:Town Hall', 'END:VEVENT',
        // 20 Jan 2027: 95 days after 17 Oct, beyond the 90-day horizon.
        'BEGIN:VEVENT', 'UID:far@synthetic.invalid', 'DTSTAMP:20261017T050000Z', 'SUMMARY:Wednesday Whist Drive',
        'RECURRENCE-ID;TZID=Europe/London:20270120T190000', 'DTSTART;TZID=Europe/London:20270120T190000',
        'STATUS:CANCELLED', 'END:VEVENT',
        // 4 Nov 2026: inside the horizon.
        'BEGIN:VEVENT', 'UID:far@synthetic.invalid', 'DTSTAMP:20261017T050000Z', 'SUMMARY:Wednesday Whist Drive',
        'RECURRENCE-ID;TZID=Europe/London:20261104T190000', 'DTSTART;TZID=Europe/London:20261104T190000',
        'STATUS:CANCELLED', 'END:VEVENT',
        'END:VCALENDAR',
    ])."\r\n";
}

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    Storage::fake('local');
    freezeLondon('2026-10-17 06:00:00');
    $this->seed(CategorySeeder::class);
});

it('keeps a cancelled date beyond the horizon and inserts it as Cancelled when the sync reaches it', function () {
    $source = Source::factory()->ics()->active()->create(['url' => 'https://feed.example.org/whist.ics']);
    ingestFeed(ingestFarCancelledSeries());
    ingestLiveRun($source);

    $event = ingestApprove(EventDraft::query()->where('source_id', $source->id)->sole());

    expect($event->refresh()->cancelled_dates)->toBe(['2026-11-04', '2027-01-20'])
        ->and(EventOccurrence::query()->where('event_id', $event->id)->where('recurrence_key', '2026-11-04')->value('status'))->toBe(OccurrenceStatus::Cancelled)
        ->and(EventOccurrence::query()->where('event_id', $event->id)->where('recurrence_key', '2027-01-20')->exists())->toBeFalse();

    freezeLondon('2026-11-01 04:00:00');
    $this->artisan('calendar:sync-occurrences')->assertSuccessful();

    $statuses = EventOccurrence::query()->where('event_id', $event->id)
        ->whereIn('recurrence_key', ['2027-01-13', '2027-01-20', '2027-01-27'])
        ->pluck('status', 'recurrence_key');

    expect($statuses['2027-01-20'])->toBe(OccurrenceStatus::Cancelled)
        ->and($statuses['2027-01-13'])->toBe(OccurrenceStatus::Scheduled)
        ->and($statuses['2027-01-27'])->toBe(OccurrenceStatus::Scheduled);
});

it('never changes the status of an existing occurrence for a listed date', function () {
    $source = Source::factory()->ics()->active()->create(['url' => 'https://feed.example.org/whist.ics']);
    ingestFeed(ingestFarCancelledSeries());
    ingestLiveRun($source);
    $event = ingestApprove(EventDraft::query()->where('source_id', $source->id)->sole());

    // The admin restores 4 Nov after approval; the next sync keeps that.
    EventOccurrence::query()->where('event_id', $event->id)->where('recurrence_key', '2026-11-04')
        ->update(['status' => OccurrenceStatus::Scheduled->value]);

    $this->artisan('calendar:sync-occurrences')->assertSuccessful();

    expect(EventOccurrence::query()->where('event_id', $event->id)->where('recurrence_key', '2026-11-04')->value('status'))->toBe(OccurrenceStatus::Scheduled);
});
