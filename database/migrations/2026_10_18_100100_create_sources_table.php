<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A source is an external site the crawler checks for events (SRC-01).
     * Configuration columns (name ... is_organiser_source) are edited by the
     * admin through the Filament source form; is_active changes only through
     * the "Create drafts" activation and "Pause" actions (D-01). Health,
     * conditional-GET and scheduling columns (health, consecutive_failures,
     * etag, last_modified, next_check_at, last_*_at, last_items_found,
     * structure_alert_at) are written only by the ingestion pipeline.
     *
     * Phase 3 only extracts ICS feeds; Phase 4 widens
     * sources_extraction_method_check together with ExtractionMethod.
     * Every instant is timestampTz (UTC); check_time is a London wall-clock
     * time (D-02, early morning).
     */
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->string('short_name', 40);
            $table->string('url', 2048);
            $table->string('events_page_url', 2048)->nullable();
            $table->string('extraction_method', 16);
            $table->string('adapter', 32)->nullable();
            // {"identity": "uid" | "tec_post_id" | "summary_start"}
            $table->jsonb('parser_config')->default(DB::raw("'{\"identity\":\"uid\"}'::jsonb"));
            $table->foreignId('default_category_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->foreignId('default_venue_id')->nullable()->constrained('venues')->restrictOnDelete();
            $table->string('location_mode', 16)->default('local');
            $table->string('default_timezone', 64)->default('Europe/London');
            $table->string('year_policy', 16)->default('none');
            $table->text('legal_basis')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('supports_missing_detection')->default(false);
            $table->boolean('is_active')->default(false);
            $table->string('check_frequency', 16)->default('daily');
            $table->time('check_time')->default('05:30');
            // [{"mode": "skip" | "keep", "keyword": "..."}] (D-04)
            $table->jsonb('title_rules')->default(DB::raw("'[]'::jsonb"));
            // "Organiser's own site": the main attribution when two sources link one event (D-25).
            $table->boolean('is_organiser_source')->default(false);

            $table->string('health', 16)->default('healthy');
            $table->smallInteger('consecutive_failures')->default(0);
            $table->string('etag', 255)->nullable();
            $table->string('last_modified', 64)->nullable();
            $table->timestampTz('next_check_at')->nullable();
            $table->timestampTz('last_run_at')->nullable();
            $table->timestampTz('last_success_at')->nullable();
            $table->integer('last_items_found')->nullable();
            $table->timestampTz('structure_alert_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE sources ADD CONSTRAINT sources_extraction_method_check CHECK (extraction_method IN ('ics'))");
        DB::statement("ALTER TABLE sources ADD CONSTRAINT sources_location_mode_check CHECK (location_mode IN ('local', 'filter'))");
        DB::statement("ALTER TABLE sources ADD CONSTRAINT sources_check_frequency_check CHECK (check_frequency IN ('daily', 'weekly'))");
        DB::statement("ALTER TABLE sources ADD CONSTRAINT sources_health_check CHECK (health IN ('healthy', 'degraded', 'unhealthy'))");
        DB::statement('ALTER TABLE sources ADD CONSTRAINT sources_consecutive_failures_check CHECK (consecutive_failures >= 0)');

        // Due-source query in ingest:dispatch-due.
        DB::statement('CREATE INDEX sources_due ON sources (next_check_at) WHERE is_active');
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
