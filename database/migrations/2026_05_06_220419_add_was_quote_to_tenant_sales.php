<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_sales', function (Blueprint $table) {
            // Tracks whether this sale row was previously a quote that got committed.
            // payment_status='quote' → user clicks Convert to sale → commitDraft
            // promotes the row to payment_status='paid' AND sets was_quote=true.
            // This is the metric that drives the quotes dashboard's
            // 'Recently converted' card.
            $table->boolean('was_quote')->default(false)->after('transaction_id');
            $table->index(['tenant_id', 'was_quote'], 'tenant_sales_tenant_quote_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_sales', function (Blueprint $table) {
            $table->dropIndex('tenant_sales_tenant_quote_idx');
            $table->dropColumn('was_quote');
        });
    }
};
