<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links drafts to the source item they came from and adds what review of
     * ingested changes needs:
     *  - source_id / source_item_id: written only by DraftWriter's
     *    source-aware methods (forceFill), never fillable.
     *  - target_recurrence_key: the London date one update/cancellation draft
     *    applies to (null = the whole event).
     *  - diff: field → {current, proposed, source} for update drafts.
     *  - relevant_starts_at: the UTC instant that orders the queue for
     *    update/cancellation drafts (D-09).
     *  - duplicate_of_draft_id / duplicate_reason / duplicate_score: a
     *    possible duplicate among pending drafts (url or fuzzy match).
     *  - excluded_dates (drafts and events): ICS EXDATE London dates the
     *    series skips (D-19), honoured by RecurrenceExpander.
     *
     * event_drafts_extraction_method_check gains 'ics' (ExtractionMethod::Ics)
     * and event_drafts_reject_reason_check gains 'ignored'.
     * event_drafts_one_pending_per_item allows one pending draft per source
     * item (PIPE-06). source_items.current_draft_id is nullOnDelete because
     * EventDraft is MassPrunable (Pitfall 12).
     */
    public function up(): void
    {
        Schema::table('event_drafts', function (Blueprint $table) {
            $table->foreignId('source_id')->nullable()->constrained('sources')->nullOnDelete();
            $table->foreignId('source_item_id')->nullable()->constrained('source_items')->nullOnDelete();
            $table->string('target_recurrence_key', 32)->nullable();
            $table->jsonb('diff')->nullable();
            $table->timestampTz('relevant_starts_at')->nullable();
            $table->foreignId('duplicate_of_draft_id')->nullable()->constrained('event_drafts')->nullOnDelete();
            $table->string('duplicate_reason', 16)->nullable();
            $table->decimal('duplicate_score', 4, 3)->nullable();
            $table->jsonb('excluded_dates')->default(DB::raw("'[]'::jsonb"));
        });

        DB::statement("ALTER TABLE event_drafts ADD CONSTRAINT event_drafts_duplicate_reason_check CHECK (duplicate_reason IS NULL OR duplicate_reason IN ('url', 'fuzzy'))");
        DB::statement('ALTER TABLE event_drafts ADD CONSTRAINT event_drafts_duplicate_score_check CHECK (duplicate_score IS NULL OR duplicate_score BETWEEN 0 AND 1)');

        DB::statement('ALTER TABLE event_drafts DROP CONSTRAINT event_drafts_extraction_method_check');
        DB::statement("ALTER TABLE event_drafts ADD CONSTRAINT event_drafts_extraction_method_check CHECK (extraction_method IN ('submission', 'manual', 'ics'))");
        DB::statement('ALTER TABLE event_drafts DROP CONSTRAINT event_drafts_reject_reason_check');
        DB::statement("ALTER TABLE event_drafts ADD CONSTRAINT event_drafts_reject_reason_check CHECK (reject_reason IS NULL OR reject_reason IN ('spam', 'out_of_area', 'not_an_event', 'past', 'duplicate', 'incomplete', 'other', 'ignored'))");

        // PIPE-06: one pending draft per source item, enforced by the database.
        DB::statement("CREATE UNIQUE INDEX event_drafts_one_pending_per_item ON event_drafts (source_item_id) WHERE review_status = 'pending_review'");
        DB::statement('CREATE INDEX event_drafts_source_item_id_index ON event_drafts (source_item_id)');

        Schema::table('source_items', function (Blueprint $table) {
            $table->foreignId('current_draft_id')->nullable()->constrained('event_drafts')->nullOnDelete();
        });

        Schema::table('events', function (Blueprint $table) {
            $table->jsonb('excluded_dates')->default(DB::raw("'[]'::jsonb"));
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('excluded_dates');
        });

        Schema::table('source_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_draft_id');
        });

        DB::statement('DROP INDEX IF EXISTS event_drafts_source_item_id_index');
        DB::statement('DROP INDEX IF EXISTS event_drafts_one_pending_per_item');

        DB::statement('ALTER TABLE event_drafts DROP CONSTRAINT event_drafts_reject_reason_check');
        DB::statement("ALTER TABLE event_drafts ADD CONSTRAINT event_drafts_reject_reason_check CHECK (reject_reason IS NULL OR reject_reason IN ('spam', 'out_of_area', 'not_an_event', 'past', 'duplicate', 'incomplete', 'other'))");
        DB::statement('ALTER TABLE event_drafts DROP CONSTRAINT event_drafts_extraction_method_check');
        DB::statement("ALTER TABLE event_drafts ADD CONSTRAINT event_drafts_extraction_method_check CHECK (extraction_method IN ('submission', 'manual'))");

        DB::statement('ALTER TABLE event_drafts DROP CONSTRAINT IF EXISTS event_drafts_duplicate_score_check');
        DB::statement('ALTER TABLE event_drafts DROP CONSTRAINT IF EXISTS event_drafts_duplicate_reason_check');

        Schema::table('event_drafts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('duplicate_of_draft_id');
            $table->dropConstrainedForeignId('source_item_id');
            $table->dropConstrainedForeignId('source_id');
            $table->dropColumn([
                'target_recurrence_key', 'diff', 'relevant_starts_at',
                'duplicate_reason', 'duplicate_score', 'excluded_dates',
            ]);
        });
    }
};
