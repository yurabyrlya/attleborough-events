<?php

namespace App\Submissions;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Server-side Cloudflare Turnstile check for the public form (SUB-04, D-17).
 *
 * In-house on Laravel's Http client so tests stay offline (Http::fake the
 * SITEVERIFY_URL; preventStrayRequests() stays on). It fails closed: a
 * missing secret, an empty or oversized token, a network error, an
 * unexpected answer, the wrong action or the wrong hostname all refuse.
 *
 * Deliberately not final: browser tests bind a passing subclass with
 * app()->instance() because a real browser can't reach Cloudflare offline.
 */
class TurnstileVerifier
{
    public const SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /**
     * Turnstile tokens are at most 2048 characters (Cloudflare docs).
     */
    public const MAX_TOKEN_LENGTH = 2048;

    /**
     * Verify the widget's token for this visitor.
     *
     * @return list<string> error codes; an empty list means it passed
     */
    public function verify(?string $token, string $ip): array
    {
        $secret = config('services.turnstile.secret');

        if (! is_string($secret) || $secret === '') {
            return ['missing-input-secret'];
        }

        if (! is_string($token) || $token === '' || strlen($token) > self::MAX_TOKEN_LENGTH) {
            return ['missing-input-response'];
        }

        try {
            $body = Http::asForm()->acceptJson()->timeout(5)->post(self::SITEVERIFY_URL, [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $ip,
                'idempotency_key' => (string) Str::uuid(),
            ])->throw()->json();
        } catch (Throwable) {
            return ['internal-error'];
        }

        if (! is_array($body)) {
            return ['internal-error'];
        }

        if (($body['success'] ?? false) !== true) {
            $codes = array_values(array_filter((array) ($body['error-codes'] ?? []), 'is_string'));

            return $codes === [] ? ['invalid-input-response'] : $codes;
        }

        // Cloudflare's testing keys answer without action or a real hostname;
        // trust them for local development only, never in production.
        if (($body['metadata']['result_with_testing_key'] ?? false) === true && ! app()->isProduction()) {
            return [];
        }

        if (($body['action'] ?? null) !== config('services.turnstile.action', 'submit')) {
            return ['action-mismatch'];
        }

        $hostnames = (array) config('services.turnstile.hostnames', []);

        if ($hostnames !== [] && ! in_array($body['hostname'] ?? null, $hostnames, true)) {
            return ['hostname-mismatch'];
        }

        return [];
    }
}
