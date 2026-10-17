<?php

namespace App\Review;

use App\Models\EventDraft;

/**
 * Draft references the admin and organisers can read aloud: "WOA-" plus six
 * characters from an alphabet without 0, O, 1, I or L.
 *
 * Regenerates while the reference is taken; the UNIQUE index on
 * event_drafts.reference is the backstop for a concurrent clash.
 */
final class ReferenceCode
{
    public const PREFIX = 'WOA-';

    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const LENGTH = 6;

    public static function unique(): string
    {
        do {
            $reference = self::generate();
        } while (EventDraft::query()->where('reference', $reference)->exists());

        return $reference;
    }

    private static function generate(): string
    {
        $last = strlen(self::ALPHABET) - 1;
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $last)];
        }

        return self::PREFIX.$code;
    }
}
