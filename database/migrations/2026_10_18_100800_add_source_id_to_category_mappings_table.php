<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-source category mappings (EXT-01, conflict C11): a feed's own
     * label ('Worship') can map for that one source without affecting the
     * others. source_id null = every source (the Phase 2 global mappings).
     *
     * The unique key widens from lower(source_value) to
     * (coalesce(source_id, 0), lower(source_value)), so a value is unique
     * once globally and once per source. coalesce() makes two global rows
     * collide (NULLs would never be equal); source ids start at 1. SaveRules
     * checks the same key on the fully normalised value first and refuses in
     * plain English. CategoryAssigner prefers a source's own mapping.
     */
    public function up(): void
    {
        Schema::table('category_mappings', function (Blueprint $table) {
            $table->foreignId('source_id')->nullable()->after('category_id')->constrained('sources')->cascadeOnDelete();
        });

        DB::statement('DROP INDEX category_mappings_source_value_unique');
        DB::statement('CREATE UNIQUE INDEX category_mappings_source_value_unique ON category_mappings (coalesce(source_id, 0), lower(source_value))');
    }

    /**
     * Fails on the narrower index while per-source rows repeat a value;
     * remove those first.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX category_mappings_source_value_unique');

        Schema::table('category_mappings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_id');
        });

        DB::statement('CREATE UNIQUE INDEX category_mappings_source_value_unique ON category_mappings (lower(source_value))');
    }
};
