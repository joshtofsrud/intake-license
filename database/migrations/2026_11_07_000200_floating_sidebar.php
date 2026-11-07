<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-FLOAT-SIDEBAR: each person's sidebar style. Null or 'locked' is the
 * sidebar as it has always been; 'float' insets it as a rounded panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenant_users', 'admin_sidebar')) {
            Schema::table('tenant_users', function (Blueprint $t) {
                $t->string('admin_sidebar', 8)->nullable()->after('admin_theme');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenant_users', 'admin_sidebar')) {
            Schema::table('tenant_users', function (Blueprint $t) {
                $t->dropColumn('admin_sidebar');
            });
        }
    }
};
