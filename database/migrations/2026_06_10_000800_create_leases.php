<?php
// MARKER-PATCH-230

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->onDelete('restrict');
            $t->foreignUuid('location_id')->nullable()->constrained('tenant_locations')->nullOnDelete();
            $t->foreignUuid('customer_id')->nullable()->constrained('tenant_customers')->nullOnDelete();
            $t->foreignUuid('package_id')->nullable()->constrained('lease_packages')->nullOnDelete();
            $t->string('lease_number')->unique();
            $t->string('package_name_snapshot');           // package can change later; lease keeps what was sold
            // Season window — UTC instants, same time semantics as rentals.
            $t->dateTime('season_start');
            $t->dateTime('season_end');
            $t->dateTime('returned_at')->nullable();
            // status: active | returned | cancelled (out/overdue derived from season_end)
            $t->string('status', 16)->default('active');
            // Money (Rail 2) — paid_cents denormalized from the ledger, like rentals.
            $t->unsignedInteger('subtotal_cents')->default(0);
            $t->unsignedInteger('tax_cents')->default(0);
            $t->unsignedInteger('total_cents')->default(0);
            $t->unsignedInteger('paid_cents')->default(0);
            // Deposit authorization (Rail 2) — never money until captured.
            $t->unsignedInteger('deposit_hold_cents')->default(0);
            $t->string('deposit_status', 24)->default('none');
            $t->string('stripe_deposit_intent_id')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'status', 'season_end'], 'lease_tenant_status_end');
            $t->index(['tenant_id', 'customer_id'], 'lease_tenant_customer');
        });

        // MARKER-PATCH-230 — sales-as-money for leases: tenant_sales.lease_id
        // (mirrors rental_id from 219b). The register bridge writes it; the
        // payment cascade reads it to update the lease paid_cents cache.
        Schema::table('tenant_sales', function (Blueprint $t) {
            $t->foreignUuid('lease_id')->nullable()->after('rental_id')
              ->constrained('leases')->nullOnDelete();
            $t->index('lease_id', 'tenant_sales_lease_idx');
        });

        Schema::create('lease_assignments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->onDelete('restrict');
            $t->foreignUuid('lease_id')->constrained('leases')->onDelete('cascade');
            $t->foreignUuid('slot_id')->nullable()->constrained('lease_package_slots')->nullOnDelete();
            $t->foreignUuid('unit_id')->nullable()->constrained('tenant_rental_units')->nullOnDelete();
            $t->string('unit_name_snapshot');
            $t->string('unit_serial_snapshot')->nullable();
            $t->string('category_name_snapshot')->nullable();
            // Per-unit return condition (filled at check-in, patch 231).
            $t->string('return_condition', 24)->nullable();
            $t->dateTime('returned_at')->nullable();
            $t->timestamps();
            // The conflict join keys on (unit_id) for active leases.
            $t->index(['tenant_id', 'unit_id'], 'la_unit');
            $t->index('lease_id', 'la_lease');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_sales', function (Blueprint $t) {
            $t->dropForeign(['lease_id']);
            $t->dropIndex('tenant_sales_lease_idx');
            $t->dropColumn('lease_id');
        });
        Schema::dropIfExists('lease_assignments');
        Schema::dropIfExists('leases');
    }
};
