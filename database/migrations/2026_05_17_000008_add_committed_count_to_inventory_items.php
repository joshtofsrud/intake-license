<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds committed_count to tenant_inventory_items.
 *
 * COMMITTED = items assigned to a specific person/transaction:
 *   - tenant_special_orders.customer_id IS NOT NULL
 *     AND status IN ('needed', 'ordered', 'arrived')
 *   - tenant_appointment_parts on a non-terminal appointment
 *   - tenant_sale_items where parent sale payment_status != 'draft'
 *   - rentals checked out (when rental subsystem ships)
 *
 * Available stock = computed_stock_count - committed_count.
 * This column lets us read "available" without SUM queries against
 * three other tables — important at scale (200+ shops, peak hours
 * doing 1000+ inventory reads/sec).
 *
 * V1 SCOPE — this migration: column-add only. Column defaults to 0.
 * No service hooks, no backfill, no display changes. Future patch
 * builds the actual reservation subsystem and populates this column.
 *
 * NULLABILITY: NOT NULL with default 0. We want the column always
 * present and queryable; null would just mean "we haven't computed
 * it yet" which is the same as 0 for our purposes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_inventory_items', function (Blueprint $table) {
            $table->integer('committed_count')
                ->default(0)
                ->after('computed_stock_count');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_inventory_items', function (Blueprint $table) {
            $table->dropColumn('committed_count');
        });
    }
};
