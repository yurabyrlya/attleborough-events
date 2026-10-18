<?php

namespace App\Ingestion\Http;

/**
 * Turns a host name into its A and AAAA addresses for the SSRF guard.
 *
 * Production binds DnsHostResolver (AppServiceProvider). Tests bind a fake
 * in tests/Pest.php for every suite, so no test performs real DNS.
 */
interface HostResolver
{
    /**
     * @return list<string> IPv4/IPv6 addresses; an empty list means the host
     *                      could not be resolved, and the fetch fails closed
     */
    public function resolve(string $host): array;
}
