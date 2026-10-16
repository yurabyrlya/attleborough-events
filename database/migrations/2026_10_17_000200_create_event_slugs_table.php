<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Slug history (EVT-02): every slug an event used to have, so old shared
     * links answer 301 to the current slug. A slug in here is never given to
     * another event (UNIQUE across the table, checked by EventSlugger against
     * events.slug too).
     */
    public function up(): void
    {
        Schema::create('event_slugs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 100)->unique();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('event_id');
        });

        DB::statement("ALTER TABLE event_slugs ADD CONSTRAINT event_slugs_slug_format CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('event_slugs');
    }
};
