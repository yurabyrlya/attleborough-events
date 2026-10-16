<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const FORMAT = '^[a-z0-9]+(-[a-z0-9]+)*$';

    private const MAX = 80;

    /** Mirrors App\Publishing\EventSlugger::RESERVED (self-contained on purpose). */
    private const RESERVED = ['feed', 'calendar', 'ics', 'rss', 'submit', 'new', 'search', 'api'];

    /**
     * Every event gets exactly one unique, well-formed slug (EVT-02).
     *
     * Existing rows are backfilled deterministically in id order: the stored
     * slug lowercased when it is already well formed, otherwise Str::slug of
     * the title, otherwise "event"; cut to 80 characters at a hyphen; the
     * first row keeps the bare slug and later rows get -2, -3 ... Safe on a
     * database that already holds real events.
     */
    public function up(): void
    {
        $taken = [];

        foreach (DB::table('events')->orderBy('id')->get(['id', 'title', 'slug']) as $row) {
            $candidate = $this->base((string) $row->title, (string) $row->slug);
            $slug = $candidate;

            for ($n = 2; isset($taken[$slug]); $n++) {
                $slug = $candidate.'-'.$n;
            }

            $taken[$slug] = true;

            if ($slug !== $row->slug) {
                DB::table('events')->where('id', $row->id)->update(['slug' => $slug]);
            }
        }

        Schema::table('events', function ($table) {
            $table->unique('slug');
        });

        DB::statement("ALTER TABLE events ADD CONSTRAINT events_slug_format CHECK (slug ~ '".self::FORMAT."')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_slug_format');

        Schema::table('events', function ($table) {
            $table->dropUnique(['slug']);
        });
    }

    private function base(string $title, string $stored): string
    {
        $slug = strtolower($stored);

        if (preg_match('/'.self::FORMAT.'/', $slug) !== 1) {
            $slug = Str::slug($title);
        }

        if ($slug === '') {
            $slug = 'event';
        }

        if (strlen($slug) > self::MAX) {
            $head = substr($slug, 0, self::MAX);
            $hyphen = $slug[self::MAX] === '-' ? self::MAX : strrpos($head, '-');
            $slug = rtrim($hyphen !== false && $hyphen > 0 ? substr($head, 0, $hyphen) : $head, '-');
        }

        return in_array($slug, self::RESERVED, true) ? $slug.'-event' : $slug;
    }
};
