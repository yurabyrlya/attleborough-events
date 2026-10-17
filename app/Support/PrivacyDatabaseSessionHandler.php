<?php

namespace App\Support;

use Illuminate\Session\DatabaseSessionHandler;

/**
 * The database session handler without the visitor's IP address and user
 * agent (D-04, review CR-01). /privacy promises that the IP is only held in
 * the submission rate-limit count, for up to 24 hours, so the `sessions`
 * table must not keep it. Laravel's handler writes `ip_address` and
 * `user_agent` on every request; this one leaves both columns null.
 *
 * Registered as the "database" session driver in AppServiceProvider, so any
 * environment that uses database sessions gets it without extra settings.
 */
class PrivacyDatabaseSessionHandler extends DatabaseSessionHandler
{
    /**
     * Never add the request's IP address or user agent to the session row.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function addRequestInformation(&$payload): static
    {
        return $this;
    }
}
