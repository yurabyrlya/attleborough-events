<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A draft is a proposed event waiting for (or past) admin review. It
     * holds the LOCAL wall-clock schedule (start_date/start_time/end_date/
     * end_time in `timezone`), like events; every schedule and venue field is
     * nullable because drafts may be incomplete (D-01). Field data is written
     * only by App\Review\DraftWriter; review state only by the review actions
     * in App\Publishing\Actions (ApproveDraft and, later, Reject/Reopen).
     *
     * source_item_id and diff (spec §9) arrive with crawling in Phase 3.
     */
    public function up(): void
    {
        Schema::create('event_drafts', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 12)->unique();
            $table->string('origin', 16);
            $table->string('kind', 16)->default('new');
            $table->string('review_status', 16)->default('pending_review');
            $table->string('extraction_method', 16);
            $table->foreignId('event_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('duplicate_of_event_id')->nullable()->constrained('events')->nullOnDelete();

            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('category_reason', 255)->nullable();
            $table->boolean('category_flagged')->default(false);
            $table->foreignId('submitted_category_id')->nullable()->constrained('categories')->nullOnDelete();

            $table->foreignId('venue_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('venue_name', 120)->nullable();
            $table->string('venue_address', 255)->nullable();
            $table->string('venue_postcode', 10)->nullable();
            $table->string('venue_town', 100)->nullable();

            $table->string('timezone', 64)->default('Europe/London');
            $table->boolean('all_day')->default(false);
            $table->date('start_date')->nullable();
            $table->time('start_time')->nullable();
            $table->date('end_date')->nullable();
            $table->time('end_time')->nullable();
            $table->text('recurrence_rule')->nullable();
            $table->string('repeats_text', 255)->nullable();
            $table->boolean('repeats_acknowledged')->default(false);

            $table->boolean('family_friendly')->default(false);
            $table->string('price_type', 16)->default('unknown');
            $table->string('price_text', 255)->nullable();
            $table->string('organiser_name', 255)->nullable();
            $table->string('organiser_contact', 255)->nullable();
            $table->string('organiser_website', 2048)->nullable();
            $table->boolean('contact_public')->default(false);
            $table->string('source_url', 2048)->nullable();
            $table->text('source_note')->nullable();

            $table->jsonb('raw_payload')->nullable();
            $table->timestampTz('consented_at')->nullable();
            $table->smallInteger('quality_score')->nullable();
            $table->string('quality_band', 8)->nullable();
            $table->jsonb('quality_reasons')->default(DB::raw("'[]'::jsonb"));
            $table->jsonb('parse_errors')->default(DB::raw("'[]'::jsonb"));

            $table->string('reject_reason', 16)->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE event_drafts ADD CONSTRAINT event_drafts_origin_check CHECK (origin IN ('manual', 'submission', 'discovered'))");
        DB::statement("ALTER TABLE event_drafts ADD CONSTRAINT event_drafts_kind_check CHECK (kind IN ('new', 'update', 'cancellation'))");
        DB::statement("ALTER TABLE event_drafts ADD CONSTRAINT event_drafts_review_status_check CHECK (review_status IN ('pending_review', 'approved', 'rejected', 'duplicate', 'superseded'))");
        DB::statement("ALTER TABLE event_drafts ADD CONSTRAINT event_drafts_extraction_method_check CHECK (extraction_method IN ('submission', 'manual'))");
        DB::statement("ALTER TABLE event_drafts ADD CONSTRAINT event_drafts_price_type_check CHECK (price_type IN ('free', 'paid', 'unknown'))");
        DB::statement("ALTER TABLE event_drafts ADD CONSTRAINT event_drafts_quality_band_check CHECK (quality_band IS NULL OR quality_band IN ('high', 'medium', 'low'))");
        DB::statement('ALTER TABLE event_drafts ADD CONSTRAINT event_drafts_quality_score_check CHECK (quality_score IS NULL OR quality_score BETWEEN 0 AND 100)');
        DB::statement("ALTER TABLE event_drafts ADD CONSTRAINT event_drafts_reject_reason_check CHECK (reject_reason IS NULL OR reject_reason IN ('spam', 'out_of_area', 'not_an_event', 'past', 'duplicate', 'incomplete', 'other'))");
        DB::statement("ALTER TABLE event_drafts ADD CONSTRAINT event_drafts_reject_reason_only_when_rejected CHECK (reject_reason IS NULL OR review_status = 'rejected')");
        DB::statement("ALTER TABLE event_drafts ADD CONSTRAINT event_drafts_approved_has_event CHECK (review_status <> 'approved' OR event_id IS NOT NULL)");

        // D-09 queue order: soonest event first, then oldest draft.
        DB::statement('CREATE INDEX event_drafts_queue_order ON event_drafts (review_status, start_date, start_time, created_at, id)');

        // One "new" draft per published event: a concurrent second approval
        // linking the same event fails instead of creating two provenances.
        DB::statement("CREATE UNIQUE INDEX event_drafts_one_new_per_event ON event_drafts (event_id) WHERE kind = 'new'");
    }

    public function down(): void
    {
        Schema::dropIfExists('event_drafts');
    }
};
