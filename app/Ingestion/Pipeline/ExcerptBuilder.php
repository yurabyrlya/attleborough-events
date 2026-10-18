<?php

namespace App\Ingestion\Pipeline;

use App\Support\TextLimit;

/**
 * The description of a discovered draft (PIPE-09, D-15): plain text, with
 * email addresses, UK phone numbers and the labels in front of them removed,
 * at most 200 graphemes including the ellipsis (TextLimit::graphemes, so an
 * emoji ZWJ sequence or an accented letter is never split).
 *
 * Only the excerpt reaches the draft; the full feed text stays internal
 * (raw_payload['description'], source_items.payload). Returns null when
 * nothing readable is left, so the caller uses the D-21 fallback.
 *
 * Patterns: RESEARCH Pattern 12, probed on sample strings. A label word
 * (tel, phone, mobile, email ...) is removed only together with the
 * contact it introduces, so "Bring your phone" survives.
 */
final class ExcerptBuilder
{
    public const int LIMIT = 200;

    private const string LABEL = '(?:\b(?:tel(?:ephone)?|phone|mob(?:ile)?|e-?mail)\b\s*[:.]?\s*)?';

    private const string EMAIL = '[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}';

    private const string UK_PHONE = '(?:\+44\s?\(0\)\s?|\+44\s?|\b0)(?:\d[\s\-]?){9,10}\d?\b';

    public static function make(?string $full): ?string
    {
        if ($full === null) {
            return null;
        }

        // A space before each tag keeps "<p>One</p><p>Two</p>" two words.
        $text = strip_tags(str_replace('<', ' <', $full));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $text = (string) preg_replace('/'.self::LABEL.self::EMAIL.'/iu', ' ', $text);
        $text = (string) preg_replace('/'.self::LABEL.self::UK_PHONE.'/iu', ' ', $text);

        $text = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $text));
        $text = (string) preg_replace('/ ([,.;:!?])/u', '$1', $text);

        if (preg_match('/[\p{L}\p{N}]/u', $text) !== 1) {
            return null;
        }

        return TextLimit::graphemes($text, self::LIMIT);
    }
}
