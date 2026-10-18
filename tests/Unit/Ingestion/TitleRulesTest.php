<?php

use App\Ingestion\Pipeline\TitleRules;

/*
 * D-04: per-source skip / keep rules on item titles, whole-word,
 * case-insensitive and NFC-normalised (CategoryAssigner::titleContainsKeyword).
 */

function titleRule(string $mode, string $keyword): array
{
    return ['mode' => $mode, 'keyword' => $keyword];
}

it('drops titles matching a skip rule as whole words only', function () {
    $rules = [titleRule(TitleRules::MODE_SKIP, 'Private hire')];

    expect(TitleRules::allows($rules, 'PRIVATE HIRE - Hall'))->toBeFalse()
        ->and(TitleRules::allows($rules, "Private hirers' fair"))->toBeTrue()
        ->and(TitleRules::allows($rules, 'Coffee morning'))->toBeTrue();
});

it('keeps only titles matching a keep rule when keep rules exist', function () {
    $rules = [titleRule(TitleRules::MODE_KEEP, 'yoga')];

    expect(TitleRules::allows($rules, 'Gentle Yoga'))->toBeTrue()
        ->and(TitleRules::allows($rules, 'Yogalates'))->toBeFalse()
        ->and(TitleRules::allows($rules, 'Book Sale'))->toBeFalse();
});

it('lets a skip rule beat a keep rule', function () {
    $rules = [titleRule(TitleRules::MODE_KEEP, 'yoga'), titleRule(TitleRules::MODE_SKIP, 'private')];

    expect(TitleRules::allows($rules, 'Private yoga class'))->toBeFalse()
        ->and(TitleRules::allows($rules, 'Yoga'))->toBeTrue();
});

it('keeps everything when there are no rules', function () {
    expect(TitleRules::allows([], 'Anything at all'))->toBeTrue();
});

it('matches after NFC normalisation', function () {
    // Rule typed with a combining accent, title with the precomposed letter.
    $rules = [titleRule(TitleRules::MODE_SKIP, "Cafe\u{0301}")];

    expect(TitleRules::allows($rules, "CAF\u{00C9} morning"))->toBeFalse();
});

it('ignores malformed rule entries', function () {
    $rules = [
        'skip everything',
        ['mode' => 'skip'],
        ['mode' => 'delete', 'keyword' => 'sale'],
        ['mode' => TitleRules::MODE_SKIP, 'keyword' => '   '],
        ['mode' => TitleRules::MODE_KEEP, 'keyword' => 42],
    ];

    expect(TitleRules::allows($rules, 'Book sale'))->toBeTrue();
});
