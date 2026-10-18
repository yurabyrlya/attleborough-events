<?php

use App\Calendar\RecurrenceExpander;
use App\Enums\OccurrenceStatus;
use App\Enums\RunMode;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Jobs\FetchSourceJob;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\EventOccurrence;
use App\Models\Source;
use App\Models\SourceRun;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\ApproveDraft;
use App\Publishing\Actions\UpdatePublishedEvent;
use App\Publishing\Data\PublishableEventData;
use App\Review\DraftPublishability;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/*
 * D-19 and D-08 at approval: an ICS series publishes its EXDATEs as
 * events.excluded_dates (honoured by RecurrenceExpander as EXDATE) and its
 * cancelled overrides as Cancelled occurrences. An admin edit and the
 * nightly sync keep the exclusions (conflict C2).
 */

const EXCLUDED_FEED_URL = 'https://feed.example.org/series.ics';

/**
 * Ingests the synthetic series fixture through a queued live run (offline).
 */
function excludedIngestSeries(): void
{
    Http::fake(function (Request $request) {
        if (str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?? '', '/robots.txt')) {
            return Http::response("User-agent: *\nDisallow:\n", 200, ['Content-Type' => 'text/plain']);
        }

        return Http::response(icsFixture('series.ics'), 200, ['Content-Type' => 'text/calendar; charset=utf-8']);
    });

    $source = Source::factory()->ics()->active()->create(['name' => 'Synthetic Series', 'url' => EXCLUDED_FEED_URL]);
    $run = new SourceRun(['mode' => RunMode::Live, 'trigger' => RunTrigger::Manual]);
    $run->forceFill(['source_id' => $source->id, 'status' => RunStatus::Queued])->save();

    FetchSourceJob::dispatchSync($run->id);

    expect($run->refresh()->status)->toBe(RunStatus::Succeeded);
}

/**
 * The series draft with this title, given a venue (the admin's pick) and
 * approved.
 */
function excludedApprove(string $title, User $admin): Event
{
    $draft = EventDraft::query()->where('title', $title)->firstOrFail();
    $draft->forceFill(['venue_id' => Venue::factory()->create()->id])->save();

    expect(app(DraftPublishability::class)->blockers($draft->refresh()))->toBe([]);

    return app(ApproveDraft::class)($draft, $admin);
}

/**
 * @return array<string, string> recurrence_key => status
 */
function excludedStatuses(Event $event): array
{
    return EventOccurrence::query()
        ->where('event_id', $event->id)
        ->orderBy('recurrence_key')
        ->get()
        ->mapWithKeys(fn (EventOccurrence $o): array => [$o->recurrence_key => $o->status->value])
        ->all();
}

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    Storage::fake('local');
    freezeLondon('2026-10-17 06:00:00');
    $this->seed(CategorySeeder::class);
    $this->admin = User::factory()->create(['email' => 'admin@example.test']);

    excludedIngestSeries();
});

it('publishes the EXDATE as excluded_dates so 21 Oct gets no occurrence', function () {
    $event = excludedApprove('Wednesday Folk Club', $this->admin);

    expect($event->refresh()->excluded_dates)->toBe(['2026-10-21'])
        ->and(array_keys(excludedStatuses($event)))->toBe(['2026-10-28', '2026-11-04', '2026-11-11', '2026-11-18']);

    // 19:00 on 28 Oct is after BST ends (25 Oct): 19:00 UTC.
    $row = DB::selectOne("select starts_at at time zone 'UTC' as s from event_occurrences where event_id = ? and recurrence_key = '2026-10-28'", [$event->id]);

    expect(substr((string) $row->s, 0, 19))->toBe('2026-10-28 19:00:00');
});

it('keeps the exclusion when the admin edits the series and after the nightly sync', function () {
    $event = excludedApprove('Wednesday Folk Club', $this->admin);

    $form = PublishableEventData::fromEvent($event->refresh());
    $form['title'] = 'Wednesday Folk Club at the Town Hall';

    app(UpdatePublishedEvent::class)($event, PublishableEventData::fromForm($form), $this->admin);

    expect($event->refresh()->title)->toBe('Wednesday Folk Club at the Town Hall')
        ->and($event->excluded_dates)->toBe(['2026-10-21'])
        ->and(excludedStatuses($event))->not->toHaveKey('2026-10-21');

    $this->artisan('calendar:sync-occurrences')->assertSuccessful();

    expect(excludedStatuses($event))->not->toHaveKey('2026-10-21')
        ->and(array_keys(excludedStatuses($event)))->toBe(['2026-10-28', '2026-11-04', '2026-11-11', '2026-11-18']);
});

it('cancels the cancelled override at approval and shows it as Cancelled on the event page', function () {
    $event = excludedApprove('Craft Circle', $this->admin);

    // 21 Oct is a normal date for Craft Circle; only 4 Nov is cancelled.
    expect(excludedStatuses($event))->toBe([
        '2026-10-21' => OccurrenceStatus::Scheduled->value,
        '2026-10-28' => OccurrenceStatus::Scheduled->value,
        '2026-11-04' => OccurrenceStatus::Cancelled->value,
        '2026-11-11' => OccurrenceStatus::Scheduled->value,
        '2026-11-18' => OccurrenceStatus::Scheduled->value,
    ])
        ->and($event->refresh()->excluded_dates)->toBe([]);

    $this->get(route('events.show', $event->slug))
        ->assertOk()
        ->assertSeeInOrder(['Wed 4 Nov', 'Cancelled', 'Wed 11 Nov']);
});

it('says which dates a series skips in its summary', function () {
    $event = excludedApprove('Wednesday Folk Club', $this->admin);

    $summary = app(RecurrenceExpander::class)->summary($event->refresh());

    expect($summary)->toContain('Weekly on Wednesday')
        ->toContain('except')
        ->toContain('21 Oct');
});

it('honours all-day exclusions as VALUE=DATE EXDATEs', function () {
    $event = new Event([
        'timezone' => 'Europe/London',
        'all_day' => true,
        'start_date' => '2026-10-17',
        'start_time' => null,
        'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=SA',
    ]);
    $event->excluded_dates = ['2026-10-24'];

    $dates = app(RecurrenceExpander::class)->instanceDates(
        $event,
        CarbonImmutable::parse('2026-10-17', 'Europe/London'),
        CarbonImmutable::parse('2026-11-08', 'Europe/London'),
    );

    expect($dates)->toBe(['2026-10-17', '2026-10-31', '2026-11-07']);
});

it('refuses a draft whose excluded dates are not real dates', function () {
    $draft = EventDraft::query()->where('title', 'Wednesday Folk Club')->firstOrFail();
    $draft->forceFill(['venue_id' => Venue::factory()->create()->id, 'excluded_dates' => ['2026-02-30']])->save();

    expect(fn () => PublishableEventData::fromDraft($draft->refresh()))
        ->toThrow(InvalidArgumentException::class, 'Invalid excluded date [2026-02-30].');
});

it('carries excluded dates from drafts only, never from the edit form', function () {
    $draft = EventDraft::query()->where('title', 'Wednesday Folk Club')->firstOrFail();
    $draft->forceFill(['venue_id' => Venue::factory()->create()->id])->save();

    $fromDraft = PublishableEventData::fromDraft($draft->refresh());

    expect($fromDraft->excludedDates)->toBe(['2026-10-21'])
        ->and($fromDraft->toAttributes()['excluded_dates'])->toBe(['2026-10-21']);

    $fromForm = PublishableEventData::fromForm([...$fromDraft->toAttributes(), ...$fromDraft->recurrence()->toFormState()]);

    expect($fromForm->excludedDates)->toBeNull()
        ->and($fromForm->toAttributes())->not->toHaveKey('excluded_dates');
});
