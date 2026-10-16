<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Status audit (ADM-03): who last changed an event's or occurrence's
     * status, and when. Written only by App\Publishing\Actions\ChangeEventStatus
     * and ChangeOccurrenceStatus. NULL means never changed since publish.
     * The existing CHECK constraints on status values stay the DB backstop.
     */
    public function up(): void
    {
        foreach (['events', 'event_occurrences'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->timestampTz('status_changed_at')->nullable();
                $table->foreignId('status_changed_by')->nullable()->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['event_occurrences', 'events'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('status_changed_by');
                $table->dropColumn('status_changed_at');
            });
        }
    }
};
