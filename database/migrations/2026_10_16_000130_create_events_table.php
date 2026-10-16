<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The event row holds the LOCAL wall-clock schedule exactly as the admin
     * typed it (start_date/start_time/end_date/end_time in `timezone`).
     * UTC instants live on event_occurrences.
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug');
            $table->text('description');
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('venue_id')->constrained()->restrictOnDelete();
            $table->boolean('family_friendly')->default(false);
            $table->string('price_type', 16)->default('unknown');
            $table->string('price_text')->nullable();
            $table->string('organiser_name')->nullable();
            $table->string('organiser_contact')->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->text('source_note')->nullable();
            $table->string('timezone', 64)->default('Europe/London');
            $table->boolean('all_day')->default(false);
            $table->date('start_date');
            $table->time('start_time')->nullable();
            $table->date('end_date')->nullable();
            $table->time('end_time')->nullable();
            $table->text('recurrence_rule')->nullable();
            $table->string('status', 16);
            $table->string('origin', 16);
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at');
            $table->timestampsTz();

            $table->index('status');
        });

        DB::statement("ALTER TABLE events ADD CONSTRAINT events_price_type_check CHECK (price_type IN ('free', 'paid', 'unknown'))");
        DB::statement("ALTER TABLE events ADD CONSTRAINT events_status_check CHECK (status IN ('published', 'hidden', 'cancelled'))");
        DB::statement("ALTER TABLE events ADD CONSTRAINT events_origin_check CHECK (origin IN ('manual', 'submission', 'discovered'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
