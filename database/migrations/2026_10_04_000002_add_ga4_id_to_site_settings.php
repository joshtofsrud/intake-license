<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-MKT-ANALYTICS — GA4 measurement ID for intake.works.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('site_settings') && ! Schema::hasColumn('site_settings', 'ga4_id')) {
            Schema::table('site_settings', function (Blueprint $t) {
                $t->string('ga4_id', 32)->nullable()->after('gtm_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('site_settings', 'ga4_id')) {
            Schema::table('site_settings', fn (Blueprint $t) => $t->dropColumn('ga4_id'));
        }
    }
};
