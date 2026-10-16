<?php

namespace App\Publishing;

use App\Models\Event;
use App\Models\EventSlug;
use Illuminate\Support\Str;

/**
 * The ONLY writer of events.slug (EVT-02). Called by PublishEvent and
 * UpdatePublishedEvent inside their transactions, before the event is saved.
 *
 * A slug is lowercase ASCII matching ^[a-z0-9]+(-[a-z0-9]+)*$: Str::slug of
 * the title (transliterated), "event" when that is empty, cut to 80
 * characters at a hyphen, with "-event" appended to a reserved word. The
 * first free candidate of base, base-2, base-3 ... wins. An event keeps its
 * current slug while that is still one of its title's candidates, so an
 * unrelated edit never moves the URL.
 *
 * History (event_slugs): when an existing event's slug changes, the old one
 * is kept for this event so /events/{old} answers 301; renaming back makes a
 * history slug current again and removes it from history. A slug held in
 * ANOTHER event's history counts as taken, so old shared links never land on
 * a different event. Phase 5 merges can move a duplicate's slugs onto the
 * kept event.
 *
 * The UNIQUE indexes on events.slug and event_slugs.slug make a concurrent
 * duplicate save fail rather than share a slug.
 */
final class EventSlugger
{
    public const MAX = 80;

    public const FALLBACK = 'event';

    /** @var list<string> */
    public const RESERVED = ['feed', 'calendar', 'ics', 'rss', 'submit', 'new', 'search', 'api'];

    public function assign(Event $event): void
    {
        $base = self::base($event->title);
        $current = $event->exists ? (string) $event->getOriginal('slug') : null;

        if ($current !== null && self::isCandidateOf($current, $base) && ! $this->isTaken($current, $event)) {
            $event->slug = $current;

            return;
        }

        $slug = $base;

        for ($n = 2; $this->isTaken($slug, $event); $n++) {
            $slug = $base.'-'.$n;
        }

        $event->slug = $slug;

        if ($current !== null && $current !== '' && $current !== $slug) {
            $this->remember($event, $current, $slug);
        }
    }

    /**
     * Keep the old slug for this event and drop the new one from its history
     * (renamed back). Runs inside the caller's transaction.
     */
    private function remember(Event $event, string $old, string $new): void
    {
        $event->slugHistory()->where('slug', $new)->delete();

        if (! EventSlug::query()->where('slug', $old)->exists()) {
            $event->slugHistory()->create(['slug' => $old]);
        }
    }

    /**
     * The un-suffixed slug for a title.
     */
    public static function base(string $title): string
    {
        $slug = Str::slug($title);

        if ($slug === '') {
            $slug = self::FALLBACK;
        }

        if (strlen($slug) > self::MAX) {
            $head = substr($slug, 0, self::MAX);
            $hyphen = $slug[self::MAX] === '-' ? self::MAX : strrpos($head, '-');
            $slug = rtrim($hyphen !== false && $hyphen > 0 ? substr($head, 0, $hyphen) : $head, '-');
        }

        return in_array($slug, self::RESERVED, true) ? $slug.'-'.self::FALLBACK : $slug;
    }

    private static function isCandidateOf(string $slug, string $base): bool
    {
        return $slug === $base || preg_match('/^'.preg_quote($base, '/').'-([2-9]|[1-9]\d+)$/', $slug) === 1;
    }

    /**
     * Taken when another event holds it as its current slug or in its history.
     */
    private function isTaken(string $slug, Event $event): bool
    {
        return Event::query()
            ->where('slug', $slug)
            ->when($event->exists, fn ($query) => $query->whereKeyNot($event->getKey()))
            ->exists()
            || EventSlug::query()
                ->where('slug', $slug)
                ->when($event->exists, fn ($query) => $query->where('event_id', '!=', $event->getKey()))
                ->exists();
    }
}
