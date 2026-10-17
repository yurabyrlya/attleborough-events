<?php

use App\Enums\QualityBand;
use App\Models\EventDraft;
use App\Review\Data\QualityResult;
use App\Review\QualityScorer;

/**
 * An unsaved, complete draft at a typed venue with an end time.
 */
function scorerDraft(array $attributes = []): EventDraft
{
    return (new EventDraft)->forceFill(array_merge([
        'title' => 'Barn Dance',
        'description' => 'Live band and a caller.',
        'venue_id' => null,
        'venue_name' => 'The Old Barn',
        'all_day' => false,
        'start_date' => '2026-10-24',
        'start_time' => '19:30:00',
        'end_time' => '22:00:00',
        'organiser_contact' => 'jo@example.org',
        'repeats_text' => null,
        'recurrence_rule' => null,
        'repeats_acknowledged' => false,
        'parse_errors' => [],
    ], $attributes));
}

function scoreDraft(EventDraft $draft, bool $venueRecognised = true, bool $categoryFlagged = false): QualityResult
{
    return (new QualityScorer)->score($draft, $venueRecognised, $categoryFlagged);
}

dataset('band boundaries', [
    '100 is High' => [100, QualityBand::High],
    '80 is High' => [80, QualityBand::High],
    '79 is Medium' => [79, QualityBand::Medium],
    '50 is Medium' => [50, QualityBand::Medium],
    '49 is Low' => [49, QualityBand::Low],
    '0 is Low' => [0, QualityBand::Low],
]);

it('bands a score at the config thresholds', function (int $score, QualityBand $band) {
    expect(QualityBand::fromScore($score))->toBe($band);
})->with('band boundaries');

it('scores a complete draft at a recognised venue 100/High with no reasons', function () {
    $result = scoreDraft(scorerDraft());

    expect($result->score)->toBe(100)
        ->and($result->band)->toBe(QualityBand::High)
        ->and($result->reasons)->toBe([]);
});

it('scores an unknown venue with no end time 70/Medium', function () {
    $result = scoreDraft(scorerDraft(['end_time' => null]), venueRecognised: false);

    expect($result->score)->toBe(70)
        ->and($result->band)->toBe(QualityBand::Medium)
        ->and($result->reasons)->toBe(["Venue not recognised: 'The Old Barn'", 'No end time']);
});

it('scores unknown venue + Other + no end + unset repeats 30/Low, reasons in the fixed order', function () {
    $result = scoreDraft(
        scorerDraft(['end_time' => null, 'repeats_text' => 'Every other Tuesday']),
        venueRecognised: false,
        categoryFlagged: true,
    );

    expect($result->score)->toBe(30)
        ->and($result->band)->toBe(QualityBand::Low)
        ->and($result->reasons)->toBe([
            "Venue not recognised: 'The Old Barn'",
            'No category matched, set to Other',
            'No end time',
            "Organiser says it repeats ('Every other Tuesday') but no repeat rule is set",
        ]);
});

it('clamps a title-only draft to 0/Low with a missing reason per field, in order', function () {
    $result = scoreDraft(
        (new EventDraft)->forceFill(['title' => 'Barn Dance', 'all_day' => false]),
        venueRecognised: false,
    );

    expect($result->score)->toBe(0)
        ->and($result->band)->toBe(QualityBand::Low)
        ->and($result->reasons)->toBe([
            'Missing start date',
            'Missing start time',
            'Missing venue',
            'Missing description',
            'Missing a contact, website or link',
            'No end time',
        ]);
});

it('deducts once per missing field and lists missing fields before everything else', function () {
    $result = scoreDraft(scorerDraft([
        'title' => '  ',
        'description' => null,
        'end_time' => null,
        'parse_errors' => ['start date "next Satdy"'],
    ]), venueRecognised: false);

    // 100 - 50 - 50 - 40 - 20 - 10 = -70, clamped to 0
    expect($result->score)->toBe(0)
        ->and($result->reasons)->toBe([
            'Missing title',
            'Missing description',
            'Couldn\'t read: start date "next Satdy"',
            "Venue not recognised: 'The Old Barn'",
            'No end time',
        ]);
});

it('takes one 40-point deduction for two parse errors but lists each', function () {
    $result = scoreDraft(scorerDraft(['parse_errors' => ['the start time', 'the price']]));

    expect($result->score)->toBe(60)
        ->and($result->band)->toBe(QualityBand::Medium)
        ->and($result->reasons)->toBe(["Couldn't read: the start time", "Couldn't read: the price"]);
});

it('lists three parse errors then "and 2 more" for five', function () {
    $result = scoreDraft(scorerDraft(['parse_errors' => ['one', 'two', 'three', 'four', 'five']]));

    expect($result->score)->toBe(60)
        ->and($result->reasons)->toBe([
            "Couldn't read: one",
            "Couldn't read: two",
            "Couldn't read: three",
            'and 2 more',
        ]);
});

it('does not ask for an end time or start time on an all-day event', function () {
    $result = scoreDraft(scorerDraft(['all_day' => true, 'start_time' => null, 'end_time' => null]));

    expect($result->score)->toBe(100)
        ->and($result->reasons)->not->toContain('No end time')
        ->and($result->reasons)->not->toContain('Missing start time');
});

it('drops the repeats deduction once the admin acknowledges it or sets a rule', function (array $attributes) {
    $result = scoreDraft(scorerDraft(array_merge(['repeats_text' => 'Every other Tuesday'], $attributes)));

    expect($result->score)->toBe(100)
        ->and($result->reasons)->toBe([]);
})->with([
    'acknowledged' => [['repeats_acknowledged' => true]],
    'rule set' => [['recurrence_rule' => 'FREQ=WEEKLY;INTERVAL=2;BYDAY=TU']],
]);

it('accepts a website or source link in place of a contact', function (array $attributes) {
    $result = scoreDraft(scorerDraft(array_merge(['organiser_contact' => null], $attributes)));

    expect($result->score)->toBe(100)
        ->and($result->reasons)->not->toContain('Missing a contact, website or link');
})->with([
    'website' => [['organiser_website' => 'https://example.org']],
    'source link' => [['source_url' => 'https://example.org/event']],
]);

it('treats a confirmed venue_id as present, not missing', function () {
    $result = scoreDraft(scorerDraft(['venue_id' => 7, 'venue_name' => null]));

    expect($result->score)->toBe(100)
        ->and($result->reasons)->toBe([]);
});

it('gives identical, non-repeating reasons for identical input', function () {
    $draft = scorerDraft(['end_time' => null, 'repeats_text' => 'Weekly', 'parse_errors' => ['x', 'y']]);

    $first = scoreDraft($draft, venueRecognised: false, categoryFlagged: true);
    $second = scoreDraft($draft, venueRecognised: false, categoryFlagged: true);

    expect($first->reasons)->toBe($second->reasons)
        ->and($first->score)->toBe($second->score)
        ->and($first->reasons)->toBe(array_values(array_unique($first->reasons)));
});

it('never goes above 100 even with zero or negative weights', function () {
    config(['review.quality.weights.no_end_time' => -30]);

    expect(scoreDraft(scorerDraft(['end_time' => null]))->score)->toBe(100);
});

it('reads its weights from config', function () {
    config(['review.quality.weights.no_end_time' => 25]);

    expect(scoreDraft(scorerDraft(['end_time' => null]))->score)->toBe(75);
});
