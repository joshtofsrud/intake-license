<?php

namespace App\Services\Tenant\ReportBuilder\Sources;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-ONLINE-REPORTS — Shipments: what online orders shipped, what the customer was charged for
 * shipping and what it cost the shop. Cancelled shipments are left out, and so are orders that were
 * never paid. A shipment's date is when its order was paid. Cost is only what was entered, so every
 * comparison is made on the shipments that have a cost, never against a guessed zero.
 */
class ShipmentsSource extends OnlineBase
{
    private const SRC = ['shop' => 'Your shop', 'supplier' => 'Supplier (dropship)'];
    private const ST = ['pending' => 'Not shipped yet', 'shipped' => 'Shipped', 'out_for_delivery' => 'Out for delivery',
        'delivered' => 'Delivered', 'special_order' => 'Special order'];
    private const PAID = ['paid', 'fulfilling', 'fulfilled', 'completed'];
    private const UNITS = '(SELECT COALESCE(SUM(si.quantity), 0) FROM tenant_order_shipment_items si WHERE si.shipment_id = sh.id)';

    public function meta(): array
    {
        return [
            'label' => 'Shipments',
            'note'  => 'Shipments on paid online orders, by the day the order was paid. Shipping cost is what you entered for each shipment; charged-versus-cost figures only use shipments that have a cost.',
            'snap'  => false,
            'measures' => [
                'ships'    => ['Shipments', 'n'],
                'units'    => ['Units shipped', 'n'],
                'charged'  => ['Shipping charged', '$'],
                'cost'     => ['Shipping cost (entered)', '$'],
                'net'      => ['Charged minus cost', '$'],
                'recover'  => ['Cost recovered', '%'],
                'uncosted' => ['Shipments with no cost entered', 'n'],
                'supunits' => ['Units from suppliers', 'n'],
                'supshare' => ['Dropship share of units', '%'],
                'supships' => ['Supplier shipments', 'n'],
            ],
            'dims' => [
                'day' => ['Day', 't'], 'week' => ['Week', 't'], 'month' => ['Month', 't'],
                'source' => ['Shipped from', 'c'], 'wh' => ['Warehouse', 'c'], 'carrier' => ['Carrier', 'c'],
                'service' => ['Shipping service', 'c'], 'status' => ['Shipment status', 'c'],
            ],
            'filters' => ['source' => 'Shipped from', 'wh' => 'Warehouse', 'carrier' => 'Carrier', 'status' => 'Shipment status'],
        ];
    }

    public function options(string $key): array
    {
        return match ($key) {
            'source' => self::SRC,
            'status' => self::ST,
            'wh'      => $this->distinct('tenant_order_shipments', 'warehouse_name'),
            'carrier' => $this->distinct('tenant_order_shipments', 'carrier'),
            default => [],
        };
    }

    private function dimSql(string $d): string
    {
        return match ($d) {
            'source'  => 'sh.source',
            'wh'      => "COALESCE(NULLIF(sh.warehouse_name, ''), 'none')",
            'carrier' => "COALESCE(NULLIF(sh.carrier, ''), 'none')",
            'service' => "COALESCE(NULLIF(sh.service_name, ''), 'none')",
            'status'  => 'sh.status',
            default   => "''",
        };
    }

    private function base(array $filters, Carbon $from, Carbon $to)
    {
        [$a, $b] = $this->utc($from, $to);
        $q = DB::table('tenant_order_shipments as sh')
            ->join('tenant_orders as o', 'o.id', '=', 'sh.order_id')
            ->where('sh.tenant_id', $this->tid())->where('o.tenant_id', $this->tid())
            ->where('sh.status', '!=', 'cancelled')
            ->whereIn('o.status', self::PAID)
            ->whereRaw('COALESCE(o.paid_at, sh.created_at) BETWEEN ? AND ?', [$a, $b]);
        $cols = ['source' => 'sh.source', 'wh' => $this->dimSql('wh'), 'carrier' => $this->dimSql('carrier'), 'status' => 'sh.status'];
        foreach ($this->byKey($filters) as $k => $spec) {
            if (isset($cols[$k])) $this->sqlFilter($q, $cols[$k], $spec);
        }
        return $q;
    }

    private function select($q)
    {
        $U = self::UNITS;
        return $q->selectRaw('COUNT(*) as c_ships')
            ->selectRaw("SUM($U) as c_units")
            ->selectRaw('SUM(sh.shipping_cents) as c_charged')
            ->selectRaw('SUM(CASE WHEN sh.cost_cents IS NOT NULL THEN sh.cost_cents ELSE 0 END) as c_cost')
            ->selectRaw('SUM(CASE WHEN sh.cost_cents IS NOT NULL THEN sh.shipping_cents ELSE 0 END) as c_chargedc')
            ->selectRaw('SUM(CASE WHEN sh.cost_cents IS NULL THEN 1 ELSE 0 END) as c_uncosted')
            ->selectRaw("SUM(CASE WHEN sh.source = 'supplier' THEN $U ELSE 0 END) as c_supunits")
            ->selectRaw("SUM(CASE WHEN sh.source = 'supplier' THEN 1 ELSE 0 END) as c_supships");
    }

    private static function comps(object $r): array
    {
        return ['ships' => (float) $r->c_ships, 'units' => (float) $r->c_units, 'charged' => (float) $r->c_charged,
            'cost' => (float) $r->c_cost, 'chargedc' => (float) $r->c_chargedc, 'uncosted' => (float) $r->c_uncosted,
            'supunits' => (float) $r->c_supunits, 'supships' => (float) $r->c_supships];
    }

    public function run(array $dims, array $filters, Carbon $from, Carbon $to): array
    {
        $q = $this->base($filters, $from, $to);
        $timeDim = false;
        foreach ($dims as $n => $d) {
            if (in_array($d, ['day', 'week', 'month'], true)) {
                $timeDim = true;
            } else {
                $q->selectRaw($this->dimSql($d) . " as g$n")->groupByRaw("g$n");
            }
        }
        if ($timeDim) {
            $q->selectRaw("DATE_FORMAT(COALESCE(o.paid_at, sh.created_at), '%Y-%m-%d %H') as gh")->groupByRaw('gh');
        }
        $this->select($q);

        $groups = [];
        foreach ($q->get() as $r) {
            $keys = []; $labels = [];
            foreach ($dims as $n => $d) {
                if (in_array($d, ['day', 'week', 'month'], true)) {
                    [$keys[$n], $labels[$n]] = $this->timeKeyLabel($d, (string) $r->gh);
                } else {
                    [$keys[$n], $labels[$n]] = $this->keyLabel($d, (string) $r->{"g$n"});
                }
            }
            $this->add($groups, $keys, $labels, self::comps($r));
        }
        $t = $this->select($this->base($filters, $from, $to))->first();
        return ['groups' => $groups, 'total' => $t ? self::comps($t) : []];
    }

    private function keyLabel(string $d, string $raw): array
    {
        return match ($d) {
            'source' => [$raw, self::SRC[$raw] ?? ucfirst($raw)],
            'status' => [$raw, self::ST[$raw] ?? ucfirst(str_replace('_', ' ', $raw))],
            'wh'      => [$raw, $raw === 'none' ? 'No warehouse' : $raw],
            'carrier' => [$raw, $raw === 'none' ? 'No carrier entered' : $raw],
            'service' => [$raw, $raw === 'none' ? 'No service entered' : $raw],
            default => [$raw, $raw],
        };
    }

    public function value(string $m, array $c): ?float
    {
        $c += ['ships' => 0, 'units' => 0, 'charged' => 0, 'cost' => 0, 'chargedc' => 0, 'uncosted' => 0, 'supunits' => 0, 'supships' => 0];
        $costed = $c['ships'] - $c['uncosted'];
        return match ($m) {
            'ships'    => (float) $c['ships'],
            'units'    => (float) $c['units'],
            'charged'  => $c['charged'] / 100,
            'cost'     => $costed > 0 ? $c['cost'] / 100 : null,
            'net'      => $costed > 0 ? ($c['chargedc'] - $c['cost']) / 100 : null,
            'recover'  => $costed > 0 ? self::ratio($c['chargedc'], $c['cost'], 100) : null,
            'uncosted' => (float) $c['uncosted'],
            'supunits' => (float) $c['supunits'],
            'supshare' => self::ratio($c['supunits'], $c['units'], 100),
            'supships' => (float) $c['supships'],
            default    => null,
        };
    }
}
