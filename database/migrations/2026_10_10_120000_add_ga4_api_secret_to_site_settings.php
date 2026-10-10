<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-GA4-CONVERSIONS: the GA4 Measurement Protocol secret, so signups and
// booked calls can be sent to GA4 from the server.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('site_settings') && ! Schema::hasColumn('site_settings', 'ga4_api_secret')) {
            Schema::table('site_settings', function (Blueprint $t) {
                $t->string('ga4_api_secret', 64)->nullable()->after('ga4_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('site_settings', 'ga4_api_secret')) {
            Schema::table('site_settings', fn (Blueprint $t) => $t->dropColumn('ga4_api_secret'));
        }
    }
};
