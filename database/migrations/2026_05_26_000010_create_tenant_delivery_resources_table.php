<?php
// MARKER-PATCH-152A

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tenant_delivery_resources — delivery-only resource concept.
 *
 * Deliberately separate from tenant_resources to keep appointment
 * scheduling concerns isolated from delivery logistics. A "delivery
 * resource" is a vehicle, driver lane, or in-shop drop slot you can
 * assign deliveries to (time-slot mode only — capacity tenants do
 * not see this).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_delivery_resources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name', 120);
            $table->string('subtitle', 160)->nullable();
            $table->char('color_hex', 7)->default('#60A5FA');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['tenant_id', 'is_active', 'sort_order'], 'tdr_tenant_active_sort');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_delivery_resources');
    }
};