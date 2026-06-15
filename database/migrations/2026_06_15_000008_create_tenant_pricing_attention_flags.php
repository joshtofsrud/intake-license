<?php
// MARKER-PATCH-HLC3B

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One open flag per (tenant, item, reason). Reasons the tier-2 sync writes:
 * cost_vanished / map_vanished / msrp_vanished — a price that was present went
 * gone, on an item the tenant has IN STOCK. below_map / off_msrp are evaluated
 * live by the pricing-attention surface (later patch) and share this table.
 *
 * Reconcile semantics: condition true -> upsert an open row; condition false ->
 * mark any open row resolved. The unique key keeps it idempotent across runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_pricing_attention_flags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('inventory_item_id')
                ->constrained('tenant_inventory_items')
                ->cascadeOnDelete();
            $table->uuid('distributor_catalog_id')->nullable();
            $table->string('reason', 32);
            $table->json('detail')->nullable();
            $table->string('status', 16)->default('open');
            $table->timestamp('resolved_at')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'inventory_item_id', 'reason'], 'tpaf_unique');
            $table->index(['tenant_id', 'status'], 'tpaf_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_pricing_attention_flags');
    }
};
