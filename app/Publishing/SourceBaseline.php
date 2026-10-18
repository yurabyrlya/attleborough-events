<?php

namespace App\Publishing;

use App\Ingestion\Pipeline\ContentHasher;
use App\Models\SourceItem;

/**
 * Moves a source item's accepted baseline after the admin decides a
 * source change (REV-06, REV-07, PIPE-06). The next run compares the feed
 * with this baseline, so a decided change is not raised again.
 *
 * - moveToCurrent(): the whole current payload becomes the baseline
 *   (Apply an update, Ignore an update).
 * - moveCancelledPart(): only the cancelled facts (master cancelled flag
 *   and cancelled_keys) are copied from the payload into the baseline
 *   (Confirm or Ignore a cancellation). Any other change in the same
 *   snapshot stays a difference, and the next run raises it as an update
 *   (03-09 runs detection whenever content_hash ≠ accepted_hash). The
 *   hash is recomputed with ContentHasher::contentHashFromPayload, the one
 *   hashing contract ingestion uses, so when the cancellation was the only
 *   difference accepted_hash ends equal to content_hash.
 *
 * Callers pass an item they have already locked FOR UPDATE, last in the
 * draft → event → occurrence → item order. Writes source_items only.
 */
final readonly class SourceBaseline
{
    /** Payload keys that make up "the cancelled part" of an item. */
    public const array CANCELLED_PART = ['cancelled', 'cancelled_keys', 'status'];

    public function __construct(private ContentHasher $hasher) {}

    public function moveToCurrent(SourceItem $item): void
    {
        $item->forceFill([
            'accepted_hash' => $item->content_hash,
            'accepted_payload' => $item->payload,
        ])->save();
    }

    public function moveCancelledPart(SourceItem $item): void
    {
        $payload = is_array($item->payload) ? $item->payload : [];
        $accepted = is_array($item->accepted_payload) ? $item->accepted_payload : $payload;

        // 'status' is listed for completeness; payloads written by
        // NormalisedFacts carry no such key (an item's missing/cancelled
        // state lives in source_items.status), so only present keys move.
        foreach (self::CANCELLED_PART as $key) {
            if (array_key_exists($key, $payload)) {
                $accepted[$key] = $payload[$key];
            }
        }

        $item->forceFill([
            'accepted_payload' => $accepted,
            'accepted_hash' => $this->hasher->contentHashFromPayload($accepted),
        ])->save();
    }
}
