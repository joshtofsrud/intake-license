<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-LOAD-PANEL: per-admin preferences, starting with which view the
 * master-admin dashboard opens on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'admin_prefs')) return;
        Schema::table('users', function (Blueprint $t) {
            $t->json('admin_prefs')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'admin_prefs')) return;
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('admin_prefs');
        });
    }
};
