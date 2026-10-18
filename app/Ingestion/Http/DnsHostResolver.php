<?php

namespace App\Ingestion\Http;

/**
 * Production HostResolver: the host's A and AAAA records from DNS.
 *
 * Any lookup failure returns an empty list, which HttpFetcher treats as
 * "Host could not be resolved" (fail closed). The SSRF guard then requires
 * every returned address to be public and pins curl to the first one.
 */
final class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $host = rtrim(strtolower(trim($host)), '.');

        if ($host === '') {
            return [];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (! is_array($records)) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false) {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }
}
