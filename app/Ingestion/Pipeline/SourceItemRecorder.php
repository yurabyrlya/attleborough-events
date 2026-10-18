<?php

namespace App\Ingestion\Pipeline;

use App\Enums\DraftKind;
use App\Enums\SourceItemStatus;
use App\Ingestion\Data\ExtractedItem;
use App\Models\Source;
use App\Models\SourceItem;
use App\Models\SourceRun;
use App\Review\Data\DraftData;
use App\Review\DraftWriter;
use App\Support\TextLimit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Records one extracted item of a live run (03-05): one DB transaction per
 * item, returning the single bucket the item is counted in (SRC-07).
 *
 * Order:
 * 1. A known identity is marked seen in this run first (last_seen_run_id,
 *    last_seen_at, missing_runs 0), whatever bucket follows, so an item the
 *    feed still lists is never treated as missing (PIPE-08 precondition).
 * 2. No usable facts → skipped_parse_error (the item's parse errors kept).
 * 3. Past / far future (WindowClassifier, calendar.horizon_days, D-20) →
 *    skipped_past / skipped_far_future, never drafted.
 * 4. Known item, same raw_hash → unchanged (touch only).
 * 5. Known item, different raw_hash → the new raw/content hash and payload
 *    are stored and the item counts as changed (draft decisions: 03-09).
 * 6. New identity → a source item (active, review pending) and one pending
 *    New draft (DraftWriter::createFromSource).
 *
 * Skipped identities that are not yet known get no source item: an item
 * far in the future today must still be drafted when it enters the window.
 */
final class SourceItemRecorder
{
    public const string NEW = 'new';

    public const string CHANGED = 'changed';

    public const string UNCHANGED = 'unchanged';

    public const string SKIPPED_PAST = 'skipped_past';

    public const string SKIPPED_FAR_FUTURE = 'skipped_far_future';

    public const string SKIPPED_RULE = 'skipped_rule';

    public const string SKIPPED_PARSE_ERROR = 'skipped_parse_error';

    /**
     * The source_runs counter each bucket increments.
     */
    public const array COUNTER = [
        self::NEW => 'items_new',
        self::CHANGED => 'items_changed',
        self::UNCHANGED => 'items_unchanged',
        self::SKIPPED_PAST => 'skipped_past',
        self::SKIPPED_FAR_FUTURE => 'skipped_far_future',
        self::SKIPPED_RULE => 'skipped_rule',
        self::SKIPPED_PARSE_ERROR => 'skipped_parse_error',
    ];

    /** Grapheme limit of a draft description (PIPE-09, D-15). */
    public const int EXCERPT_LIMIT = 200;

    public function __construct(
        private readonly WindowClassifier $window,
        private readonly DraftWriter $drafts,
    ) {}

    /**
     * @return self::NEW|self::CHANGED|self::UNCHANGED|self::SKIPPED_PAST|self::SKIPPED_FAR_FUTURE|self::SKIPPED_RULE|self::SKIPPED_PARSE_ERROR
     */
    public function record(SourceRun $run, Source $source, ExtractedItem $item, CarbonImmutable $nowLondon): string
    {
        return DB::transaction(function () use ($run, $source, $item, $nowLondon): string {
            $nowUtc = $nowLondon->utc();

            $existing = SourceItem::query()
                ->where('source_id', $source->id)
                ->where('external_id', $item->externalId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $existing->forceFill([
                    'last_seen_run_id' => $run->id,
                    'last_seen_at' => $nowUtc,
                    'missing_runs' => 0,
                ]);
            }

            $bucket = $this->classify($item, $existing, $nowLondon);

            if ($existing !== null) {
                if ($bucket === self::SKIPPED_PARSE_ERROR) {
                    $existing->forceFill(['parse_errors' => $item->parseErrors]);
                }

                if ($bucket === self::CHANGED) {
                    $this->storeContent($existing, $item);
                }

                $existing->save();

                return $bucket;
            }

            if ($bucket === self::NEW) {
                $this->createWithDraft($run, $source, $item, $nowUtc);
            }

            return $bucket;
        });
    }

    /**
     * The bucket for an item, without writing anything.
     */
    private function classify(ExtractedItem $item, ?SourceItem $existing, CarbonImmutable $nowLondon): string
    {
        $facts = $item->facts;

        if ($facts === null) {
            return self::SKIPPED_PARSE_ERROR;
        }

        $windowClass = $this->window->classify($facts, $nowLondon);

        if ($windowClass === WindowClassifier::PAST) {
            return self::SKIPPED_PAST;
        }

        if ($windowClass === WindowClassifier::FAR_FUTURE) {
            return self::SKIPPED_FAR_FUTURE;
        }

        if ($existing === null) {
            return self::NEW;
        }

        return $existing->raw_hash === $item->rawHash ? self::UNCHANGED : self::CHANGED;
    }

    private function createWithDraft(SourceRun $run, Source $source, ExtractedItem $item, CarbonImmutable $nowUtc): void
    {
        $facts = $item->facts;

        if ($facts === null) {
            return;
        }

        $sourceItem = new SourceItem;
        $sourceItem->forceFill([
            'source_id' => $source->id,
            'external_id' => $item->externalId,
            'status' => SourceItemStatus::Active,
            'review_outcome' => 'pending',
            'first_seen_run_id' => $run->id,
            'last_seen_run_id' => $run->id,
            'first_seen_at' => $nowUtc,
            'last_seen_at' => $nowUtc,
            'missing_runs' => 0,
        ]);
        $this->storeContent($sourceItem, $item);
        $sourceItem->save();

        $data = DraftData::fromSourceItem(
            $facts,
            $source,
            $this->description($facts->description, $source),
            $item->parseErrors,
            $facts->toPayload(),
        );

        $this->drafts->createFromSource($data, DraftKind::New, $source, $sourceItem, $facts->categories);
    }

    private function storeContent(SourceItem $sourceItem, ExtractedItem $item): void
    {
        $sourceItem->forceFill([
            'raw_fragment' => $item->rawFragment,
            'raw_hash' => $item->rawHash,
            'content_hash' => $item->contentHash,
            'payload' => $item->facts?->toPayload(),
            'parse_errors' => $item->parseErrors,
            'last_known_start_at' => $item->facts?->nextInstanceStartUtc,
        ]);
    }

    /**
     * The draft description: the feed text cut to the excerpt limit, or the
     * D-21 fallback when the feed gives none.
     */
    private function description(?string $full, Source $source): string
    {
        $excerpt = $full === null ? '' : TextLimit::graphemes($full, self::EXCERPT_LIMIT);

        return $excerpt !== '' ? $excerpt : self::fallbackDescription($source);
    }

    /**
     * D-21: the neutral description for an item without one.
     */
    public static function fallbackDescription(Source $source): string
    {
        return 'Details from '.$source->name.'.';
    }
}
