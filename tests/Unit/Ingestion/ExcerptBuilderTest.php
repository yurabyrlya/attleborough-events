<?php

use App\Ingestion\Pipeline\ExcerptBuilder;

/*
 * PIPE-09 / D-15: the draft description is a short plain-text excerpt with
 * emails, UK phone numbers and their labels removed, at most 200 graphemes
 * including the ellipsis.
 */

it('removes UK phone numbers and their labels', function (string $full, ?string $excerpt) {
    expect(ExcerptBuilder::make($full))->toBe($excerpt);
})->with([
    'local number' => ['Call 01953 452123 today', 'Call today'],
    'international with (0) and label' => ['Tel +44 (0)1953 452 123 for tickets', 'for tickets'],
    'labelled number only' => ['Phone 01953-452-123', null],
    'label with colon' => ['Book on Tel: 07700 900123, places limited', 'Book on, places limited'],
    'mobile label' => ['Mobile 07700900123 or just turn up', 'or just turn up'],
]);

it('removes email addresses and their labels', function () {
    expect(ExcerptBuilder::make('Email jo.bloggs@example.co.uk please'))->toBe('please')
        ->and(ExcerptBuilder::make('Contact jo@example.org for details'))->toBe('Contact for details');
});

it('keeps years, times and ordinary words', function () {
    expect(ExcerptBuilder::make('Starts 2026 at 10:30'))->toBe('Starts 2026 at 10:30')
        ->and(ExcerptBuilder::make('Bring your phone to scan tickets'))->toBe('Bring your phone to scan tickets');
});

it('strips markup and decodes entities into plain text', function () {
    expect(ExcerptBuilder::make('<p>Fun &amp; games</p>'))->toBe('Fun & games')
        ->and(ExcerptBuilder::make('<p>One</p><p>Two</p>'))->toBe('One Two');
});

it('cuts a long text to exactly 200 graphemes ending with the ellipsis', function () {
    $excerpt = ExcerptBuilder::make(str_repeat('abcde ', 50));

    expect(grapheme_strlen($excerpt))->toBe(200)
        ->and($excerpt)->toEndWith('…');
});

it('never splits an emoji ZWJ sequence or an accented letter at the cut', function () {
    $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
    $excerpt = ExcerptBuilder::make(str_repeat('a', 198).$family.str_repeat('b', 10));

    expect(grapheme_strlen($excerpt))->toBe(200)
        ->and($excerpt)->toEndWith($family.'…');

    // e + combining acute accent as the 199th grapheme.
    $accented = "e\u{0301}";
    $excerpt = ExcerptBuilder::make(str_repeat('a', 198).$accented.str_repeat('b', 10));

    expect($excerpt)->toEndWith($accented.'…')
        ->and(mb_check_encoding($excerpt, 'UTF-8'))->toBeTrue();
});

it('returns null for nothing to show', function (?string $full) {
    expect(ExcerptBuilder::make($full))->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
    'whitespace' => ["  \n\t "],
    'markup only' => ['<p> </p>'],
    'contact only' => ['jo@example.org 01953 452123'],
]);
