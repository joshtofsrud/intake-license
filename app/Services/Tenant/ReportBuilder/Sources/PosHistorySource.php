<?php

namespace App\Services\Tenant\ReportBuilder\Sources;

use App\Models\Tenant\TenantPosConnection;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-POS-SALES-HISTORY: Connected POS sales — past sales read in from the shop's
 * outside POS. They are not Intake sales and are never mixed into the Sales data:
 * every measure here is named "Connected POS …" so a report says where its numbers
 * came from. A return is a sale with negative lines, so everything is net of returns.
 *
 * Which Intake item, category and location a line belongs to is looked up as the
 * report runs (through the product and outlet links), so an item imported after the
 * history was read is counted under its category from then on.
 */
class PosHistorySource extends Source
{
    private const ITEM = "COALESCE(it.id, CONCAT('n:', COALESCE(l.name, l.sku, 'Unnamed line')))";
    private const CAT = "CASE WHEN it.id IS NULL THEN 'notin' ELSE COALESCE(it.category_id, 'uncat') END";
    private const MPCT = 'CASE WHEN l.unit_cost_cents IS NULL OR l.unit_price_cents = 0 THEN NULL ELSE (l.unit_price_cents - l.unit_cost_cents) * 100.0 / l.unit_price_cents END';

    private ?array $outlets = null;

    public function meta(): array
    {
        return [
            'label' => 'Connected POS sales',
            'note'  => 'Sales made in your Connected POS and read in as history. They are not Intake sales: they are not in the Sales data, the cash drawer or your tax reports. Revenue is before tax; returns are taken off. Sales Intake sent there (online orders) are left out, so nothing is counted twice.',
            'snap'  => false,
            'measures' => [
                'rev'     => ['Connected POS revenue', '$'],
                'margin'  => ['Connected POS margin $', '$'],
                'marginp' => ['Connected POS margin %', '%'],
                'items'   => ['Connected POS items sold', 'n'],
                'tickets' => ['Connected POS sales', 'n'],
                'ipt'     => ['Connected POS items per sale', 'd'],
                'avg'     => ['Connected POS average sale', '$'],
                'disc'    => ['Connected POS discounts', '$'],
                'refunds' => ['Connected POS returns', '$'],
                'tax'     => ['Connected POS tax', '$'],
            ],
            'dims' => [
                'day'   => ['Day', 't'], 'week' => ['Week', 't'], 'month' => ['Month', 't'], 'hour' => ['Hour of day', 't'],
                'loc'   => ['Location', 'c'], 'cat' => ['Category', 'c'], 'ctype' => ['Customer type', 'c'], 'item' => ['Item', 'c'],
                'mband' => ['Margin range', 'c'], 'pband' => ['Price band', 'c'], 'inintake' => ['In Intake?', 'c'],
            ],
            'filters' => ['loc' => 'Location', 'cat' => 'Category', 'ctype' => 'Customer type',
                'mband' => 'Margin range', 'pband' => 'Price band', 'inintake' => 'In Intake?'],
        ];
    }

    public function options(string $key): array
    {
        return match ($key) {
            'loc'      => $this->locOptions(),
            'cat'      => $this->activeCategories() + ['uncat' => 'Uncategorized', 'notin' => 'Not an Intake item'],
            'ctype'    => ['known' => 'Known customer', 'walkin' => 'Walk-in'],
            'inintake' => ['yes' => 'Intake item', 'no' => 'Text line only'],
            'mband', 'pband' => $this->bandOptions($key),
            default    => [],
        };
    }

    /** outlet id => [key, label]: the Intake location it is mapped to, or the outlet itself. */
    private function outlets(): array
    {
        if ($this->outlets !== null) {
            return $this->outlets;
        }
        $conn = TenantPosConnection::where('tenant_id', $this->tid())->first();
        $names = [];
        foreach ((array) ($conn?->locations ?? []) as $o) {
            if (isset($o['id'])) {
                $names[(string) $o['id']] = (string) ($o['name'] ?? '');
            }
        }
        $map = array_map('strval', array_filter((array) ($conn?->location_map ?? [])));
        $locs = $this->names('loc');
        $seen = DB::table('tenant_pos_sales')->where('tenant_id', $this->tid())->whereNotNull('external_outlet_id')->distinct()->pluck('external_outlet_id');
        $out = [];
        foreach ($seen as $id) {
            $id = (string) $id;
            $out[$id] = isset($map[$id], $locs[$map[$id]])
                ? [$map[$id], $locs[$map[$id]]]
                : ['o:' . $id, ($names[$id] ?? '') !== '' ? $names[$id] . ' (Connected POS)' : 'Connected POS outlet'];
        }

        return $this->outlets = $out;
    }

    private function locOptions(): array
    {
        $out = [];
        foreach ($this->outlets() as [$k, $label]) {
            $out[$k] = $label;
        }

        return $out + ['' => 'No outlet'];
    }

    /** The outlet → location key, as SQL. Values are quoted by the database driver. */
    private function locSql(): string
    {
        $pdo = DB::connection()->getPdo();
        $when = '';
        foreach ($this->outlets() as $id => [$k]) {
            $when .= ' WHEN ' . $pdo->quote($id) . ' THEN ' . $pdo->quote($k);
        }

        return $when === '' ? "''" : "CASE s.external_outlet_id$when ELSE '' END";
    }

    private function dimSql(string $d): array
    {
        return match ($d) {
            'day', 'week', 'month', 'pp' => ['s.sale_date', 'date'],
            'hour'     => ["DATE_FORMAT(s.sold_at, '%Y-%m-%d %H')", 'utch'],
            'loc'      => [$this->locSql(), 'key'],
            'cat'      => [self::CAT, 'key'],
            'ctype'    => ["CASE WHEN s.external_customer_id IS NULL THEN 'walkin' ELSE 'known' END", 'key'],
            'item'     => [self::ITEM, 'key'],
            'inintake' => ["CASE WHEN it.id IS NULL THEN 'no' ELSE 'yes' END", 'key'],
            'mband'    => [self::bandSql(self::MPCT, [30, 40, 50, 60]), 'key'],
            'pband'    => [self::bandSql('l.unit_price_cents', [2500, 5000, 10000, 20000]), 'key'],
            default    => ["''", 'key'],
        };
    }

    private function base(array $filters, Carbon $from, Carbon $to)
    {
        $tid = $this->tid();
        $q = DB::table('tenant_pos_sale_lines as l')
            ->join('tenant_pos_sales as s', 's.id', '=', 'l.pos_sale_id')
            ->leftJoin('tenant_pos_items as pi', function ($j) {
                $j->on('pi.tenant_id', '=', 'l.tenant_id')->on('pi.external_id', '=', 'l.external_product_id');
            })
            ->leftJoin('tenant_inventory_items as it', function ($j) {
                $j->on('it.id', '=', 'pi.inventory_item_id')->whereNull('it.deleted_at');
            })
            ->where('s.tenant_id', $tid)
            ->where('l.tenant_id', $tid)
            ->whereBetween('s.sale_date', [$from->toDateString(), $to->toDateString()]);

        foreach ($this->byKey($filters) as $k => $spec) {
            if (in_array($k, ['loc', 'cat', 'ctype', 'inintake', 'mband', 'pband'], true)) {
                $this->sqlFilter($q, $this->dimSql($k)[0], $spec);
            }
        }

        return $q;
    }

    private function selectComponents($q)
    {
        return $q->selectRaw('SUM(l.total_cents) as c_rev')
            ->selectRaw('SUM(CASE WHEN l.unit_cost_cents IS NOT NULL THEN l.total_cents - l.unit_cost_cents * l.quantity ELSE 0 END) as c_margin')
            ->selectRaw('SUM(CASE WHEN l.unit_cost_cents IS NOT NULL THEN l.total_cents ELSE 0 END) as c_costed')
            ->selectRaw('SUM(l.quantity) as c_items')
            ->selectRaw('COUNT(DISTINCT CASE WHEN s.subtotal_cents >= 0 THEN s.id END) as c_tickets')
            ->selectRaw('SUM(CASE WHEN l.quantity >= 0 THEN l.discount_cents ELSE 0 END) as c_disc')
            ->selectRaw('SUM(CASE WHEN l.total_cents < 0 THEN -l.total_cents ELSE 0 END) as c_refunds')
            ->selectRaw('SUM(l.tax_cents) as c_tax');
    }

    private static function comps(object $r): array
    {
        return [
            'rev' => (float) $r->c_rev, 'margin' => (float) $r->c_margin, 'costed' => (float) $r->c_costed,
            'items' => (float) $r->c_items, 'tickets' => (float) $r->c_tickets, 'disc' => (float) $r->c_disc,
            'refunds' => (float) $r->c_refunds, 'tax' => (float) $r->c_tax,
        ];
    }

    public function run(array $dims, array $filters, Carbon $from, Carbon $to): array
    {
        $q = $this->base($filters, $from, $to);
        $specs = [];
        foreach ($dims as $n => $d) {
            $specs[$n] = $this->dimSql($d);
            $q->selectRaw($specs[$n][0] . " as g$n")->groupByRaw("g$n");
        }
        if (in_array('item', $dims, true)) {
            $q->selectRaw('MAX(COALESCE(it.name, l.name, l.sku)) as lname');
        }
        $this->selectComponents($q);

        $groups = [];
        foreach ($q->get() as $r) {
            $keys = [];
            $labels = [];
            foreach ($dims as $n => $d) {
                [$keys[$n], $labels[$n]] = $this->keyLabel($d, $specs[$n][1], (string) $r->{"g$n"}, $r);
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
            'loc'      => [$raw, $raw === '' ? 'No outlet' : ($this->locOptions()[$raw] ?? 'Removed location')],
            'cat'      => [$raw, ['uncat' => 'Uncategorized', 'notin' => 'Not an Intake item'][$raw] ?? ($this->names('cat')[$raw] ?? 'Removed category')],
            'ctype'    => [$raw, $raw === 'walkin' ? 'Walk-in' : 'Known customer'],
            'item'     => [$raw, (string) ($r->lname ?? $raw)],
            'inintake' => [$raw, $raw === 'yes' ? 'Intake item' : 'Text line only'],
            'mband', 'pband' => [$raw, $this->bandOptions($d)[$raw] ?? $raw],
            default    => [$raw, $raw],
        };
    }

    public function value(string $m, array $c): ?float
    {
        $c += ['rev' => 0, 'margin' => 0, 'costed' => 0, 'items' => 0, 'tickets' => 0, 'disc' => 0, 'refunds' => 0, 'tax' => 0];

        return match ($m) {
            'rev'     => $c['rev'] / 100,
            'margin'  => $c['costed'] != 0 ? $c['margin'] / 100 : null, // no line with a cost: unknown, not zero
            'marginp' => self::ratio($c['margin'], $c['costed'], 100),
            'items'   => (float) $c['items'],
            'tickets' => (float) $c['tickets'],
            'ipt'     => self::ratio($c['items'], $c['tickets']),
            'avg'     => ($r = self::ratio($c['rev'], $c['tickets'])) === null ? null : $r / 100,
            'disc'    => $c['disc'] / 100,
            'refunds' => $c['refunds'] / 100,
            'tax'     => $c['tax'] / 100,
            default   => null,
        };
    }
}
