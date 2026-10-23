<?php

namespace App\Services\Distributors;

use App\Models\PlatformDistributorConnection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * One place that answers "is this brand in?" for every distributor.
 *
 * Two levels, and neither of them ever removes anything a shop already owns:
 *
 *   PLATFORM (master admin, per distributor). Decides which brands are brought
 *   into the shared catalog by the sync. A brand that is switched off is not
 *   fetched, EXCEPT that catalog rows a shop already carries keep refreshing
 *   (see protectedVariantNos), so an item a shop has on its shelf and can sell
 *   never goes stale or loses its source.
 *
 *   SHOP (per tenant, per distributor). Decides which brands the shop is
 *   offered on its import screen. Master admin can set a shop's brand from its
 *   own screen for shops with the distributor add-on; those rows are marked
 *   set_by = 'admin' and the shop cannot change them.
 *
 * Neither level touches tenant_inventory_items. A brand being off only means
 * "do not bring new rows in / do not offer new rows".
 */
class BrandControl
{
    /** Lower-cased, trimmed brand name: the key every table here joins on. */
    public static function key(?string $brand): string
    {
        return mb_strtolower(trim((string) $brand));
    }

    // ------------------------------------------------------------ platform

    /** 'on' = every brand unless switched off. 'off' = only brands switched on. */
    public function platformMode(string $code): string
    {
        $mode = (string) PlatformDistributorConnection::query()
            ->where('distributor_code', strtoupper($code))->value('brand_default');

        return $mode === 'off' ? 'off' : 'on';
    }

    public function setPlatformMode(string $code, string $mode): void
    {
        $code = strtoupper($code);
        PlatformDistributorConnection::forCode($code);   // make sure the row exists
        PlatformDistributorConnection::query()->where('distributor_code', $code)
            ->update(['brand_default' => $mode === 'off' ? 'off' : 'on']);
    }

    /** @return array<string,bool> brand_key => enabled, explicit switches only */
    public function platformSwitches(string $code): array
    {
        return DB::table('distributor_brand_settings')
            ->where('distributor_code', strtoupper($code))
            ->pluck('enabled', 'brand_key')
            ->map(fn ($v) => (bool) $v)
            ->all();
    }

    public function platformAllows(string $code, string $brand, ?string $mode = null, ?array $switches = null): bool
    {
        $mode     ??= $this->platformMode($code);
        $switches ??= $this->platformSwitches($code);
        $k = self::key($brand);

        if (array_key_exists($k, $switches)) {
            return $switches[$k];
        }
        return $mode === 'on';
    }

    public function setPlatform(string $code, string $brand, bool $enabled, ?string $by = null): void
    {
        $now = now();
        DB::table('distributor_brand_settings')->updateOrInsert(
            ['distributor_code' => strtoupper($code), 'brand_key' => self::key($brand)],
            ['brand_name' => trim($brand), 'enabled' => $enabled, 'updated_by' => $by,
             'updated_at' => $now, 'created_at' => $now],
        );
    }

    /**
     * Variant numbers of this brand's catalog rows that a shop already carries.
     *
     * "Carries" is deliberately wider than "has in stock": a row linked to ANY
     * shop item, as the item's own catalog row or as one of its vendor sources.
     * Those keep syncing when the brand is switched off, because the shop's
     * stocked, sellable item depends on them. Cheap to be generous here; an
     * unlinked row costs nothing to leave out.
     *
     * @return array<int,string>
     */
    public function protectedVariantNos(string $code, string $brand): array
    {
        return DB::table('platform_distributor_catalogs as c')
            ->where('c.distributor_code', strtoupper($code))
            ->whereRaw('LOWER(TRIM(c.manufacturer)) = ?', [self::key($brand)])
            ->where(function ($w) {
                $w->whereExists(fn ($e) => $e->selectRaw('1')->from('tenant_inventory_items as i')
                        ->whereColumn('i.distributor_catalog_id', 'c.id'))
                  ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('tenant_inventory_item_vendors as v')
                        ->whereColumn('v.distributor_catalog_id', 'c.id'));
            })
            ->pluck('c.distributor_variant_no')
            ->map(fn ($v) => (string) $v)
            ->all();
    }

    /** Should the sync look at this brand at all? Switched on, or still carried by a shop. */
    public function syncable(string $code, string $brand, ?string $mode = null, ?array $switches = null): bool
    {
        if ($this->platformAllows($code, $brand, $mode, $switches)) {
            return true;
        }
        return DB::table('platform_distributor_catalogs as c')
            ->where('c.distributor_code', strtoupper($code))
            ->whereRaw('LOWER(TRIM(c.manufacturer)) = ?', [self::key($brand)])
            ->where(function ($w) {
                $w->whereExists(fn ($e) => $e->selectRaw('1')->from('tenant_inventory_items as i')
                        ->whereColumn('i.distributor_catalog_id', 'c.id'))
                  ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('tenant_inventory_item_vendors as v')
                        ->whereColumn('v.distributor_catalog_id', 'c.id'));
            })
            ->exists();
    }

    // -------------------------------------------------------------- tenant

    /** @return array<string,object> brand_key => row (enabled, set_by, brand_name) */
    public function tenantRows(string $tenantId, string $code): array
    {
        return DB::table('tenant_distributor_brand_settings')
            ->where('tenant_id', $tenantId)
            ->where('distributor_code', strtoupper($code))
            ->get()->keyBy('brand_key')->all();
    }

    /** A shop's own call, refused when master admin has set that brand. */
    public function setTenant(string $tenantId, string $code, string $brand, bool $enabled, string $setBy = 'tenant', ?string $by = null): bool
    {
        $code = strtoupper($code);
        $k    = self::key($brand);

        $existing = DB::table('tenant_distributor_brand_settings')
            ->where('tenant_id', $tenantId)->where('distributor_code', $code)->where('brand_key', $k)->first();

        if ($existing && $existing->set_by === 'admin' && $setBy !== 'admin') {
            return false;   // locked by master admin
        }

        // A shop switching a brand back ON leaves nothing behind: no row
        // means on, so the table only ever holds exceptions.
        if ($enabled && $setBy === 'tenant') {
            DB::table('tenant_distributor_brand_settings')
                ->where('tenant_id', $tenantId)->where('distributor_code', $code)->where('brand_key', $k)->delete();
            return true;
        }

        $now = now();
        DB::table('tenant_distributor_brand_settings')->updateOrInsert(
            ['tenant_id' => $tenantId, 'distributor_code' => $code, 'brand_key' => $k],
            ['brand_name' => trim($brand), 'enabled' => $enabled, 'set_by' => $setBy,
             'updated_by' => $by, 'updated_at' => $now, 'created_at' => $now],
        );
        return true;
    }

    /** Master admin hands a brand back to the shop. */
    public function releaseTenant(string $tenantId, string $code, string $brand): void
    {
        DB::table('tenant_distributor_brand_settings')
            ->where('tenant_id', $tenantId)->where('distributor_code', strtoupper($code))
            ->where('brand_key', self::key($brand))->delete();
    }

    // ------------------------------------------------------------- queries

    /**
     * Narrow a platform_distributor_catalogs query to the brands that are in.
     * Pass a tenant id to also apply that shop's own switches.
     *
     * Rows with no manufacturer are never hidden by a "switched off" list: a
     * blank is not a brand, and hiding it would lose rows nobody chose to lose.
     *
     * @param EloquentBuilder|QueryBuilder $q
     * @return EloquentBuilder|QueryBuilder
     */
    public function constrain($q, string $code, ?string $tenantId = null)
    {
        $code     = strtoupper($code);
        $mode     = $this->platformMode($code);
        $switches = $this->platformSwitches($code);
        $expr     = DB::raw('LOWER(TRIM(manufacturer))');

        $off = array_keys(array_filter($switches, fn ($on) => ! $on));
        $on  = array_keys(array_filter($switches, fn ($on) => $on));

        if ($mode === 'off') {
            $on ? $q->whereIn($expr, $on) : $q->whereRaw('1 = 0');
        } elseif ($off) {
            $q->where(fn ($w) => $w->whereNull('manufacturer')->orWhereNotIn($expr, $off));
        }

        if ($tenantId !== null) {
            $tenantOff = DB::table('tenant_distributor_brand_settings')
                ->where('tenant_id', $tenantId)->where('distributor_code', $code)
                ->where('enabled', false)->pluck('brand_key')->all();
            if ($tenantOff) {
                $q->where(fn ($w) => $w->whereNull('manufacturer')->orWhereNotIn($expr, $tenantOff));
            }
        }

        return $q;
    }

    /**
     * Brands a shop can be offered for one distributor, with item counts and
     * the shop's own state. Platform-off brands are not listed: they are not in
     * the shared catalog's offer, so there is nothing for the shop to decide.
     *
     * @return array<int,array{name:string,key:string,items:int,enabled:bool,locked:bool}>
     */
    public function brandsForTenant(string $tenantId, string $code): array
    {
        $code = strtoupper($code);

        $q = DB::table('platform_distributor_catalogs')
            ->where('distributor_code', $code)->where('is_active', true)
            ->whereNotNull('manufacturer')->whereRaw("TRIM(manufacturer) <> ''");
        $q = $this->constrain($q, $code, null);

        $counts = $q->select('manufacturer', DB::raw('COUNT(*) as n'))
            ->groupBy('manufacturer')->orderBy('manufacturer')->get();

        $rows = $this->tenantRows($tenantId, $code);
        $out  = [];
        foreach ($counts as $c) {
            $k   = self::key($c->manufacturer);
            $row = $rows[$k] ?? null;
            $out[$k] = [
                'name'    => (string) $c->manufacturer,
                'key'     => $k,
                'items'   => (int) $c->n,
                'enabled' => $row ? (bool) $row->enabled : true,
                'locked'  => $row !== null && $row->set_by === 'admin',
            ];
        }
        return array_values($out);
    }
}
