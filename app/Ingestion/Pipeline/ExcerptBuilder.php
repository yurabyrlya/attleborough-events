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
 *
 * Review CR-01: UK numbers are also written with the area code in brackets
 * ("(01953) 452300") or with dots ("01953.452300"), and a 6-8 digit local
 * number ("Call 452300") is stripped when a phone word introduces it.
 * mayContainContact() is the loose deny-by-default backstop bulk approve
 * uses for whatever these patterns still miss.
 *
 * Verification VER-01: containsContact() is the strict twin, built from
 * the same patterns make() strips. The edit page and single approval of a
 * discovered draft refuse a description it matches (DiscoveredDescription).
 */
final class ExcerptBuilder
{
    public const int LIMIT = 200;

    private const string LABEL = '(?:\b(?:tel(?:ephone)?|phone|mob(?:ile)?|e-?mail)\b\s*[:.]?\s*)?';

    private const string EMAIL = '[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}';

    /**
     * +44 (optionally "(0)") or a leading 0 (optionally bracketed), then
     * 10-12 more digits. One space, hyphen or dot may follow each digit, and
     * a closing bracket may end the area code.
     */
    private const string UK_PHONE = '(?:\+44\s?(?:\(0\)\s?)?|\(?\b0)(?:\d\)?[\s.\-]?){8,10}\d(?!\d)';

    /** A local number without area code: 6-8 digits, single separators allowed. */
    private const string LOCAL_PHONE = '\b\d(?:[\s.\-]?\d){5,7}(?!\d)';

    /** Labels removed together with a local number. */
    private const string LOCAL_LABEL = '\b(?:tel(?:ephone)?|phone|mob(?:ile)?)\b\s*(?:on\s+)?[:.]?\s*';

    /** Verbs that stay ("Call locally") while the local number after them goes. */
    private const string LOCAL_VERB = '(\b(?:call|ring|text)(?:\s+us)?(?:\s+on)?\b\s*[:.]?\s*)';

    /**
     * Loose backstop: an @, a 6+ digit run, or two 3+ digit groups split by
     * spaces, dots, hyphens or a bracket.
     */
    private const string CONTACT_LIKE = '@|\d{6,}|\d{3,}[\s.\-)]+\d{3,}';

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
        $text = (string) preg_replace('/'.self::LOCAL_LABEL.self::LOCAL_PHONE.'/iu', ' ', $text);
        $text = (string) preg_replace('/'.self::LOCAL_VERB.self::LOCAL_PHONE.'/iu', '$1 ', $text);

        $text = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $text));
        $text = (string) preg_replace('/ ([,.;:!?])/u', '$1', $text);

        if (preg_match('/[\p{L}\p{N}]/u', $text) !== 1) {
            return null;
        }

        return TextLimit::graphemes($text, self::LIMIT);
    }

    /**
     * Whether a description holds a phone number or email address that
     * make() would strip: an email, a UK number, or a local number after a
     * phone word (VER-01). The strict rule behind the edit page and single
     * approval of a discovered draft, so it refuses only real contacts and
     * never ordinary text such as "Seasons 2026-2027" or "Open 0930-1630".
     */
    public static function containsContact(?string $text): bool
    {
        if ($text === null) {
            return false;
        }

        foreach ([self::EMAIL, self::UK_PHONE, self::LOCAL_LABEL.self::LOCAL_PHONE, self::LOCAL_VERB.self::LOCAL_PHONE] as $pattern) {
            if (preg_match('/'.$pattern.'/iu', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a description may still hold a phone number or email address
     * (review CR-01). Deliberately loose: a match only means a human has to
     * read the draft before it is published, so bulk approve skips it.
     * Everything containsContact() finds counts too (e.g. a number written
     * with a space after every digit), so the loose rule is never narrower.
     */
    public static function mayContainContact(?string $text): bool
    {
        return $text !== null
            && (preg_match('/'.self::CONTACT_LIKE.'/u', $text) === 1 || self::containsContact($text));
    }
}
