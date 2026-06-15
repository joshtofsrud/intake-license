<?php
// MARKER-PATCH-HLC3A

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The editable mapping grid — master admin's single source of truth for how a
 * distributor's feed fills the canonical item. One row per (distributor,
 * canonical_field). Seeded with HLC's mapping; every value is editable.
 *
 *   source_path     where the value comes from ('VariantNo', 'Prices',
 *                   'CaseDimensions.Quantity'). null when the transform builds
 *                   the value itself (e.g. coalesce).
 *   transform       direct | bool | pick_from_array | lookup | coalesce |
 *                   pick_category_level | join_array | json_passthrough
 *   transform_args  json — match/field/cast/level/order/sep/default…
 *   lookup_table    json — value->value table for the lookup transform.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distributor_field_maps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('distributor_code', 32);
            $table->string('canonical_field', 64);
            $table->string('source_path', 255)->nullable();
            $table->string('transform', 40)->default('direct');
            $table->json('transform_args')->nullable();
            $table->json('lookup_table')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['distributor_code', 'canonical_field'], 'dfm_dist_field_unique');
            $table->index(['distributor_code', 'is_active'], 'dfm_dist_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distributor_field_maps');
    }
};
