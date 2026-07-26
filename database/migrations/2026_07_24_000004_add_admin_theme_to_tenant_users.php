<?php

// MARKER-USER-THEME-PREF — per-person light/dark. Nullable on purpose:
// null means "no choice made", which inherits the tenant's stored theme, so
// existing staff see exactly what they saw before this shipped.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $t) {
            // 'b' (light) | 'c' (dark) | null (use the shop default)
            $t->string('admin_theme', 1)->nullable()->after('role_id');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_users', function (Blueprint $t) {
            $t->dropColumn('admin_theme');
        });
    }
};
