<?php
// MARKER-PATCH-158-A

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tenant_customer_assets — persistent record of a customer's bikes, vehicles,
 * pets, or whatever physical items they bring in for service.
 *
 * Belongs to a customer (not an appointment). Survives across appointments,
 * which is what makes the "last seen Mar 12" history work.
 *
 * Archive instead of hard-delete (preserves history).
 *
 * Foundation for multi-asset appointments (patch arc 158-A through 158-E).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_customer_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained('tenant_customers')->cascadeOnDelete();

            $table->string('name', 200);
            $table->string('identifier', 120)->nullable(); // serial / plate / chip / tag — freeform
            $table->json('metadata')->nullable();          // industry-pack overflow
            $table->text('notes')->nullable();

            // Denormalized for the "Last seen Mar 12" hint in the picker UI.
            // Updated by the appointment_assets observer/service when an asset
            // is attached to an appointment. Eventually consistent — staleness
            // tolerated.
            $table->timestamp('last_seen_at')->nullable();
            $table->uuid('last_appointment_id')->nullable();

            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            // Lookups: "all of this customer's active assets", picker queries
            $table->index(['tenant_id', 'customer_id', 'archived_at'], 'tca_customer_lookup');
            // For tenant-wide reports / search by identifier
            $table->index(['tenant_id', 'identifier'], 'tca_identifier');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_customer_assets');
    }
};
