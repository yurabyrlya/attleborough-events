<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One check of one source (SRC-07). Written only by the ingestion
     * pipeline (RunLauncher, FetchSourceJob, IngestRun, the janitor in
     * ingest:dispatch-due); nothing on a run is admin-editable.
     *
     * Status moves queued → running → {succeeded | not_modified | failed |
     * blocked}. A preview run (mode = preview, D-01) never writes items or
     * drafts; its would-be outcome is kept in `preview`.
     *
     * source_runs_one_active guarantees at most one queued or running run
     * per source (SRC-04) regardless of any cache lock: a second dispatch
     * fails with a unique violation. error_message never holds response
     * bodies. Every instant is timestampTz (UTC).
     */
    public function up(): void
    {
        Schema::create('source_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('mode', 16);
            $table->string('trigger', 16);
            $table->string('status', 16)->default('queued');
            $table->string('failure_kind', 32)->nullable();

            $table->smallInteger('http_status')->nullable();
            $table->string('final_url', 2048)->nullable();
            $table->bigInteger('bytes')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->string('raw_path', 255)->nullable();
            $table->char('raw_sha256', 64)->nullable();

            foreach ([
                'items_found', 'items_new', 'items_changed', 'items_unchanged', 'items_cancelled', 'items_missing',
                'skipped_past', 'skipped_far_future', 'skipped_rule', 'skipped_parse_error', 'skipped_out_of_area',
                'drafts_created', 'drafts_replaced',
            ] as $counter) {
                $table->integer($counter)->default(0);
            }

            // London dates the run observed (PIPE-08 missing detection window).
            $table->date('observed_from')->nullable();
            $table->date('observed_until')->nullable();
            $table->boolean('is_complete')->default(false);
            $table->boolean('structure_alert')->default(false);
            $table->string('error_message', 500)->nullable();
            $table->jsonb('preview')->nullable();

            $table->timestampTz('queued_at')->useCurrent();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['source_id', 'id']);
        });

        DB::statement("ALTER TABLE source_runs ADD CONSTRAINT source_runs_mode_check CHECK (mode IN ('live', 'preview'))");
        DB::statement("ALTER TABLE source_runs ADD CONSTRAINT source_runs_trigger_check CHECK (trigger IN ('schedule', 'manual', 'activation'))");
        DB::statement("ALTER TABLE source_runs ADD CONSTRAINT source_runs_status_check CHECK (status IN ('queued', 'running', 'succeeded', 'not_modified', 'failed', 'blocked'))");
        DB::statement("ALTER TABLE source_runs ADD CONSTRAINT source_runs_failure_kind_check CHECK (failure_kind IS NULL OR failure_kind IN ('network', 'timeout', 'http_status', 'rate_limited', 'too_large', 'content_invalid', 'parse_error', 'ssrf_blocked', 'abandoned'))");
        DB::statement("ALTER TABLE source_runs ADD CONSTRAINT source_runs_failure_kind_only_when_failed CHECK (failure_kind IS NULL OR status = 'failed')");

        // SRC-04: one queued or running run per source, enforced by the database.
        DB::statement("CREATE UNIQUE INDEX source_runs_one_active ON source_runs (source_id) WHERE status IN ('queued', 'running')");
    }

    public function down(): void
    {
        Schema::dropIfExists('source_runs');
    }
};
