<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-DASH-AUDIENCE: dashboard templates, phase 3. Additive only.
 *
 * platform_settings.dashboard_customize_enabled: one switch that turns
 *   per-person dashboard customizing off for every shop (each template keeps
 *   its own setting for when it's turned back on).
 * tenant_users.dashboard_prefs: what each person hid or reordered, per
 *   template key: {"brief": {"hidden": ["setup"], "order": {"1-1": ["last30", "week"]}}}.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('platform_settings', 'dashboard_customize_enabled')) {
            Schema::table('platform_settings', function (Blueprint $t) {
                $t->boolean('dashboard_customize_enabled')->default(true);
            });
        }
        if (! Schema::hasColumn('tenant_users', 'dashboard_prefs')) {
            Schema::table('tenant_users', function (Blueprint $t) {
                $t->json('dashboard_prefs')->nullable()->after('dashboard_tiles');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenant_users', 'dashboard_prefs')) {
            Schema::table('tenant_users', fn (Blueprint $t) => $t->dropColumn('dashboard_prefs'));
        }
        if (Schema::hasColumn('platform_settings', 'dashboard_customize_enabled')) {
            Schema::table('platform_settings', fn (Blueprint $t) => $t->dropColumn('dashboard_customize_enabled'));
        }
    }
};
