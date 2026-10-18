<?php

use App\Enums\DraftKind;
use App\Enums\EventOrigin;
use App\Enums\ExtractionMethod;
use App\Enums\QualityBand;
use App\Enums\ReviewStatus;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\ApproveDraft;
use App\Review\Data\DraftData;
use App\Review\DraftPublishability;
use App\Review\DraftWriter;
use App\Review\Exceptions\DraftNotPublishable;
use App\Review\Exceptions\InvalidReviewTransition;
use Database\Seeders\CategorySeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * DraftData mirroring a draft's current content, with named overrides: what
 * an admin edit form would hand to DraftWriter::update().
 *
 * @param  array<string, mixed>  $overrides  DraftData constructor arguments
 */
function draftDataFrom(EventDraft $draft, array $overrides = []): DraftData
{
    return new DraftData(...array_merge([
        'origin' => $draft->origin,
        'extractionMethod' => $draft->extraction_method,
        'title' => $draft->title,
        'description' => $draft->description,
        'preferredCategoryId' => $draft->category_id,
        'venueId' => $draft->venue_id,
        'venueName' => $draft->venue_name,
        'venueAddress' => $draft->venue_address,
        'venuePostcode' => $draft->venue_postcode,
        'allDay' => $draft->all_day,
        'startDate' => $draft->start_date?->format('Y-m-d'),
        'startTime' => $draft->start_time,
        'endDate' => $draft->end_date?->format('Y-m-d'),
        'endTime' => $draft->end_time,
        'recurrenceRule' => $draft->recurrence_rule,
        'repeatsText' => $draft->repeats_text,
        'repeatsAcknowledged' => $draft->repeats_acknowledged,
        'organiserContact' => $draft->organiser_contact,
        'sourceUrl' => $draft->source_url,
    ], $overrides));
}

/**
 * The blockers ApproveDraft refuses with, or null when it does not refuse.
 *
 * @return list<string>|null
 */
function approvalBlockers(EventDraft $draft, User $admin): ?array
{
    try {
        app(ApproveDraft::class)($draft, $admin);
    } catch (DraftNotPublishable $e) {
        return $e->blockers;
    }

    return null;
}

/**
 * An event's occurrence starts as raw UTC strings, in order.
 *
 * @return list<string>
 */
function occurrenceStartsUtc(Event $event): array
{
    $rows = DB::select("select starts_at at time zone 'UTC' as s from event_occurrences where event_id = ? order by starts_at", [$event->id]);

    return array_map(fn (object $row): string => substr((string) $row->s, 0, 19), $rows);
}

beforeEach(function () {
    freezeLondon('2026-10-16 12:00');
    $this->admin = User::factory()->create(['email' => 'admin@example.test']);
    $this->seed(CategorySeeder::class);
});

it('refuses a submission with a free-text venue and keeps it pending', function () {
    $draft = EventDraft::factory()->submission()->freeTextVenue()->create();

    expect(approvalBlockers($draft, $this->admin))->toBe(['Pick or create a venue.'])
        ->and($draft->refresh()->review_status)->toBe(ReviewStatus::PendingReview)
        ->and($draft->event_id)->toBeNull()
        ->and(Event::count())->toBe(0);
});

it('publishes the same draft once the admin sets the venue', function () {
    $draft = EventDraft::factory()->submission()->freeTextVenue()->create();
    $venue = Venue::factory()->create();

    app(DraftWriter::class)->update($draft, draftDataFrom($draft, ['venueId' => $venue->id]));

    $event = app(ApproveDraft::class)($draft, $this->admin);
    $draft->refresh();

    expect($draft->review_status)->toBe(ReviewStatus::Approved)
        ->and($draft->event_id)->toBe($event->id)
        ->and($draft->reviewed_by)->toBe($this->admin->id)
        ->and($event->venue_id)->toBe($venue->id)
        ->and($event->origin)->toBe(EventOrigin::Submission)
        ->and($event->approved_by)->toBe($this->admin->id);

    $eventRow = DB::selectOne("select approved_at at time zone 'UTC' as t from events where id = ?", [$event->id]);
    $draftRow = DB::selectOne("select reviewed_at at time zone 'UTC' as t from event_drafts where id = ?", [$draft->id]);

    expect(substr((string) $eventRow->t, 0, 19))->toBe('2026-10-16 11:00:00')
        ->and(substr((string) $draftRow->t, 0, 19))->toBe('2026-10-16 11:00:00');
});

it('files a draft with no preferred category under Other, flagged', function () {
    $draft = app(DraftWriter::class)->create(new DraftData(
        origin: EventOrigin::Submission,
        extractionMethod: ExtractionMethod::Submission,
        title: 'Mystery Evening',
    ));

    expect($draft->category->slug)->toBe('other')
        ->and($draft->category_flagged)->toBeTrue()
        ->and($draft->category_reason)->toBe('No match → Other');
});

it('publishes a weekly series once with correct instants across the end of BST', function () {
    $draft = EventDraft::factory()
        ->recurring('FREQ=WEEKLY;BYDAY=TH;COUNT=4', '2026-10-22', '19:30:00')
        ->create();

    $event = app(ApproveDraft::class)($draft, $this->admin);

    expect(Event::count())->toBe(1)
        ->and($event->recurrence_rule)->toBe('FREQ=WEEKLY;BYDAY=TH;COUNT=4')
        ->and(occurrenceStartsUtc($event))->toBe([
            '2026-10-22 18:30:00',
            '2026-10-29 19:30:00',
            '2026-11-05 19:30:00',
            '2026-11-12 19:30:00',
        ]);
});

it('publishes a monthly series starting on the day BST begins at the right instant', function () {
    freezeLondon('2027-03-01 12:00');

    $draft = EventDraft::factory()
        ->recurring('FREQ=MONTHLY;BYDAY=4SU;COUNT=3', '2027-03-28', '10:00:00')
        ->create();

    $event = app(ApproveDraft::class)($draft, $this->admin);

    expect(occurrenceStartsUtc($event)[0])->toBe('2027-03-28 09:00:00');
});

it('creates one event when the same draft is approved twice', function () {
    $draft = EventDraft::factory()->create();

    app(ApproveDraft::class)($draft, $this->admin);
    $firstReviewedAt = DB::selectOne("select reviewed_at at time zone 'UTC' as t from event_drafts where id = ?", [$draft->id])->t;

    $this->travel(1)->hours();

    expect(fn () => app(ApproveDraft::class)($draft, $this->admin))
        ->toThrow(InvalidReviewTransition::class);

    $secondReviewedAt = DB::selectOne("select reviewed_at at time zone 'UTC' as t from event_drafts where id = ?", [$draft->id])->t;

    expect(Event::count())->toBe(1)
        ->and($secondReviewedAt)->toBe($firstReviewedAt);
});

it('refuses approval by anyone but the admin and publishes nothing', function () {
    $draft = EventDraft::factory()->create();
    $someoneElse = User::factory()->create(['email' => 'someone@example.test']);

    expect(fn () => app(ApproveDraft::class)($draft, $someoneElse))
        ->toThrow(AuthorizationException::class);

    expect($draft->refresh()->review_status)->toBe(ReviewStatus::PendingReview)
        ->and(Event::count())->toBe(0);
});

dataset('unpublishable drafts', [
    'not a new-event draft' => [['kind' => DraftKind::Update], 'Only new-event drafts can be approved here.'],
    'no venue' => [['venue_id' => null], 'Pick or create a venue.'],
    'no category' => [['category_id' => null], 'Pick a category.'],
    'blank title' => [['title' => '   '], 'Add a title.'],
    'no description' => [['description' => null], 'Add a description.'],
    'no start date' => [['start_date' => null], 'Add a start date.'],
    'timed with no start time' => [['start_time' => null], 'Add a start time.'],
    'no contact of any kind' => [['organiser_contact' => null, 'source_url' => null, 'organiser_website' => null], 'Add a source URL, an organiser contact or a website.'],
    'repeats text not acknowledged' => [['repeats_text' => 'Every other Tuesday'], "The organiser says it repeats. Set a repeat rule, or tick 'No repeat rule needed'."],
    'same-day end time equal to the start time' => [['start_time' => '19:30:00', 'end_time' => '19:30:00'], 'The end time must be different from the start time.'],
    'end time equal to the start time, end date on the start date' => [['start_time' => '19:30:00', 'end_date' => '2026-10-24', 'end_time' => '19:30:00'], 'The end time must be different from the start time.'],
    'repeating over several days' => [['recurrence_rule' => 'FREQ=WEEKLY;BYDAY=SA', 'end_date' => '2026-10-25'], "Repeating events can't span several days."],
    'rule the form cannot represent' => [['recurrence_rule' => 'FREQ=DAILY'], "This repeat rule can't be published from the form."],
    'rule not fitting the start date' => [['recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO'], "This repeat rule can't be published from the form."],
]);

it('refuses an unpublishable draft with exactly its blocker', function (array $attributes, string $blocker) {
    $draft = EventDraft::factory()->create($attributes);

    expect(approvalBlockers($draft, $this->admin))->toBe([$blocker])
        ->and(app(DraftPublishability::class)->blockers($draft))->toBe([$blocker])
        ->and($draft->refresh()->review_status)->toBe(ReviewStatus::PendingReview)
        ->and(Event::count())->toBe(0);
})->with('unpublishable drafts');

it('lists every field blocker in a fixed order', function () {
    $draft = EventDraft::factory()->freeTextVenue()->create([
        'description' => '',
        'start_time' => null,
        'repeats_text' => 'Weekly',
    ]);

    expect(approvalBlockers($draft, $this->admin))->toBe([
        'Pick or create a venue.',
        'Add a description.',
        'Add a start time.',
        "The organiser says it repeats. Set a repeat rule, or tick 'No repeat rule needed'.",
    ]);
});

it('approves an acknowledged "repeats" draft as a one-off', function () {
    $draft = EventDraft::factory()->withRepeatsText()->create(['repeats_acknowledged' => true]);

    $event = app(ApproveDraft::class)($draft, $this->admin);

    expect($event->recurrence_rule)->toBeNull()
        ->and(occurrenceStartsUtc($event))->toBe(['2026-10-24 18:30:00']);
});

it('approves an all-day draft with no start time', function () {
    $draft = EventDraft::factory()->create(['all_day' => true, 'start_time' => null, 'end_time' => null]);

    expect(approvalBlockers($draft, $this->admin))->toBeNull();
});

dataset('bulk key cases', [
    'publishable High' => [[], []],
    'publishable Medium' => [['quality_band' => QualityBand::Medium, 'quality_score' => 60], []],
    'unscored counts as Low' => [['quality_band' => null, 'quality_score' => null], ['low_quality']],
    'Low' => [['quality_band' => QualityBand::Low, 'quality_score' => 20], ['low_quality']],
    'parse errors' => [['parse_errors' => ['Could not read the date']], ['parse_errors']],
    'missing description' => [['description' => null], ['blocked']],
    'no venue and missing description' => [['venue_id' => null, 'description' => null], ['unresolved_venue', 'blocked']],
    'same-day end time equal to the start time' => [['end_time' => '19:30:00'], ['end_time_same_as_start']],
    'end time equal to the start time on a later day' => [['end_date' => '2026-10-25', 'end_time' => '19:30:00'], []],
    'every specific key, in order' => [
        ['parse_errors' => ['Bad time'], 'quality_band' => QualityBand::Low, 'venue_id' => null, 'repeats_text' => 'Fortnightly'],
        ['parse_errors', 'low_quality', 'unresolved_venue', 'repeats_unset'],
    ],
]);

it('gives bulk approve every applicable skip key in the fixed order', function (array $attributes, array $keys) {
    $draft = EventDraft::factory()->create($attributes);

    expect(app(DraftPublishability::class)->bulkBlockerKeys($draft))->toBe($keys);
})->with('bulk key cases');

it('skips a High draft with no confirmed venue for that reason only, even with an exact-match venue', function () {
    Venue::factory()->create(['name' => 'The Old Barn']);
    $draft = EventDraft::factory()->freeTextVenue()->create();

    expect(app(DraftPublishability::class)->bulkBlockerKeys($draft))->toBe(['unresolved_venue']);
});

it('skips a High draft whose organiser says it repeats until a rule is set, acknowledged or not', function (bool $acknowledged) {
    $draft = EventDraft::factory()->withRepeatsText()->create(['repeats_acknowledged' => $acknowledged]);

    expect(app(DraftPublishability::class)->bulkBlockerKeys($draft))->toBe(['repeats_unset']);
})->with(['not acknowledged' => false, 'acknowledged' => true]);

it('skips a possible duplicate', function () {
    $draft = EventDraft::factory()->duplicateOf(Event::factory()->create())->create();

    expect(app(DraftPublishability::class)->bulkBlockerKeys($draft))->toContain('duplicate');
});

it('skips a discovered draft whose description may still hold a contact (review CR-01)', function (string $description) {
    $draft = EventDraft::factory()->create(['origin' => EventOrigin::Discovered, 'description' => $description]);

    expect(app(DraftPublishability::class)->bulkBlockerKeys($draft))->toBe(['contact_like'])
        // A single approve, after a human has read the draft, is still allowed.
        ->and(app(DraftPublishability::class)->blockers($draft))->toBe([]);
})->with([
    'bracketed area code' => ['Box office (01953) 452300'],
    'local number' => ['Box office 452300'],
    'email' => ['Write to jo@example.org'],
]);

it('does not flag manual drafts or clean discovered descriptions as contact-like', function () {
    $manual = EventDraft::factory()->create(['description' => 'Box office (01953) 452300']);
    $clean = EventDraft::factory()->create(['origin' => EventOrigin::Discovered, 'description' => 'Bring your phone. Doors 7.30pm, £5.00.']);

    expect(app(DraftPublishability::class)->bulkBlockerKeys($manual))->toBe([])
        ->and(app(DraftPublishability::class)->bulkBlockerKeys($clean))->toBe([]);
});

dataset('skip messages', [
    'parse_errors' => ['parse_errors', 3, '3 skipped: has parse errors'],
    'contact_like' => ['contact_like', 1, '1 skipped: description may contain a phone number or email'],
    'duplicate' => ['duplicate', 1, '1 skipped: possible duplicate'],
    'low_quality' => ['low_quality', 4, '4 skipped: Low quality'],
    'unresolved_venue' => ['unresolved_venue', 1, '1 skipped: no confirmed venue'],
    'repeats_unset' => ['repeats_unset', 2, '2 skipped: repeat rule not set'],
    'end_time_same_as_start' => ['end_time_same_as_start', 1, '1 skipped: end time same as start time'],
    'blocked' => ['blocked', 5, '5 skipped: not ready to publish'],
    'already_reviewed' => ['already_reviewed', 1, '1 skipped: already reviewed'],
    'not_pending' => ['not_pending', 2, '2 skipped: already reviewed'],
]);

it('words each bulk skip reason', function (string $key, int $count, string $message) {
    expect(DraftPublishability::skipMessage($key, $count))->toBe($message);
})->with('skip messages');

it('refuses an unknown skip reason', function () {
    DraftPublishability::skipMessage('mystery', 1);
})->throws(InvalidArgumentException::class);
