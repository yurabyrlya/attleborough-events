<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Liveness evidence (OPS-04): one row per heartbeat name (scheduler,
     * queue, calendar-sync, ops-drill). A table, not the cache store, so
     * cache:clear or a deploy can never erase it. Written only by the
     * scheduler, the queue worker and artisan commands.
     */
    public function up(): void
    {
        Schema::create('heartbeats', function (Blueprint $table) {
            $table->string('name', 64)->primary();
            $table->timestampTz('last_seen_at');
            $table->jsonb('meta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('heartbeats');
    }
};
