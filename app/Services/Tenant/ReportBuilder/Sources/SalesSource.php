<?php

namespace App\Services\Tenant\ReportBuilder\Sources;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sales — paid register and online-store sale lines. A refund is its own sale
 * row pointing at the original, with positive quantities, so its lines count
 * NEGATIVE here: revenue, margin and items are net of refunds.
 */
class SalesSource extends Source
{
    private const CAT = "CASE WHEN i.type = 'product' THEN COALESCE(it.category_id, 'uncat') WHEN i.type = 'service' THEN 'svc' ELSE 'other' END";
    private const SIGN = '(CASE WHEN s.refund_of_sale_id IS NULL THEN 1 ELSE -1 END)';
    // MARKER-SIGNED-MATH: the money columns are UNSIGNED, and MySQL refuses any
    // subtraction that goes below zero (a discount over the line, a sale under
    // cost), which 500'd Ask for a report. Every operand is cast to SIGNED first.
    private const LINE = '(CAST(i.unit_price_cents AS SIGNED) * i.quantity - CAST(i.discount_cents AS SIGNED))';
    private const MPCT = 'CASE WHEN i.cost_cents_snapshot IS NULL OR i.unit_price_cents = 0 THEN NULL ELSE (CAST(i.unit_price_cents AS SIGNED) - CAST(i.cost_cents_snapshot AS SIGNED)) * 100.0 / i.unit_price_cents END';

    public function meta(): array
    {
        return [
            'label' => 'Sales',
            'note'  => 'Paid register and online-store sales. Refunds are taken off. Revenue is before tax and tips. Tax collected is the sales tax charged on those sales, refunds taken off.',
            'snap'  => false,
            'measures' => [
                'rev'     => ['Revenue', '$'],
                'margin'  => ['Margin $', '$'],
                'marginp' => ['Margin %', '%'],
                'items'   => ['Items sold', 'n'],
                'tickets' => ['Tickets', 'n'],
                'ipt'     => ['Items per ticket', 'd'],
                'avg'     => ['Average ticket', '$'],
                'disc'    => ['Discounts given', '$'],
                'refunds' => ['Refunds', '$'],
                'tax'     => ['Tax collected', '$'],
                'taxable' => ['Taxable sales', '$'],
            ],
            'dims' => [
                'day'   => ['Day', 't'], 'week' => ['Week', 't'], 'month' => ['Month', 't'], 'hour' => ['Hour of day', 't'],
                'loc'   => ['Location', 'c'], 'staff' => ['Staff (rang up by)', 'c', 'managers'], 'cat' => ['Category', 'c'],
                'pay'   => ['Payment method', 'c'], 'ctype' => ['Customer type', 'c'], 'item' => ['Item', 'c'],
                'mband' => ['Margin range', 'c'], 'pband' => ['Price band', 'c'],
            ],
            'filters' => ['loc' => 'Location', 'staff' => 'Staff', 'cat' => 'Category', 'pay' => 'Payment method',
                'ctype' => 'Customer type', 'mband' => 'Margin range', 'pband' => 'Price band'],
        ];
    }

    public function options(string $key): array
    {
        return match ($key) {
            'loc'   => $this->names('loc'),
            'staff' => $this->names('user'),
            'cat'   => $this->activeCategories() + ['uncat' => 'Uncategorized', 'svc' => 'Services', 'other' => 'Other'],
            'pay'   => $this->payOptions(),
            'ctype' => ['known' => 'Known customer', 'walkin' => 'Walk-in'],
            'mband', 'pband' => $this->bandOptions($key),
            default => [],
        };
    }

    private function payOptions(): array
    {
        $vals = DB::table('tenant_sales')->where('tenant_id', $this->tid())->whereNotNull('payment_method')
            ->where('payment_method', '!=', '')->distinct()->orderBy('payment_method')->pluck('payment_method')->all();
        $out = [];
        foreach ($vals as $v) {
            $out[$v] = self::payLabel($v);
        }
        return $out + ['unknown' => 'Unknown'];
    }

    public static function payLabel(string $v): string
    {
        return ['card' => 'Card', 'cash' => 'Cash', 'gift_card' => 'Gift card', 'check' => 'Check', 'unknown' => 'Unknown',
            'stripe' => 'Card (online)', 'paypal' => 'PayPal', 'split' => 'Split', 'layaway' => 'Layaway', 'other' => 'Other'][$v]
            ?? ucfirst(str_replace('_', ' ', $v));
    }

    private function dimSql(string $d): array
    {
        return match ($d) {
            'day', 'week', 'month', 'pp' => ['s.sale_date', 'date'],
            'hour'  => ["DATE_FORMAT(COALESCE(s.paid_at, s.created_at), '%Y-%m-%d %H')", 'utch'],
            'loc'   => ["COALESCE(s.location_id, '')", 'key'],
            'staff' => ["COALESCE(s.rang_up_by_user_id, '')", 'key'],
            'cat'   => [self::CAT, 'key'],
            'pay'   => ["COALESCE(NULLIF(s.payment_method, ''), 'unknown')", 'key'],
            'ctype' => ["CASE WHEN s.customer_id IS NULL THEN 'walkin' ELSE 'known' END", 'key'],
            'item'  => ["COALESCE(i.inventory_item_id, CONCAT('n:', i.name_snapshot))", 'key'],
            'mband' => [self::bandSql(self::MPCT, [30, 40, 50, 60]), 'key'],
            'pband' => [self::bandSql('i.unit_price_cents', [2500, 5000, 10000, 20000]), 'key'],
            default => ["''", 'key'],
        };
    }

    private function base(array $filters, Carbon $from, Carbon $to)
    {
        $tid = $this->tid();
        $q = DB::table('tenant_sale_items as i')
            ->join('tenant_sales as s', 's.id', '=', 'i.sale_id')
            ->leftJoin('tenant_inventory_items as it', 'it.id', '=', 'i.inventory_item_id')
            ->where('s.tenant_id', $tid)
            ->where('i.tenant_id', $tid)
            ->where('s.payment_status', 'paid')
            ->whereBetween('s.sale_date', [$from->toDateString(), $to->toDateString()]);

        $f = $this->byKey($filters);
        $cols = [
            'loc' => "COALESCE(s.location_id, '')", 'staff' => "COALESCE(s.rang_up_by_user_id, '')", 'cat' => self::CAT,
            'pay' => "COALESCE(NULLIF(s.payment_method, ''), 'unknown')",
            'ctype' => "CASE WHEN s.customer_id IS NULL THEN 'walkin' ELSE 'known' END",
            'mband' => self::bandSql(self::MPCT, [30, 40, 50, 60]),
            'pband' => self::bandSql('i.unit_price_cents', [2500, 5000, 10000, 20000]),
        ];
        foreach ($f as $k => $spec) {
            if (isset($cols[$k])) {
                $this->sqlFilter($q, $cols[$k], $spec);
            }
        }
        return $q;
    }

    private function selectComponents($q)
    {
        $S = self::SIGN; $L = self::LINE;
        return $q->selectRaw("SUM($S * $L) as c_rev")
            ->selectRaw("SUM(CASE WHEN i.cost_cents_snapshot IS NOT NULL THEN $S * ($L - CAST(i.cost_cents_snapshot AS SIGNED) * i.quantity) ELSE 0 END) as c_margin")
            ->selectRaw("SUM(CASE WHEN i.cost_cents_snapshot IS NOT NULL THEN $S * $L ELSE 0 END) as c_costed")
            ->selectRaw("SUM(CASE WHEN i.type IN ('product', 'open_item') THEN $S * i.quantity ELSE 0 END) as c_items")
            ->selectRaw('COUNT(DISTINCT CASE WHEN s.refund_of_sale_id IS NULL THEN s.id END) as c_tickets')
            ->selectRaw('SUM(CASE WHEN s.refund_of_sale_id IS NULL THEN i.discount_cents ELSE 0 END) as c_disc')
            ->selectRaw("SUM(CASE WHEN s.refund_of_sale_id IS NOT NULL THEN $L ELSE 0 END) as c_refunds")
            ->selectRaw("SUM($S * i.tax_cents) as c_tax")
            ->selectRaw("SUM(CASE WHEN i.is_taxable = 1 THEN $S * $L ELSE 0 END) as c_taxable");
    }

    private static function comps(object $r): array
    {
        return [
            'rev' => (float) $r->c_rev, 'margin' => (float) $r->c_margin, 'costed' => (float) $r->c_costed,
            'items' => (float) $r->c_items, 'tickets' => (float) $r->c_tickets, 'disc' => (float) $r->c_disc,
            'refunds' => (float) $r->c_refunds, 'tax' => (float) $r->c_tax, 'taxable' => (float) $r->c_taxable,
        ];
    }

    public function run(array $dims, array $filters, Carbon $from, Carbon $to): array
    {
        $q = $this->base($filters, $from, $to);
        $specs = [];
        foreach ($dims as $n => $d) {
            $specs[$n] = $this->dimSql($d);
            // MARKER-SIGNED-MATH: group by the alias; a long CASE (margin band) repeated in GROUP BY fails ONLY_FULL_GROUP_BY
            $q->selectRaw($specs[$n][0] . " as g$n")->groupByRaw("g$n");
        }
        if (in_array('item', $dims, true)) {
            $q->selectRaw('MAX(i.name_snapshot) as lname');
        }
        $this->selectComponents($q);

        $groups = [];
        foreach ($q->get() as $r) {
            $keys = []; $labels = [];
            foreach ($dims as $n => $d) {
                $raw = (string) $r->{"g$n"};
                [$keys[$n], $labels[$n]] = $this->keyLabel($d, $specs[$n][1], $raw, $r);
            }
            $this->add($groups, $keys, $labels, self::comps($r));
        }

        $t = $this->selectComponents($this->base($filters, $from, $to))->first();
        return ['groups' => $groups, 'total' => $t ? self::comps($t) : []];
    }

    private function keyLabel(string $d, string $kind, string $raw, object $r): array
    {
        if ($kind === 'date') {
            $k = $this->p->key($d, substr($raw, 0, 10));
            return [$k, $this->p->label($d, $k)];
        }
        if ($kind === 'utch') {
            [$date, $hour] = $this->p->localFromUtcHour($raw);
            $k = $this->p->key('hour', $date, $hour);
            return [$k, $this->p->label('hour', $k)];
        }
        return match ($d) {
            'loc'   => [$raw, $raw === '' ? 'No location' : ($this->names('loc')[$raw] ?? 'Removed location')],
            'staff' => [$raw, $raw === '' ? 'Unknown' : ($this->names('user')[$raw] ?? 'Former staff')],
            'cat'   => [$raw, ['uncat' => 'Uncategorized', 'svc' => 'Services', 'other' => 'Other'][$raw] ?? ($this->names('cat')[$raw] ?? 'Removed category')],
            'pay'   => [$raw, self::payLabel($raw)],
            'ctype' => [$raw, $raw === 'walkin' ? 'Walk-in' : 'Known customer'],
            'item'  => [$raw, (string) ($r->lname ?? $raw)],
            'mband', 'pband' => [$raw, $this->bandOptions($d)[$raw] ?? $raw],
            default => [$raw, $raw],
        };
    }

    public function value(string $m, array $c): ?float
    {
        $c += ['rev' => 0, 'margin' => 0, 'costed' => 0, 'items' => 0, 'tickets' => 0, 'disc' => 0, 'refunds' => 0, 'tax' => 0, 'taxable' => 0];
        return match ($m) {
            'rev'     => $c['rev'] / 100,
            'margin'  => $c['costed'] != 0 ? $c['margin'] / 100 : null,   // no line with a saved cost: unknown, not zero
            'marginp' => self::ratio($c['margin'], $c['costed'], 100),
            'items'   => (float) $c['items'],
            'tickets' => (float) $c['tickets'],
            'ipt'     => self::ratio($c['items'], $c['tickets']),
            'avg'     => ($r = self::ratio($c['rev'], $c['tickets'])) === null ? null : $r / 100,
            'disc'    => $c['disc'] / 100,
            'refunds' => $c['refunds'] / 100,
            'tax'     => $c['tax'] / 100,
            'taxable' => $c['taxable'] / 100,
            default   => null,
        };
    }
}
