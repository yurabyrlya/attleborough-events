<?php

/*
 * D-06 / D-08: every public colour pair meets WCAG 2.2 AA in both device
 * themes. Pure PHP: reads resources/css/app.css and computes WCAG 2.x
 * contrast ratios, so a token edit that breaks contrast fails CI.
 */

const BRAND_COLOUR_TOKENS = ['accent', 'accent-ink', 'accent-soft', 'bg', 'cancel', 'focus', 'ink', 'line', 'muted', 'surface'];

function brandCssSource(): string
{
    return (string) file_get_contents(dirname(__DIR__, 3).'/resources/css/app.css');
}

/**
 * Body of the first `{ ... }` block that follows $opener (brace-matched).
 */
function brandCssBlock(string $css, string $opener): string
{
    $start = strpos($css, $opener);

    if ($start === false) {
        return '';
    }

    $open = strpos($css, '{', $start);

    if ($open === false) {
        return '';
    }

    $depth = 0;

    for ($i = $open, $length = strlen($css); $i < $length; $i++) {
        if ($css[$i] === '{') {
            $depth++;
        } elseif ($css[$i] === '}' && --$depth === 0) {
            return substr($css, $open + 1, $i - $open - 1);
        }
    }

    return '';
}

/**
 * @return array<string, string> token name => upper-case #RRGGBB
 */
function brandColourTokens(string $theme): array
{
    $block = brandCssBlock(brandCssSource(), $theme === 'dark' ? '@variant dark' : '@theme static');
    preg_match_all('/--color-([a-z-]+)\s*:\s*(#[0-9a-fA-F]{6})\s*;/', $block, $matches);

    $tokens = array_combine($matches[1], array_map('strtoupper', $matches[2]));
    ksort($tokens);

    return $tokens;
}

function brandRelativeLuminance(string $hex): float
{
    $channels = array_map(
        fn (string $pair): float => hexdec($pair) / 255,
        str_split(ltrim($hex, '#'), 2),
    );

    [$r, $g, $b] = array_map(
        fn (float $c): float => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4,
        $channels,
    );

    return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
}

function brandContrast(string $foreground, string $background): float
{
    $a = brandRelativeLuminance($foreground);
    $b = brandRelativeLuminance($background);

    return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
}

it('defines exactly the same ten colour tokens in the light and dark blocks', function () {
    expect(array_keys(brandColourTokens('light')))->toBe(BRAND_COLOUR_TOKENS)
        ->and(array_keys(brandColourTokens('dark')))->toBe(BRAND_COLOUR_TOKENS);
});

it('removes the default Tailwind palette', function () {
    expect(brandCssBlock(brandCssSource(), '@theme static'))->toMatch('/--color-\*\s*:\s*initial\s*;/');
});

it('meets AA for text pairs and 3:1 for focus and border pairs', function (string $theme) {
    $t = brandColourTokens($theme);

    expect($t)->toHaveKeys(BRAND_COLOUR_TOKENS);

    foreach (['ink', 'muted', 'accent', 'cancel'] as $text) {
        foreach (['bg', 'surface'] as $background) {
            expect(brandContrast($t[$text], $t[$background]))
                ->toBeGreaterThanOrEqual(4.5, "{$theme}: {$text} on {$background}");
        }
    }

    expect(brandContrast($t['accent'], $t['accent-soft']))->toBeGreaterThanOrEqual(4.5, "{$theme}: accent on accent-soft")
        ->and(brandContrast($t['accent-ink'], $t['accent']))->toBeGreaterThanOrEqual(4.5, "{$theme}: accent-ink on accent");

    foreach (['focus', 'line'] as $ui) {
        foreach (['bg', 'surface'] as $background) {
            expect(brandContrast($t[$ui], $t[$background]))
                ->toBeGreaterThanOrEqual(3.0, "{$theme}: {$ui} on {$background}");
        }
    }
})->with(['light', 'dark']);

it('pins the Norfolk green accents and proves the ratio function', function () {
    expect(brandColourTokens('light')['accent'] ?? null)->toBe('#2E6B3F')
        ->and(brandColourTokens('dark')['accent'] ?? null)->toBe('#86CF97')
        ->and(round(brandContrast('#2E6B3F', '#FFFFFF'), 2))->toBe(6.38)
        ->and(round(brandContrast('#86CF97', '#111614'), 2))->toBe(9.91);
});
