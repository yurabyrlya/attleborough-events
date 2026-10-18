<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Politeness state per crawled host (SRC-05, D-02). A table, not the
     * cache store, so every queue worker sees the same request spacing (the
     * row is locked FOR UPDATE while a slot is reserved) and cache:clear or
     * a deploy can never reset it. Written only by HostThrottle and
     * RobotsPolicy.
     *
     * host: lower-case, with ":port" appended only when the port is not the
     * scheme default. last_request_at is the slot of the latest reserved
     * request; next_allowed_at is the earliest instant the next request may
     * start (Crawl-delay and Retry-After can only push it later).
     *
     * The robots.txt cache lives here too (RFC 9309 §2.4: at most 24 h).
     * robots_body is kept only for the 'rules' status, and is at most the
     * 512 KiB that is parsed. Every instant is timestampTz (UTC).
     */
    public function up(): void
    {
        Schema::create('crawl_hosts', function (Blueprint $table) {
            $table->string('host', 255)->primary();
            $table->timestampTz('next_allowed_at');
            $table->timestampTz('last_request_at')->nullable();
            $table->string('robots_status', 16)->nullable();
            $table->text('robots_body')->nullable();
            $table->timestampTz('robots_fetched_at')->nullable();
            $table->integer('crawl_delay_seconds')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE crawl_hosts ADD CONSTRAINT crawl_hosts_robots_status_check CHECK (robots_status IS NULL OR robots_status IN ('rules', 'allow_all', 'disallow_all', 'blocked'))");
        DB::statement('ALTER TABLE crawl_hosts ADD CONSTRAINT crawl_hosts_crawl_delay_check CHECK (crawl_delay_seconds IS NULL OR crawl_delay_seconds BETWEEN 0 AND 86400)');
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_hosts');
    }
};
