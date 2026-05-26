<?php
// MARKER-PATCH-158-B

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add multi_asset_enabled to tenants — gates the customer-asset tracking
 * feature. Default OFF; tenants opt in via Settings → Business.
 *
 * Mirrors the deliveries_enabled pattern (patch 156).
 *
 * When OFF:
 *   - Assets section hidden from customer detail page
 *   - "Add asset" / asset picker hidden from appointment view
 *   - Appointment view renders existing flat services list as-is
 *
 * When ON:
 *   - All multi-asset surfaces (158-C, 158-D, 158-E) become visible
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('multi_asset_enabled')->default(false)->after('deliveries_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('multi_asset_enabled');
        });
    }
};
