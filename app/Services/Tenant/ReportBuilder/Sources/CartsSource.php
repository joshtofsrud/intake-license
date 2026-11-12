<?php

namespace App\Services\Tenant\ReportBuilder\Sources;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-ONLINE-REPORTS — Carts: every online-store cart that had something in it, by the day it
 * was started, and how it ended. Abandoned means the cart sat idle (or the payment hold ran out)
 * and the shop's reaper closed it. Carts still open are counted on their own, never as lost.
 */
class CartsSource extends OnlineBase
{
    private const PAID = ['paid', 'fulfilling', 'fulfilled', 'completed'];
    private const OPEN = ['cart', 'pending_payment'];
    private const ST = ['paid' => 'Paid', 'abandoned' => 'Abandoned', 'open' => 'Still open', 'cancelled' => 'Cancelled'];
    private const FT = ['ship' => 'Shipping', 'pickup' => 'Pickup', 'local_delivery' => 'Local delivery', 'none' => 'Not chosen yet'];
    private const END = "CASE WHEN o.status IN ('paid','fulfilling','fulfilled','completed') THEN 'paid' WHEN o.status = 'abandoned' THEN 'abandoned' WHEN o.status IN ('cart','pending_payment') THEN 'open' ELSE 'cancelled' END";

    public function meta(): array
    {
        return [
            'label' => 'Carts',
            'note'  => 'Online-store carts with items in them, by the day they were started. Abandoned carts were closed after sitting idle. Carts still open are not counted as lost.',
            'snap'  => false,
            'measures' => [
                'started'  => ['Carts started', 'n'],
                'paid'     => ['Carts paid', 'n'],
                'aband'    => ['Carts abandoned', 'n'],
                'open'     => ['Carts still open', 'n'],
                'abandval' => ['Value abandoned', '$'],
                'paidval'  => ['Value paid', '$'],
                'abandp'   => ['Abandonment rate', '%'],
            ],
            'dims' => [
                'day' => ['Day', 't'], 'week' => ['Week', 't'], 'month' => ['Month', 't'],
                'ftype' => ['Delivery choice', 'c'], 'end' => ['How it ended', 'c'], 'item' => ['Item in the cart', 'c'],
            ],
            'filters' => ['ftype' => 'Delivery choice', 'end' => 'How it ended'],
        ];
    }

    public function options(string $key): array
    {
        return match ($key) {
            'ftype' => self::FT,
            'end'   => self::ST,
            default => [],
        };
    }

    private function dimSql(string $d): string
    {
        return match ($d) {
            'ftype' => "COALESCE(NULLIF(o.fulfillment_type, ''), 'none')",
            'end'   => self::END,
            'item'  => "COALESCE(oi.inventory_item_id, CONCAT('n:', oi.name_snapshot))",
            default => "''",
        };
    }

    private function base(array $filters, Carbon $from, Carbon $to, bool $items)
    {
        [$a, $b] = $this->utc($from, $to);
        $q = DB::table('tenant_orders as o')
            ->where('o.tenant_id', $this->tid())
            ->whereBetween('o.created_at', [$a, $b])
            ->whereExists(fn ($e) => $e->selectRaw('1')->from('tenant_order_items as x')->whereColumn('x.order_id', 'o.id'));
        if ($items) {
            $q->join('tenant_order_items as oi', 'oi.order_id', '=', 'o.id');
        }
        $cols = ['ftype' => $this->dimSql('ftype'), 'end' => self::END];
        foreach ($this->byKey($filters) as $k => $spec) {
            if (isset($cols[$k])) $this->sqlFilter($q, $cols[$k], $spec);
        }
        return $q;
    }

    private function select($q, bool $items)
    {
        // with an item breakdown a cart counts once per item in it, valued at that item's lines
        $v = $items ? 'oi.line_total_cents' : 'o.subtotal_cents';
        return $q->selectRaw('COUNT(DISTINCT o.id) as c_started')
            ->selectRaw("COUNT(DISTINCT CASE WHEN o.status IN ('paid','fulfilling','fulfilled','completed') THEN o.id END) as c_paid")
            ->selectRaw("COUNT(DISTINCT CASE WHEN o.status = 'abandoned' THEN o.id END) as c_aband")
            ->selectRaw("COUNT(DISTINCT CASE WHEN o.status IN ('cart','pending_payment') THEN o.id END) as c_open")
            ->selectRaw("SUM(CASE WHEN o.status = 'abandoned' THEN $v ELSE 0 END) as c_abandval")
            ->selectRaw("SUM(CASE WHEN o.status IN ('paid','fulfilling','fulfilled','completed') THEN $v ELSE 0 END) as c_paidval");
    }

    private static function comps(object $r): array
    {
        return ['started' => (float) $r->c_started, 'paid' => (float) $r->c_paid, 'aband' => (float) $r->c_aband, 'open' => (float) $r->c_open,
            'abandval' => (float) $r->c_abandval, 'paidval' => (float) $r->c_paidval];
    }

    public function run(array $dims, array $filters, Carbon $from, Carbon $to): array
    {
        $items = in_array('item', $dims, true);
        $q = $this->base($filters, $from, $to, $items);
        $timeDim = false;
        foreach ($dims as $n => $d) {
            if (in_array($d, ['day', 'week', 'month'], true)) {
                $timeDim = true;
            } else {
                $q->selectRaw($this->dimSql($d) . " as g$n")->groupByRaw("g$n");
            }
        }
        if ($timeDim) {
            $q->selectRaw("DATE_FORMAT(o.created_at, '%Y-%m-%d %H') as gh")->groupByRaw('gh');
        }
        if ($items) {
            $q->selectRaw('MAX(oi.name_snapshot) as lname');
        }
        $this->select($q, $items);

        $groups = [];
        foreach ($q->get() as $r) {
            $keys = []; $labels = [];
            foreach ($dims as $n => $d) {
                if (in_array($d, ['day', 'week', 'month'], true)) {
                    [$keys[$n], $labels[$n]] = $this->timeKeyLabel($d, (string) $r->gh);
                    continue;
                }
                $raw = (string) $r->{"g$n"};
                $keys[$n] = $raw;
                $labels[$n] = match ($d) {
                    'ftype' => self::FT[$raw] ?? ucfirst(str_replace('_', ' ', $raw)),
                    'end'   => self::ST[$raw] ?? $raw,
                    'item'  => (string) ($r->lname ?? $raw),
                    default => $raw,
                };
            }
            $this->add($groups, $keys, $labels, self::comps($r));
        }
        $t = $this->select($this->base($filters, $from, $to, $items), $items)->first();
        return ['groups' => $groups, 'total' => $t ? self::comps($t) : []];
    }

    public function value(string $m, array $c): ?float
    {
        $c += ['started' => 0, 'paid' => 0, 'aband' => 0, 'open' => 0, 'abandval' => 0, 'paidval' => 0];
        return match ($m) {
            'started'  => (float) $c['started'],
            'paid'     => (float) $c['paid'],
            'aband'    => (float) $c['aband'],
            'open'     => (float) $c['open'],
            'abandval' => $c['abandval'] / 100,
            'paidval'  => $c['paidval'] / 100,
            'abandp'   => self::ratio($c['aband'], $c['aband'] + $c['paid'], 100),   // of carts that ended, open ones left out
            default    => null,
        };
    }
}
