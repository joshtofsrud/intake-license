<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-PATCH-561 — Online Retail Wave 2: per-item storefront visibility.
// Opt-IN by default (a tenant should never wake up to their whole inventory
// published online). Bulk enable arrives with storefront settings (Wave 5);
// until then one UPDATE opts a catalog in.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_inventory_items', function (Blueprint $table) {
            $table->boolean('show_online')->default(false)->after('is_active');
            $table->index(['tenant_id', 'show_online'], 'tii_tenant_online');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_inventory_items', function (Blueprint $table) {
            $table->dropIndex('tii_tenant_online');
            $table->dropColumn('show_online');
        });
    }
};
