<?php

namespace App\Ingestion\Http;

/**
 * An RFC 9309 robots.txt parser and matcher (in-house: spatie/robots-txt
 * merges the '*' group into specific groups and ignores wildcards when
 * weighting rules, RESEARCH "Package Legitimacy Audit").
 *
 * - At most 512 KiB of the file is parsed (§2.5).
 * - A group is one or more User-agent lines followed by its rules. Product
 *   tokens match case-insensitively; every group naming the crawler is
 *   merged, and the '*' group applies only when none does (§2.2.1).
 * - The longest matching rule wins, measured in octets of the normalised
 *   pattern; when an Allow and a Disallow are equally long, Allow wins
 *   (§2.2.2). The answer therefore never depends on rule or group order.
 * - '*' matches any sequence of characters and a trailing '$' anchors the
 *   end (§2.2.3). Matching is a linear two-pointer wildcard walk, never a
 *   regex built from remote text (T-03-10).
 * - Percent-encoding is normalised on both sides before comparison.
 * - /robots.txt itself is always allowed (§2.2.2).
 * - Crawl-delay is not in RFC 9309; the largest value in the selected
 *   groups is read and clamped to [0, 86400] seconds.
 */
final readonly class RobotsRules
{
    public const int MAX_BYTES = 524288;

    public const int MAX_CRAWL_DELAY = 86400;

    /**
     * @param  list<array{agents: list<string>, rules: list<array{allow: bool, pattern: string}>, crawlDelay: ?int}>  $groups
     */
    private function __construct(private array $groups) {}

    public static function parse(string $body): self
    {
        $body = substr($body, 0, self::MAX_BYTES);

        if (str_starts_with($body, "\xEF\xBB\xBF")) {
            $body = substr($body, 3);
        }

        $groups = [];
        $current = null;
        $readingAgents = false;

        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $hash = strpos($line, '#');

            if ($hash !== false) {
                $line = substr($line, 0, $hash);
            }

            $colon = strpos($line, ':');

            if ($colon === false) {
                continue;
            }

            $key = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));

            if ($key === 'user-agent') {
                if (! $readingAgents) {
                    if ($current !== null) {
                        $groups[] = $current;
                    }

                    $current = ['agents' => [], 'rules' => [], 'crawlDelay' => null];
                    $readingAgents = true;
                }

                $current['agents'][] = self::productToken($value);

                continue;
            }

            if ($current === null) {
                // Rules before the first User-agent belong to no group.
                continue;
            }

            if ($key === 'allow' || $key === 'disallow') {
                $readingAgents = false;

                if ($value !== '') {
                    $current['rules'][] = ['allow' => $key === 'allow', 'pattern' => self::normalise($value)];
                }

                continue;
            }

            if ($key === 'crawl-delay') {
                $readingAgents = false;
                $delay = self::readCrawlDelay($value);

                if ($delay !== null) {
                    $current['crawlDelay'] = max($current['crawlDelay'] ?? 0, $delay);
                }
            }

            // Other keys (Sitemap, Host, …) neither end nor start a group.
        }

        if ($current !== null) {
            $groups[] = $current;
        }

        return new self($groups);
    }

    /**
     * Whether $path (path plus optional "?query") may be fetched.
     */
    public function allows(string $path, string $productToken): bool
    {
        if ($path === '') {
            $path = '/';
        }

        $path = self::normalise($path);

        if ($path === '/robots.txt') {
            return true;
        }

        $best = null;

        foreach ($this->selectedGroups($productToken) as $group) {
            foreach ($group['rules'] as $rule) {
                if (! self::matches($rule['pattern'], $path)) {
                    continue;
                }

                $length = strlen($rule['pattern']);

                if ($best === null
                    || $length > $best['length']
                    || ($length === $best['length'] && $rule['allow'])) {
                    $best = ['length' => $length, 'allow' => $rule['allow']];
                }
            }
        }

        return $best === null || $best['allow'];
    }

    /**
     * The Crawl-delay for the crawler in seconds, or null when none is set.
     */
    public function crawlDelay(string $productToken): ?int
    {
        $delay = null;

        foreach ($this->selectedGroups($productToken) as $group) {
            if ($group['crawlDelay'] !== null) {
                $delay = max($delay ?? 0, $group['crawlDelay']);
            }
        }

        return $delay;
    }

    /**
     * Every group naming the product token, or the '*' groups when none do.
     *
     * @return list<array{agents: list<string>, rules: list<array{allow: bool, pattern: string}>, crawlDelay: ?int}>
     */
    private function selectedGroups(string $productToken): array
    {
        $token = strtolower(self::productToken($productToken));

        $specific = array_values(array_filter(
            $this->groups,
            fn (array $group): bool => in_array($token, array_map(strtolower(...), $group['agents']), true),
        ));

        if ($specific !== []) {
            return $specific;
        }

        return array_values(array_filter(
            $this->groups,
            fn (array $group): bool => in_array('*', $group['agents'], true),
        ));
    }

    /**
     * "AttleboroughEventsBot/1.0 (+…)" → "AttleboroughEventsBot".
     */
    private static function productToken(string $value): string
    {
        return (string) preg_replace('/[\/\s].*$/s', '', trim($value));
    }

    private static function readCrawlDelay(string $value): ?int
    {
        if (preg_match('/^\d+(\.\d+)?$/', $value) !== 1) {
            return null;
        }

        $whole = explode('.', $value)[0];

        if (strlen($whole) > 6) {
            return self::MAX_CRAWL_DELAY;
        }

        return min(self::MAX_CRAWL_DELAY, (int) ceil((float) $value));
    }

    /**
     * RFC 9309 §2.2.2 normalisation: percent-escapes of unreserved ASCII
     * characters are decoded, other escapes are upper-cased, and octets
     * outside printable US-ASCII are percent-encoded. '*' and '$' pass
     * through untouched, so patterns and paths normalise the same way.
     */
    private static function normalise(string $value): string
    {
        $value = (string) preg_replace_callback(
            '/%([0-9A-Fa-f]{2})/',
            function (array $match): string {
                $char = chr((int) hexdec($match[1]));

                return preg_match('/^[A-Za-z0-9\-._~]$/', $char) === 1 ? $char : '%'.strtoupper($match[1]);
            },
            $value,
        );

        return (string) preg_replace_callback(
            '/[^\x21-\x7E]/',
            fn (array $match): string => '%'.strtoupper(bin2hex($match[0])),
            $value,
        );
    }

    /**
     * Whether the robots pattern matches $path from its first character: a
     * prefix match, or a whole-path match when the pattern ends in '$'.
     */
    private static function matches(string $pattern, string $path): bool
    {
        if (str_ends_with($pattern, '$')) {
            $pattern = substr($pattern, 0, -1);
        } else {
            $pattern .= '*';
        }

        $patternLength = strlen($pattern);
        $pathLength = strlen($path);
        $p = 0;
        $s = 0;
        $star = -1;
        $resume = 0;

        while ($s < $pathLength) {
            if ($p < $patternLength && $pattern[$p] === '*') {
                $star = $p++;
                $resume = $s;
            } elseif ($p < $patternLength && $pattern[$p] === $path[$s]) {
                $p++;
                $s++;
            } elseif ($star !== -1) {
                $p = $star + 1;
                $s = ++$resume;
            } else {
                return false;
            }
        }

        while ($p < $patternLength && $pattern[$p] === '*') {
            $p++;
        }

        return $p === $patternLength;
    }
}
