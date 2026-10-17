<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * D-04 (review CR-01): sessions written before the privacy session
     * handler hold the visitor's IP address and user agent, and a failed
     * /submit left the organiser's details in a plaintext payload. Encrypted
     * sessions can't read those old payloads anyway, so remove every row:
     * visitors get a fresh session and the admin signs in again. Data only;
     * the schema is unchanged.
     */
    public function up(): void
    {
        DB::table('sessions')->delete();
    }

    /**
     * Deleted sessions can't be restored, and nothing needs them back.
     */
    public function down(): void
    {
        //
    }
};
