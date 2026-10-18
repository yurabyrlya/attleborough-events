<?php

namespace App\Ingestion\Http;

use App\Enums\FetchFailureKind;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use League\Uri\Uri;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * The single polite fetch path for every source URL (SRC-05, SRC-06).
 *
 * For each hop (the first URL and every redirect target, at most
 * ingest.max_redirects): URL policy + SSRF guard on every resolved address
 * → beforeHop(): robots.txt for that URL's host (RobotsPolicy; a disallowed
 * URL is a `blocked` result and is never requested) and a reserved host
 * slot (HostThrottle, at least max(ingest.min_host_delay, Crawl-delay)
 * after the host's previous request, slept inline with Sleep) → one GET
 * with the honest User-Agent, timeouts inside the overall fetch deadline,
 * the checked IP pinned with CURLOPT_RESOLVE and a decoded-byte cap →
 * manual redirect / 304 / 429-503 / >= 400 handling → BodyValidator.
 *
 * robots.txt itself is fetched through the same guard, throttle, pinned
 * transport, redirect limit and byte cap (fetchRobotsTxt()).
 *
 * It never throws for source problems: every remote failure is a typed
 * FetchResult. The one deliberate exception is HostBusy: when the host's
 * next slot is further away than ingest.max_inline_wait, or the wait would
 * leave less than one request timeout before the fetch deadline, the job
 * must release itself instead of sleeping (RESEARCH Pitfall 7).
 * Programming errors (bad client options) still throw. It never evades bot
 * protection: one User-Agent, no header spoofing, no challenge solving; a
 * wall is recorded as content_invalid.
 *
 * Transport: an explicit CurlHandler. Guzzle routes `stream => true`
 * requests to its PHP-stream handler, which ignores curl options, so the
 * DNS pin would silently disappear. Instead the body goes to a CappedSink
 * (decoded bytes, aborts past max_bytes) and is then read back in 64 KiB
 * chunks, which also enforces the cap under Http::fake.
 *
 * Deliberately not final: browser tests may bind a stub subclass.
 */
class HttpFetcher
{
    private const int CHUNK_BYTES = 65536;

    private const int RETRY_AFTER_CAP_SECONDS = 86400;

    public const string ACCEPT_ICS = 'text/calendar, text/plain;q=0.5, */*;q=0.1';

    public const string ACCEPT_ROBOTS = 'text/plain, */*;q=0.1';

    public function __construct(
        private readonly SsrfGuard $guard,
        private readonly BodyValidator $validator,
        private readonly RobotsPolicy $robots,
        private readonly HostThrottle $throttle,
    ) {}

    /**
     * "AttleboroughEventsBot/1.0 (+https://…/about-crawler)".
     */
    public static function userAgent(): string
    {
        return config('ingest.user_agent_product').' (+'.config('ingest.contact_url').')';
    }

    /**
     * @throws HostBusy when the host cannot be requested within the inline wait
     */
    public function fetch(FetchRequest $request): FetchResult
    {
        $clock = hrtime(true);
        $startedAt = $request->startedAt ?? CarbonImmutable::now();
        $maxRedirects = (int) config('ingest.max_redirects');
        $url = $request->url;

        for ($hop = 0; ; $hop++) {
            $result = $this->fetchHop($request, $url, $startedAt);

            if ($result instanceof RedirectTo) {
                if ($hop >= $maxRedirects) {
                    return FetchResult::failed(
                        FetchFailureKind::HttpStatus,
                        'Too many redirects (more than '.$maxRedirects.')',
                        $url,
                        httpStatus: $result->status,
                    )->withDuration($this->elapsedMs($clock));
                }

                $url = $result->url;

                continue;
            }

            return $result->withDuration($this->elapsedMs($clock));
        }
    }

    /**
     * One request: policy checks, the GET, and the response classified.
     */
    private function fetchHop(FetchRequest $request, string $url, CarbonImmutable $startedAt): FetchResult|RedirectTo
    {
        $pin = $this->guardHop($url);

        if ($pin instanceof FetchResult) {
            return $pin;
        }

        $refusal = $this->beforeHop($url, $request, $startedAt);

        if ($refusal instanceof FetchResult) {
            return $refusal;
        }

        $secondsLeft = $this->secondsLeft($startedAt);

        if ($secondsLeft <= 0) {
            return FetchResult::failed(FetchFailureKind::Timeout, 'Fetch deadline reached', $url);
        }

        $maxBytes = (int) config('ingest.max_bytes');
        $sink = new CappedSink($maxBytes);
        $headerTooLarge = false;

        try {
            $response = $this->send($url, $pin, $this->icsHeaders($request), $secondsLeft, $sink, $headerTooLarge);
        } catch (HttpClientException|TransferException $e) {
            $sink->close();

            if ($headerTooLarge || $sink->overflowed()) {
                return FetchResult::failed(FetchFailureKind::TooLarge, $this->tooLargeMessage($maxBytes), $url);
            }

            return $this->transportFailure($e, $url);
        }

        try {
            return $this->classify($request, $url, $response, $sink, $maxBytes);
        } catch (RuntimeException $e) {
            // The body stream broke mid-read (connection reset, timeout).
            return $this->transportFailure($e, $url);
        } finally {
            $sink->close();
        }
    }

    /**
     * Called before every hop's GET, after the SSRF guard: robots.txt for the
     * URL's host, then a reserved host slot. Returns a `blocked` result when
     * robots.txt disallows the URL (nothing is sent), null to go ahead. Time
     * spent here (the robots.txt request, inline host waits) counts against
     * the fetch deadline.
     *
     * @throws HostBusy
     */
    protected function beforeHop(string $url, FetchRequest $request, CarbonImmutable $startedAt): ?FetchResult
    {
        // A spent deadline is this fetch's problem, not the host's: never let
        // it pose as an unreachable robots.txt (which would cache disallow_all).
        if ($this->secondsLeft($startedAt) <= 0) {
            return FetchResult::failed(FetchFailureKind::Timeout, 'Fetch deadline reached', $url);
        }

        $verdict = $this->robots->check(
            $url,
            fn (string $robotsUrl): RobotsFetch => $this->fetchRobotsTxt($robotsUrl, $startedAt),
        );

        if (! $verdict->allowed) {
            return FetchResult::blocked($verdict->reason ?? 'robots.txt disallows this URL', $url);
        }

        $this->waitForSlot($url, $verdict->crawlDelay, $startedAt);

        return null;
    }

    /**
     * robots.txt through the same SSRF guard, host throttle, pinned transport
     * and byte cap as every other request, following at most
     * ingest.max_redirects redirects (RFC 9309 §2.3.1.2). robots.txt is
     * always allowed, so it is never itself robots-checked. Anything that
     * does not end in a response is RobotsFetch::unreachable().
     *
     * @throws HostBusy
     */
    protected function fetchRobotsTxt(string $robotsUrl, CarbonImmutable $startedAt): RobotsFetch
    {
        $maxRedirects = (int) config('ingest.max_redirects');
        $maxBytes = (int) config('ingest.max_bytes');
        $url = $robotsUrl;

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $pin = $this->guardHop($url);

            if ($pin instanceof FetchResult) {
                return RobotsFetch::unreachable();
            }

            $this->waitForSlot($url, null, $startedAt);

            $secondsLeft = $this->secondsLeft($startedAt);

            if ($secondsLeft <= 0) {
                return RobotsFetch::unreachable();
            }

            $sink = new CappedSink($maxBytes);
            $headerTooLarge = false;
            $headers = ['User-Agent' => self::userAgent(), 'Accept' => self::ACCEPT_ROBOTS];

            try {
                $response = $this->send($url, $pin, $headers, $secondsLeft, $sink, $headerTooLarge);
            } catch (HttpClientException|TransferException) {
                $sink->close();

                return RobotsFetch::unreachable();
            }

            try {
                $status = $response->status();

                if ($status >= 300 && $status < 400 && $status !== 304) {
                    $location = $response->header('Location');

                    if ($location === '') {
                        return RobotsFetch::unreachable();
                    }

                    $url = Uri::new($url)->resolve($location)->toString();

                    continue;
                }

                $body = $sink->overflowed() ? null : $this->readCapped($response, $maxBytes);

                if ($body === null) {
                    return RobotsFetch::unreachable();
                }

                return new RobotsFetch($status, $body, $this->headers($response));
            } catch (RuntimeException) {
                // The body stream broke mid-read (connection reset, timeout).
                return RobotsFetch::unreachable();
            } finally {
                $sink->close();
            }
        }

        return RobotsFetch::unreachable();
    }

    /**
     * Reserve the host's next slot and sleep until it, or throw HostBusy when
     * the wait is longer than ingest.max_inline_wait or would leave less
     * than one request timeout (ingest.timeout) before the fetch deadline.
     *
     * @throws HostBusy
     */
    private function waitForSlot(string $url, ?int $crawlDelay, CarbonImmutable $startedAt): void
    {
        $delay = max((int) config('ingest.min_host_delay'), $crawlDelay ?? 0);
        $maxWait = (int) min(
            (int) config('ingest.max_inline_wait'),
            floor($this->secondsLeft($startedAt) - (float) config('ingest.timeout')),
        );

        $wait = $this->throttle->reserve(HostThrottle::keyFor($url), $delay, $maxWait);

        if ($wait > 0) {
            Sleep::for($wait)->seconds();
        }
    }

    /**
     * URL policy and SSRF guard: every resolved address must be public.
     *
     * @return list<string>|FetchResult CURLOPT_RESOLVE entries, or the failure
     */
    private function guardHop(string $url): array|FetchResult
    {
        $refusal = $this->guard->checkUrl($url);

        if ($refusal !== null) {
            return FetchResult::failed(FetchFailureKind::SsrfBlocked, $refusal, $url);
        }

        $host = $this->guard->hostOf($url);
        $addresses = $this->guard->addresses($host);

        if ($addresses === []) {
            return FetchResult::failed(FetchFailureKind::Network, 'Host could not be resolved', $url);
        }

        foreach ($addresses as $address) {
            if (! $this->guard->isPublic($address)) {
                return FetchResult::failed(FetchFailureKind::SsrfBlocked, 'Host resolves to a non-public address', $url);
            }
        }

        // An IP literal is never resolved by curl, so there is nothing to pin.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        return $this->guard->resolveOptions($host, $this->guard->portOf($url), $addresses[0]);
    }

    /**
     * The feed request headers: honest User-Agent, Accept and conditional
     * validators when the caller has them.
     *
     * @return array<string, string>
     */
    private function icsHeaders(FetchRequest $request): array
    {
        $headers = [
            'User-Agent' => self::userAgent(),
            'Accept' => self::ACCEPT_ICS,
        ];

        if ($request->etag !== null && $request->etag !== '') {
            $headers['If-None-Match'] = $request->etag;
        }

        if ($request->lastModified !== null && $request->lastModified !== '') {
            $headers['If-Modified-Since'] = $request->lastModified;
        }

        return $headers;
    }

    /**
     * One GET through the explicit curl handler: no redirects, the address
     * pin, the capped sink and timeouts inside the time left.
     *
     * @param  list<string>  $pin
     * @param  array<string, string>  $headers
     */
    private function send(string $url, array $pin, array $headers, float $secondsLeft, CappedSink $sink, bool &$headerTooLarge): Response
    {
        $maxBytes = $sink->maxBytes;

        $curl = $pin === [] ? [] : [CURLOPT_RESOLVE => $pin];

        return Http::withoutRedirecting()
            ->setHandler(new CurlHandler)
            ->withHeaders($headers)
            ->connectTimeout(min((float) config('ingest.connect_timeout'), $secondsLeft))
            ->timeout(min((float) config('ingest.timeout'), $secondsLeft))
            ->withOptions([
                'decode_content' => true,
                'protocols' => ['http', 'https'],
                'sink' => $sink->stream,
                'curl' => $curl,
                // Refuse a declared oversize body before downloading it.
                'on_headers' => function (ResponseInterface $response) use ($maxBytes, &$headerTooLarge): void {
                    if ($this->declaredLength($response->getHeaderLine('Content-Length')) > $maxBytes) {
                        $headerTooLarge = true;

                        throw new RuntimeException('Declared body too large');
                    }
                },
            ])
            ->get($url);
    }

    private function classify(FetchRequest $request, string $url, Response $response, CappedSink $sink, int $maxBytes): FetchResult|RedirectTo
    {
        $status = $response->status();
        $headers = $this->headers($response);

        if ($status >= 300 && $status < 400 && $status !== 304) {
            $location = $response->header('Location');

            if ($location === '') {
                return FetchResult::failed(FetchFailureKind::HttpStatus, 'HTTP '.$status.' redirect without a Location', $url, httpStatus: $status, headers: $headers);
            }

            return new RedirectTo(Uri::new($url)->resolve($location)->toString(), $status);
        }

        if ($status === 304) {
            return FetchResult::notModified(
                $url,
                $headers,
                etag: $this->headerOrNull($response, 'ETag') ?? $request->etag,
                lastModified: $this->headerOrNull($response, 'Last-Modified') ?? $request->lastModified,
            );
        }

        if ($status === 429 || $status === 503) {
            return FetchResult::failed(
                FetchFailureKind::RateLimited,
                FetchFailureKind::RateLimited->getLabel().' (HTTP '.$status.')',
                $url,
                httpStatus: $status,
                headers: $headers,
                retryAfterSeconds: $this->retryAfter($response->header('Retry-After')),
            );
        }

        if ($status >= 400 || $status < 200) {
            return FetchResult::failed(FetchFailureKind::HttpStatus, 'HTTP '.$status, $url, httpStatus: $status, headers: $headers);
        }

        if ($sink->overflowed() || $this->declaredLength($response->header('Content-Length')) > $maxBytes) {
            return FetchResult::failed(FetchFailureKind::TooLarge, $this->tooLargeMessage($maxBytes), $url, httpStatus: $status, headers: $headers);
        }

        $body = $this->readCapped($response, $maxBytes);

        if ($body === null) {
            return FetchResult::failed(FetchFailureKind::TooLarge, $this->tooLargeMessage($maxBytes), $url, httpStatus: $status, headers: $headers);
        }

        $invalid = $this->validator->validate($request->method, $body);

        if ($invalid !== null) {
            return FetchResult::failed(FetchFailureKind::ContentInvalid, $invalid, $url, httpStatus: $status, body: $body, headers: $headers);
        }

        return FetchResult::ok(
            $body,
            $url,
            $status,
            $headers,
            etag: $this->headerOrNull($response, 'ETag'),
            lastModified: $this->headerOrNull($response, 'Last-Modified'),
        );
    }

    /**
     * The decoded body read in 64 KiB chunks; null once it passes $maxBytes.
     * Size is strlen() of decoded bytes, never characters or Content-Length.
     */
    private function readCapped(Response $response, int $maxBytes): ?string
    {
        $stream = $response->toPsrResponse()->getBody();

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $body = '';

        while (! $stream->eof()) {
            $chunk = $stream->read(self::CHUNK_BYTES);

            if ($chunk === '') {
                break;
            }

            $body .= $chunk;

            if (strlen($body) > $maxBytes) {
                return null;
            }
        }

        return $body;
    }

    /**
     * Retry-After as delta-seconds or an HTTP date, capped at 24 hours.
     */
    private function retryAfter(string $value): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            $seconds = strlen($value) > 9 ? self::RETRY_AFTER_CAP_SECONDS : (int) $value;
        } else {
            $date = DateTimeImmutable::createFromFormat(DATE_RFC7231, $value, new DateTimeZone('UTC'));

            if ($date === false) {
                return null;
            }

            $seconds = $date->getTimestamp() - CarbonImmutable::now()->getTimestamp();
        }

        return max(0, min(self::RETRY_AFTER_CAP_SECONDS, $seconds));
    }

    private function transportFailure(Exception $e, string $url): FetchResult
    {
        $text = strtolower($e->getMessage());

        if (str_contains($text, 'curl error 28') || str_contains($text, 'timed out') || str_contains($text, 'timeout')) {
            return FetchResult::failed(FetchFailureKind::Timeout, FetchFailureKind::Timeout->getLabel(), $url);
        }

        return FetchResult::failed(FetchFailureKind::Network, FetchFailureKind::Network->getLabel(), $url);
    }

    /**
     * Seconds left of the overall fetch deadline (may be zero or negative).
     */
    private function secondsLeft(CarbonImmutable $startedAt): float
    {
        $used = max(0.0, $startedAt->diffInMilliseconds(CarbonImmutable::now()) / 1000);

        return (float) config('ingest.fetch_deadline') - $used;
    }

    private function declaredLength(string $header): int
    {
        $header = trim($header);

        if (! ctype_digit($header)) {
            return 0;
        }

        return strlen($header) > 18 ? PHP_INT_MAX : (int) $header;
    }

    private function tooLargeMessage(int $maxBytes): string
    {
        return FetchFailureKind::TooLarge->getLabel().' (over '.$maxBytes.' bytes)';
    }

    private function headerOrNull(Response $response, string $name): ?string
    {
        $value = $response->header($name);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, string>
     */
    private function headers(Response $response): array
    {
        $headers = [];

        foreach ($response->headers() as $name => $values) {
            $headers[strtolower((string) $name)] = implode(', ', (array) $values);
        }

        return $headers;
    }

    private function elapsedMs(int|float $clock): int
    {
        return (int) round((hrtime(true) - $clock) / 1_000_000);
    }
}
