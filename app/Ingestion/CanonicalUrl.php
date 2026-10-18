<?php

namespace App\Ingestion;

use League\Uri\Uri;

/**
 * The comparable form of an event page URL, for duplicate detection
 * (PIPE-05): two links to the same page give the same string.
 *
 * Lower-cases the scheme and host, strips a leading "www.", drops the
 * user info, the fragment and a default port (80 / 443), removes tracking
 * parameters (utm_*, mc_*, fbclid, gclid), sorts the remaining query
 * parameters and strips a trailing slash from the path. The root path
 * stays "/". The path keeps its case (paths are case-sensitive).
 *
 * Only http(s) URLs with a host have a canonical form; anything else
 * (javascript:, mailto:, relative or unparseable text) gives null, so it
 * can never match. Parsing uses league/uri, like SafeLinks' http/https
 * rule.
 */
final class CanonicalUrl
{
    private const array DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /** Query parameters dropped exactly (lower-cased name). */
    private const array TRACKING_PARAMS = ['fbclid', 'gclid'];

    /** Query parameters dropped by prefix (lower-cased name). */
    private const array TRACKING_PREFIXES = ['utm_', 'mc_'];

    public static function of(?string $url): ?string
    {
        $url = $url === null ? '' : trim($url);

        if ($url === '') {
            return null;
        }

        $uri = Uri::tryNew($url);

        if ($uri === null) {
            return null;
        }

        $scheme = strtolower((string) $uri->getScheme());

        if (! array_key_exists($scheme, self::DEFAULT_PORTS)) {
            return null;
        }

        $host = (string) preg_replace('/^www\./', '', strtolower((string) $uri->getHost()));

        if ($host === '') {
            return null;
        }

        $port = $uri->getPort();
        $port = $port === null || $port === self::DEFAULT_PORTS[$scheme] ? '' : ':'.$port;

        $path = rtrim($uri->getPath(), '/');
        $path = $path === '' ? '/' : $path;

        $query = self::query($uri->getQuery());

        return $scheme.'://'.$host.$port.$path.($query !== '' ? '?'.$query : '');
    }

    /**
     * The query without tracking parameters, pairs sorted (name, then
     * value) and kept as written otherwise.
     */
    private static function query(?string $query): string
    {
        if ($query === null || $query === '') {
            return '';
        }

        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            $name = strtolower(rawurldecode(str_replace('+', ' ', explode('=', $pair, 2)[0])));

            if (in_array($name, self::TRACKING_PARAMS, true) || self::hasTrackingPrefix($name)) {
                continue;
            }

            $pairs[] = $pair;
        }

        sort($pairs, SORT_STRING);

        return implode('&', $pairs);
    }

    private static function hasTrackingPrefix(string $name): bool
    {
        foreach (self::TRACKING_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
