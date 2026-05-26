<?php
// MARKER-PATCH-158-A

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tenant_appointment_assets — pivot rows attaching a customer's asset to a
 * specific appointment.
 *
 * Each row represents "this bike is on this appointment." Multiple rows per
 * appointment = multi-asset booking (the Tofsrud family with 5 bikes).
 *
 * Snapshots asset name + identifier at attachment time so that renaming the
 * asset later doesn't rewrite history.
 *
 * Services (tenant_appointment_items) and addons (tenant_appointment_addons)
 * gain a nullable appointment_asset_id pointing to this table — see migration
 * 000025.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_appointment_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('appointment_id')->constrained('tenant_appointments')->cascadeOnDelete();
            $table->foreignUuid('customer_asset_id')->constrained('tenant_customer_assets')->cascadeOnDelete();

            // Snapshots — at the moment this asset was attached
            $table->string('asset_name_snapshot', 200);
            $table->string('identifier_snapshot', 120)->nullable();

            // Display order in the appointment view; sortable by drag handle
            $table->unsignedInteger('sort_order')->default(0);

            // Rollup of all services + addons attached to this asset.
            // Recalculated whenever an item/addon is added or removed.
            $table->unsignedInteger('subtotal_cents')->default(0);

            $table->timestamps();

            // Common lookup: render all assets for an appointment in sort order
            $table->index(['appointment_id', 'sort_order'], 'taa_appt_sort');
            // For "show me all appointments this asset has been on" (asset history)
            $table->index(['tenant_id', 'customer_asset_id'], 'taa_asset_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_appointment_assets');
    }
};
