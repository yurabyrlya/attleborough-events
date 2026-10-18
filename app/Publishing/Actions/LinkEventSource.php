<?php

namespace App\Publishing\Actions;

use App\Models\Event;
use App\Models\EventSource;
use App\Models\Source;
use App\Models\SourceItem;
use App\Models\User;
use App\Publishing\AdminGate;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The ONLY writer of event_sources (REV-05, LEG-04): links a published event
 * to a source that lists it, from ApproveDraft (linked_via approved) or a
 * duplicate decision (linked_via duplicate, 03-11).
 *
 * Primary attribution (D-12, D-25):
 *  - the event's first link becomes primary;
 *  - a later link from a source flagged "Organiser's own site"
 *    (is_organiser_source) takes primary from a non-organiser primary;
 *  - any other later link is "also listed by".
 *
 * Admin-only, checked before the transaction opens. Locks the events row
 * first (same order as UpdatePublishedEvent, ChangeEventStatus and
 * calendar sync), then the current primary link FOR UPDATE, and clears the
 * old flag before setting the new one (event_sources_one_primary allows one
 * primary per event). One link per source item is a UNIQUE constraint: a
 * second link for the same item is refused with nothing changed.
 */
final class LinkEventSource
{
    public const ITEM_ALREADY_LINKED = 'This feed item is already linked to an event.';

    /**
     * Review CR-03: what Approve and Mark as duplicate tell the admin when
     * the draft's feed item is already linked (e.g. an old rejected draft of
     * an item that was approved since).
     */
    public const ITEM_ALREADY_PUBLISHED = 'This feed item is already published as an event, so this draft cannot be published or linked again. Reject it instead.';

    /**
     * @param  string  $linkedVia  EventSource::VIA_APPROVED | EventSource::VIA_DUPLICATE
     *
     * @throws AuthorizationException when the actor is not the configured admin
     * @throws InvalidArgumentException when the item is already linked, belongs
     *                                  to another source or linkedVia is unknown
     */
    public function __invoke(Event $event, Source $source, ?SourceItem $item, string $linkedVia, User $actor): EventSource
    {
        AdminGate::authorize($actor, 'Only the admin can link event sources.');

        if (! in_array($linkedVia, EventSource::LINKED_VIA, true)) {
            throw new InvalidArgumentException("Unknown link type [{$linkedVia}].");
        }

        if ($item !== null && $item->source_id !== $source->id) {
            throw new InvalidArgumentException('The feed item belongs to another source.');
        }

        try {
            [$link, $demotedId] = DB::transaction(function () use ($event, $source, $item, $linkedVia, $actor): array {
                Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

                if ($item !== null && EventSource::query()->where('source_item_id', $item->id)->exists()) {
                    throw new InvalidArgumentException(self::ITEM_ALREADY_LINKED);
                }

                $primary = EventSource::query()
                    ->where('event_id', $event->id)
                    ->where('is_primary', true)
                    ->lockForUpdate()
                    ->first();

                $takesPrimary = $primary === null
                    || ($source->is_organiser_source && ! self::isOrganiserSource($primary));

                $demotedId = null;

                if ($takesPrimary && $primary !== null) {
                    // Cleared first: the partial unique index allows one primary.
                    $primary->forceFill(['is_primary' => false])->save();
                    $demotedId = $primary->id;
                }

                $link = new EventSource;
                $link->forceFill([
                    'event_id' => $event->id,
                    'source_id' => $source->id,
                    'source_item_id' => $item?->id,
                    'is_primary' => $takesPrimary,
                    'linked_via' => $linkedVia,
                    'item_url' => self::itemUrl($item),
                    'linked_by' => $actor->id,
                    'linked_at' => CarbonImmutable::now()->utc(),
                ])->save();

                return [$link, $demotedId];
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent link for the same item won the race (or a second
            // primary slipped past the lock): this call changed nothing.
            throw new InvalidArgumentException(self::ITEM_ALREADY_LINKED);
        }

        // Ids only. After the outermost commit, so a rolled-back approval
        // never logs a link.
        DB::afterCommit(fn () => Log::info('event source linked', [
            'event_source_id' => $link->id,
            'event_id' => $link->event_id,
            'source_id' => $link->source_id,
            'source_item_id' => $link->source_item_id,
            'is_primary' => $link->is_primary,
            'demoted_event_source_id' => $demotedId,
            'user_id' => $actor->id,
        ]));

        return $link;
    }

    private static function isOrganiserSource(EventSource $link): bool
    {
        return (bool) Source::query()->whereKey($link->source_id)->value('is_organiser_source');
    }

    /**
     * The item's own page (D-16) when it has one that fits the column;
     * never cut, so a too-long URL is simply not stored.
     */
    private static function itemUrl(?SourceItem $item): ?string
    {
        $url = $item?->payload['url'] ?? null;

        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);

        return $url !== '' && strlen($url) <= 2048 ? $url : null;
    }
}
