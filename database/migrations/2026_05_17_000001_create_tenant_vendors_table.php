<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-scoped vendor catalog for special orders + receiving.
 *
 * IMPORTANT — not the same as platform_distributor_catalogs:
 *   - platform_distributor_catalogs (existing): global sync source
 *     for nightly catalog data (QBP master catalog, Hawley catalog, etc).
 *     Owned by the platform, shared across all tenants.
 *   - tenant_vendors (this table): a single tenant's list of
 *     "people I buy from." A tenant may have 3-10 vendor rows.
 *     Tenant-scoped, freely editable by the tenant.
 *
 * The two concepts overlap — most tenants will have a "QBP" vendor
 * row that corresponds to the platform-level QBP distributor. But
 * a tenant can also have vendors that aren't on the platform sync:
 * "the local bike co-op down the street," "Amazon Business," etc.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_vendors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name', 128);
            $table->string('contact_email', 128)->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('account_number', 64)->nullable();

            // Optional link to the platform distributor catalog if this
            // vendor maps to one. Null for local/non-synced vendors.
            $table->foreignUuid('distributor_catalog_id')
                ->nullable()
                ->constrained('platform_distributor_catalogs')
                ->nullOnDelete();

            $table->text('notes')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'name'], 'tv_tenant_name_unique');
            $table->index(['tenant_id', 'is_active'], 'tv_tenant_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_vendors');
    }
};
