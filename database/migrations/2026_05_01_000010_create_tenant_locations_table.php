<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant locations.
 *
 * Every tenant has at least one location (the "Main" location), even if
 * they're single-location forever. Multi-location is a capability flag
 * (via FeatureAccessService) that controls whether the UI lets them
 * create more than one.
 *
 * Default location is the one with is_default=true. There can be only
 * one default per tenant (enforced in app logic, not DB constraint —
 * a unique partial index would work but Laravel migration syntax for
 * partial indexes is awkward).
 *
 * Per-location overrides for booking_window_days and min_notice_hours
 * fall back to tenant-level values when null. Same pattern as the
 * catalog/shop column-pair on inventory items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            // Identity
            $table->string('name', 128);
            $table->string('slug', 128); // url-safe, tenant-unique
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);

            // Address
            $table->string('address_line_1', 255)->nullable();
            $table->string('address_line_2', 255)->nullable();
            $table->string('city', 128)->nullable();
            $table->string('state', 64)->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->string('country', 2)->default('US');

            // Contact
            $table->string('phone', 32)->nullable();
            $table->string('email', 255)->nullable();

            // Operations
            $table->string('timezone', 64)->nullable(); // IANA, falls back to tenant timezone
            $table->unsignedSmallInteger('booking_window_days_override')->nullable();
            $table->unsignedSmallInteger('min_notice_hours_override')->nullable();

            // Future-proofing
            $table->json('settings')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'is_active', 'sort_order']);
            $table->index(['tenant_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_locations');
    }
};
