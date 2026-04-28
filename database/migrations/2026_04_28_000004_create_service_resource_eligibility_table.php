<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service→Resource eligibility pivot. Defines which resources can perform
 * which services. EMPTY for a service = ALL active resources eligible
 * (the natural default; specialization is opt-in).
 *
 * Behavioral contract:
 *  - No rows for service X        -> any active resource can do X
 *  - 1+ rows for service X        -> only listed resources can do X
 *
 * Performance: composite index on (tenant_id, service_item_id) is the
 * primary lookup path — BookingService loads all eligibility rows for
 * a service at once. (tenant_id, resource_id) for the inverse.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_service_resource_eligibility', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('service_item_id')
                ->constrained('tenant_service_items')->cascadeOnDelete();
            $t->foreignUuid('resource_id')
                ->constrained('tenant_resources')->cascadeOnDelete();
            $t->timestamps();

            $t->unique(['service_item_id', 'resource_id'], 'tsre_unique');
            $t->index(['tenant_id', 'service_item_id'], 'tsre_tenant_service');
            $t->index(['tenant_id', 'resource_id'], 'tsre_tenant_resource');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_service_resource_eligibility');
    }
};
