<?php
// MARKER-PATCH-156

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add deliveries_enabled to tenants — gates the internal pickup/dropoff
 * scheduling feature. Default OFF; tenants opt in via Settings → Business.
 *
 * Mirrors the classes_enabled pattern (patch 88, April 2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('deliveries_enabled')->default(false)->after('classes_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('deliveries_enabled');
        });
    }
};
