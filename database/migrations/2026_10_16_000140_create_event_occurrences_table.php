<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per concrete instance of an event, all instants in UTC.
     * recurrence_key is the London local date (Y-m-d) of the instance, so
     * changing only a series' time keeps keys (and admin-set statuses).
     */
    public function up(): void
    {
        Schema::create('event_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('recurrence_key', 32);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->timestampTz('expires_at');
            $table->string('status', 16)->default('scheduled');
            $table->timestampsTz();

            $table->unique(['event_id', 'recurrence_key'], 'event_occurrences_event_key_uq');
        });

        DB::statement("ALTER TABLE event_occurrences ADD CONSTRAINT event_occurrences_status_check CHECK (status IN ('scheduled', 'cancelled', 'hidden'))");
        DB::statement("CREATE INDEX event_occurrences_public_idx ON event_occurrences (starts_at, expires_at) WHERE status <> 'hidden'");
    }

    public function down(): void
    {
        Schema::dropIfExists('event_occurrences');
    }
};
