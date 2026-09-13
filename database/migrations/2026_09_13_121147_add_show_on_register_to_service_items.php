<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-QUICK-ADD — which services get a tap-to-add button at the register.
 *
 * Separate from simple_enabled and quick_only, which govern the PUBLIC booking
 * flow. A service a cashier rings hourly is not necessarily one a shop wants on
 * its public booking menu, and ticking one should never silently do the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_service_items', function (Blueprint $table) {
            $table->boolean('show_on_register')->default(false)->after('quick_only');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_service_items', function (Blueprint $table) {
            $table->dropColumn('show_on_register');
        });
    }
};
