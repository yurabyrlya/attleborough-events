<?php

namespace App\Ingestion\Http;

/**
 * What a robots.txt request returned, as RobotsPolicy needs it: the final
 * status, body and lower-cased headers, or $status = null when the file
 * could not be reached at all (network failure, timeout, refused address,
 * too many redirects, oversize body).
 */
final readonly class RobotsFetch
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public ?int $status,
        public string $body = '',
        public array $headers = [],
    ) {}

    public static function unreachable(): self
    {
        return new self(null);
    }
}
