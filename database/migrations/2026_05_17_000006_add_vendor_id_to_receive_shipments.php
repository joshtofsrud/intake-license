<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds vendor_id FK to tenant_inventory_receive_shipments.
 *
 * COMPATIBILITY: the existing distributor_code + distributor_name
 * string columns are kept. They were used pre-vendor-system and
 * any tenant who has receiving history will have data in those
 * columns. New flows prefer the FK; legacy flows still work.
 *
 * NO BACKFILL. Existing receive-shipment rows get vendor_id = null.
 * If a tenant wants their history vendor-linked, they can do it
 * manually from the receiving detail page (Stage 5/6 work). A
 * blanket distributor_name → vendor_id auto-match would risk bad
 * matches; we'd rather have null than wrong.
 *
 * NULLABLE. New receive shipments don't HAVE to have a vendor
 * picked — sometimes a shipment arrives without a clean source
 * (a customer brought parts in, a tenant got a one-off direct
 * from a manufacturer, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_inventory_receive_shipments', function (Blueprint $table) {
            $table->foreignUuid('vendor_id')
                ->nullable()
                ->after('distributor_name')
                ->constrained('tenant_vendors')
                ->nullOnDelete();

            $table->index(['tenant_id', 'vendor_id', 'status'], 'tirs_tenant_vendor_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_inventory_receive_shipments', function (Blueprint $table) {
            $table->dropIndex('tirs_tenant_vendor_status_idx');
            $table->dropForeign(['vendor_id']);
            $table->dropColumn('vendor_id');
        });
    }
};
