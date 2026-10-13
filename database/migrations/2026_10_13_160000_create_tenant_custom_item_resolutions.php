<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-CUSTOM-ITEMS: what a shop decided about a custom (typed-in) register
 * line, keyed by the name as typed and its price. Sale lines are never edited.
 *
 *   added    became inventory item inventory_item_id
 *   matched  is inventory item inventory_item_id (for reports only)
 *   skipped  not inventory (labor, shop supplies, one-off); reason
 *
 * A decision covers the lines rung up before resolved_at; the same name rung
 * up again later shows again. price_cents NULL with ignore_always = "never
 * list this name", whatever the price.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_custom_item_resolutions')) {
            return;
        }
        Schema::create('tenant_custom_item_resolutions', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name_key', 191);
            $t->integer('price_cents')->nullable();
            $t->string('status', 16);
            $t->uuid('inventory_item_id')->nullable();
            $t->string('reason', 40)->nullable();
            $t->boolean('ignore_always')->default(false);
            $t->string('display_name', 255)->nullable();
            $t->uuid('resolved_by_user_id')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'name_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_custom_item_resolutions');
    }
};
