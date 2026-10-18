<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One event as a source publishes it, keyed by the source's identity
     * strategy (UNIQUE source_id + external_id). Written only by the
     * ingestion pipeline (SourceItemRecorder, MissingItemDetector) and, for
     * the accepted baseline and review outcome, by the Publishing review
     * actions.
     *
     * raw_hash detects any byte change; content_hash detects a change to the
     * normalised facts. accepted_hash/accepted_payload are the baseline the
     * admin last accepted (approve, apply, ignore, mark duplicate, quiet
     * change). payload is internal only (D-17).
     *
     * current_draft_id (added in the next migration, because event_drafts
     * gains a source_item_id FK back to this table) is nullOnDelete:
     * EventDraft is MassPrunable, so pruning fires no model events and the
     * database must clear the link (Pitfall 12). Every instant is
     * timestampTz (UTC).
     */
    public function up(): void
    {
        Schema::create('source_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 255);
            $table->string('status', 16)->default('active');
            $table->text('raw_fragment')->nullable();
            $table->char('raw_hash', 64)->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->jsonb('payload')->nullable();
            $table->char('accepted_hash', 64)->nullable();
            $table->jsonb('accepted_payload')->nullable();
            $table->string('review_outcome', 16)->nullable();
            $table->timestampTz('last_known_start_at')->nullable();
            $table->foreignId('first_seen_run_id')->nullable()->constrained('source_runs')->nullOnDelete();
            $table->foreignId('last_seen_run_id')->nullable()->constrained('source_runs')->nullOnDelete();
            $table->timestampTz('first_seen_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->smallInteger('missing_runs')->default(0);
            $table->jsonb('parse_errors')->default(DB::raw("'[]'::jsonb"));
            $table->timestampsTz();

            $table->unique(['source_id', 'external_id']);
        });

        DB::statement("ALTER TABLE source_items ADD CONSTRAINT source_items_status_check CHECK (status IN ('active', 'cancelled', 'missing'))");
        DB::statement("ALTER TABLE source_items ADD CONSTRAINT source_items_review_outcome_check CHECK (review_outcome IS NULL OR review_outcome IN ('pending', 'approved', 'rejected', 'duplicate'))");
        DB::statement('ALTER TABLE source_items ADD CONSTRAINT source_items_missing_runs_check CHECK (missing_runs >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('source_items');
    }
};
