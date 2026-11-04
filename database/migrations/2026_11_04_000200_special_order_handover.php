<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-SO-HANDOVER: holds, handover and customer notices for special orders.
 *
 *  - A hold can belong to a special order with no sale (a work-order part, or
 *    an order for a customer who hasn't been rung up), so the reservation's
 *    sale columns become nullable and it gains special_order_id.
 *  - The order records when and how the customer was told, who handed it
 *    over and why (a hand-over without a sale needs a reason), and whether
 *    its sale line's stock was left for handover.
 *  - A transfer request can carry the special order it moves, so the hold
 *    follows the part from the receiving store to the pickup store.
 *  - tenant_inventory_items.committed_count is dropped: nothing has ever
 *    written or read it (holds live in reserved_count).
 *
 * Everything is additive except the committed_count drop, which only runs
 * when no row carries a non-zero value.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Nullability only (no type change), which MySQL allows on a column
        // that carries a foreign key. Raw SQL so no other attribute changes.
        DB::statement('ALTER TABLE tenant_inventory_reservations MODIFY sale_id CHAR(36) NULL');
        DB::statement('ALTER TABLE tenant_inventory_reservations MODIFY sale_item_id CHAR(36) NULL');

        if (! Schema::hasColumn('tenant_inventory_reservations', 'special_order_id')) {
            Schema::table('tenant_inventory_reservations', function (Blueprint $t) {
                $t->uuid('special_order_id')->nullable()->after('sale_item_id');
                $t->index(['special_order_id', 'status'], 'tir_so_status_idx');
            });
        }

        Schema::table('tenant_special_orders', function (Blueprint $t) {
            if (! Schema::hasColumn('tenant_special_orders', 'notified_at')) {
                $t->timestamp('notified_at')->nullable()->after('arrived_at');
                $t->string('notified_via', 64)->nullable()->after('notified_at');
            }
            if (! Schema::hasColumn('tenant_special_orders', 'handed_over_by_user_id')) {
                $t->uuid('handed_over_by_user_id')->nullable()->after('pulled_at');
                $t->string('handover_note', 255)->nullable()->after('handed_over_by_user_id');
            }
            if (! Schema::hasColumn('tenant_special_orders', 'line_stock_deferred')) {
                // true when the sale line skipped taking stock at checkout
                // because the part was not here yet; it takes it at handover.
                $t->boolean('line_stock_deferred')->default(false)->after('sale_item_id');
            }
        });

        if (! Schema::hasColumn('tenant_transfer_requests', 'special_order_id')) {
            Schema::table('tenant_transfer_requests', function (Blueprint $t) {
                $t->uuid('special_order_id')->nullable()->after('sale_id');
                $t->index('special_order_id', 'ttr_so_idx');
            });
        }

        if (Schema::hasColumn('tenant_inventory_items', 'committed_count')
            && ! DB::table('tenant_inventory_items')->where('committed_count', '!=', 0)->exists()) {
            Schema::table('tenant_inventory_items', function (Blueprint $t) {
                $t->dropColumn('committed_count');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenant_transfer_requests', 'special_order_id')) {
            Schema::table('tenant_transfer_requests', function (Blueprint $t) {
                $t->dropIndex('ttr_so_idx');
                $t->dropColumn('special_order_id');
            });
        }
        Schema::table('tenant_special_orders', function (Blueprint $t) {
            foreach (['notified_at', 'notified_via', 'handed_over_by_user_id', 'handover_note', 'line_stock_deferred'] as $c) {
                if (Schema::hasColumn('tenant_special_orders', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
        if (Schema::hasColumn('tenant_inventory_reservations', 'special_order_id')) {
            Schema::table('tenant_inventory_reservations', function (Blueprint $t) {
                $t->dropIndex('tir_so_status_idx');
                $t->dropColumn('special_order_id');
            });
        }
        // sale_id / sale_item_id stay nullable: rows written since may have none.
    }
};
