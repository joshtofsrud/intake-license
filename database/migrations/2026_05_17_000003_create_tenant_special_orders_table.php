<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Special orders — the main table.
 *
 * One row per "this many units of this item, going to this destination."
 * Multiple rows can share a batch_id when staff ordered them together
 * (e.g., 6 tubes split across 3 customers = 3 rows with same batch_id).
 *
 * STATUS LIFECYCLE:
 *   needed   → soft request, not yet ordered. Vendor may be TBD.
 *              Created when booking-flow soft SO, or when staff hasn't
 *              picked a vendor yet.
 *   ordered  → committed to a vendor with a reference + expected date.
 *              The active "waiting" state.
 *   arrived  → on the receiving bench. Waiting to be pulled at the
 *              register or marked complete on an appointment.
 *   pulled   → consumed. Done. Audit-history only.
 *   cancelled → killed at any prior state. Refunds deposit if any.
 *
 * PARTIAL RECEIPT MECHANIC:
 *   When a vendor short-ships (4 of 6 arrived), the service layer
 *   SPLITS the row: original becomes "arrived" qty=4, a new sibling
 *   row is created with parent_id = original.id, qty=2, status=ordered.
 *   This is simpler than tracking arrived/expected on one row and
 *   keeps the status enum honest.
 *
 * NULLABILITY RULES:
 *   - inventory_item_id is nullable: "not yet catalogued" items at
 *     creation time. Service layer can promote later when the item
 *     gets a catalog entry.
 *   - customer_id is nullable: shop-stock SOs (bulk order) have no
 *     customer. Also true for the catch-all "+ New" entry point.
 *   - appointment_id is nullable: an SO can be tied to an appointment,
 *     to a customer-but-not-a-specific-appointment, or to neither.
 *   - vendor_id is nullable: status=needed rows may have vendor TBD.
 *     Required at status=ordered+ (enforced in the service layer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_special_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            // Display number — auto-incremented per tenant. e.g. SO-2401.
            // Counter table is the same pattern as tenant_sale_counters
            // but for SOs. (Service layer owns the counter logic.)
            $table->string('so_number', 16);

            // What's being ordered
            $table->foreignUuid('inventory_item_id')
                ->nullable()
                ->constrained('tenant_inventory_items')
                ->nullOnDelete();
            // Snapshot of the item name at creation. Survives item deletion
            // and changes. Used for display in audit / history contexts.
            $table->string('item_name_snapshot', 255);

            $table->integer('quantity');

            // Where it's going (any combo of these can be null)
            $table->foreignUuid('customer_id')
                ->nullable()
                ->constrained('tenant_customers')
                ->nullOnDelete();
            $table->foreignUuid('appointment_id')
                ->nullable()
                ->constrained('tenant_appointments')
                ->nullOnDelete();

            // Vendor — nullable when status=needed and vendor TBD
            $table->foreignUuid('vendor_id')
                ->nullable()
                ->constrained('tenant_vendors')
                ->nullOnDelete();
            $table->string('vendor_reference', 64)->nullable();

            $table->enum('status', ['needed', 'ordered', 'arrived', 'pulled', 'cancelled'])
                ->default('needed');

            // Where did this SO originate? Useful for workflow analytics
            // ("what % of SOs come from booking-flow vs register vs intake").
            $table->enum('created_from', ['register', 'appointment', 'item', 'manual', 'booking'])
                ->default('manual');

            // Costs
            $table->integer('unit_cost_cents_estimated')->nullable();
            $table->integer('unit_cost_cents_actual')->nullable();

            // Timing
            $table->date('expected_arrival_date')->nullable();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('pulled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Deposit (QoL feature)
            $table->integer('deposit_cents')->default(0);
            $table->timestamp('deposit_paid_at')->nullable();
            // Stripe PaymentIntent or similar — opaque reference to whatever
            // captured the deposit. Service layer fills this in.
            $table->string('deposit_payment_ref', 128)->nullable();

            // Batch grouping (multi-customer batching, QoL feature).
            // Rows with the same batch_id were created together and ship
            // together. The batch_id is just a UUID — no separate table;
            // it's a coordinating identifier, not an entity.
            $table->uuid('batch_id')->nullable();

            // Partial-receipt parent. When a row is split because of a
            // partial vendor shipment, the new sibling rows point at the
            // original via parent_id. Null = this is the original.
            $table->foreignUuid('parent_id')
                ->nullable()
                ->constrained('tenant_special_orders')
                ->nullOnDelete();

            $table->text('cancellation_reason')->nullable();

            $table->foreignUuid('created_by_user_id')
                ->nullable()
                ->constrained('tenant_users')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'so_number'], 'tso_tenant_so_unique');
            $table->index(['tenant_id', 'status'], 'tso_tenant_status_idx');
            $table->index(['tenant_id', 'vendor_id', 'status'], 'tso_tenant_vendor_status_idx');
            $table->index(['tenant_id', 'customer_id'], 'tso_tenant_customer_idx');
            $table->index(['tenant_id', 'appointment_id'], 'tso_tenant_appt_idx');
            $table->index(['tenant_id', 'expected_arrival_date'], 'tso_tenant_eta_idx');
            $table->index('batch_id', 'tso_batch_idx');
            $table->index('parent_id', 'tso_parent_idx');
        });

        // Per-tenant counter for SO numbers. Same pattern as
        // tenant_sale_counters — service layer atomically increments.
        Schema::create('tenant_special_order_counters', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->primary()->constrained()->cascadeOnDelete();
            $table->integer('next_number')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_special_order_counters');
        Schema::dropIfExists('tenant_special_orders');
    }
};
