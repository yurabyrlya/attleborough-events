<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Title keyword rules (PIPE-03 link 3): a whole-word keyword in a draft's
     * title picks its category; lower priority first, then lower id.
     * Written only by App\Review\SaveRules (the Rules screen) and
     * RulesSeeder. Keywords are unique case-insensitively; SaveRules checks
     * the fully normalised form first and says so in plain English.
     */
    public function up(): void
    {
        Schema::create('category_keyword_rules', function (Blueprint $table) {
            $table->id();
            $table->string('keyword', 80);
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->smallInteger('priority')->default(100);
            $table->timestampsTz();

            $table->index(['priority', 'id']);
        });

        DB::statement('CREATE UNIQUE INDEX category_keyword_rules_keyword_unique ON category_keyword_rules (lower(keyword))');
        DB::statement("ALTER TABLE category_keyword_rules ADD CONSTRAINT category_keyword_rules_keyword_not_blank CHECK (btrim(keyword) <> '')");
    }

    public function down(): void
    {
        Schema::dropIfExists('category_keyword_rules');
    }
};
