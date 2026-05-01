<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-defined inventory categories.
 *
 * parent_id supports hierarchy (Drivetrain > Chains, Drivetrain > Cassettes)
 * even though v1 UI is flat. Adding hierarchy later is a re-key migration
 * over millions of items x 500 tenants — costs nothing to support now.
 *
 * tax_class_code is a forward-compatibility hook for spec section 12 Q2 (per-item
 * tax classes). v1 ignores it and uses the shop-wide rate. When tax classes
 * ship, this column carries the value.
 *
 * Industry packs seed common categories on tenant creation. AI Quick Setup
 * can also write categories during onboarding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_inventory_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            // Self-referencing for hierarchy
            $table->foreignUuid('parent_id')
                ->nullable()
                ->constrained('tenant_inventory_categories')
                ->nullOnDelete();

            $table->string('name', 128);
            $table->string('slug', 128); // url-safe, tenant-unique
            $table->integer('sort_order')->default(0);

            // Forward-compat: tax class for spec section 12 Q2
            $table->string('tax_class_code', 32)->nullable();

            // Source: 'industry_pack' (seeded), 'ai_quick_setup', 'manual'
            $table->string('source', 32)->default('manual');

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'parent_id']);
            $table->index(['tenant_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_inventory_categories');
    }
};
