<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the legacy `has_waitlist_addon` column from tenants.
 *
 * The waitlist feature is now managed through the addon framework
 * (tenant_feature_addons table). The 2026_04_20_000005 data migration
 * already moved any has_waitlist_addon=1 flags into the framework.
 * This column is no longer read anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tenants', 'has_waitlist_addon')) {
            Schema::table('tenants', function (Blueprint $t) {
                $t->dropColumn('has_waitlist_addon');
            });
        }
    }

    public function down(): void
    {
        // Re-adding the column is safe; it defaults to 0.
        // Original data is not restored (and not needed — framework is source of truth).
        if (! Schema::hasColumn('tenants', 'has_waitlist_addon')) {
            Schema::table('tenants', function (Blueprint $t) {
                $t->boolean('has_waitlist_addon')->default(false);
            });
        }
    }
};
