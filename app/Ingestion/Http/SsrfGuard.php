<?php

namespace App\Ingestion\Http;

/**
 * Server-side request forgery guard for every fetch hop (T-03-03, T-03-04).
 *
 * - checkUrl(): http/https only, no userinfo, port absent/80/443, a host.
 * - addresses(): resolves the host through the injected HostResolver (an IP
 *   literal is used as is). The caller requires EVERY address to be public.
 * - resolveOptions(): the CURLOPT_RESOLVE entry that pins curl to the
 *   address that was checked, so DNS cannot rebind between check and
 *   connect. Http::fake never runs curl options (RESEARCH Pitfall 9), so the
 *   builder is unit-tested directly.
 */
class SsrfGuard
{
    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * @return string|null why the URL is refused; null when it is allowed
     */
    public function checkUrl(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return 'Malformed URL';
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        if ($scheme !== 'http' && $scheme !== 'https') {
            return 'Only http and https URLs are fetched';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'URLs with a user name or password are not fetched';
        }

        if (! isset($parts['host']) || trim($parts['host'], '[]') === '') {
            return 'URL has no host';
        }

        if (isset($parts['port']) && ! in_array($parts['port'], [80, 443], true)) {
            return 'Only ports 80 and 443 are fetched';
        }

        return null;
    }

    /**
     * The host of an allowed URL, lower-cased and without IPv6 brackets.
     */
    public function hostOf(string $url): string
    {
        return strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
    }

    /**
     * The port the request will connect to: explicit, or the scheme default.
     */
    public function portOf(string $url): int
    {
        $port = parse_url($url, PHP_URL_PORT);

        if (is_int($port)) {
            return $port;
        }

        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'http' ? 80 : 443;
    }

    /**
     * Every address the host resolves to. An IP literal resolves to itself.
     *
     * @return list<string>
     */
    public function addresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        return array_values(array_unique($this->resolver->resolve($host)));
    }

    /**
     * True only for globally routable unicast addresses.
     *
     * PHP 8.4's FILTER_FLAG_GLOBAL_RANGE rejects private, loopback,
     * link-local, CGNAT, "this network", IETF-reserved, benchmarking,
     * documentation and IPv4-mapped loopback ranges, but accepts multicast
     * and NAT64 (64:ff9b::/96, which can embed 127.0.0.1); those are blocked
     * explicitly (RESEARCH Pattern 1, verified probe).
     */
    public function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, $this->globalRangeFlags()) === false) {
            return false;
        }

        $packed = inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            return (ord($packed[0]) & 0xF0) !== 0xE0; // 224.0.0.0/4 multicast
        }

        $hex = bin2hex($packed);

        return ! str_starts_with($hex, '0064ff9b') // 64:ff9b::/96 NAT64
            && ! str_starts_with($hex, 'ff');      // ff00::/8 multicast
    }

    /**
     * FILTER_FLAG_GLOBAL_RANGE behind a plain int: PHPStan's filter_var
     * extension wrongly infers that this flag can never fail (it types the
     * result non-falsy-string), which would mark the guard's refusal branch
     * as dead code.
     */
    private function globalRangeFlags(): int
    {
        return FILTER_FLAG_GLOBAL_RANGE;
    }

    /**
     * CURLOPT_RESOLVE entries pinning host:port to the checked address.
     *
     * @return list<string>
     */
    public function resolveOptions(string $host, int $port, string $ip): array
    {
        $address = str_contains($ip, ':') ? '['.$ip.']' : $ip;

        return [$host.':'.$port.':'.$address];
    }
}
