<?php

use App\Enums\EventOrigin;
use App\Enums\ExtractionMethod;
use App\Enums\QualityBand;
use App\Models\Category;
use App\Models\Venue;
use App\Review\Data\DraftData;
use App\Review\DraftWriter;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    freezeLondon('2026-10-16 12:00');
    $this->seed(CategorySeeder::class);
    $this->community = Category::where('slug', 'community')->firstOrFail();
});

/**
 * A complete public submission at a typed venue, with overrides.
 */
function scoringSubmission(array $overrides = []): DraftData
{
    return new DraftData(...array_merge([
        'origin' => EventOrigin::Submission,
        'extractionMethod' => ExtractionMethod::Submission,
        'title' => 'Barn Dance',
        'description' => 'Live band and a caller. Bring a partner or find one.',
        'preferredCategoryId' => test()->community->id,
        'venueName' => 'The Old Barn',
        'venueAddress' => '1 Church Street',
        'venuePostcode' => 'NR17 9ZZ',
        'startDate' => '2026-10-24',
        'startTime' => '19:30:00',
        'organiserName' => 'Jo Bloggs',
        'organiserContact' => 'jo@example.org',
    ], $overrides));
}

it('stores 70/Medium with reasons for an unknown venue and no end time, then lifts to 100/High when the admin fixes both', function () {
    $writer = app(DraftWriter::class);

    $draft = $writer->create(scoringSubmission());

    expect($draft->quality_score)->toBe(70)
        ->and($draft->quality_band)->toBe(QualityBand::Medium)
        ->and($draft->quality_reasons)->toBe(["Venue not recognised: 'The Old Barn'", 'No end time']);

    $venue = Venue::factory()->create(['name' => 'Connaught Hall']);

    $updated = $writer->update($draft, scoringSubmission([
        'venueId' => $venue->id,
        'venueName' => null,
        'venueAddress' => null,
        'venuePostcode' => null,
        'endTime' => '22:00:00',
    ]));

    expect($updated->quality_score)->toBe(100)
        ->and($updated->quality_band)->toBe(QualityBand::High)
        ->and($updated->quality_reasons)->toBe([]);
});

it('persists the score, band and reasons on the row', function () {
    $draft = app(DraftWriter::class)->create(scoringSubmission());

    $row = DB::selectOne('select quality_score, quality_band, quality_reasons from event_drafts where id = ?', [$draft->id]);

    expect($row->quality_score)->toBe(70)
        ->and($row->quality_band)->toBe('medium')
        ->and(json_decode($row->quality_reasons, true))->toBe(["Venue not recognised: 'The Old Barn'", 'No end time']);
});

it('counts a typed venue exactly matching a known venue as recognised but never sets venue_id', function () {
    $venue = Venue::factory()->create(['name' => 'The Old Barn']);
    $venuesBefore = Venue::count();

    $draft = app(DraftWriter::class)->create(scoringSubmission(['venueName' => 'the old barn', 'endTime' => '22:00:00']));

    expect($draft->quality_score)->toBe(100)
        ->and($draft->quality_band)->toBe(QualityBand::High)
        ->and($draft->quality_reasons)->toBe([])
        ->and($draft->venue_id)->toBeNull()
        ->and(Venue::count())->toBe($venuesBefore)
        ->and($venue->fresh()->name)->toBe('The Old Barn');
});

it('deducts for the Other fallback and unset repeats text: 30/Low', function () {
    $draft = app(DraftWriter::class)->create(scoringSubmission([
        'preferredCategoryId' => null,
        'repeatsText' => 'Every other Tuesday',
    ]));

    expect($draft->quality_score)->toBe(30)
        ->and($draft->quality_band)->toBe(QualityBand::Low)
        ->and($draft->quality_reasons)->toBe([
            "Venue not recognised: 'The Old Barn'",
            'No category matched, set to Other',
            'No end time',
            "Organiser says it repeats ('Every other Tuesday') but no repeat rule is set",
        ]);
});
