<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-SPLASH — marks the one page a tenant uses as its splash.
 *
 * Deliberately a flag on pages rather than a separate table: a splash IS a
 * page — same sections, same builder, same revisions, and a shop can link a
 * campaign straight at its slug. The settings that govern WHEN it appears
 * live in tenants.settings alongside the other per-shop toggles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_pages', function (Blueprint $table) {
            $table->boolean('is_splash')->default(false)->after('is_home');
            $table->index(['tenant_id', 'is_splash']);
        });
    }

    public function down(): void
    {
        Schema::table('tenant_pages', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'is_splash']);
            $table->dropColumn('is_splash');
        });
    }
};
