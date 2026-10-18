<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which sources list a published event (REV-05, LEG-04, D-10..D-12,
     * D-25). Written only by App\Publishing\Actions\LinkEventSource.
     *
     *  - is_primary: the main attribution. event_sources_one_primary allows
     *    at most one per event; LinkEventSource clears the old flag before
     *    setting a new one.
     *  - source_item_id: UNIQUE, so one feed item links one event at most.
     *    nullOnDelete keeps the attribution when the item is deleted with
     *    its history (sources are restrictOnDelete, so a source with links
     *    cannot be deleted at all).
     *  - linked_via: 'approved' (the draft was approved) or 'duplicate'
     *    (marked as a duplicate of an existing event).
     *  - item_url: the item's own page at link time (D-16), never cut.
     *  - linked_at: timestampTz (UTC).
     */
    public function up(): void
    {
        Schema::create('event_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_item_id')->nullable()->unique()->constrained('source_items')->nullOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->string('linked_via', 16);
            $table->string('item_url', 2048)->nullable();
            $table->foreignId('linked_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('linked_at');
            $table->timestampsTz();

            $table->index(['event_id', 'linked_at']);
            $table->index('source_id');
        });

        DB::statement("ALTER TABLE event_sources ADD CONSTRAINT event_sources_linked_via_check CHECK (linked_via IN ('approved', 'duplicate'))");
        DB::statement('CREATE UNIQUE INDEX event_sources_one_primary ON event_sources (event_id) WHERE is_primary');
    }

    public function down(): void
    {
        Schema::dropIfExists('event_sources');
    }
};
