<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-PATCH-176 — make the sale payment ledger support standalone refunds.
 *
 * - sale_id becomes NULLABLE: a standalone refund (e.g. refunding a pre-Intake
 *   fee) has no sale to hang off, but is still a money-out row in the ledger.
 * - customer_id ADDED (nullable at column level, but enforced-present for
 *   standalone refunds in the service layer): a refund ALWAYS has a customer.
 *   Sale-tied rows can leave it null and derive the customer from the sale.
 *
 * Patch-177 builds the standalone-refund flow on top of this.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tenant_sale_payments')) {
            return;
        }

        // 1) Drop the existing FK + NOT NULL on sale_id, re-add as nullable.
        Schema::table('tenant_sale_payments', function (Blueprint $t) {
            $t->dropForeign(['sale_id']);
        });
        Schema::table('tenant_sale_payments', function (Blueprint $t) {
            $t->uuid('sale_id')->nullable()->change();
            $t->foreign('sale_id')
              ->references('id')->on('tenant_sales')
              ->cascadeOnDelete();
        });

        // 2) Add customer_id if not present.
        if (!Schema::hasColumn('tenant_sale_payments', 'customer_id')) {
            Schema::table('tenant_sale_payments', function (Blueprint $t) {
                $t->foreignUuid('customer_id')
                  ->nullable()
                  ->after('sale_id')
                  ->constrained('tenant_customers')
                  ->onDelete('restrict');
                $t->index(['tenant_id', 'customer_id'], 'tsp_tenant_customer_idx');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('tenant_sale_payments')) {
            return;
        }
        Schema::table('tenant_sale_payments', function (Blueprint $t) {
            if (Schema::hasColumn('tenant_sale_payments', 'customer_id')) {
                $t->dropForeign(['customer_id']);
                $t->dropIndex('tsp_tenant_customer_idx');
                $t->dropColumn('customer_id');
            }
        });
        // Note: we intentionally do NOT re-impose NOT NULL on sale_id in down(),
        // since standalone-refund rows may exist by then.
    }
};
