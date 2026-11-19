<?php

use App\Models\Tenant\TenantDistributorCatalogSubscription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-BRAND-STOCK: a brand sold direct is switched on per shop by master
 * admin, and that is the whole connection. Shops already switched on are
 * connected here. The brand's own web-store "available" flag stops deciding
 * what a dealer may import: it means stock for the public, not for dealers.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('platform_distributor_connections', 'brand_direct') || ! Schema::hasTable('tenant_distributor_access')) {
            return;
        }
        $codes = DB::table('platform_distributor_connections')->where('brand_direct', true)
            ->pluck('distributor_code')->map(fn ($c) => strtoupper((string) $c))->all();
        if (! $codes) {
            return;
        }

        DB::table('distributor_field_maps')->whereIn('distributor_code', $codes)->where('canonical_field', 'is_sellable')->delete();
        DB::table('platform_distributor_catalogs')->whereIn('distributor_code', $codes)->update(['is_sellable' => null]);

        foreach (DB::table('tenant_distributor_access')->whereIn('distributor_code', $codes)->get(['tenant_id', 'distributor_code']) as $a) {
            $sub = TenantDistributorCatalogSubscription::firstOrCreate(
                ['tenant_id' => $a->tenant_id, 'distributor_code' => strtoupper($a->distributor_code)],
                ['is_active' => false, 'price_seed' => 'msrp']
            );
            $creds = (array) ($sub->credentials_encrypted ?? []);
            $creds['api_key'] = 'platform';
            $creds['region'] = $creds['region'] ?? 'us';
            $sub->credentials_encrypted = $creds;
            $sub->is_active = true;
            $sub->save();
        }
    }

    public function down(): void
    {
    }
};
