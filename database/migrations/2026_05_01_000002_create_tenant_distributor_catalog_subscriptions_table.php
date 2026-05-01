<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant toggle for which distributor catalogs are active.
 *
 * Bike shop on BTI + QBP but not J&B? Two rows, both is_active = true,
 * no row for J&B. UPC scan only searches across distributors the tenant
 * has subscribed to.
 *
 * Decoupled from billing — Bike Pack add-on gates whether ANY distributor
 * sync is available, but within that, the tenant chooses which feeds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_distributor_catalog_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            // Distributor — references the platform-level catalog by code, not FK
            // (one subscription = whole distributor, not per-row)
            $table->string('distributor_code', 32);

            // Tenant-specific config
            $table->boolean('is_active')->default(true);
            $table->string('account_number', 64)->nullable();    // shop's account # with the distributor
            $table->json('credentials_encrypted')->nullable();   // EDI creds for v2 ordering integration

            // Sync state
            $table->timestamp('last_sync_at')->nullable();
            $table->string('last_sync_status', 32)->nullable();  // 'success', 'partial', 'failed'
            $table->text('last_sync_error')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'distributor_code']);
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_distributor_catalog_subscriptions');
    }
};
