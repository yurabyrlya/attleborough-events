<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The admin's duplicate decisions (D-13 memory, REV-05). Written only by
     * App\Publishing\Actions\MarkDraftDuplicate ('duplicate') and
     * RecordNotDuplicate ('not_duplicate'); DuplicateDetector reads the
     * 'not_duplicate' rows so the same pair is never flagged again.
     *
     *  - Subject: the ingested source item (stable across re-drafts) when
     *    there is one, else the draft itself. Exactly one
     *    (duplicate_decisions_one_subject).
     *  - Candidate: the published event or the pending draft it was
     *    compared with. Exactly one (duplicate_decisions_one_candidate).
     *  - draft_id cascades: EventDraft is MassPrunable, so no model events
     *    fire when drafts are purged (Pitfall 12).
     *  - duplicate_decisions_pair: one row per (subject, candidate), with
     *    nulls coalesced so the index also covers the null columns.
     *  - decided_at: timestampTz (UTC).
     */
    public function up(): void
    {
        Schema::create('duplicate_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_item_id')->nullable()->constrained('source_items')->cascadeOnDelete();
            $table->foreignId('draft_id')->nullable()->constrained('event_drafts')->cascadeOnDelete();
            $table->foreignId('candidate_event_id')->nullable()->constrained('events')->cascadeOnDelete();
            $table->foreignId('candidate_draft_id')->nullable()->constrained('event_drafts')->cascadeOnDelete();
            $table->string('decision', 16);
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at');

            $table->index('candidate_event_id');
            $table->index('candidate_draft_id');
        });

        DB::statement("ALTER TABLE duplicate_decisions ADD CONSTRAINT duplicate_decisions_decision_check CHECK (decision IN ('not_duplicate', 'duplicate'))");
        DB::statement('ALTER TABLE duplicate_decisions ADD CONSTRAINT duplicate_decisions_one_subject CHECK (num_nonnulls(source_item_id, draft_id) = 1)');
        DB::statement('ALTER TABLE duplicate_decisions ADD CONSTRAINT duplicate_decisions_one_candidate CHECK (num_nonnulls(candidate_event_id, candidate_draft_id) = 1)');
        DB::statement('CREATE UNIQUE INDEX duplicate_decisions_pair ON duplicate_decisions ((coalesce(source_item_id, 0)), (coalesce(draft_id, 0)), (coalesce(candidate_event_id, 0)), (coalesce(candidate_draft_id, 0)))');
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicate_decisions');
    }
};
