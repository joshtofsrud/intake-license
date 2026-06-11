<?php
// MARKER-PATCH-229

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_packages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->onDelete('restrict');
            $t->string('name');
            $t->string('subtitle')->nullable();        // "ages 12 & under", …
            $t->unsignedInteger('season_price_cents')->default(0);
            $t->unsignedInteger('deposit_cents')->default(0);
            $t->boolean('active')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(100);
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'archived_at'], 'lp_tenant_active');
        });

        Schema::create('lease_package_slots', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->onDelete('restrict');
            $t->foreignUuid('package_id')->constrained('lease_packages')->onDelete('cascade');
            $t->foreignUuid('category_id')->constrained('tenant_rental_categories')->onDelete('restrict');
            // Free-text size filter matched against unit.size at fulfillment
            // (e.g. "100-140cm", "Mondo 18-24"). Null = any size in category.
            $t->string('size_filter', 60)->nullable();
            $t->unsignedSmallInteger('quantity')->default(1);
            $t->unsignedSmallInteger('sort_order')->default(100);
            $t->timestamps();
            $t->index(['tenant_id', 'package_id'], 'lps_pkg');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_package_slots');
        Schema::dropIfExists('lease_packages');
    }
};
