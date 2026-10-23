<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brand control for every distributor, at two levels.
 *
 *  - distributor_brand_settings: the PLATFORM switch. Which brands of a
 *    distributor Intake ingests into the shared catalog at all.
 *  - platform_distributor_connections.brand_default: what a brand with no
 *    switch of its own does. 'on' (the default, so nothing already running
 *    changes) means every brand comes in unless switched off; 'off' means only
 *    brands switched on come in.
 *  - tenant_distributor_brand_settings: a SHOP's switch. Which of the shared
 *    catalog's brands that shop is offered on its import screen. set_by says
 *    who made the call: 'tenant' rows the shop can change; 'admin' rows were
 *    set from master admin and the shop cannot change them.
 *
 * Additive only. Turning a brand off never touches a shop's items: these
 * tables only decide what NEW catalog rows are brought in or offered.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('distributor_brand_settings')) {
            Schema::create('distributor_brand_settings', function (Blueprint $t) {
                $t->id();
                $t->string('distributor_code', 32);
                $t->string('brand_key', 128);     // lower-cased, trimmed brand name
                $t->string('brand_name', 128);    // as the distributor spells it
                $t->boolean('enabled')->default(true);
                $t->string('updated_by', 64)->nullable();
                $t->timestamps();
                $t->unique(['distributor_code', 'brand_key'], 'dbs_code_brand_unique');
            });
        }

        if (Schema::hasTable('platform_distributor_connections')
            && ! Schema::hasColumn('platform_distributor_connections', 'brand_default')) {
            Schema::table('platform_distributor_connections', function (Blueprint $t) {
                $t->string('brand_default', 8)->default('on');
            });
        }

        if (! Schema::hasTable('tenant_distributor_brand_settings')) {
            Schema::create('tenant_distributor_brand_settings', function (Blueprint $t) {
                $t->id();
                $t->uuid('tenant_id');
                $t->string('distributor_code', 32);
                $t->string('brand_key', 128);
                $t->string('brand_name', 128);
                $t->boolean('enabled')->default(true);
                $t->string('set_by', 8)->default('tenant');   // tenant | admin
                $t->string('updated_by', 64)->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'distributor_code', 'brand_key'], 'tdbs_tenant_code_brand_unique');
                $t->index(['tenant_id', 'distributor_code'], 'tdbs_tenant_code_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_distributor_brand_settings');
        Schema::dropIfExists('distributor_brand_settings');
        if (Schema::hasColumn('platform_distributor_connections', 'brand_default')) {
            Schema::table('platform_distributor_connections', fn (Blueprint $t) => $t->dropColumn('brand_default'));
        }
    }
};
