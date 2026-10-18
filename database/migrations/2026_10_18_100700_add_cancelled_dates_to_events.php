<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * events.cancelled_dates: London dates (Y-m-d) of a series the source
     * cancelled and the admin accepted (ICS cancelled overrides at approval,
     * D-08/D-19). OccurrenceGenerator inserts a NEW occurrence for one of
     * these dates as Cancelled, so a cancelled date beyond
     * calendar.horizon_days is not published as Scheduled when the nightly
     * sync reaches it. Existing occurrences keep their status (admin
     * restores survive). Written only by App\Publishing\Actions (forceFill).
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->jsonb('cancelled_dates')->default(DB::raw("'[]'::jsonb"));
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('cancelled_dates');
        });
    }
};
