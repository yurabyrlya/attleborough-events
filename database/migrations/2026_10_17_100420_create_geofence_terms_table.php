<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The geofence (ADM-04): postcode districts (stored upper-case without
     * spaces, e.g. NR17) and place names that keep regional listings local.
     * Managed on the Rules screen through App\Review\SaveRules and seeded by
     * RulesSeeder; enforcement arrives in Phase 4.
     */
    public function up(): void
    {
        Schema::create('geofence_terms', function (Blueprint $table) {
            $table->id();
            $table->string('type', 24);
            $table->string('value', 120);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE geofence_terms ADD CONSTRAINT geofence_terms_type_check CHECK (type IN ('postcode_district', 'place_name'))");
        DB::statement("ALTER TABLE geofence_terms ADD CONSTRAINT geofence_terms_value_not_blank CHECK (btrim(value) <> '')");
        DB::statement('CREATE UNIQUE INDEX geofence_terms_type_value_unique ON geofence_terms (type, lower(value))');
    }

    public function down(): void
    {
        Schema::dropIfExists('geofence_terms');
    }
};
