<?php

namespace App\Support;

/**
 * Grapheme-safe text shortening (EVT-02 encoding). Counts and cuts user-
 * perceived characters with the intl grapheme functions, so an emoji ZWJ
 * sequence or a letter with a combining accent is never split and the
 * result is always valid UTF-8.
 */
final class TextLimit
{
    /**
     * With $collapse (meta/OG descriptions) every Unicode whitespace run
     * becomes one space. Without it (the .ics DESCRIPTION) line breaks are
     * kept: newlines are normalised to "\n" and only the ends are trimmed.
     * Longer than $max graphemes is cut to $max minus the length of $end,
     * right-trimmed, then $end is appended.
     */
    public static function graphemes(string $text, int $max, string $end = '…', bool $collapse = true): string
    {
        $text = $collapse
            ? trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $text))
            : trim(str_replace(["\r\n", "\r"], "\n", $text));

        $length = grapheme_strlen($text);

        if (! is_int($length) || $length <= $max) {
            return $text;
        }

        $keep = max(0, $max - (int) grapheme_strlen($end));
        $cut = grapheme_substr($text, 0, $keep);

        return rtrim(is_string($cut) ? $cut : '').$end;
    }
}
