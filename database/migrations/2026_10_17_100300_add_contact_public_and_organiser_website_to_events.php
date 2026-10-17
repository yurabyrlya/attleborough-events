<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Organiser contact choice carried to the published event (D-03, D-19).
     * contact_public gates the organiser contact on every public surface;
     * it defaults to true so every Phase 1 event keeps its public output.
     * Public submissions arrive with false and keep it through approval.
     * organiser_website is shown on the event page as a safe link.
     * Written only by App\Publishing\Actions (PublishEvent, UpdatePublishedEvent).
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('organiser_website', 2048)->nullable();
            $table->boolean('contact_public')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['contact_public', 'organiser_website']);
        });
    }
};
