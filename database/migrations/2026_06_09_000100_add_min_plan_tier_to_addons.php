<?php
// MARKER-PATCH-217

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * min_plan_tier — a hard availability floor for catalog addons.
 *
 * Null = purchasable on any plan (status quo for every existing addon).
 * 'branded' / 'scale' / 'custom' = the addon cannot be activated, and does
 * not grant access, below that tier — even if a tenant_feature_addons row
 * exists. First consumers: rentals + rental_extensions (decision 2026-06-09:
 * always a la carte, never on Starter).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addons', function (Blueprint $t) {
            $t->string('min_plan_tier', 16)->nullable()->after('included_in_plans');
        });
    }

    public function down(): void
    {
        Schema::table('addons', function (Blueprint $t) {
            $t->dropColumn('min_plan_tier');
        });
    }
};
