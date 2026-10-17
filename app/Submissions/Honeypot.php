<?php

namespace App\Submissions;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * The form's honeypot and single-use timing token (SUB-04, RESEARCH
 * Pattern 7 and Pitfall 5).
 *
 * The trap field must be PRESENT and empty: a bot that drops it is refused
 * just like one that fills it. The token is the encrypted time the form was
 * rendered plus a random nonce, so it can't be forged or altered; it must
 * be at least review.submission.min_seconds old (whole seconds) and at most
 * review.submission.max_age_minutes old, and it can be used once.
 *
 * check() returns null when everything is fine or one of these keys:
 * trap, missing, too_fast, too_old, reused.
 */
final class Honeypot
{
    public const FIELD = 'fax_number';

    public const TOKEN = 'form_token';

    /**
     * A fresh timing token for a rendered form.
     */
    public static function mintToken(): string
    {
        return Crypt::encryptString((string) json_encode([
            't' => now()->getTimestamp(),
            'n' => Str::random(16),
        ]));
    }

    /**
     * @param  array<string, mixed>  $input  the raw request input
     */
    public static function check(array $input): ?string
    {
        if (! array_key_exists(self::FIELD, $input)) {
            return 'missing';
        }

        $trap = $input[self::FIELD];

        if ($trap !== null && $trap !== '') {
            return 'trap';
        }

        $token = $input[self::TOKEN] ?? null;
        $issuedAt = is_string($token) ? self::issuedAt($token) : null;

        if (! is_string($token) || $issuedAt === null) {
            return 'missing';
        }

        $elapsed = now()->getTimestamp() - $issuedAt;

        if ($elapsed < (int) config('review.submission.min_seconds')) {
            return 'too_fast';
        }

        if ($elapsed > (int) config('review.submission.max_age_minutes') * 60) {
            return 'too_old';
        }

        if (Cache::has(self::cacheKey($token))) {
            return 'reused';
        }

        return null;
    }

    /**
     * Mark a token as spent. False when it was already spent (a race
     * between two identical posts): the caller must refuse.
     */
    public static function markUsed(string $token): bool
    {
        return Cache::add(
            self::cacheKey($token),
            true,
            now()->addMinutes((int) config('review.submission.max_age_minutes')),
        );
    }

    /**
     * The token's issue time, or null when it is not one of ours.
     */
    private static function issuedAt(string $token): ?int
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($payload) || ! is_int($payload['t'] ?? null) || ! is_string($payload['n'] ?? null)) {
            return null;
        }

        return $payload['t'];
    }

    private static function cacheKey(string $token): string
    {
        return 'submission-token:'.hash('sha256', $token);
    }
}
