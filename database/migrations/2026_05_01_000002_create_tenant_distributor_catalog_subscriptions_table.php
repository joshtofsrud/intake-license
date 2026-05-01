<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant toggle for which distributor catalogs are active.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_distributor_catalog_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('distributor_code', 32);

            $table->boolean('is_active')->default(true);
            $table->string('account_number', 64)->nullable();
            $table->json('credentials_encrypted')->nullable();

            $table->timestamp('last_sync_at')->nullable();
            $table->string('last_sync_status', 32)->nullable();
            $table->text('last_sync_error')->nullable();

            $table->timestamps();

            // Explicit short index names — MySQL has a 64-char identifier limit
            $table->unique(['tenant_id', 'distributor_code'], 'tdcs_tenant_dist_unique');
            $table->index(['tenant_id', 'is_active'], 'tdcs_tenant_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_distributor_catalog_subscriptions');
    }
};
