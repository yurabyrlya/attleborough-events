<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Source-value mappings (PIPE-03 link 2): a source's own category label
     * (e.g. 'Quiz Night') picks a category before title keyword rules are
     * tried. Written only by App\Review\SaveRules (the Rules screen).
     * Source values are unique case-insensitively; SaveRules checks the
     * fully normalised form first and says so in plain English.
     *
     * Phase 3 adds a nullable source_id and widens the unique key to
     * (source_id, lower(source_value)), so a label can mean different things
     * on different sources.
     */
    public function up(): void
    {
        Schema::create('category_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('source_value', 120);
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->timestampsTz();
        });

        DB::statement('CREATE UNIQUE INDEX category_mappings_source_value_unique ON category_mappings (lower(source_value))');
        DB::statement("ALTER TABLE category_mappings ADD CONSTRAINT category_mappings_source_value_not_blank CHECK (btrim(source_value) <> '')");
    }

    public function down(): void
    {
        Schema::dropIfExists('category_mappings');
    }
};
