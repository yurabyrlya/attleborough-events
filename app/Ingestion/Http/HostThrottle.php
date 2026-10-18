<?php

namespace App\Ingestion\Http;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cross-worker request spacing per host (SRC-05, D-02).
 *
 * reserve() locks the host's crawl_hosts row (SELECT … FOR UPDATE) and books
 * the earliest slot that is at least $delaySeconds after the host's latest
 * reserved request and not before next_allowed_at. The host's stored
 * Crawl-delay is never undercut, so robots.txt requests are spaced by it too.
 * It returns how long the caller must wait; the caller sleeps OUTSIDE the
 * transaction. A wait longer than $maxWaitSeconds books nothing and throws
 * HostBusy, so a refused worker does not push the host's schedule back.
 *
 * Instants are written as explicit UTC strings (query builder, no casts).
 */
class HostThrottle
{
    /**
     * The crawl_hosts key of a URL: the lower-case host, with ":port" only
     * when the port is not the scheme's default.
     */
    public static function keyFor(string $url): string
    {
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $port = parse_url($url, PHP_URL_PORT);
        $default = $scheme === 'http' ? 80 : 443;

        if (str_contains($host, ':')) {
            $host = '['.$host.']';
        }

        return is_int($port) && $port !== $default ? $host.':'.$port : $host;
    }

    /**
     * @return int seconds to wait before sending (0 = now)
     *
     * @throws HostBusy when the wait exceeds $maxWaitSeconds
     */
    public function reserve(string $host, int $delaySeconds, int $maxWaitSeconds): int
    {
        return DB::transaction(function () use ($host, $delaySeconds, $maxWaitSeconds): int {
            $now = CarbonImmutable::now()->utc();
            $stamp = $now->format('Y-m-d H:i:s');

            DB::table('crawl_hosts')->insertOrIgnore([
                'host' => $host,
                'next_allowed_at' => $stamp,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);

            $row = DB::table('crawl_hosts')->where('host', $host)->lockForUpdate()->first();

            $delay = max(0, $delaySeconds, (int) ($row->crawl_delay_seconds ?? 0));
            $slot = $now->max(CarbonImmutable::parse($row->next_allowed_at, 'UTC'));

            if ($row->last_request_at !== null) {
                $slot = $slot->max(CarbonImmutable::parse($row->last_request_at, 'UTC')->addSeconds($delay));
            }

            $wait = max(0, $slot->getTimestamp() - $now->getTimestamp());

            if ($wait > max(0, $maxWaitSeconds)) {
                throw new HostBusy($host, $wait);
            }

            DB::table('crawl_hosts')->where('host', $host)->update([
                'last_request_at' => $slot->format('Y-m-d H:i:s'),
                'next_allowed_at' => $slot->addSeconds($delay)->format('Y-m-d H:i:s'),
                'updated_at' => $stamp,
            ]);

            return $wait;
        });
    }
}
