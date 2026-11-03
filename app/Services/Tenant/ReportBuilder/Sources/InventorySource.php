<?php

namespace App\Services\Tenant\ReportBuilder\Sources;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Inventory — a snapshot of what's on hand today, plus pace measures (weeks
 * of cover, turns, sell-through) worked out from sales in the date range.
 * Cost uses the cost you paid, then your set cost, then the distributor cost,
 * the same order as stock valuation everywhere else. Negative stock counts
 * as zero. Age is days since the item was last received (or added, if it has
 * never been received).
 */
class InventorySource extends Source
{
    public const BUCKETS = ['0–30 days', '31–90 days', '91–180 days', '181–365 days', 'Over a year'];

    public function meta(): array
    {
        return [
            'label' => 'Inventory',
            'note'  => 'A snapshot as of today. Weeks of cover, days left, turns and sell-through use sales in the date range.',
            'snap'  => true,
            'cav'   => ['Age is days since an item was last received, or added if it was never received.', 'Negative stock counts as zero.'],
            'measures' => [
                'units'  => ['Units on hand', 'n'],
                'cost'   => ['Value at cost', '$'],
                'retail' => ['Value at retail', '$'],
                'items'  => ['Items in stock', 'n'],
                'cover'  => ['Weeks of cover', 'd'],
                'dleft'  => ['Days of stock left', 'd'],
                'turns'  => ['Turns (a year, at this pace)', 'd'],
                'st'     => ['Sell-through', '%'],
                'dead'   => ['Dead stock', '$'],
                'a1'     => ['Aged 0–30 days', '$'],
                'a2'     => ['Aged 31–90 days', '$'],
                'a3'     => ['Aged 91–180 days', '$'],
                'a4'     => ['Aged over 180 days', '$'],
            ],
            'dims' => [
                'cat' => ['Category', 'c'], 'loc' => ['Location', 'c'], 'vend' => ['Vendor', 'c'], 'brand' => ['Brand', 'c'],
                'bucket' => ['Age', 'c'], 'item' => ['Item', 'c'], 'mband' => ['Margin range', 'c'], 'pband' => ['Price band', 'c'],
            ],
            'filters' => ['cat' => 'Category', 'loc' => 'Location', 'vend' => 'Vendor', 'brand' => 'Brand', 'bucket' => 'Age',
                'mband' => 'Margin range', 'pband' => 'Price band'],
        ];
    }

    public function options(string $key): array
    {
        return match ($key) {
            'cat'    => $this->activeCategories() + ['uncat' => 'Uncategorized'],
            'loc'    => $this->names('loc'),
            'vend'   => $this->names('vend') + ['none' => 'No vendor'],
            'brand'  => $this->brandOptions(),
            'bucket' => array_combine(['0', '1', '2', '3', '4'], self::BUCKETS),
            'mband', 'pband' => $this->bandOptions($key),
            default  => [],
        };
    }

    private function brandOptions(): array
    {
        $rows = DB::table('tenant_inventory_items as it')
            ->join('platform_distributor_catalogs as pc', 'pc.id', '=', 'it.distributor_catalog_id')
            ->where('it.tenant_id', $this->tid())->where('it.is_active', 1)->whereNull('it.deleted_at')
            ->whereNotNull('pc.manufacturer')->where('pc.manufacturer', '!=', '')
            ->distinct()->orderBy('pc.manufacturer')->pluck('pc.manufacturer')->all();
        $out = [];
        foreach ($rows as $b) {
            $out[mb_strtolower(trim($b))] = trim($b);
        }
        return $out + ['none' => 'No brand'];
    }

    public function deadDays(): int
    {
        $v = data_get($this->tenant->settings, 'inventory.dead_stock_days');
        return is_numeric($v) && (int) $v > 0 ? (int) $v : 180;
    }

    /** What every run needs, worked out once per request: a Reports tab runs this source several times over the same catalog. */
    private array $prep = [];

    /** When another report is the main one, only items it can match are needed: the ones that sold in the range. */
    private bool $onlySold = false;

    public function setOnlySold(bool $v): void
    {
        $this->onlySold = $v;
    }

    /** How many items would run out within $days at the pace they sold in the range. No rows are built, so it stays cheap on a big catalog. */
    public function countRunningOut(Carbon $from, Carbon $to, int $days): int
    {
        $P = $this->prepared(false, $from, $to);
        $perDayDiv = max(1, $this->p->days($from, $to));
        $n = 0;
        foreach ($P['rows'] as $r) {
            $su = $P['sold'][$r[0]][0] ?? 0.0;
            $perDay = $su / $perDayDiv;
            if ($perDay > 0 && max(0, $r[4]) / $perDay < $days) $n++;
        }
        return $n;
    }

    private function prepared(bool $byLoc, Carbon $from, Carbon $to): array
    {
        $key = ($byLoc ? 'L' : 'N') . $from->toDateString() . $to->toDateString();
        if (isset($this->prep[$key])) return $this->prep[$key];
        $this->prep = [];   // one entry at a time keeps memory flat

        $tid = $this->tid();
        $today = $this->p->today();
        $todayDay = (int) floor(strtotime($today->toDateString() . ' UTC') / 86400);
        $items = DB::table('tenant_inventory_items as it')
            ->leftJoin('platform_distributor_catalogs as pc', 'pc.id', '=', 'it.distributor_catalog_id')
            ->where('it.tenant_id', $tid)->where('it.is_active', 1)->whereNull('it.deleted_at')
            ->select('it.id', 'it.name', 'it.category_id', 'it.default_vendor_id', 'it.computed_stock_count', 'it.created_at', 'pc.manufacturer')
            ->selectRaw('COALESCE(it.received_cost_cents, it.shop_cost_cents, it.catalog_cost_cents) as unit_cost')
            ->selectRaw('COALESCE(it.shop_sell_price_cents, it.catalog_msrp_cents) as price');

        $lastRecv = DB::table('tenant_inventory_receive_shipment_items as ri')
            ->join('tenant_inventory_receive_shipments as s', 's.id', '=', 'ri.shipment_id')
            ->where('s.tenant_id', $tid)->where('s.status', 'committed')->whereNull('s.deleted_at')
            ->whereNotNull('ri.inventory_item_id')
            ->selectRaw('ri.inventory_item_id as id, MAX(s.received_date) as d')
            ->groupBy('ri.inventory_item_id')->pluck('d', 'id')->all();

        $soldQ = DB::table('tenant_sale_items as li')->join('tenant_sales as s', 's.id', '=', 'li.sale_id')
            ->where('li.tenant_id', $tid)->where('s.tenant_id', $tid)->whereNotNull('li.inventory_item_id')
            ->where('s.payment_status', 'paid')->whereBetween('s.sale_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('li.inventory_item_id as id' . ($byLoc ? ", COALESCE(s.location_id, '') as loc" : ''))
            ->selectRaw('SUM(CASE WHEN s.refund_of_sale_id IS NULL THEN li.quantity ELSE -li.quantity END) as units')
            ->selectRaw('SUM(CASE WHEN s.refund_of_sale_id IS NULL THEN 1 ELSE -1 END * COALESCE(li.cost_cents_snapshot, 0) * li.quantity) as cogs')
            ->groupBy('li.inventory_item_id');
        if ($byLoc) $soldQ->groupByRaw("COALESCE(s.location_id, '')");
        $sold = [];
        foreach ($soldQ->get() as $r) {
            $sold[(string) $r->id . ($byLoc ? '|' . $r->loc : '')] = [(float) $r->units, (float) $r->cogs];
        }

        $moved = DB::table('tenant_sale_items as li')->join('tenant_sales as s', 's.id', '=', 'li.sale_id')
            ->where('li.tenant_id', $tid)->whereNotNull('li.inventory_item_id')->where('s.payment_status', 'paid')
            ->whereNull('s.refund_of_sale_id')->where('s.sale_date', '>=', $today->copy()->subDays($this->deadDays())->toDateString())
            ->distinct()->pluck('li.inventory_item_id')->flip()->all();

        $locStock = [];
        if ($byLoc) {
            foreach (DB::table('tenant_inventory_item_locations')->where('tenant_id', $tid)->get(['inventory_item_id', 'location_id', 'computed_stock_count']) as $r) {
                $locStock[(string) $r->inventory_item_id][(string) $r->location_id] = (int) $r->computed_stock_count;
            }
        }


        // One packed row per item (a shop can hold tens of thousands, and a Reports tab keeps these for the whole page).
        // [id, name, unit cost, price, stock, category, vendor, brand name, brand, age bucket, margin band, price band, sold lately]
        $rows = [];
        foreach ($items->cursor() as $it) {
            $id = (string) $it->id;
            $unitCost = $it->unit_cost !== null ? (int) $it->unit_cost : null;
            $price = $it->price !== null ? (int) $it->price : null;
            $brandName = trim((string) $it->manufacturer);
            $ageFrom = $lastRecv[$id] ?? ($it->created_at ? substr((string) $it->created_at, 0, 10) : $today->toDateString());
            $ageTs = strtotime(substr((string) $ageFrom, 0, 10) . ' UTC');
            $age = $ageTs === false ? 0 : max(0, $todayDay - (int) floor($ageTs / 86400));
            $rows[] = [
                $id, (string) $it->name, $unitCost, $price, (int) $it->computed_stock_count,
                $it->category_id ? (string) $it->category_id : 'uncat',
                $it->default_vendor_id ? (string) $it->default_vendor_id : 'none',
                $brandName, $brandName === '' ? 'none' : mb_strtolower($brandName),
                (string) ($age <= 30 ? 0 : ($age <= 90 ? 1 : ($age <= 180 ? 2 : ($age <= 365 ? 3 : 4)))),
                ($unitCost !== null && $price) ? self::mband(($price - $unitCost) / $price * 100) : 'none',
                self::pband($price), isset($moved[$id]),
            ];
        }
        unset($items);

        // Sold units per item and location, indexed so a location run doesn't scan every sale for every item.
        $soldBy = [];
        if ($byLoc) {
            foreach ($sold as $sk => $v) {
                [$sid, $sloc] = explode('|', $sk, 2) + [1 => ''];
                $soldBy[$sid][$sloc] = $v;
            }
        }
        return $this->prep[$key] = ['rows' => $rows, 'sold' => $sold, 'soldBy' => $soldBy, 'locStock' => $locStock];
    }

    public function run(array $dims, array $filters, Carbon $from, Carbon $to): array
    {
        $f = $this->byKey($filters);
        $byLoc = in_array('loc', $dims, true) || isset($f['loc']);
        $P = $this->prepared($byLoc, $from, $to);
        $sold = $P['sold']; $soldBy = $P['soldBy']; $locStock = $P['locStock'];

        $catN = $this->names('cat'); $vendN = $this->names('vend'); $locN = $this->names('loc');
        $groups = [];
        $total = [];
        foreach ($P['rows'] as [$id, $name, $unitCost, $price, $stock0, $cat, $vend, $brandName, $brand, $bucket, $mb, $pb, $wasMoved]) {

            $attrs = ['cat' => $cat, 'vend' => $vend, 'brand' => $brand, 'bucket' => $bucket, 'mband' => $mb, 'pband' => $pb];
            foreach (['cat', 'vend', 'brand', 'bucket', 'mband', 'pband'] as $k) {
                if (!$this->passes($attrs[$k], $f[$k] ?? null)) continue 2;
            }

            $places = $byLoc ? ($locStock[$id] ?? []) : ['' => $stock0];
            if ($byLoc) {
                foreach ($soldBy[$id] ?? [] as $l => $_) {
                    $places[$l] = $places[$l] ?? 0;
                }
            }
            foreach ($places as $loc => $stock) {
                $loc = (string) $loc;
                if ($byLoc && !$this->passes($loc, $f['loc'] ?? null)) continue;
                $units = max(0, $stock);
                [$su, $cogs] = $sold[$id . ($byLoc ? '|' . $loc : '')] ?? [0.0, 0.0];
                if ($units === 0 && $su == 0 && in_array('item', $dims, true)) continue;
                if ($this->onlySold && $su == 0 && in_array('item', $dims, true)) continue;
                $cost = $units * (int) ($unitCost ?? 0);
                $c = [
                    'units' => $units, 'cost' => $cost, 'retail' => $units * (int) ($price ?? 0), 'skus' => $units > 0 ? 1 : 0,
                    'sold' => $su, 'cogs' => $cogs, 'dead' => ($units > 0 && !$wasMoved) ? $cost : 0,
                    'a1' => $bucket === '0' ? $cost : 0, 'a2' => $bucket === '1' ? $cost : 0, 'a3' => $bucket === '2' ? $cost : 0,
                    'a4' => in_array($bucket, ['3', '4'], true) ? $cost : 0,
                ];
                $keys = []; $labels = [];
                foreach ($dims as $n => $d) {
                    [$keys[$n], $labels[$n]] = match ($d) {
                        'cat'    => [$cat, $cat === 'uncat' ? 'Uncategorized' : ($catN[$cat] ?? 'Removed category')],
                        'vend'   => [$vend, $vend === 'none' ? 'No vendor' : ($vendN[$vend] ?? 'Removed vendor')],
                        'brand'  => [$brand, $brand === 'none' ? 'No brand' : $brandName],
                        'bucket' => [$bucket, self::BUCKETS[(int) $bucket]],
                        'mband', 'pband' => [$d === 'mband' ? $mb : $pb, $this->bandOptions($d)[$d === 'mband' ? $mb : $pb]],
                        'loc'    => [$loc, $loc === '' ? 'No location' : ($locN[$loc] ?? 'Removed location')],
                        default  => [$id, $name],
                    };
                }
                $this->add($groups, $keys, $labels, $c);
                foreach ($c as $k => $v) $total[$k] = ($total[$k] ?? 0) + $v;
            }
        }
        $this->setRangeDays($this->p->days($from, $to));
        return ['groups' => $groups, 'total' => $total];
    }

    public function value(string $m, array $c): ?float
    {
        $c += ['units' => 0, 'cost' => 0, 'retail' => 0, 'skus' => 0, 'sold' => 0, 'cogs' => 0, 'dead' => 0, 'a1' => 0, 'a2' => 0, 'a3' => 0, 'a4' => 0];
        $perDay = $c['sold'] / $this->rangeDays;
        return match ($m) {
            'units'  => (float) $c['units'],
            'cost'   => $c['cost'] / 100,
            'retail' => $c['retail'] / 100,
            'items'  => (float) $c['skus'],
            'cover'  => $perDay > 0 ? $c['units'] / ($perDay * 7) : null,
            'dleft'  => $perDay > 0 ? $c['units'] / $perDay : null,
            'turns'  => $c['cost'] > 0 ? ($c['cogs'] * 365 / $this->rangeDays) / $c['cost'] : null,
            'st'     => ($c['sold'] + $c['units']) > 0 ? $c['sold'] / ($c['sold'] + $c['units']) * 100 : null,
            'dead', 'a1', 'a2', 'a3', 'a4' => $c[$m] / 100,
            default  => null,
        };
    }
}
