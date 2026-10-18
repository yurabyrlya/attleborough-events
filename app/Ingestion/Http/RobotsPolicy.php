<?php

namespace App\Ingestion\Http;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * robots.txt for every URL the crawler requests (SRC-05, T-03-07).
 *
 * The host's robots.txt is read from the crawl_hosts cache when it is
 * younger than 24 h (RFC 9309 §2.4), otherwise fetched through the caller's
 * closure, which goes through HttpFetcher's own SSRF guard, host throttle,
 * redirect limit (5) and byte cap. The outcome is stored as one of:
 *
 * - rules: a 2xx; the body (at most 512 KiB) is parsed by RobotsRules.
 * - allow_all: a 4xx other than 401/403/429, e.g. 404 (§2.3.1.3).
 * - blocked: 401, 403, 429 or a bot-challenge marker (cf-mitigated header,
 *   "Just a moment", "challenge-platform") at any status. Stricter than the
 *   RFC by project rule: bot protection is never worked around.
 * - disallow_all: a 5xx, any other status, or no response at all
 *   (§2.3.1.4). Cached for one hour only, so a short outage does not stop
 *   the host for a whole day.
 */
class RobotsPolicy
{
    public const int CACHE_SECONDS = 86400;

    public const int ERROR_CACHE_SECONDS = 3600;

    public const string RULES = 'rules';

    public const string ALLOW_ALL = 'allow_all';

    public const string DISALLOW_ALL = 'disallow_all';

    public const string BLOCKED = 'blocked';

    /**
     * Parsed rules per host, keyed by the cached fetch time.
     *
     * @var array<string, array{fetchedAt: string, rules: RobotsRules}>
     */
    private array $parsed = [];

    /**
     * "AttleboroughEventsBot", the RFC 9309 product token of the User-Agent.
     */
    public static function productToken(): string
    {
        return explode('/', (string) config('ingest.user_agent_product'))[0];
    }

    /**
     * The robots.txt URL of the origin $url belongs to.
     */
    public static function robotsUrlFor(string $url): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return $scheme.'://'.HostThrottle::keyFor($url).'/robots.txt';
    }

    /**
     * @param  Closure(string): RobotsFetch  $fetchRobots  fetches robots.txt by URL
     */
    public function check(string $url, Closure $fetchRobots): RobotsVerdict
    {
        $host = HostThrottle::keyFor($url);
        $row = $this->cached($host);

        if ($row === null) {
            $row = $this->store($host, $fetchRobots(self::robotsUrlFor($url)));
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);
        $target = ($path === '' ? '/' : $path).(is_string($query) ? '?'.$query : '');

        if ($target === '/robots.txt') {
            return RobotsVerdict::allow($row['crawlDelay']);
        }

        return match ($row['status']) {
            self::ALLOW_ALL => RobotsVerdict::allow(),
            self::BLOCKED => RobotsVerdict::deny('robots.txt for '.$host.' is refused or behind bot protection'),
            self::DISALLOW_ALL => RobotsVerdict::deny('robots.txt for '.$host.' could not be fetched, so every URL is treated as disallowed'),
            default => $this->rulesFor($host, $row)->allows($target, self::productToken())
                ? RobotsVerdict::allow($row['crawlDelay'])
                : RobotsVerdict::deny('robots.txt disallows this URL', $row['crawlDelay']),
        };
    }

    /**
     * @return array{status: string, body: ?string, fetchedAt: string, crawlDelay: ?int}|null
     */
    private function cached(string $host): ?array
    {
        $row = DB::table('crawl_hosts')->where('host', $host)->first();

        if ($row === null || $row->robots_status === null || $row->robots_fetched_at === null) {
            return null;
        }

        $fetchedAt = CarbonImmutable::parse($row->robots_fetched_at, 'UTC');
        $ttl = $row->robots_status === self::DISALLOW_ALL ? self::ERROR_CACHE_SECONDS : self::CACHE_SECONDS;

        if ($fetchedAt->addSeconds($ttl)->lessThanOrEqualTo(CarbonImmutable::now())) {
            return null;
        }

        return [
            'status' => (string) $row->robots_status,
            'body' => $row->robots_body === null ? null : (string) $row->robots_body,
            'fetchedAt' => $fetchedAt->format('Y-m-d H:i:s'),
            'crawlDelay' => $row->crawl_delay_seconds === null ? null : (int) $row->crawl_delay_seconds,
        ];
    }

    /**
     * @return array{status: string, body: ?string, fetchedAt: string, crawlDelay: ?int}
     */
    private function store(string $host, RobotsFetch $fetch): array
    {
        $status = $this->classify($fetch);
        $body = null;
        $crawlDelay = null;

        if ($status === self::RULES) {
            // A text column holds valid UTF-8 without NUL bytes only.
            $body = str_replace("\0", '', mb_scrub(substr($fetch->body, 0, RobotsRules::MAX_BYTES), 'UTF-8'));
            $rules = RobotsRules::parse($body);
            $crawlDelay = $rules->crawlDelay(self::productToken());
        }

        $now = CarbonImmutable::now()->utc()->format('Y-m-d H:i:s');

        DB::table('crawl_hosts')->upsert([[
            'host' => $host,
            'next_allowed_at' => $now,
            'robots_status' => $status,
            'robots_body' => $body,
            'robots_fetched_at' => $now,
            'crawl_delay_seconds' => $crawlDelay,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['host'], ['robots_status', 'robots_body', 'robots_fetched_at', 'crawl_delay_seconds', 'updated_at']);

        if (isset($rules)) {
            $this->parsed[$host] = ['fetchedAt' => $now, 'rules' => $rules];
        }

        return ['status' => $status, 'body' => $body, 'fetchedAt' => $now, 'crawlDelay' => $crawlDelay];
    }

    private function classify(RobotsFetch $fetch): string
    {
        if ($fetch->status === null) {
            return self::DISALLOW_ALL;
        }

        if (isset($fetch->headers['cf-mitigated'])
            || stripos($fetch->body, 'Just a moment') !== false
            || stripos($fetch->body, 'challenge-platform') !== false) {
            return self::BLOCKED;
        }

        return match (true) {
            in_array($fetch->status, [401, 403, 429], true) => self::BLOCKED,
            $fetch->status >= 200 && $fetch->status < 300 => self::RULES,
            $fetch->status >= 400 && $fetch->status < 500 => self::ALLOW_ALL,
            default => self::DISALLOW_ALL,
        };
    }

    /**
     * @param  array{status: string, body: ?string, fetchedAt: string, crawlDelay: ?int}  $row
     */
    private function rulesFor(string $host, array $row): RobotsRules
    {
        $memo = $this->parsed[$host] ?? null;

        if ($memo !== null && $memo['fetchedAt'] === $row['fetchedAt']) {
            return $memo['rules'];
        }

        $rules = RobotsRules::parse($row['body'] ?? '');
        $this->parsed[$host] = ['fetchedAt' => $row['fetchedAt'], 'rules' => $rules];

        return $rules;
    }
}
