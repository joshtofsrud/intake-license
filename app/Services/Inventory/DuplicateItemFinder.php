<?php
// MARKER-DUP-MERGE

namespace App\Services\Inventory;

use App\Models\Tenant\TenantDuplicateGroup;
use App\Support\Barcode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finds inventory items that are the same product and records them as
 * tenant_duplicate_groups for Inventory › Duplicates.
 *
 * Same product means:
 *   - barcode: any barcode on one item equals any barcode on another, UPC /
 *     EAN / barcode-SKU as one pool (App\Support\Barcode). Chains join: A
 *     shares with B and B with C makes one group of three.
 *   - part number: ONLY for items with no barcode at all — the same brand
 *     and part number. These always go to "Needs a look"; a part number is
 *     weaker evidence than a barcode.
 *
 * The item KEPT is the catalog one: linked to a distributor catalog row (its
 * title, description and images are the good ones), then the shop's
 * first-choice distributor, then the most sales, then the oldest. The shop's
 * own price, cost, stock, bin and sales come across from the others when the
 * merge runs (DuplicateItemMerger).
 *
 * "Needs a look" when: a part-number match; stock on more than one copy (it
 * would be added together — the same shelf may have been counted twice); or
 * two different prices the SHOP set. Everything else is "ready".
 *
 * Decisions stick: a group someone dismissed or merged is never rewritten.
 */
class DuplicateItemFinder
{
    private const JUNK_MPN = ['NA', 'NONE', 'NULL', 'TBD', '0000', 'XXXX'];

    /** @return array{ready:int, review:int, cleared:int} */
    public function find(string $tenantId): array
    {
        $items = DB::table('tenant_inventory_items as i')
            ->leftJoin('platform_distributor_catalogs as c', 'c.id', '=', 'i.distributor_catalog_id')
            ->where('i.tenant_id', $tenantId)->whereNull('i.deleted_at')
            ->get(['i.id', 'i.name', 'i.sku', 'i.catalog_upc', 'i.catalog_ean', 'i.catalog_mpn', 'i.shop_brand',
                   'c.manufacturer', 'i.shop_sell_price_cents', 'i.catalog_msrp_cents', 'i.distributor_catalog_id', 'i.created_at'])
            ->keyBy('id');

        // ---- barcode groups (union-find over the barcode pool) --------------
        $parent = [];
        $find = function ($x) use (&$parent, &$find) {
            return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x]));
        };
        $owner = [];
        $codes = [];
        foreach ($items as $id => $i) {
            $parent[$id] = $id;
            foreach (Barcode::keys($i->catalog_upc, $i->catalog_ean, $i->sku) as $k) {
                $codes[$id][] = $k;
                if (isset($owner[$k])) {
                    $parent[$find($id)] = $find($owner[$k]);
                } else {
                    $owner[$k] = $id;
                }
            }
        }
        $groups = [];
        foreach ($items as $id => $i) {
            if (isset($codes[$id])) {
                $groups[$find($id)][] = $id;
            }
        }
        $sets = [];
        foreach ($groups as $g) {
            if (count($g) > 1) {
                $label = [];
                foreach ($g as $id) { foreach ($codes[$id] as $k) { $label[$k] = true; } }
                $sets[] = ['kind' => 'barcode', 'ids' => $g, 'label' => 'barcode ' . implode(' / ', array_slice(array_keys($label), 0, 3))];
            }
        }

        // ---- part-number groups: items with no barcode only -----------------
        $norm = fn ($v) => preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $v));
        $byPart = [];
        foreach ($items as $id => $i) {
            if (isset($codes[$id])) { continue; }
            $b = $norm($i->shop_brand ?: $i->manufacturer);
            $m = $norm($i->catalog_mpn);
            if ($b === '' || strlen($m) < 2 || in_array($m, self::JUNK_MPN, true)) { continue; }
            $byPart[$b . '|' . $m][] = $id;
        }
        foreach ($byPart as $key => $g) {
            if (count($g) > 1) {
                $sets[] = ['kind' => 'part_number', 'ids' => $g, 'label' => 'part number ' . $key];
            }
        }

        // ---- history for every grouped item ---------------------------------
        $ids = $sets ? array_merge(...array_column($sets, 'ids')) : [];
        $sales = $recv = $stock = $locs = $vend = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach (DB::table('tenant_sale_items')->whereIn('inventory_item_id', $chunk)
                ->selectRaw('inventory_item_id k, COUNT(*) n')->groupBy('k')->get() as $r) { $sales[$r->k] = (int) $r->n; }
            foreach (DB::table('tenant_inventory_receive_shipment_items')->whereIn('inventory_item_id', $chunk)
                ->selectRaw('inventory_item_id k, COUNT(*) n')->groupBy('k')->get() as $r) { $recv[$r->k] = (int) $r->n; }
            foreach (DB::table('tenant_inventory_item_locations')->whereIn('inventory_item_id', $chunk)
                ->where('computed_stock_count', '!=', 0)->get(['inventory_item_id', 'location_id', 'computed_stock_count']) as $r) {
                $stock[$r->inventory_item_id] = ($stock[$r->inventory_item_id] ?? 0) + (int) $r->computed_stock_count;
                $locs[$r->inventory_item_id][$r->location_id] = (int) $r->computed_stock_count;
            }
            foreach (DB::table('tenant_inventory_item_vendors')->whereIn('inventory_item_id', $chunk)
                ->whereNotNull('distributor_code')->get(['inventory_item_id', 'distributor_code']) as $r) {
                $vend[$r->inventory_item_id][strtoupper($r->distributor_code)] = true;
            }
        }
        $rank = DB::table('tenant_distributor_catalog_subscriptions')->where('tenant_id', $tenantId)
            ->pluck('data_priority', 'distributor_code')
            ->mapWithKeys(fn ($v, $k) => [strtoupper($k) => (int) $v])->all();

        $linked   = fn ($id) => $items[$id]->distributor_catalog_id !== null || ! empty($vend[$id]);
        $bestRank = fn ($id) => empty($vend[$id]) ? 999 : min(array_map(fn ($c) => $rank[$c] ?? 99, array_keys($vend[$id])));

        // ---- store -----------------------------------------------------------
        $now = now();
        $seen = [];
        $counts = ['ready' => 0, 'review' => 0, 'cleared' => 0];
        $existing = TenantDuplicateGroup::where('tenant_id', $tenantId)->get()->keyBy('group_key');

        foreach ($sets as $s) {
            $g = $s['ids'];
            usort($g, fn ($a, $b) => [(int) ! $linked($a), $bestRank($a), -($sales[$a] ?? 0), -($recv[$a] ?? 0), (string) $items[$a]->created_at]
                                  <=> [(int) ! $linked($b), $bestRank($b), -($sales[$b] ?? 0), -($recv[$b] ?? 0), (string) $items[$b]->created_at]);

            $sorted = $g;
            sort($sorted);
            $key = sha1(implode(',', $sorted));
            $seen[$key] = true;

            $shopPrices = array_values(array_unique(array_filter(array_map(fn ($id) => $items[$id]->shop_sell_price_cents, $g), fn ($p) => $p !== null)));
            $withStock  = array_values(array_filter($g, fn ($id) => ($stock[$id] ?? 0) !== 0));

            $reasons = [];
            if ($s['kind'] === 'part_number') { $reasons[] = 'part_number'; }
            if (count($withStock) > 1)          { $reasons[] = 'stock_on_both'; }
            if (count($shopPrices) > 1)         { $reasons[] = 'price_conflict'; }

            // What the merged item would be: the kept record, the shop's price
            // (the first copy in keep order that has one), stock added up.
            $resultPrice = null;
            foreach ($g as $id) {
                if ($items[$id]->shop_sell_price_cents !== null) { $resultPrice = (int) $items[$id]->shop_sell_price_cents; break; }
            }
            $resultPrice ??= $items[$g[0]]->catalog_msrp_cents !== null ? (int) $items[$g[0]]->catalog_msrp_cents : null;

            $stockLocations = [];
            foreach ($withStock as $id) { foreach ($locs[$id] ?? [] as $loc => $n) { $stockLocations[$loc] = true; } }

            $preview = [
                'copies' => array_map(fn ($id) => [
                    'id'          => $id,
                    'name'        => (string) $items[$id]->name,
                    'sku'         => (string) $items[$id]->sku,
                    'from'        => array_keys($vend[$id] ?? []),
                    'catalog'     => $linked($id),
                    'shop_price'  => $items[$id]->shop_sell_price_cents !== null ? (int) $items[$id]->shop_sell_price_cents : null,
                    'list_price'  => $items[$id]->catalog_msrp_cents !== null ? (int) $items[$id]->catalog_msrp_cents : null,
                    'stock'       => $stock[$id] ?? 0,
                    'sales'       => $sales[$id] ?? 0,
                ], $g),
                'result_price'    => $resultPrice,
                'result_stock'    => array_sum(array_map(fn ($id) => $stock[$id] ?? 0, $g)),
                // A stock choice is only offered when every copy's stock sits
                // at one location — then "keep N" has one obvious meaning.
                'stock_location'  => count($stockLocations) === 1 ? array_key_first($stockLocations) : null,
            ];

            $row = $existing[$key] ?? null;
            if ($row && in_array($row->status, ['merged', 'dismissed'], true)) {
                continue;
            }
            $status = $reasons ? 'review' : 'ready';
            $counts[$status]++;

            $attrs = [
                'kind' => $s['kind'], 'label' => Str::limit($s['label'], 188), 'status' => $status,
                'reasons' => $reasons, 'keep_item_id' => $g[0], 'item_ids' => $g,
                'preview' => $preview, 'error' => null, 'found_at' => $now,
            ];
            if ($row) {
                $row->forceFill($attrs)->save();
            } else {
                TenantDuplicateGroup::create(['tenant_id' => $tenantId, 'group_key' => $key] + $attrs);
            }
        }

        // Open groups that no longer exist (merged by hand, item deleted, a
        // barcode corrected) are cleared. Decisions are kept.
        foreach ($existing as $key => $row) {
            if (! isset($seen[$key]) && in_array($row->status, TenantDuplicateGroup::OPEN, true)) {
                $row->delete();
                $counts['cleared']++;
            }
        }

        return $counts;
    }
}
