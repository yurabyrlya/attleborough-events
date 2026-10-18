<?php

namespace App\Review;

use App\Enums\EventOrigin;
use App\Ingestion\Pipeline\ExcerptBuilder;
use App\Models\EventDraft;
use Closure;

/**
 * The public description rules for a discovered draft (PIPE-09, D-15,
 * D-17; verification VER-01): at most ExcerptBuilder::LIMIT (200)
 * characters and no phone number or email, whether it is the auto excerpt
 * or the admin's edit. Manual entries and public submissions keep their
 * own rules.
 *
 * Characters are graphemes, counted the way ExcerptBuilder cuts the
 * excerpt (TextLimit::graphemes), so an untouched excerpt with an emoji or
 * an accented letter always passes.
 *
 * The contact check is ExcerptBuilder::containsContact(), the strict rule
 * built from the patterns the excerpt strips. The loose CR-01 backstop
 * (ExcerptBuilder::mayContainContact(), e.g. any two 3+ digit groups) stays
 * a bulk-approve skip only: it would refuse harmless text such as
 * "Seasons 2026-2027" after a human has read it.
 *
 * One rule for the edit form (rule()), single approval and bulk approve
 * (problems(), via DraftPublishability).
 */
final class DiscoveredDescription
{
    public const int LIMIT = ExcerptBuilder::LIMIT;

    public const string HELP = 'Up to 200 characters. Phone numbers and emails are not allowed on discovered events.';

    public const string TOO_LONG = 'Shorten the description to 200 characters.';

    public const string HAS_CONTACT = 'Remove the phone number or email from the description.';

    public static function applies(mixed $draft): bool
    {
        return $draft instanceof EventDraft && $draft->origin === EventOrigin::Discovered;
    }

    public static function length(?string $text): int
    {
        $length = grapheme_strlen(trim((string) $text));

        return is_int($length) ? $length : mb_strlen(trim((string) $text));
    }

    /**
     * Problem id => plain message, in a fixed order; empty when the text
     * may be published as a discovered event's description.
     *
     * @return array<string, string>
     */
    public static function problems(?string $text): array
    {
        $problems = [];

        if (self::length($text) > self::LIMIT) {
            $problems['description_too_long'] = self::TOO_LONG;
        }

        if (ExcerptBuilder::containsContact($text)) {
            $problems['description_contact'] = self::HAS_CONTACT;
        }

        return $problems;
    }

    /**
     * The edit form's validation rule for a discovered draft's description.
     */
    public static function rule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $text = is_string($value) ? $value : null;
            $length = self::length($text);

            if ($length > self::LIMIT) {
                $fail("Discovered events can show up to 200 characters. Shorten the description (it has {$length}).");
            }

            if (ExcerptBuilder::containsContact($text)) {
                $fail('Remove the phone number or email from the description. Discovered events never show contacts from feeds.');
            }
        };
    }
}
