<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ----------------------------------------------------------------
        // Service categories
        // ----------------------------------------------------------------
        Schema::create('tenant_service_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug', 100);
            $table->text('description')->nullable();
            $table->string('image_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });

        // ----------------------------------------------------------------
        // Service tiers (e.g. Standard, Full Rebuild, Rush)
        // ----------------------------------------------------------------
        Schema::create('tenant_service_tiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug', 100);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });

        // ----------------------------------------------------------------
        // Service items (the things being serviced)
        // ----------------------------------------------------------------
        Schema::create('tenant_service_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('category_id')->constrained('tenant_service_categories')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug', 100);
            $table->text('description')->nullable();
            $table->string('image_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });

        // ----------------------------------------------------------------
        // Item tier prices (join: per-item price for each tier)
        // null price = tier not offered for this item
        // ----------------------------------------------------------------
        Schema::create('tenant_item_tier_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('tenant_service_items')->cascadeOnDelete();
            $table->foreignUuid('tier_id')->constrained('tenant_service_tiers')->cascadeOnDelete();
            $table->unsignedInteger('price_cents')->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'tier_id']);
        });

        // ----------------------------------------------------------------
        // Add-ons (global or item-specific)
        // ----------------------------------------------------------------
        Schema::create('tenant_addons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('price_cents')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // ----------------------------------------------------------------
        // Item add-ons join
        // ----------------------------------------------------------------
        Schema::create('tenant_item_addons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('item_id')->constrained('tenant_service_items')->cascadeOnDelete();
            $table->foreignUuid('addon_id')->constrained('tenant_addons')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['item_id', 'addon_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_item_addons');
        Schema::dropIfExists('tenant_addons');
        Schema::dropIfExists('tenant_item_tier_prices');
        Schema::dropIfExists('tenant_service_items');
        Schema::dropIfExists('tenant_service_tiers');
        Schema::dropIfExists('tenant_service_categories');
    }
};
