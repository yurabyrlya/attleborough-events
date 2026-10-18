<?php

namespace App\Ingestion\Http;

use RuntimeException;

/**
 * A php://temp stream that refuses to hold more than $maxBytes, used as
 * Guzzle's `sink` so curl writes the DECODED body into it (T-03-05).
 *
 * Once more than $maxBytes have been written the next write fails, curl
 * aborts the transfer and overflowed() is true, so a huge or
 * decompression-bomb body never fills the disk. A plain resource (not a PSR
 * stream object) because Laravel's Http::fake also writes fake bodies into
 * resource sinks.
 */
final class CappedSink
{
    private const string FILTER = 'attleborough.capped_sink';

    private int $written = 0;

    private bool $overflowed = false;

    /**
     * @var resource
     */
    public readonly mixed $stream;

    public function __construct(public readonly int $maxBytes)
    {
        if (! in_array(self::FILTER, stream_get_filters(), true)) {
            stream_filter_register(self::FILTER, CappedSinkFilter::class);
        }

        $stream = fopen('php://temp', 'w+');

        if ($stream === false) {
            throw new RuntimeException('Could not open a temporary stream');
        }

        stream_filter_append($stream, self::FILTER, STREAM_FILTER_WRITE, $this);

        $this->stream = $stream;
    }

    /**
     * Count $bytes against the budget; false once it is exceeded.
     */
    public function take(int $bytes): bool
    {
        $this->written += $bytes;

        if ($this->written > $this->maxBytes) {
            $this->overflowed = true;

            return false;
        }

        return true;
    }

    public function overflowed(): bool
    {
        return $this->overflowed;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}
