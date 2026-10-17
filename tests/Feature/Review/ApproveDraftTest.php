<?php

use App\Enums\EventOrigin;
use App\Enums\ExtractionMethod;
use App\Enums\ReviewStatus;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\ApproveDraft;
use App\Review\Data\DraftData;
use App\Review\DraftWriter;
use App\Review\Exceptions\DraftNotPublishable;
use Database\Seeders\CategorySeeder;
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
