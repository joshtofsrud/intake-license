<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_sale_items', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Tenant scope (denormalized for tenant-scoped query speed)
            $table->foreignUuid('tenant_id')->constrained('tenants')->onDelete('restrict');

            // Parent sale
            $table->foreignUuid('sale_id')->constrained('tenant_sales')->onDelete('cascade');
            // cascade: deleting a sale wipes its lines (rare path; refunds are separate rows)

            /*
             * Line type — drives which source FK is set:
             *   service     → service_id populated
             *   product     → inventory_item_id populated
             *   open_item   → neither (free-form name + price)
             *   gift_card   → gift_card_id reserved (FK deferred)
             */
            $table->enum('type', ['service', 'product', 'open_item', 'gift_card']);

            // Source references (conditional on type)
            $table->foreignUuid('service_id')->nullable()->constrained('tenant_services')->onDelete('restrict');
            $table->foreignUuid('inventory_item_id')->nullable()->constrained('tenant_inventory_items')->onDelete('restrict');
            $table->uuid('gift_card_id')->nullable(); // FK reserved; tenant_gift_cards table not yet built

            // Snapshot fields (per design principle 13 — name survives edits to source)
            $table->string('name_snapshot');
            $table->text('description_snapshot')->nullable();
            $table->unsignedInteger('cost_cents_snapshot')->nullable();
            // cost is nullable for open_item / service lines without a defined cost

            // Quantity (decimal for items sold by weight, length, time)
            $table->decimal('quantity', 10, 3)->default(1);

            // Money — line-level, all cents, non-negative
            $table->unsignedInteger('unit_price_cents');
            $table->unsignedInteger('discount_cents')->default(0);
            $table->decimal('tax_rate_snapshot', 5, 3)->nullable();
            // tax_rate captured at write time — rate at moment of sale, audit-honest
            $table->boolean('is_taxable')->default(true);
            $table->unsignedInteger('tax_cents')->default(0);
            $table->unsignedInteger('tip_cents')->default(0);
            // tip per line enables future split-attribution (multi-stylist salons)
            $table->unsignedInteger('line_total_cents');
            // computed: (unit_price * quantity) - discount + tax (no tip — tip is additive at sale level for now)

            // Staff attribution (for service lines; null for product / open_item)
            $table->foreignUuid('assigned_staff_id')->nullable()->constrained('tenant_users')->onDelete('restrict');

            // Line ordering on the sale (manually reorderable)
            $table->unsignedInteger('position')->default(0);

            // Notes — line-level, optional
            $table->text('notes')->nullable();

            $table->timestamps();

            // Indexes (explicit short names per §2.4 P19)
            $table->index(['tenant_id', 'sale_id'], 'tsi_tenant_sale_idx');
            $table->index(['tenant_id', 'type'], 'tsi_tenant_type_idx');
            $table->index('service_id', 'tsi_service_idx');
            $table->index('inventory_item_id', 'tsi_inv_item_idx');
            $table->index('gift_card_id', 'tsi_gift_card_idx');
            $table->index('assigned_staff_id', 'tsi_staff_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_sale_items');
    }
};
