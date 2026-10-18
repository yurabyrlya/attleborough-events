<?php

namespace App\Ingestion\Http;

use php_user_filter;

/**
 * Write filter behind CappedSink: passes bytes through until the sink's
 * budget is spent, then fails the write so curl aborts the transfer.
 */
final class CappedSinkFilter extends php_user_filter
{
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        $sink = $this->params;

        if (! $sink instanceof CappedSink) {
            return PSFS_ERR_FATAL;
        }

        while ($bucket = stream_bucket_make_writeable($in)) {
            if (! $sink->take($bucket->datalen)) {
                return PSFS_ERR_FATAL;
            }

            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
