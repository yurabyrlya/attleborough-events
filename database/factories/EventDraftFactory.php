<?php

namespace Database\Factories;

use App\Enums\DraftKind;
use App\Enums\EventOrigin;
use App\Enums\ExtractionMethod;
use App\Enums\PriceType;
use App\Enums\QualityBand;
use App\Enums\ReviewStatus;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\Venue;
use App\Review\ReferenceCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test-only factory. Production drafts are written exclusively by
 * App\Review\DraftWriter (field data) and the review actions in
 * App\Publishing\Actions (review state); no seeder uses this factory.
 *
 * Factories bypass EventDraft's #[Fillable] list, so the states below set
 * review-state columns (origin, quality_*, parse_errors, event_id ...)
 * directly to build each queue situation.
 *
 * The default is a publishable pending manual draft: category, venue, a
 * timed start with an end, an organiser contact, scored High.
 *
 * @extends Factory<EventDraft>
 */
class EventDraftFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => ReferenceCode::unique(),
            'origin' => EventOrigin::Manual,
            'kind' => DraftKind::New,
            'review_status' => ReviewStatus::PendingReview,
            'extraction_method' => ExtractionMethod::Manual,
            'title' => ucfirst(fake()->words(3, true)),
            'description' => fake()->paragraph(),
            'category_id' => Category::factory(),
            'category_reason' => 'Chosen by admin',
            'category_flagged' => false,
            'venue_id' => Venue::factory(),
            'timezone' => 'Europe/London',
            'all_day' => false,
            'start_date' => '2026-10-24',
            'start_time' => '19:30:00',
            'end_date' => null,
            'end_time' => '22:00:00',
            'recurrence_rule' => null,
            'repeats_text' => null,
            'repeats_acknowledged' => false,
            'family_friendly' => false,
            'price_type' => PriceType::Unknown,
            'organiser_contact' => fake()->safeEmail(),
            'quality_score' => 100,
            'quality_band' => QualityBand::High,
            'quality_reasons' => [],
            'parse_errors' => [],
        ];
    }

    /**
     * A public submission: the organiser's category pick kept for review.
     */
    public function submission(): static
    {
        return $this->state([
            'origin' => EventOrigin::Submission,
            'extraction_method' => ExtractionMethod::Submission,
            'category_reason' => 'Chosen by organiser',
            // Lazy: resolved after category_id, so it is the same category.
            'submitted_category_id' => fn (array $attributes) => $attributes['category_id'],
            'contact_public' => true,
            'consented_at' => now()->utc(),
        ]);
    }

    public function manual(): static
    {
        return $this->state([
            'origin' => EventOrigin::Manual,
            'extraction_method' => ExtractionMethod::Manual,
            'category_reason' => 'Chosen by admin',
        ]);
    }

    /**
     * "Somewhere else": a typed venue, not yet matched to a Venue.
     */
    public function freeTextVenue(): static
    {
        return $this->state([
            'venue_id' => null,
            'venue_name' => 'The Old Barn',
            'venue_address' => '1 Church Street',
            'venue_postcode' => 'NR17 2AA',
        ]);
    }

    /**
     * The organiser says it repeats but no rule is set yet.
     */
    public function withRepeatsText(string $text = 'Every other Tuesday'): static
    {
        return $this->state([
            'repeats_text' => $text,
            'recurrence_rule' => null,
        ]);
    }

    /**
     * A repeating series (single-day, so no end date).
     */
    public function recurring(string $rrule, string $startDate, string $startTime): static
    {
        return $this->state([
            'recurrence_rule' => $rrule,
            'start_date' => $startDate,
            'start_time' => $startTime,
            'end_date' => null,
        ]);
    }

    /**
     * @param  list<string>  $reasons
     */
    public function scored(QualityBand $band, int $score, array $reasons = []): static
    {
        return $this->state([
            'quality_band' => $band,
            'quality_score' => $score,
            'quality_reasons' => $reasons,
        ]);
    }

    /**
     * @param  list<string>  $errors
     */
    public function withParseErrors(array $errors): static
    {
        return $this->state(['parse_errors' => $errors]);
    }

    public function duplicateOf(Event $event): static
    {
        return $this->state(['duplicate_of_event_id' => $event->id]);
    }

    /**
     * Already approved: linked to the event, stamped with its approver and
     * approval time.
     */
    public function approvedFor(Event $event): static
    {
        return $this->state([
            'review_status' => ReviewStatus::Approved,
            'event_id' => $event->id,
            'reviewed_by' => $event->approved_by,
            'reviewed_at' => $event->approved_at,
        ]);
    }
}
