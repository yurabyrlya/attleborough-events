<?php

namespace App\Ingestion\Data;

use Carbon\CarbonImmutable;

/**
 * What a run should do with one known feed item (03-09, ChangeDetector).
 *
 * - none: nothing beyond storing the item's new content.
 * - quiet: only wording changed (D-06): the accepted payload follows the
 *   item, no draft.
 * - replace: the pending draft is rewritten in place (one pending draft per
 *   item, PIPE-06); its kind stays.
 * - update: a new kind-update draft for the linked event with the
 *   three-column diff.
 * - cancel: a new kind-cancellation draft (whole event, listed dates, or
 *   missing from the feed); it replaces a pending update draft.
 * - redraft: a new kind-new draft (a rejected item whose facts changed,
 *   D-23, or an undecided item that has no pending draft).
 * - supersede: the pending draft is marked superseded and nothing new is
 *   raised (back to the baseline, or a new draft whose item was cancelled
 *   or went missing).
 *
 * diff: update → {field: {published, before, now}} in ChangeDetector::FIELDS
 * order; cancel → {cancelled: {...}}, {cancelled_keys: [Y-m-d ...]} or
 * {status: {published, before: active, now: missing}}.
 * targetKeys: the cancelled London dates, ascending (empty = whole event).
 * relevantStartsAt: UTC instant that orders the queue (D-09).
 */
final readonly class ChangeVerdict
{
    public const string NONE = 'none';

    public const string QUIET = 'quiet';

    public const string REPLACE = 'replace';

    public const string UPDATE = 'update';

    public const string CANCEL = 'cancel';

    public const string REDRAFT = 'redraft';

    public const string SUPERSEDE = 'supersede';

    /**
     * @param  self::NONE|self::QUIET|self::REPLACE|self::UPDATE|self::CANCEL|self::REDRAFT|self::SUPERSEDE  $type
     * @param  array<string, mixed>  $diff
     * @param  list<string>  $targetKeys
     */
    public function __construct(
        public string $type,
        public array $diff = [],
        public array $targetKeys = [],
        public ?CarbonImmutable $relevantStartsAt = null,
    ) {}

    public static function none(): self
    {
        return new self(self::NONE);
    }

    /**
     * True when acting on the verdict writes or replaces a draft.
     */
    public function raisesDraft(): bool
    {
        return in_array($this->type, [self::REPLACE, self::UPDATE, self::CANCEL, self::REDRAFT], true);
    }
}
