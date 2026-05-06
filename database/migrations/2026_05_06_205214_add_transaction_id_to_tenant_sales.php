<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_sales', function (Blueprint $table) {
            // Groups multiple sale rows into a single transaction.
            // NULL = standalone sale or refund (no companion rows).
            // Non-null = part of a multi-row transaction (e.g., exchange:
            //   one row with payment_status='refunded', one with 'paid',
            //   sharing the same transaction_id).
            $table->uuid('transaction_id')->nullable()->after('refund_of_sale_id');
            $table->index(['tenant_id', 'transaction_id'], 'tenant_sales_tenant_txn_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_sales', function (Blueprint $table) {
            $table->dropIndex('tenant_sales_tenant_txn_idx');
            $table->dropColumn('transaction_id');
        });
    }
};
