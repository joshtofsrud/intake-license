<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-PRICING-MOBILE-PRIORITY: add-on codes whose line shows on the plan
 * cards on phones before "+ more". Basics rows carry their own flag inside
 * pricing_basics, so they need no column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('site_settings', 'pricing_mobile_addons')) {
            Schema::table('site_settings', function (Blueprint $t) {
                $t->json('pricing_mobile_addons')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('site_settings', 'pricing_mobile_addons')) {
            Schema::table('site_settings', fn (Blueprint $t) => $t->dropColumn('pricing_mobile_addons'));
        }
    }
};
