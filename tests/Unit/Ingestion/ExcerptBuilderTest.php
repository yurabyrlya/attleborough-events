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
    // Review CR-01: bracketed area codes, dotted separators, local numbers.
    'bracketed area code' => ['Call (01953) 452300 to book', 'Call to book'],
    'bracketed area code with label' => ['Phone (01953) 452300', null],
    'dotted' => ['Ring 01953.452300 for tickets', 'Ring for tickets'],
    'dotted groups' => ['Ring 01953.452.300 for tickets', 'Ring for tickets'],
    'international without (0)' => ['Book on +44 1953 452300 now', 'Book on now'],
    'local number after call' => ['Call 452300 locally', 'Call locally'],
    'local number after ring us on' => ['Ring us on 452 300 to book', 'Ring us on to book'],
    'local number after text' => ['Text 452-300 to enter', 'Text to enter'],
    'local number with label' => ['Tel: 452 300 evenings', 'evenings'],
    'dotted local number with label' => ['Telephone 452.300 evenings', 'evenings'],
]);

it('removes email addresses and their labels', function () {
    expect(ExcerptBuilder::make('Email jo.bloggs@example.co.uk please'))->toBe('please')
        ->and(ExcerptBuilder::make('Contact jo@example.org for details'))->toBe('Contact for details');
});

it('keeps years, times and ordinary words', function () {
    expect(ExcerptBuilder::make('Starts 2026 at 10:30'))->toBe('Starts 2026 at 10:30')
        ->and(ExcerptBuilder::make('Bring your phone to scan tickets'))->toBe('Bring your phone to scan tickets')
        ->and(ExcerptBuilder::make('Bring your phone, it is 2026'))->toBe('Bring your phone, it is 2026')
        ->and(ExcerptBuilder::make('Call in any time'))->toBe('Call in any time')
        ->and(ExcerptBuilder::make('Open 0930-1630 daily'))->toBe('Open 0930-1630 daily')
        ->and(ExcerptBuilder::make('Doors 7.30pm, tickets £5.00'))->toBe('Doors 7.30pm, tickets £5.00');
});

it('flags text that may still hold a contact (bulk approve backstop)', function (?string $text, bool $flagged) {
    expect(ExcerptBuilder::mayContainContact($text))->toBe($flagged);
})->with([
    'null' => [null, false],
    'ordinary text' => ['Bring your phone, doors 7.30pm, £5.00, Sat 14 Nov 2026', false],
    'an @' => ['Write to jo at example dot org or @jo', true],
    'six-digit run' => ['Box office 452300', true],
    'bracketed area code' => ['Box office (01953) 452300', true],
    'spaced groups' => ['Box office 452 300', true],
    'dotted groups' => ['Box office 01953.452.300', true],
]);

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
