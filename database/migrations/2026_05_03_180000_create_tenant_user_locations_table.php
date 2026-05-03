<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_user_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Tenant scope (denormalized for tenant-scoped queries)
            $table->foreignUuid('tenant_id')->constrained('tenants')->onDelete('restrict');

            // The pivot
            $table->foreignUuid('tenant_user_id')
                  ->constrained('tenant_users')
                  ->onDelete('cascade');
            // cascade: deleting a tenant user removes their location grants

            $table->foreignUuid('location_id')
                  ->constrained('tenant_locations')
                  ->onDelete('cascade');
            // cascade: deleting a location removes user grants for it

            // Revocation without deletion — owner can revoke access without losing audit trail
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Unique: one row per (user, location)
            $table->unique(['tenant_user_id', 'location_id'], 'tul_user_loc_unique');

            // Useful indexes
            $table->index(['tenant_id', 'tenant_user_id'], 'tul_tenant_user_idx');
            $table->index(['tenant_id', 'location_id'], 'tul_tenant_loc_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_user_locations');
    }
};
