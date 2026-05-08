<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parts & Add-ons on appointments.
 *
 * Adds tenant_appointment_parts: physical inventory items consumed during
 * an appointment (chains, tubes, bolts, etc.). Mirrors the snapshot pattern
 * from tenant_appointment_addons but adds the inventory link, taxability,
 * and a committed_at timestamp for tracking when stock was decremented.
 *
 * Why a separate table (vs. extending tenant_appointment_items with a `type`
 * enum): mirrors the existing addon pattern, keeps service queries from
 * accidentally scanning over parts, and lets parts have their own snapshot
 * fields (cost-at-time, taxability) without bloating the service items table.
 *
 * Also extends tenant_inventory_movements.movement_type enum to include
 * 'appointment' and 'appointment_refund' so the audit trail is honest about
 * where stock movements came from.
 */
return new class extends Migration {
    public function up(): void
    {
        // ---- tenant_appointment_parts ----
        Schema::create('tenant_appointment_parts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('appointment_id')
              ->constrained('tenant_appointments')
              ->cascadeOnDelete();
            // Nullable so the appointment isn't broken if the inventory item
            // is later deleted — the snapshot fields preserve what was billed.
            $t->foreignUuid('inventory_item_id')
              ->nullable()
              ->constrained('tenant_inventory_items')
              ->nullOnDelete();

            // Snapshots — these are the source of truth for what was billed,
            // independent of any later catalog edits. Same principle as
            // tenant_sale_items.
            $t->string('item_name_snapshot');
            $t->string('item_sku_snapshot', 64)->nullable();
            $t->unsignedSmallInteger('quantity')->default(1);
            $t->unsignedInteger('unit_price_cents');
            $t->integer('cost_cents_at_time')->nullable();
            $t->boolean('is_taxable')->default(true);

            // Override pattern matches addons/items: null = use snapshot,
            // set = the appointment-specific value (e.g. shop discount on a
            // chain for a long-time customer).
            $t->integer('unit_price_cents_override')->nullable();

            // committed_at tracks when this part actually moved inventory.
            // Set when the appointment first transitions to completed (or
            // shipped/closed). Cleared if the appointment goes back to a
            // pre-completion status, after returning the stock. Lets us
            // safely handle bidirectional status transitions without
            // double-decrementing or skipping an increment.
            $t->timestamp('committed_at')->nullable();

            $t->timestamps();

            $t->index(['appointment_id', 'committed_at'], 'tap_appt_committed_idx');
            $t->index('inventory_item_id', 'tap_inventory_idx');
        });

        // ---- extend tenant_inventory_movements.movement_type enum ----
        // MySQL/MariaDB-specific. Both supported in the rest of the schema.
        DB::statement("
            ALTER TABLE tenant_inventory_movements
            MODIFY COLUMN movement_type ENUM(
                'sale',
                'sale_void',
                'refund',
                'receive',
                'adjustment',
                'transfer_out',
                'transfer_in',
                'initial',
                'appointment',
                'appointment_refund'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_appointment_parts');

        // Revert enum. Will fail if rows already use the new values — by design.
        DB::statement("
            ALTER TABLE tenant_inventory_movements
            MODIFY COLUMN movement_type ENUM(
                'sale',
                'sale_void',
                'refund',
                'receive',
                'adjustment',
                'transfer_out',
                'transfer_in',
                'initial'
            ) NOT NULL
        ");
    }
};
