<?php

use App\Models\Venue;
use App\Review\VenueMatcher;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->hall = Venue::factory()->create([
        'name' => 'Connaught Hall',
        'postcode' => 'NR17 2AA',
        'aliases' => ['The Connaught', 'Town Hall'],
    ]);
    Venue::factory()->create(['name' => 'Queens Square Library', 'postcode' => 'NR17 2AB', 'aliases' => []]);
    $this->venuesBefore = Venue::count();
    $this->matcher = app(VenueMatcher::class);
});

afterEach(function () {
    expect(Venue::count())->toBe($this->venuesBefore);
});

it('matches a venue exactly by name, case-insensitively', function () {
    expect($this->matcher->exact('connaught hall', null)?->id)->toBe($this->hall->id)
        ->and($this->matcher->exact('  CONNAUGHT HALL ', null)?->id)->toBe($this->hall->id);
});

it('matches a venue exactly by alias', function () {
    expect($this->matcher->exact('the connaught', null)?->id)->toBe($this->hall->id);
});

it('matches a venue exactly by postcode, ignoring case and spaces', function () {
    expect($this->matcher->exact(null, 'nr17 2aa')?->id)->toBe($this->hall->id)
        ->and($this->matcher->exact('Somewhere Else', 'NR172AA')?->id)->toBe($this->hall->id);
});

it('finds no exact match for a near miss or empty input', function () {
    expect($this->matcher->exact('Conaught Hall', null))->toBeNull()
        ->and($this->matcher->exact(null, null))->toBeNull()
        ->and($this->matcher->exact('', '  '))->toBeNull();
});

it('suggests an exact match with how it matched and score 1.0', function (?string $name, ?string $postcode, string $matchedBy) {
    $suggestion = $this->matcher->suggest($name, $postcode);

    expect($suggestion?->venueId)->toBe($this->hall->id)
        ->and($suggestion->venueName)->toBe('Connaught Hall')
        ->and($suggestion->matchedBy)->toBe($matchedBy)
        ->and($suggestion->score)->toBe(1.0);
})->with([
    'name' => ['Connaught Hall', null, 'name'],
    'alias' => ['Town Hall', null, 'alias'],
    'postcode' => ['Unknown Place', 'nr17 2aa', 'postcode'],
]);

it('suggests the most similar venue name by trigram similarity', function () {
    $suggestion = $this->matcher->suggest('Conaught Hall', null);

    expect($suggestion?->venueId)->toBe($this->hall->id)
        ->and($suggestion->matchedBy)->toBe('similar')
        ->and($suggestion->score)->toBeGreaterThanOrEqual((float) config('review.venue_similarity_threshold'))
        ->and($suggestion->score)->toBeLessThan(1.0);
});

it('picks the most similar of several candidate names', function () {
    $annexe = Venue::factory()->create(['name' => 'Connaught Hall Annexe Rooms', 'postcode' => 'NR17 2AC']);
    $this->venuesBefore = Venue::count();

    $suggestion = $this->matcher->suggest('Conaught Hall', null);
    $annexeScore = (float) DB::selectOne('select similarity(?, ?) as s', [$annexe->name, 'Conaught Hall'])->s;

    expect($annexeScore)->toBeGreaterThanOrEqual((float) config('review.venue_similarity_threshold'))
        ->and($suggestion?->venueId)->toBe($this->hall->id)
        ->and($suggestion->score)->toBeGreaterThan($annexeScore);
});

it('suggests nothing below the similarity threshold', function () {
    expect($this->matcher->suggest('Zzzz', null))->toBeNull()
        ->and($this->matcher->suggest(null, null))->toBeNull();
});

it('honours the configured threshold', function () {
    config(['review.venue_similarity_threshold' => 0.99]);

    expect($this->matcher->suggest('Conaught Hall', null))->toBeNull();
});

it('treats SQL-looking organiser text as plain text', function () {
    expect($this->matcher->suggest("x'); drop table venues; --", "' or 1=1 --"))->toBeNull()
        ->and($this->matcher->exact("' or '1'='1", null))->toBeNull();
});
