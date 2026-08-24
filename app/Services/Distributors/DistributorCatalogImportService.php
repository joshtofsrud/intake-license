<?php
// MARKER-PATCH-HLC4A

namespace App\Services\Distributors;

use App\Models\PlatformDistributorCatalog;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantInventoryItemVendor;
use App\Models\Tenant\TenantVendor;

/**
 * Pulls rows from the shared distributor catalog into a tenant's catalog,
 * deduped on product_key, merging new distributor sources onto items already
 * carried. Synchronous + returns a receipt (every action a visible reaction).
 */
class DistributorCatalogImportService
{
    /**
     * @param array{category?:string,brand?:string,include_unsellable?:bool} $filters
     * @return array{tenant_id:string,code:string,matched_catalog:int,created:int,merged:int,skipped:int,dry_run:bool}
     */
    public function import(string $tenantId, string $distributorCode, array $filters = [], bool $dryRun = false, int $limit = 0): array
    {
        $code = strtoupper($distributorCode);
        $res = [
            'tenant_id' => $tenantId, 'code' => $code, 'matched_catalog' => 0,
            'created' => 0, 'merged' => 0, 'skipped' => 0, 'dry_run' => $dryRun,
        ];

        $candidates = $this->candidates($code, $filters, $limit);
        $res['matched_catalog'] = $candidates->count();
        if ($candidates->isEmpty()) {
            return $res;
        }

        $vendor = $dryRun ? null : $this->vendorFor($tenantId, $code);
        [$byKey, $byUpc, $linkedCatalog] = $this->existingIndexes($tenantId);

        // MARKER-IMPORT-MATCHES
        $matchedRows = $this->matchedRows($candidates->pluck('id')->all());

        foreach ($candidates as $cat) {
            // 1) exact same catalog row already sourced -> idempotent skip
            if (isset($linkedCatalog[$cat->id])) {
                $res['skipped']++;
                continue;
            }

            // MARKER-IMPORT-MATCHES
            // 2) a catalog row LINKED to this one is already carried.
            //
            // Checked before product_key and UPC because it is the only test
            // built from evidence rather than one field, and the only one
            // that works when a distributor ships no UPC — 4,207 HLC rows
            // carry an EAN and nothing else, and UPC matching is blind to
            // every one of them.
            $matchId = null;
            foreach (($matchedRows[$cat->id] ?? []) as $otherId) {
                if (isset($linkedCatalog[$otherId])) {
                    $matchId = $linkedCatalog[$otherId];
                    break;
                }
            }

            // 3) same physical product by key or barcode
            $key = $cat->product_key;
            if ($matchId === null) {
                $matchId = ($key && isset($byKey[$key]))
                    ? $byKey[$key]
                    : (($cat->upc && isset($byUpc[$cat->upc])) ? $byUpc[$cat->upc] : null);
            }

            if ($matchId) {
                if (! $dryRun) {
                    $this->addSource($matchId, $vendor, $code, $cat);
                }
                $linkedCatalog[$cat->id] = $matchId;
                $res['merged']++;
                continue;
            }

            // 3) new product -> create catalog-only item + source
            if (! $dryRun) {
                $item = $this->createItem($tenantId, $cat);
                $this->addSource($item->id, $vendor, $code, $cat);
                $id = $item->id;
            } else {
                $id = 'dry';
            }
            if ($key) {
                $byKey[$key] = $id;
            }
            if ($cat->upc) {
                $byUpc[$cat->upc] = $id;
            }
            $linkedCatalog[$cat->id] = $id;
            $res['created']++;
        }

        return $res;
    }

    private function candidates(string $code, array $filters, int $limit)
    {
        $q = PlatformDistributorCatalog::query()
            ->where('distributor_code', $code)
            ->where('is_active', true);

        if (empty($filters['include_unsellable'])) {
            $q->where(fn ($w) => $w->whereNull('is_sellable')->orWhere('is_sellable', true));
        }
        if (! empty($filters['category'])) {
            $q->where('category', 'like', '%' . $filters['category'] . '%');
        }
        if (! empty($filters['brand'])) {
            $q->where('manufacturer', 'like', '%' . $filters['brand'] . '%');
        }
        if ($limit > 0) {
            $q->limit($limit);
        }

        return $q->get();
    }

    /**
     * @return array{0:array<string,string>,1:array<string,string>,2:array<string,string>}
     *   [ product_key => item_id, catalog_upc => item_id, catalog_id => item_id ]
     */
    private function existingIndexes(string $tenantId): array
    {
        $items = TenantInventoryItem::query()
            ->where('tenant_id', $tenantId)
            ->get(['id', 'catalog_upc', 'distributor_catalog_id']);

        $itemIds = $items->pluck('id');
        $pivots = TenantInventoryItemVendor::query()
            ->whereIn('inventory_item_id', $itemIds)
            ->get(['inventory_item_id', 'distributor_catalog_id']);

        $catalogIds = $items->pluck('distributor_catalog_id')
            ->merge($pivots->pluck('distributor_catalog_id'))
            ->filter()->unique()->values();

        $keyByCatalog = PlatformDistributorCatalog::query()
            ->whereIn('id', $catalogIds)
            ->pluck('product_key', 'id');

        $byKey = [];
        $byUpc = [];
        $linked = [];

        foreach ($items as $it) {
            if ($it->catalog_upc) {
                $byUpc[$it->catalog_upc] = $it->id;
            }
            if ($it->distributor_catalog_id) {
                $linked[$it->distributor_catalog_id] = $it->id;
                $k = $keyByCatalog[$it->distributor_catalog_id] ?? null;
                if ($k) {
                    $byKey[$k] = $it->id;
                }
            }
        }
        foreach ($pivots as $p) {
            if ($p->distributor_catalog_id) {
                $linked[$p->distributor_catalog_id] = $p->inventory_item_id;
                $k = $keyByCatalog[$p->distributor_catalog_id] ?? null;
                if ($k) {
                    $byKey[$k] = $p->inventory_item_id;
                }
            }
        }

        return [$byKey, $byUpc, $linked];
    }

    /**
     * MARKER-IMPORT-MATCHES — catalog rows linked to each of these rows.
     *
     * Only `auto` and `confirmed` links are honoured. A `held` pair is a
     * question nobody has answered and a `rejected` pair is someone having
     * said no — merging on either would let the importer overrule a person.
     *
     * The pair table stores each pair once with the lower id first, so both
     * directions are read and folded into one map.
     *
     * @param  array<int,string> $catalogIds
     * @return array<string,array<int,string>> catalog id => linked catalog ids
     */
    private function matchedRows(array $catalogIds): array
    {
        if (! $catalogIds) {
            return [];
        }

        $out = [];

        \App\Models\CatalogMatch::query()
            ->whereIn('status', ['auto', 'confirmed'])
            ->where(function ($q) use ($catalogIds) {
                $q->whereIn('row_a_id', $catalogIds)
                  ->orWhereIn('row_b_id', $catalogIds);
            })
            ->select(['row_a_id', 'row_b_id'])
            ->chunk(2000, function ($rows) use (&$out) {
                foreach ($rows as $m) {
                    $out[$m->row_a_id][] = $m->row_b_id;
                    $out[$m->row_b_id][] = $m->row_a_id;
                }
            });

        return $out;
    }

    private function vendorFor(string $tenantId, string $code): TenantVendor
    {
        // MARKER-VENDOR-NET-COST — prefer the explicit link.
        //
        // This used to match on NAME alone, so the vendor ended up literally
        // called "BTI" and a shop that already had "Bicycle Technologies
        // International" quietly got a second one, with every imported item
        // pointing at it. Matching on distributor_code first means a vendor
        // the shop linked by hand wins, whatever it's called.
        $linked = TenantVendor::where('tenant_id', $tenantId)
            ->where('distributor_code', strtolower($code))
            ->first();
        if ($linked) {
            return $linked;
        }

        // Fallback for installs that predate the link: match the old naming
        // convention and stamp the code, so this heals on first import.
        $byName = TenantVendor::where('tenant_id', $tenantId)
            ->whereRaw('LOWER(name) = ?', [strtolower($code)])
            ->first();
        if ($byName) {
            $byName->update(['distributor_code' => strtolower($code)]);
            return $byName;
        }

        return TenantVendor::create([
            'tenant_id'        => $tenantId,
            'name'             => $code,
            'distributor_code' => strtolower($code),
            'is_active'        => true,
        ]);
    }

    private function createItem(string $tenantId, PlatformDistributorCatalog $cat): TenantInventoryItem
    {
        return TenantInventoryItem::create([
            'tenant_id'              => $tenantId,
            'sku'                    => $cat->product_key ?: $cat->distributor_variant_no,
            'name'                   => $cat->display_name ?: ($cat->name ?: $cat->distributor_variant_no),
            'display_subtitle'       => $cat->display_subtitle,
            // MARKER-IMPORT-DESC — vendor copy, on create only. HLC and BTI
            // rarely supply it; QBP's bullet points do. Never written on a
            // merge: a shop's own description outranks a distributor's.
            'description'            => $cat->description ?: null,
            'distributor_catalog_id' => $cat->id,
            'catalog_cost_cents'     => null, // per-tenant cost arrives via tier-2 on the pivot
            'catalog_msrp_cents'     => $cat->msrp_cents,
            'catalog_map_cents'      => $cat->map_cents,
            'catalog_case_quantity'  => $cat->case_quantity,
            'catalog_upc'            => $cat->upc,
            // MARKER-CATALOG-COLORSIZE — the columns added in May finally get
            // a value. On CREATE only: a shop's own edit outranks the feed,
            // same rule the description above follows.
            'color'                  => $cat->color ?: null,
            'size'                   => $cat->size ?: null,
            'catalog_title_seen'     => $cat->display_name, // baseline for the title-change watch
            'shop_sell_price_cents'  => $cat->map_cents ?? $cat->msrp_cents, // first-link seed
            'computed_stock_count'   => 0,
            'is_stock_tracked'       => false, // catalog-only
            'is_active'              => true,
        ]);
    }

    private function addSource(string $itemId, TenantVendor $vendor, string $code, PlatformDistributorCatalog $cat): void
    {
        TenantInventoryItemVendor::firstOrCreate(
            ['inventory_item_id' => $itemId, 'vendor_id' => $vendor->id],
            [
                'distributor_code'       => $code,
                'distributor_catalog_id' => $cat->id,
                'vendor_sku'             => $cat->distributor_variant_no,
            ],
        );
    }
}
