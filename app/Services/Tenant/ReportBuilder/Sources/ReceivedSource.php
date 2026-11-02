<?php

namespace App\Services\Tenant\ReportBuilder\Sources;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Received — stock received into inventory (committed receiving only), at the
 * cost you paid. "Units sold since" counts sales of each item from the day it
 * was first received in the range to the end of the range, never more than
 * was received.
 */
class ReceivedSource extends Source
{
    public function meta(): array
    {
        return [
            'label' => 'Received',
            'note'  => 'Stock received into inventory, at the cost you paid. Units sold since and sell-through count sales of what was received in the range.',
            'snap'  => false,
            'measures' => [
                'runits'  => ['Units received', 'n'],
                'rcost'   => ['Cost received', '$'],
                'rretail' => ['Retail value at receipt', '$'],
                'rmarg'   => ['Margin $ at receipt', '$'],
                'rmp'     => ['Margin % at receipt', '%'],
                'rsold'   => ['Units sold since', 'n'],
                'rst'     => ['Sell-through of received', '%'],
            ],
            'dims' => [
                'day' => ['Day', 't'], 'week' => ['Week', 't'], 'month' => ['Month', 't'],
                'cat' => ['Category', 'c'], 'vend' => ['Vendor', 'c'], 'brand' => ['Brand', 'c'], 'loc' => ['Location', 'c'],
                'item' => ['Item', 'c'], 'mband' => ['Margin range', 'c'], 'pband' => ['Price band', 'c'],
            ],
            'filters' => ['cat' => 'Category', 'vend' => 'Vendor', 'brand' => 'Brand', 'loc' => 'Location', 'mband' => 'Margin range', 'pband' => 'Price band'],
        ];
    }

    public function options(string $key): array
    {
        return (new InventorySource($this->tenant, $this->p))->options($key);
    }

    public function run(array $dims, array $filters, Carbon $from, Carbon $to): array
    {
        $tid = $this->tid();
        $f = $this->byKey($filters);
        $fromS = $from->toDateString(); $toS = $to->toDateString();

        $lines = DB::table('tenant_inventory_receive_shipment_items as ri')
            ->join('tenant_inventory_receive_shipments as s', 's.id', '=', 'ri.shipment_id')
            ->leftJoin('tenant_inventory_items as it', 'it.id', '=', 'ri.inventory_item_id')
            ->leftJoin('platform_distributor_catalogs as pc', 'pc.id', '=', DB::raw('COALESCE(it.distributor_catalog_id, ri.distributor_catalog_id)'))
            ->where('s.tenant_id', $tid)->where('s.status', 'committed')->whereNull('s.deleted_at')
            ->whereBetween('s.received_date', [$fromS, $toS])
            ->where('ri.received_quantity', '>', 0)
            ->select('ri.inventory_item_id', 'ri.name', 'ri.received_quantity', 'ri.unit_cost_cents', 'ri.total_cost_cents',
                's.received_date', 's.location_id', 'it.category_id', 'it.default_vendor_id', 'pc.manufacturer')
            ->selectRaw('COALESCE(it.received_cost_cents, it.shop_cost_cents, it.catalog_cost_cents) as item_cost')
            ->selectRaw('COALESCE(it.shop_sell_price_cents, it.catalog_msrp_cents) as price')
            ->get();

        // Sales of each received item from its first receipt in the range.
        $first = []; $recvQty = [];
        foreach ($lines as $l) {
            if (!$l->inventory_item_id) continue;
            $id = (string) $l->inventory_item_id;
            $d = substr((string) $l->received_date, 0, 10);
            $first[$id] = isset($first[$id]) ? min($first[$id], $d) : $d;
            $recvQty[$id] = ($recvQty[$id] ?? 0) + (int) $l->received_quantity;
        }
        $soldSince = [];
        foreach (array_chunk(array_keys($first), 500) as $chunk) {
            $rows = DB::table('tenant_sale_items as li')->join('tenant_sales as s', 's.id', '=', 'li.sale_id')
                ->where('li.tenant_id', $tid)->whereIn('li.inventory_item_id', $chunk)->where('s.payment_status', 'paid')
                ->whereBetween('s.sale_date', [$fromS, $toS])
                ->selectRaw('li.inventory_item_id as id, s.sale_date as d')
                ->selectRaw('SUM(CASE WHEN s.refund_of_sale_id IS NULL THEN li.quantity ELSE -li.quantity END) as q')
                ->groupBy('li.inventory_item_id', 's.sale_date')->get();
            foreach ($rows as $r) {
                if (substr((string) $r->d, 0, 10) >= $first[(string) $r->id]) {
                    $soldSince[(string) $r->id] = ($soldSince[(string) $r->id] ?? 0) + (float) $r->q;
                }
            }
        }

        $catN = $this->names('cat'); $vendN = $this->names('vend'); $locN = $this->names('loc');
        $groups = []; $total = [];
        foreach ($lines as $l) {
            $id = $l->inventory_item_id ? (string) $l->inventory_item_id : 'n:' . $l->name;
            $qty = (int) $l->received_quantity;
            $unit = $l->unit_cost_cents !== null ? (int) $l->unit_cost_cents : ($l->item_cost !== null ? (int) $l->item_cost : null);
            $cost = $l->total_cost_cents !== null ? (int) $l->total_cost_cents : $qty * (int) ($unit ?? 0);
            $price = $l->price !== null ? (int) $l->price : null;
            $retail = $qty * (int) ($price ?? 0);
            $cat = $l->category_id ? (string) $l->category_id : 'uncat';
            $vend = $l->default_vendor_id ? (string) $l->default_vendor_id : 'none';
            $brandName = trim((string) $l->manufacturer);
            $brand = $brandName === '' ? 'none' : mb_strtolower($brandName);
            $loc = (string) ($l->location_id ?? '');
            $mb = ($unit !== null && $price) ? self::mband(($price - $unit) / $price * 100) : 'none';
            $pb = self::pband($price);
            $attrs = ['cat' => $cat, 'vend' => $vend, 'brand' => $brand, 'loc' => $loc, 'mband' => $mb, 'pband' => $pb];
            foreach ($attrs as $k => $v) {
                if (!$this->passes($v, $f[$k] ?? null)) continue 2;
            }
            $rsold = 0.0;
            if ($l->inventory_item_id && ($recvQty[$id] ?? 0) > 0) {
                $itemSold = min(max(0.0, $soldSince[$id] ?? 0.0), (float) $recvQty[$id]);
                $rsold = $itemSold * $qty / $recvQty[$id];
            }
            $c = ['units' => $qty, 'cost' => $cost, 'retail' => $retail, 'priced' => $price !== null ? $retail : 0,
                'pricedcost' => $price !== null ? $cost : 0, 'sold' => $rsold];
            $date = substr((string) $l->received_date, 0, 10);
            $keys = []; $labels = [];
            foreach ($dims as $n => $d) {
                if (in_array($d, ['day', 'week', 'month'], true)) {
                    $keys[$n] = $this->p->key($d, $date);
                    $labels[$n] = $this->p->label($d, $keys[$n]);
                    continue;
                }
                [$keys[$n], $labels[$n]] = match ($d) {
                    'cat'   => [$cat, $cat === 'uncat' ? 'Uncategorized' : ($catN[$cat] ?? 'Removed category')],
                    'vend'  => [$vend, $vend === 'none' ? 'No vendor' : ($vendN[$vend] ?? 'Removed vendor')],
                    'brand' => [$brand, $brand === 'none' ? 'No brand' : $brandName],
                    'loc'   => [$loc, $loc === '' ? 'No location' : ($locN[$loc] ?? 'Removed location')],
                    'mband', 'pband' => [$d === 'mband' ? $mb : $pb, $this->bandOptions($d)[$d === 'mband' ? $mb : $pb]],
                    default => [$id, (string) $l->name],
                };
            }
            $this->add($groups, $keys, $labels, $c);
            foreach ($c as $k => $v) $total[$k] = ($total[$k] ?? 0) + $v;
        }
        return ['groups' => $groups, 'total' => $total];
    }

    public function value(string $m, array $c): ?float
    {
        $c += ['units' => 0, 'cost' => 0, 'retail' => 0, 'priced' => 0, 'pricedcost' => 0, 'sold' => 0];
        return match ($m) {
            'runits'  => (float) $c['units'],
            'rcost'   => $c['cost'] / 100,
            'rretail' => $c['retail'] / 100,
            'rmarg'   => ($c['priced'] - $c['pricedcost']) / 100,
            'rmp'     => self::ratio($c['priced'] - $c['pricedcost'], $c['priced'], 100),
            'rsold'   => (float) $c['sold'],
            'rst'     => self::ratio($c['sold'], $c['units'], 100),
            default   => null,
        };
    }
}
