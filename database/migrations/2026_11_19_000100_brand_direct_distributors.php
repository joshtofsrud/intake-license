<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * MARKER-BRAND-DIRECT: brand-direct distributors (off for every shop until
 * master admin switches a shop on), the per-shop switch, and Kona as the
 * first one, with its field map.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('platform_distributor_connections', 'brand_direct')) {
            Schema::table('platform_distributor_connections', function (Blueprint $t) {
                $t->boolean('brand_direct')->default(false)->after('is_active');
            });
        }

        if (! Schema::hasTable('tenant_distributor_access')) {
            Schema::create('tenant_distributor_access', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
                $t->string('distributor_code', 16);
                $t->string('enabled_by', 191)->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'distributor_code'], 'tda_tenant_code_unique');
                $t->index('distributor_code', 'tda_code_idx');
            });
        }

        // Kona: brand-direct from the start, no login (its catalog is public)
        $row = DB::table('platform_distributor_connections')->where('distributor_code', 'KONA')->first();
        if ($row) {
            DB::table('platform_distributor_connections')->where('id', $row->id)
                ->update(['brand_direct' => true, 'updated_at' => now()]);
        } else {
            DB::table('platform_distributor_connections')->insert([
                'id' => (string) Str::uuid(), 'distributor_code' => 'KONA', 'region' => 'us',
                'auth_style' => 'authorization_apikey', 'is_active' => true, 'brand_direct' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        (new \Database\Seeders\KonaFieldMapSeeder())->run();

        // Kona's title recipe: the store's product title is the model alone
        // ("Rove AL"), so the title adds the brand; size and color are their
        // own fields. Edited in Title control afterwards like any rule.
        if (Schema::hasTable('catalog_title_settings') && ! DB::table('catalog_title_settings')
            ->where('distributor_code', 'KONA')
            ->where(fn ($q) => $q->whereNull('category_key')->orWhere('category_key', ''))->exists()) {
            DB::table('catalog_title_settings')->insert([
                'distributor_code'  => 'KONA',
                'category_key'      => '',
                'title_template'    => '{brand} {model}',
                'subtitle_template' => '{mpn}',
                'search_template'   => '{mpn} {brand} {model} {type0} {size} {color} {allattr}',
                'is_active'         => true,
                'notes'             => 'Kona\'s store title is the model alone; size and color come from their own fields.',
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_distributor_access');
        if (Schema::hasColumn('platform_distributor_connections', 'brand_direct')) {
            Schema::table('platform_distributor_connections', function (Blueprint $t) {
                $t->dropColumn('brand_direct');
            });
        }
    }
};
