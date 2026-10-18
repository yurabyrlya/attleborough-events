<?php

namespace App\Ingestion\Http;

use App\Enums\FetchFailureKind;

/**
 * What HttpFetcher found. Never an exception: a remote problem is a
 * `failed` result with a FetchFailureKind, robots.txt refusal is `blocked`.
 *
 * $message is built from enum labels and status codes only, never from the
 * response text (T-03-06). $body is kept on content_invalid results so the
 * run can store what the site actually sent; it is null when the body was
 * never read (or was too large).
 */
final readonly class FetchResult
{
    public const string OK = 'ok';

    public const string NOT_MODIFIED = 'not_modified';

    public const string FAILED = 'failed';

    public const string BLOCKED = 'blocked';

    /**
     * @param  array<string, string>  $headers  lower-cased names
     */
    private function __construct(
        public string $outcome,
        public ?FetchFailureKind $failureKind,
        public ?int $httpStatus,
        public ?string $body,
        public array $headers,
        public string $finalUrl,
        public int $bytes,
        public int $durationMs,
        public ?int $retryAfterSeconds,
        public ?string $etag,
        public ?string $lastModified,
        public ?string $message,
    ) {}

    /**
     * @param  array<string, string>  $headers
     */
    public static function ok(
        string $body,
        string $finalUrl,
        int $httpStatus = 200,
        array $headers = [],
        int $durationMs = 0,
        ?string $etag = null,
        ?string $lastModified = null,
    ): self {
        return new self(self::OK, null, $httpStatus, $body, $headers, $finalUrl, strlen($body), $durationMs, null, $etag, $lastModified, null);
    }

    /**
     * @param  array<string, string>  $headers
     */
    public static function notModified(
        string $finalUrl,
        array $headers = [],
        int $durationMs = 0,
        ?string $etag = null,
        ?string $lastModified = null,
    ): self {
        return new self(self::NOT_MODIFIED, null, 304, null, $headers, $finalUrl, 0, $durationMs, null, $etag, $lastModified, null);
    }

    /**
     * @param  array<string, string>  $headers
     */
    public static function failed(
        FetchFailureKind $kind,
        string $message,
        string $finalUrl,
        ?int $httpStatus = null,
        ?string $body = null,
        array $headers = [],
        int $durationMs = 0,
        ?int $retryAfterSeconds = null,
    ): self {
        return new self(self::FAILED, $kind, $httpStatus, $body, $headers, $finalUrl, $body === null ? 0 : strlen($body), $durationMs, $retryAfterSeconds, null, null, $message);
    }

    public static function blocked(string $message, string $finalUrl, int $durationMs = 0): self
    {
        return new self(self::BLOCKED, null, null, null, [], $finalUrl, 0, $durationMs, null, null, null, $message);
    }

    /**
     * The same result with the total fetch time filled in.
     */
    public function withDuration(int $durationMs): self
    {
        return new self(
            $this->outcome, $this->failureKind, $this->httpStatus, $this->body, $this->headers, $this->finalUrl,
            $this->bytes, $durationMs, $this->retryAfterSeconds, $this->etag, $this->lastModified, $this->message,
        );
    }

    public function isOk(): bool
    {
        return $this->outcome === self::OK;
    }

    public function isNotModified(): bool
    {
        return $this->outcome === self::NOT_MODIFIED;
    }

    public function isFailed(): bool
    {
        return $this->outcome === self::FAILED;
    }

    public function isBlocked(): bool
    {
        return $this->outcome === self::BLOCKED;
    }
}
