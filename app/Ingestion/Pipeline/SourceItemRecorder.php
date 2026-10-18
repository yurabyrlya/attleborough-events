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
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Records one extracted item of a live run (03-05): one DB transaction per
 * item, returning the single bucket the item is counted in (SRC-07).
 * verdict() returns the same bucket for a preview run (D-01) without
 * writing anything.
 *
 * Order:
 * 1. A known identity is marked seen in this run first (last_seen_run_id,
 *    last_seen_at, missing_runs 0), whatever bucket follows, so an item the
 *    feed still lists is never treated as missing (PIPE-08 precondition).
 * 2. No usable facts → skipped_parse_error (the item's parse errors kept).
 * 3. A title the source's skip/keep rules drop (TitleRules, D-04) →
 *    skipped_rule, never drafted.
 * 4. Past / far future (WindowClassifier, calendar.horizon_days, D-20) →
 *    skipped_past / skipped_far_future, never drafted.
 * 5. Known item, same raw_hash → unchanged (touch only).
 * 6. Known item, different raw_hash → the new raw/content hash and payload
 *    are stored and the item counts as changed (draft decisions: 03-09).
 * 7. New identity → a source item (active, review pending) and one pending
 *    New draft (DraftWriter::createFromSource) whose description is the
 *    ExcerptBuilder excerpt, or the D-21 fallback when there is none.
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

            $bucket = $this->classify($source, $item, $existing, $nowLondon);

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
     * The bucket record() would put this item in, without writing or
     * locking anything: the D-01 preview's verdict. Shares classify() with
     * record(), so a preview and the live run that follows cannot drift.
     *
     * @return self::NEW|self::CHANGED|self::UNCHANGED|self::SKIPPED_PAST|self::SKIPPED_FAR_FUTURE|self::SKIPPED_RULE|self::SKIPPED_PARSE_ERROR
     */
    public function verdict(Source $source, ExtractedItem $item, CarbonImmutable $nowLondon): string
    {
        $existing = SourceItem::query()
            ->where('source_id', $source->id)
            ->where('external_id', $item->externalId)
            ->first();

        return $this->classify($source, $item, $existing, $nowLondon);
    }

    /**
     * The bucket for an item, without writing anything.
     *
     * @return self::NEW|self::CHANGED|self::UNCHANGED|self::SKIPPED_PAST|self::SKIPPED_FAR_FUTURE|self::SKIPPED_RULE|self::SKIPPED_PARSE_ERROR
     */
    private function classify(Source $source, ExtractedItem $item, ?SourceItem $existing, CarbonImmutable $nowLondon): string
    {
        $facts = $item->facts;

        if ($facts === null) {
            return self::SKIPPED_PARSE_ERROR;
        }

        if (! TitleRules::allows($source->title_rules, $facts->title)) {
            return self::SKIPPED_RULE;
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
     * The draft description: the excerpt (PIPE-09), or the D-21 fallback
     * when the feed text is empty or nothing is left after stripping.
     */
    private function description(?string $full, Source $source): string
    {
        return ExcerptBuilder::make($full) ?? self::fallbackDescription($source);
    }

    /**
     * D-21: the neutral description for an item without one.
     */
    public static function fallbackDescription(Source $source): string
    {
        return 'Details from '.$source->name.'.';
    }
}
