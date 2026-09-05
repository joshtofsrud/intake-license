<?php
// MARKER-DIST-TOGGLE

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        // A subscription with no stored credentials was never a connection —
        // it is a row firstOrCreate() made when someone opened the page. Turn
        // those off. Anything holding credentials is real and is left alone.
        $off = DB::table('tenant_distributor_catalog_subscriptions')
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('credentials_encrypted')
                  ->orWhere('credentials_encrypted', '')
                  ->orWhere('credentials_encrypted', '[]')
                  ->orWhere('credentials_encrypted', '{}');
            })
            ->update(['is_active' => false]);

        Log::info("MARKER-DIST-TOGGLE: switched off {$off} subscription(s) that had no credentials");
    }

    public function down(): void
    {
        // Deliberately not reversed: turning them all back on would re-expose
        // every distributor's catalog to every tenant.
    }
};
