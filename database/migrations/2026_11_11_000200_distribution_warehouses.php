<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-DISTRIBUTION-WAREHOUSES: supplier warehouses as real rows, so master admin
// can show them to shops and switch dropshipping on per warehouse, and each shop
// can pick the ones it works with. Additive only.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_distribution_warehouses')) {
            Schema::create('platform_distribution_warehouses', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('distributor_code', 24);
                $t->string('code', 64);                 // the code the distributor's feed uses
                $t->string('name', 120);
                $t->string('ship_from_zip', 10)->nullable();
                $t->string('ship_from_city', 80)->nullable();
                $t->string('ship_from_state', 40)->nullable();
                $t->unsignedSmallInteger('handling_days')->default(2);
                $t->boolean('shown_to_shops')->default(false);   // shops may see its stock counts
                $t->boolean('dropship_enabled')->default(false); // shops may ship from it
                $t->timestamp('first_seen_at')->nullable();
                $t->timestamp('last_seen_at')->nullable();
                $t->timestamps();
                $t->unique(['distributor_code', 'code'], 'pdw_dist_code');
            });
        }

        if (! Schema::hasTable('tenant_warehouse_selections')) {
            Schema::create('tenant_warehouse_selections', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
                $t->uuid('warehouse_id');
                $t->boolean('enabled')->default(false);
                $t->unsignedSmallInteger('priority')->default(0);
                $t->timestamps();
                $t->unique(['tenant_id', 'warehouse_id'], 'tws_tenant_wh');
                $t->index('warehouse_id', 'tws_wh');
            });
        }

        if (Schema::hasTable('platform_settings') && ! Schema::hasColumn('platform_settings', 'distribution_settings')) {
            Schema::table('platform_settings', function (Blueprint $t) {
                $t->json('distribution_settings')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_warehouse_selections');
        Schema::dropIfExists('platform_distribution_warehouses');
        if (Schema::hasColumn('platform_settings', 'distribution_settings')) {
            Schema::table('platform_settings', fn (Blueprint $t) => $t->dropColumn('distribution_settings'));
        }
    }
};
