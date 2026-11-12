<?php

namespace App\Services\Tenant\ReportBuilder;

use App\Models\Tenant;
use Carbon\Carbon;

/**
 * TabPanels — the panels on the Reports tabs, every figure read from the report engine.
 * (MARKER-REPORT-TABS-ENGINE)
 *
 * A panel is plain data the page prints: a title, a strip of figures or a table, and the exact
 * report-builder settings it came from, so "Open in builder" and Explain show the same numbers.
 * For a shop without extended reports the totals are real, but a list is never run: its rows are not
 * read, not built and not sent to the page (the page draws placeholder rows instead).
 */
class TabPanels
{
    public function __construct(
        private readonly Tenant $tenant,
        private readonly ReportEngine $e,
        private readonly array $q,
        private readonly bool $isManager,
        private readonly bool $locked,
    ) {}

    // ---------------------------------------------------------------- small helpers

    private function cfg(string $ds, array $measures, string $dim, array $extra = []): array
    {
        return $this->e->clean($extra + [
            'ds' => $ds, 'measures' => $measures, 'dim' => $dim,
            'range' => $this->q['range'], 'from' => $this->q['from'], 'to' => $this->q['to'], 'compare' => $this->q['compare'],
        ]);
    }

    private function fmt(?float $v, string $t): string
    {
        if ($t === '$' && $v !== null && abs($v) < 0.005) return '$0';
        return ReportEngine::format($v, $t);
    }

    private static function pct(?float $cur, ?float $prev): ?float
    {
        if ($cur === null || $prev === null || abs($prev) < 0.000001) return null;
        return ($cur - $prev) / abs($prev) * 100;
    }

    private function word(): string
    {
        return TabQuery::compareWord($this->q['compare']);
    }

    /**
     * @param string $unit '%' for a relative change, 'pts' for a change in a percentage (36% to 38% is +2.0 pts)
     * @param bool $invert true where lower is better (cancellations), so a rise shows as bad
     * @return array{l: string, v: string, d: string, cls: string}
     */
    private function fig(string $label, string $value, ?string $sub = null, string $cls = '', ?float $delta = null, string $unit = '%', bool $invert = false): array
    {
        if ($delta !== null && $this->q['compare'] !== 'off') {
            if (round($delta, 1) == 0.0) {
                $sub = 'no change ' . $this->word();
            } else {
                $sub = ($delta >= 0 ? '+' : '−') . number_format(abs($delta), 1) . ($unit === 'pts' ? ' pts ' : '% ') . $this->word();
                $cls = $cls ?: (($delta >= 0) !== $invert ? 'good' : 'bad');
            }
        }
        return ['l' => $label, 'v' => $value, 'd' => (string) $sub, 'cls' => $cls];
    }

    /** A figure with its change on the period compared with. A percentage changes in points, not in percent of itself. */
    private function figCmp(string $label, array $t, string $m, string $type, ?string $sub = null, string $cls = '', bool $invert = false): array
    {
        $cur = $t['vals'][$m] ?? null;
        $prev = ($t['prev'] ?? null) !== null ? ($t['prev'][$m] ?? null) : null;
        if ($type === '%') {
            $delta = ($cur !== null && $prev !== null) ? $cur - $prev : null;
            return $this->fig($label, $this->fmt($cur, $type), $sub, $cls, $delta, 'pts', $invert);
        }
        return $this->fig($label, $this->fmt($cur, $type), $sub, $cls, self::pct($cur, $prev), '%', $invert);
    }

    private function delta(?float $cur, ?float $prev): string
    {
        $p = self::pct($cur, $prev);
        if ($p === null) return '<span class="rb-hint">—</span>';
        if (round($p, 1) == 0.0) return '<span class="rb-hint">0.0%</span>';
        return '<span class="' . ($p >= 0 ? 'd-up' : 'd-dn') . '" style="margin-left:0">' . ($p >= 0 ? '+' : '−') . number_format(abs($p), 1) . '%</span>';
    }

    private static function share(float $p): string
    {
        $p = max(0, min(100, round($p)));
        return '<span class="rx-share"><span style="width:' . $p . '%"></span></span>' . $p . '%';
    }

    private static function pill(string $t, string $c): string
    {
        return '<span class="rb-pill ' . $c . '">' . e($t) . '</span>';
    }

    private function dateText(Carbon $a, Carbon $b): string
    {
        return $a->isSameDay($b) ? $a->format('M j, Y') : ($a->year === $b->year ? $a->format('M j') . ' – ' . $b->format('M j, Y') : $a->format('M j, Y') . ' – ' . $b->format('M j, Y'));
    }

    /** What "Open in builder" shows and where it goes. */
    private function open(array $cfg, ?string $extra = null, bool $snapshot = false): array
    {
        $cat = $this->e->catalog();
        $show = [];
        foreach ($cfg['measures'] as $m) {
            [$label] = $this->e->measureDef($cfg, $m);
            $from = str_contains($m, ':') ? explode(':', $m, 2)[0] : $cfg['ds'];
            $show[] = $label . ($from !== $cfg['ds'] ? ' (from ' . ($cat[$from]['l'] ?? $from) . ')' : '');
        }
        $by = $this->e->dimLabel($cfg, $cfg['dim']);
        if ($cfg['dim2'] !== '') $by .= ' × ' . $this->e->dimLabel($cfg, $cfg['dim2']);
        [$a, $b] = $this->e->periods()->rangeFor($cfg);
        $range = $snapshot ? 'What you hold today' : ($cfg['range'] === 'Custom' ? $this->dateText($a, $b) : $cfg['range'] . ' · ' . $this->dateText($a, $b));
        $rows = [['Data', $cat[$cfg['ds']]['l'] ?? $cfg['ds']], ['Show', implode(', ', $show)], ['Break down by', $by], ['Range', $range],
            ['Compare', $snapshot ? 'Not used' : TabQuery::COMPARE[$cfg['compare']]]];
        if ($extra) $rows[] = ['Also', $extra];
        $pub = array_intersect_key($cfg, array_flip(['ds', 'measures', 'calcs', 'dim', 'dim2', 'filters', 'range', 'from', 'to', 'compare', 'sortDir', 'limit']));
        $code = rtrim(strtr(base64_encode(json_encode($pub)), '+/', '-_'), '=');
        return ['rows' => $rows, 'url' => route('tenant.reports.custom', ['cfg' => $code])];
    }

    /** One panel. $body holds 'figs', 'bar', 'table', 'html', 'after' as needed. */
    private function panel(string $id, string $title, string $hint, ?array $cfg, array $body = [], ?string $openExtra = null, bool $snapshot = false): array
    {
        $p = ['id' => $id, 'title' => $title, 'hint' => $hint, 'figs' => [], 'bar' => null, 'table' => null, 'html' => null, 'after' => null, 'note' => null, 'cfg' => null, 'open' => null, 'grid' => null, 'chart' => null];
        if ($cfg) {
            $p['cfg'] = array_intersect_key($cfg, array_flip(['ds', 'measures', 'calcs', 'dim', 'dim2', 'filters', 'range', 'from', 'to', 'compare', 'sortDir', 'limit']));
            $p['open'] = $this->open($cfg, $openExtra, $snapshot);
        }
        return $body + $p;
    }

    /**
     * A list. $rows is built by the caller only when the shop isn't locked; a locked list carries
     * nothing but its column names and how many placeholder rows to draw.
     */
    private function table(array $head, array $rows, ?array $total, string $what, ?string $empty = null): array
    {
        return ['head' => $head, 'rows' => $this->locked ? [] : $rows, 'total' => $this->locked ? null : $total, 'locked' => $this->locked,
            'what' => $what, 'fake' => min(5, max(3, count($rows))), 'empty' => $empty];
    }

    // ---------------------------------------------------------------- Retail

    public function retail(): array
    {
        $e = $this->e;
        $out = [];

        // Sales
        $c = $this->cfg('sales', ['rev', 'tickets', 'avg', 'refunds', 'marginp'], 'day');
        $t = $e->totals($c);
        $refunds = $t['vals']['refunds'] ?? null;
        $out[] = $this->panel('r-sales', 'Sales', 'paid sales, refunds taken off, before tax and tips', $c, ['figs' => [
            $this->figCmp('Revenue', $t, 'rev', '$'),
            $this->figCmp('Tickets', $t, 'tickets', 'n'),
            $this->figCmp('Average ticket', $t, 'avg', '$'),
            $this->fig('Refunds', $this->fmt($refunds, '$'), $refunds ? 'already taken off revenue' : 'none in this range', $refunds ? 'warn' : ''),
            $this->figCmp('Margin %', $t, 'marginp', '%'),
        ]]);
        $salesRev = $t['vals']['rev'] ?? 0.0;

        // Sales by staff (owners and managers)
        if ($this->isManager) {
            $c = $this->cfg('sales', ['tickets', 'ipt', 'rev'], 'staff');
            $head = ['Staff', 'Tickets', 'Items / ticket', 'Revenue', 'Share'] + ($this->q['compare'] !== 'off' ? [5 => 'vs before'] : []);
            $rows = []; $total = null;
            if (!$this->locked) {
                $r = $e->rows($c);
                foreach ($r['rows'] as $row) {
                    $rev = $e->cellValue($c, 'rev', $row['vals']);
                    $cells = [e($row['l']), $this->fmt($e->cellValue($c, 'tickets', $row['vals']), 'n'), $this->fmt($e->cellValue($c, 'ipt', $row['vals']), 'd'),
                        $this->fmt($rev, '$'), self::share($salesRev > 0 && $rev !== null ? $rev / $salesRev * 100 : 0)];
                    if ($this->q['compare'] !== 'off') $cells[] = $this->delta($rev, $e->cellValue($c, 'rev', $row['prev']));
                    $rows[] = $cells;
                }
                $total = ['Total', $this->fmt($r['totals']['sales:tickets'] ?? null, 'n'), $this->fmt($r['totals']['sales:ipt'] ?? null, 'd'), $this->fmt($r['totals']['sales:rev'] ?? null, '$'), ''];
                if ($this->q['compare'] !== 'off') $total[] = '';
            }
            $out[] = $this->panel('r-staff', 'Sales by staff', 'who rang up what', $c,
                ['table' => $this->table($head, $rows, $total, 'staff sales', 'No sales in this range.')]);
        } else {
            $out[] = $this->panel('r-staff', 'Sales by staff', 'who rang up what', null,
                ['note' => 'Sales by staff is shown to owners and managers.']);
        }

        // What's moving
        $c = $this->cfg('sales', ['items', 'rev', 'marginp', 'inv:dleft'], 'item', ['limit' => 8, 'compare' => 'off']);
        $rows = [];
        if (!$this->locked) {
            $r = $e->rows($c);
            foreach ($r['rows'] as $row) {
                $left = $e->cellValue($c, 'inv:dleft', $row['vals']);
                $cell = $left === null ? '—' : ($left <= 0 ? self::pill('Out of stock', 'bad') : ($left < 14 ? self::pill(number_format(round($left)) . ' days', 'bad') : ($left < 30 ? self::pill(number_format(round($left)) . ' days', 'warn') : number_format(round($left)))));
                $rows[] = [e($row['l']), $this->fmt($e->cellValue($c, 'items', $row['vals']), 'n'), $this->fmt($e->cellValue($c, 'rev', $row['vals']), '$'),
                    $this->fmt($e->cellValue($c, 'marginp', $row['vals']), '%'), $cell];
            }
        }
        $out[] = $this->panel('r-items', 'What\'s moving', 'top items by units sold, and how long the stock will last', $c, [
            'table' => $this->table(['Item', 'Units', 'Revenue', 'Margin %', 'Days of stock left'], $rows, null, 'top items', 'No items sold in this range.'),
            'after' => 'Days of stock left is today\'s stock divided by how fast the item sold in this range.',
        ], 'Top 8, most units first');

        // Margin by category
        $c = $this->cfg('sales', ['rev', 'margin', 'marginp'], 'cat');
        $rows = []; $total = null; $cmp = $this->q['compare'] !== 'off';
        if (!$this->locked) {
            $r = $e->rows($c);
            foreach ($r['rows'] as $row) {
                $cells = [e($row['l']), $this->fmt($e->cellValue($c, 'rev', $row['vals']), '$'), $this->fmt($e->cellValue($c, 'margin', $row['vals']), '$'), $this->fmt($e->cellValue($c, 'marginp', $row['vals']), '%')];
                if ($cmp) $cells[] = $this->delta($e->cellValue($c, 'rev', $row['vals']), $e->cellValue($c, 'rev', $row['prev']));
                $rows[] = $cells;
            }
            $total = ['Total', $this->fmt($r['totals']['sales:rev'] ?? null, '$'), $this->fmt($r['totals']['sales:margin'] ?? null, '$'), $this->fmt($r['totals']['sales:marginp'] ?? null, '%')];
            if ($cmp) $total[] = '';
        }
        $out[] = $this->panel('r-margin', 'Margin by category', 'where the profit comes from', $c, [
            'table' => $this->table(['Category', 'Revenue', 'Margin $', 'Margin %'] + ($cmp ? [4 => 'Revenue vs before'] : []), $rows, $total, 'margins', 'No sales in this range.'),
            'after' => 'Lines with no cost recorded (services, usually) show no margin. They are left out of the margin %, not counted as zero.',
        ]);

        // Inventory health (a snapshot, so it doesn't follow the range)
        $out[] = $this->inventoryPanel();

        // Receiving
        $c = $this->cfg('recv', ['runits', 'rcost', 'rmp', 'rst'], 'vend');
        $t = $e->totals($c);
        $out[] = $this->panel('r-recv', 'Receiving', 'what came in during the range, and whether it sold', $c, ['figs' => [
            $this->figCmp('Units received', $t, 'runits', 'n'),
            $this->figCmp('Cost received', $t, 'rcost', '$'),
            $this->fig('Margin at receipt', $this->fmt($t['vals']['rmp'] ?? null, '%'), 'retail value against cost'),
            $this->fig('Sold since', $this->fmt($t['vals']['rst'] ?? null, '%'), 'of what you received'),
        ]]);

        $legend = [
            ['Same numbers:', 'Revenue is paid sale lines after refunds, before tax and tips. It is the same figure you get in Custom › Sales, so a number here matches the same report there. Service lines rung up at the register are included.'],
            ['Refunds:', 'are taken off revenue, margin and items, not left out. The old Retail page left them out, which made revenue look higher than it was.'],
            ['Stock:', 'Inventory health shows what you hold today, whatever range is picked.'],
        ];
        return ['panels' => $out, 'legend' => $legend];
    }

    // ---------------------------------------------------------------- Online store (MARKER-ONLINE-REPORTS)

    private const ONLINE = [['f' => 'channel', 'op' => 'is', 'v' => 'online']];

    public function online(): array
    {
        $e = $this->e;
        $out = [];
        $cmp = $this->q['compare'] !== 'off';

        // Online sales
        $c = $this->cfg('sales', ['rev', 'tickets', 'avg', 'refunds', 'marginp'], 'day', ['filters' => self::ONLINE]);
        $t = $e->totals($c);
        $all = $e->totals($this->cfg('sales', ['rev'], 'day'));
        $allRev = $all['vals']['rev'] ?? 0.0;
        $rev = $t['vals']['rev'] ?? 0.0;
        $refunds = $t['vals']['refunds'] ?? null;
        $out[] = $this->panel('o-sales', 'Online sales', 'paid online orders, refunds taken off, before tax and tips', $c, ['figs' => [
            $this->figCmp('Revenue', $t, 'rev', '$'),
            $this->figCmp('Orders', $t, 'tickets', 'n'),
            $this->figCmp('Average order', $t, 'avg', '$'),
            $this->fig('Share of all sales', $this->fmt($allRev > 0 ? $rev / $allRev * 100 : null, '%'), 'online against register and online together'),
            $this->fig('Refunds', $this->fmt($refunds, '$'), $refunds ? 'already taken off revenue' : 'none in this range', $refunds ? 'warn' : ''),
        ]]);

        // Sales by channel
        $c = $this->cfg('sales', ['tickets', 'avg', 'rev'], 'channel');
        $rows = []; $total = null;
        if (!$this->locked) {
            $r = $e->rows($c);
            foreach ($r['rows'] as $row) {
                $v = $e->cellValue($c, 'rev', $row['vals']);
                $cells = [e($row['l']), $this->fmt($e->cellValue($c, 'tickets', $row['vals']), 'n'), $this->fmt($e->cellValue($c, 'avg', $row['vals']), '$'),
                    $this->fmt($v, '$'), self::share($allRev > 0 && $v !== null ? $v / $allRev * 100 : 0)];
                if ($cmp) $cells[] = $this->delta($v, $e->cellValue($c, 'rev', $row['prev']));
                $rows[] = $cells;
            }
            $total = ['Total', $this->fmt($r['totals']['sales:tickets'] ?? null, 'n'), $this->fmt($r['totals']['sales:avg'] ?? null, '$'), $this->fmt($r['totals']['sales:rev'] ?? null, '$'), ''];
            if ($cmp) $total[] = '';
        }
        $out[] = $this->panel('o-channel', 'Sales by channel', 'online store next to the register', $c, [
            'table' => $this->table(['Sold where', 'Tickets', 'Average ticket', 'Revenue', 'Share'] + ($cmp ? [5 => 'vs before'] : []), $rows, $total, 'channels', 'No sales in this range.'),
        ]);

        // Online sales by item
        $c = $this->cfg('sales', ['items', 'rev', 'marginp'], 'item', ['limit' => 10, 'compare' => 'off', 'filters' => self::ONLINE]);
        $out[] = $this->listPanel('o-items', 'Online sales by item', 'what the online store sells', $c, 'Item',
            [['Units', 'items', 'n'], ['Revenue', 'rev', '$'], ['Margin %', 'marginp', '%']], 'online items', 'Nothing sold online in this range.', null, 'Top 10, most units first');

        // Shipping charged against cost
        $c = $this->cfg('ship', ['ships', 'charged', 'cost', 'net', 'recover', 'uncosted'], 'carrier');
        $t = $e->totals($c);
        $unc = (int) round($t['vals']['uncosted'] ?? 0);
        $figs = [
            $this->figCmp('Shipments', $t, 'ships', 'n'),
            $this->figCmp('Charged to customers', $t, 'charged', '$'),
            $this->fig('Your cost', $this->fmt($t['vals']['cost'] ?? null, '$'), 'only shipments with a cost entered'),
            $this->fig('Charged minus cost', $this->fmt($t['vals']['net'] ?? null, '$'), 'on shipments with a cost entered',
                ($t['vals']['net'] ?? 0) < 0 ? 'bad' : ''),
            $this->fig('Cost recovered', $this->fmt($t['vals']['recover'] ?? null, '%'), 'of what shipping cost you'),
        ];
        $rows = []; $total = null;
        if (!$this->locked) {
            $r = $e->rows($c);
            foreach ($r['rows'] as $row) {
                $net = $e->cellValue($c, 'net', $row['vals']);
                $rows[] = [e($row['l']), $this->fmt($e->cellValue($c, 'ships', $row['vals']), 'n'), $this->fmt($e->cellValue($c, 'charged', $row['vals']), '$'),
                    $this->fmt($e->cellValue($c, 'cost', $row['vals']), '$'),
                    $net !== null && $net < 0 ? self::pill($this->fmt($net, '$'), 'bad') : $this->fmt($net, '$'),
                    $this->fmt($e->cellValue($c, 'recover', $row['vals']), '%')];
            }
            $total = ['Total', $this->fmt($r['totals']['ship:ships'] ?? null, 'n'), $this->fmt($r['totals']['ship:charged'] ?? null, '$'),
                $this->fmt($r['totals']['ship:cost'] ?? null, '$'), $this->fmt($r['totals']['ship:net'] ?? null, '$'), $this->fmt($r['totals']['ship:recover'] ?? null, '%')];
        }
        $ts = (float) ($t['vals']['ships'] ?? 0);
        $out[] = $this->panel('o-ship', 'Shipping charged against cost', 'what customers paid for shipping and what it cost you', $c, [
            'figs' => $figs,
            'table' => $this->table(['Carrier', 'Shipments', 'Charged', 'Your cost', 'Charged minus cost', 'Recovered'], $rows, $total, 'carriers', 'Nothing shipped in this range.'),
            'after' => $unc > 0
                ? number_format($unc) . ' of ' . number_format($ts) . ' shipments have no cost entered, so they are left out of cost and recovery. Enter what a shipment cost when you mark it shipped.'
                : 'Cost comes from what you enter when you mark a shipment shipped.',
        ]);

        // Dropship share
        $c = $this->cfg('ship', ['ships', 'units', 'supshare', 'supships', 'charged'], 'source');
        $t = $e->totals($c);
        $rows = []; $total = null;
        if (!$this->locked) {
            $r = $e->rows($c);
            $tu = (float) ($r['totals']['ship:units'] ?? 0);
            foreach ($r['rows'] as $row) {
                $u = $e->cellValue($c, 'units', $row['vals']);
                $rows[] = [e($row['l']), $this->fmt($e->cellValue($c, 'ships', $row['vals']), 'n'), $this->fmt($u, 'n'),
                    self::share($tu > 0 && $u !== null ? $u / $tu * 100 : 0), $this->fmt($e->cellValue($c, 'charged', $row['vals']), '$')];
            }
            $total = ['Total', $this->fmt($r['totals']['ship:ships'] ?? null, 'n'), $this->fmt($tu, 'n'), '', $this->fmt($r['totals']['ship:charged'] ?? null, '$')];
        }
        $out[] = $this->panel('o-drop', 'Dropship share', 'how much ships from your shop and how much from suppliers', $c, [
            'figs' => [
                $this->figCmp('Dropship share of units', $t, 'supshare', '%'),
                $this->figCmp('Supplier shipments', $t, 'supships', 'n'),
                $this->figCmp('Units shipped', $t, 'units', 'n'),
            ],
            'table' => $this->table(['Shipped from', 'Shipments', 'Units', 'Share of units', 'Shipping charged'], $rows, $total, 'sources', 'Nothing shipped in this range.'),
        ]);

        // Abandoned carts
        $c = $this->cfg('carts', ['started', 'paid', 'aband', 'abandp', 'abandval'], 'day');
        $t = $e->totals($c);
        $out[] = $this->panel('o-carts', 'Abandoned carts', 'carts with items in them, by the day they were started', $c, [
            'figs' => [
                $this->figCmp('Carts started', $t, 'started', 'n'),
                $this->figCmp('Paid', $t, 'paid', 'n'),
                $this->figCmp('Abandoned', $t, 'aband', 'n', null, '', true),
                $this->figCmp('Abandonment rate', $t, 'abandp', '%', 'of carts that ended', '', true),
                $this->figCmp('Value abandoned', $t, 'abandval', '$', null, '', true),
            ],
            'after' => 'A cart is abandoned once it has sat idle (or its payment hold ran out) and the shop closed it. Carts still open are left out of the rate.',
        ]);

        $c = $this->cfg('carts', ['aband', 'abandval', 'abandp'], 'item', ['limit' => 10, 'compare' => 'off']);
        $out[] = $this->listPanel('o-lost', 'What gets left in carts', 'items in abandoned carts, most carts first', $c, 'Item',
            [['Abandoned carts', 'aband', 'n'], ['Value', 'abandval', '$'], ['Abandonment rate', 'abandp', '%']], 'abandoned items', 'No abandoned carts in this range.', null, 'Top 10, most abandoned carts first');

        // Stock-outs (a snapshot of today)
        $out[] = $this->stockOuts();

        $legend = [
            ['Same numbers:', 'Online revenue is the same figure you get in Custom › Sales with Sold where set to Online store, so the two always match. Refunds are taken off.'],
            ['Shipping cost:', 'is what you enter for each shipment. Shipments without one are counted and named, and left out of the comparison instead of being treated as free.'],
            ['Dropship:', 'is a share of units shipped from a supplier rather than from your shelves, for paid orders by the day they were paid.'],
            ['Stock:', 'Stock-outs shows today, whatever range is picked.'],
        ];
        return ['panels' => $out, 'legend' => $legend];
    }

    /** Online items with nothing on the shelf and no supplier that could ship them, most-wanted first. */
    private function stockOuts(): array
    {
        $head = ['Item', 'On hand', 'Sold online (30 days)', 'Last sold online', 'Oversell'];
        $rows = [];
        if (!$this->locked) {
            $tid = $this->tenant->id;
            $items = \App\Models\Tenant\TenantInventoryItem::query()->where('tenant_id', $tid)->where('is_active', true)->where('show_online', true)
                ->where('computed_stock_count', '<=', 0)->get(['id', 'name', 'computed_stock_count', 'allow_oversell']);
            $ids = $items->pluck('id')->all();
            $backed = [];
            try {
                $backed = $ids ? \App\Services\Tenant\OrderStock::forTenant($this->tenant)->supplierBacked($ids) : [];
            } catch (\Throwable $e) {
                report($e);   // a supplier lookup that fails must not take the whole tab down
            }
            $ids = array_values(array_filter($ids, fn ($i) => !isset($backed[$i])));
            $sold = []; $last = [];
            if ($ids) {
                $since = \Carbon\Carbon::now('UTC')->subDays(30)->format('Y-m-d H:i:s');
                $q = \Illuminate\Support\Facades\DB::table('tenant_order_items as oi')->join('tenant_orders as o', 'o.id', '=', 'oi.order_id')
                    ->where('oi.tenant_id', $tid)->whereIn('oi.inventory_item_id', $ids)
                    ->whereIn('o.status', ['paid', 'fulfilling', 'fulfilled', 'completed'])
                    ->groupBy('oi.inventory_item_id')
                    ->selectRaw('oi.inventory_item_id as id, SUM(CASE WHEN o.paid_at >= ? THEN oi.quantity ELSE 0 END) as q30, MAX(o.paid_at) as last', [$since]);
                foreach ($q->get() as $r) { $sold[$r->id] = (float) $r->q30; $last[$r->id] = $r->last; }
            }
            $tz = $this->e->periods()->tz();
            $list = $items->filter(fn ($i) => in_array($i->id, $ids, true))->sortByDesc(fn ($i) => $sold[$i->id] ?? 0)->values();
            foreach ($list->take(25) as $i) {
                $l = $last[$i->id] ?? null;
                $rows[] = [e($i->name), self::pill((string) (int) $i->computed_stock_count, 'bad'), number_format($sold[$i->id] ?? 0),
                    $l ? \Carbon\Carbon::parse($l, 'UTC')->setTimezone($tz)->format('M j, Y') : '—', $i->allow_oversell ? 'Allowed' : 'Blocked'];
            }
        }
        return $this->panel('o-stock', 'Stock-outs', 'online items you cannot ship from anywhere today', null, [
            'table' => $this->table($head, $rows, null, 'stock-outs', 'Nothing online is out of stock.'),
            'after' => 'Items a supplier could ship are not listed. Oversell Allowed means the shop still takes the order; Blocked means shoppers are turned away.',
        ], null, true);
    }

    private function inventoryPanel(): array
    {
        $e = $this->e;
        $c = $this->cfg('inv', ['cost', 'dead'], 'bucket', ['compare' => 'off']);
        $r = $e->rows($c);
        $by = [];
        foreach ($r['rows'] as $row) $by[$row['k']] = $e->cellValue($c, 'cost', $row['vals']) ?? 0.0;
        $cost = $r['totals']['inv:cost'] ?? 0.0;
        $dead = $r['totals']['inv:dead'] ?? 0.0;
        $a = [(float) ($by['0'] ?? 0), (float) ($by['1'] ?? 0), (float) ($by['2'] ?? 0), (float) (($by['3'] ?? 0) + ($by['4'] ?? 0))];

        // Items that will run out within two weeks at the current pace.
        $under = $e->inventoryRunningOut($this->cfg('inv', ['dleft'], 'item', ['compare' => 'off']), 14);

        $days = (int) (data_get($this->tenant->settings, 'inventory.dead_stock_days') ?: 180);
        $pctOld = $cost > 0 ? number_format($a[3] / $cost * 100, 1) . '% of stock' : 'nothing on hand';
        $colors = ['var(--ia-accent)', 'var(--rb-info)', 'var(--rb-warn)', 'var(--rb-bad)'];
        $names = ['0–30 days', '31–90', '91–180', 'Over 180'];
        $segs = [];
        foreach ($a as $i => $v) $segs[] = ['color' => $colors[$i], 'flex' => max(0.0, $v), 'label' => $names[$i] . ' · ' . $this->fmt($v, '$')];

        $co = $this->cfg('inv', ['cost', 'dead', 'a2', 'a3', 'a4'], 'cat', ['compare' => 'off']);
        return $this->panel('r-inv', 'Inventory health', 'what you hold now, so it doesn\'t follow the range', $co, [
            'figs' => [
                $this->fig('Stock at cost', $this->fmt($cost, '$'), 'on hand today'),
                $this->fig('Dead stock', $this->fmt($dead, '$'), 'not sold in ' . $days . ' days', $dead > 0 ? 'warn' : ''),
                $this->fig('Aged over 180 days', $this->fmt($a[3], '$'), $pctOld, $a[3] > 0 ? 'warn' : ''),
                $this->fig('Under 14 days left', number_format($under), 'items at the current pace', $under > 0 ? 'bad' : ''),
            ],
            'bar' => $cost > 0 ? $segs : null,
        ], 'Aged 0–30 days is the rest of the stock at cost', true);
    }

    // ---------------------------------------------------------------- Money

    public function money(): array
    {
        $e = $this->e;
        $pay = new PaymentsReceived($this->tenant);
        $out = [];

        $c = $this->cfg('sales', ['rev', 'tax', 'refunds'], 'month');
        $t = $e->totals($c);
        [$a, $b] = $e->periods()->rangeFor($c);
        $split = $pay->split($a, $b);
        $extras = $pay->extras($a, $b);
        $prevPay = null;
        if ($t['prev'] !== null) {
            $prevPay = $pay->split(Carbon::parse($t['pfrom']), Carbon::parse($t['pto']))['total'] / 100;
        }
        $received = $split['total'] / 100;
        $rev = (float) ($t['vals']['rev'] ?? 0);
        $tax = (float) ($t['vals']['tax'] ?? 0);
        $tips = $extras['tips'] / 100;
        $sur = $extras['surcharge'] / 100;
        $rest = round($received - $rev - $tax - $tips - $sur, 2);
        $refunds = $t['vals']['refunds'] ?? null;

        $recon = [
            ['Sales after refunds', 'the same figure as Retail and Custom › Sales', $this->fmt($rev, '$')],
            ['+ Tax collected', 'sales tax is paid with the sale but isn\'t revenue', $this->fmt($tax, '$')],
        ];
        if (abs($tips) >= 0.005) $recon[] = ['+ Tips', 'paid with the sale, never revenue', $this->fmt($tips, '$')];
        if (abs($sur) >= 0.005) $recon[] = ['+ Surcharges', 'card or other surcharges added to the sale', $this->fmt($sur, '$')];
        $recon[] = ['+ Everything else', 'worked out as the difference: payments recorded in this range for sales dated on other days (balances, deposits), and anything that doesn\'t match a sale line', $this->fmt($rest, '$')];

        $out[] = $this->panel('m-recon', 'Money in', 'payments received in the range, and how they add up to sales', $c, [
            'figs' => [
                $this->fig('Payments received', $this->fmt($received, '$'), null, '', self::pct($received, $prevPay)),
                $this->figCmp('Sales after refunds', $t, 'rev', '$'),
                $this->figCmp('Tax collected', $t, 'tax', '$'),
                $this->fig('Refunds', $this->fmt($refunds, '$'), $refunds ? 'already taken off sales' : 'none in this range', $refunds ? 'warn' : ''),
            ],
            'bar' => $split['total'] > 0 ? [
                ['color' => 'var(--ia-accent)', 'flex' => max(0, $split['retail']), 'label' => 'Retail · ' . $this->fmt($split['retail'] / 100, '$')],
                ['color' => 'var(--rb-info)', 'flex' => max(0, $split['service']), 'label' => 'Service · ' . $this->fmt($split['service'] / 100, '$')],
                ['color' => 'var(--rb-warn)', 'flex' => max(0, $split['other']), 'label' => 'Not tied to a sale line · ' . $this->fmt($split['other'] / 100, '$')],
            ] : null,
            'recon' => ['rows' => $recon, 'total' => $this->fmt($received, '$')],
        ], 'Payments received comes from the payments ledger and is on this tab only');

        // Refunds (the list is never read for a locked shop)
        $cr = $this->cfg('sales', ['refunds', 'rev'], 'cat');
        $rows = []; $count = 0;
        if (!$this->locked) {
            $list = $pay->refundList($a, $b, 8);
            $count = $list['count'];
            foreach ($list['rows'] as $r) {
                $rows[] = [e(Carbon::parse($r['date'])->format('M j')), e($r['number']), e($r['items']), e($r['by'] ?: '—'), $this->fmt(-abs($r['cents']) / 100, '$')];
            }
        }
        $after = $this->locked || $count <= count($rows) ? '' : count($rows) . ' of ' . $count . ' refunds shown, newest first. Open it in the builder to see them split by category. ';
        $out[] = $this->panel('m-ref', 'Refunds', 'sale refunds in the range', $cr, [
            'table' => $this->table(['Date', 'Ticket', 'Items', 'By', 'Amount'], $rows, null, 'refunds', 'No refunds in this range.'),
            'after' => $after . 'Refunds on appointments are tracked by payment status only, so they aren\'t listed here.',
        ], 'Refunds are listed here; the builder splits them by category');

        // Tax by location
        $ct = $this->cfg('sales', ['taxable', 'tax', 'rev'], 'loc');
        $cmp = $this->q['compare'] !== 'off';
        $rows = []; $total = null;
        if (!$this->locked) {
            $r = $e->rows($ct);
            foreach ($r['rows'] as $row) {
                $cells = [e($row['l']), $this->fmt($e->cellValue($ct, 'taxable', $row['vals']), '$'), $this->fmt($e->cellValue($ct, 'tax', $row['vals']), '$')];
                if ($cmp) $cells[] = $this->delta($e->cellValue($ct, 'tax', $row['vals']), $e->cellValue($ct, 'tax', $row['prev']));
                $rows[] = $cells;
            }
            $total = ['Total', $this->fmt($r['totals']['sales:taxable'] ?? null, '$'), $this->fmt($r['totals']['sales:tax'] ?? null, '$')];
            if ($cmp) $total[] = '';
        }
        $out[] = $this->panel('m-tax', 'Tax collected', 'by location', $ct, [
            'table' => $this->table(['Location', 'Taxable sales', 'Tax collected'] + ($cmp ? [3 => 'vs before'] : []), $rows, $total, 'tax figures', 'No sales in this range.'),
            'after' => 'Drawer counts and card payouts aren\'t tracked here. The end-of-day cash count lives in <a href="' . e(route('tenant.reports.daily')) . '">Daily ops</a>.',
            'afterHtml' => true,
        ]);

        $legend = [
            ['Cash view:', 'this tab counts payments received in the range. Retail and Custom count sales after refunds. The reconciliation above shows the difference, so the two never look like a mistake.'],
            ['Tax:', 'is collected on each sale and is never part of revenue anywhere in Reports.'],
            ['Everything else:', 'is the one calculated line. It is the payments received minus the lines above it, so it always adds up, and it will be large when a lot of balances were paid for sales from other days.'],
        ];
        return ['panels' => $out, 'legend' => $legend];
    }

    // ---------------------------------------------------------------- shared pieces for Staff and Services

    /** A plain list for one engine breakdown. $cols: [header, measure, type]; the first column is the row name. */
    private function listPanel(string $id, string $title, string $hint, array $cfg, string $nameHead, array $cols, string $what, string $empty, ?string $after = null, ?string $openExtra = null): array
    {
        $e = $this->e;
        $rows = []; $total = null;
        if (!$this->locked) {
            $r = $e->rows($cfg);
            foreach ($r['rows'] as $row) {
                $cells = [e($row['l'])];
                foreach ($cols as [, $m, $t]) $cells[] = $this->fmt($e->cellValue($cfg, $m, $row['vals']), $t);
                $rows[] = $cells;
            }
            if (!$cfg['limit'] || count($r['rows']) < $cfg['limit']) {
                $total = ['Total'];
                foreach ($cols as [, $m, $t]) $total[] = $this->fmt($e->cellValue($cfg, $m, $r['totals']), $t);
            }
        }
        return $this->panel($id, $title, $hint, $cfg, [
            'table' => $this->table(array_merge([$nameHead], array_column($cols, 0)), $rows, $total, $what, $empty),
            'after' => $after,
        ], $openExtra);
    }

    /** Day, week or month buckets, whichever keeps a chart readable for the length of the range. */
    private function bucketFor(array $cfg): string
    {
        [$a, $b] = $this->e->periods()->rangeFor($cfg);
        $days = $this->e->periods()->days($a, $b);
        return $days <= 62 ? 'day' : ($days <= 400 ? 'week' : 'month');
    }

    /** A small bar chart as inline SVG. Bars are aggregates by period; a locked shop gets a fixed pattern instead of real values. */
    private function bars(array $labels, array $values, bool $fake = false, string $aria = 'Appointments finished per period'): string
    {
        $n = count($values);
        if ($n === 0) return '';
        $mx = max(1.0, (float) max($values));
        $w = 560; $h = 110; $step = $w / $n; $bw = max(2, min(26, $step * 0.68));
        $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" style="width:100%;max-width:640px;height:110px;display:block" role="img" aria-label="' . e($aria) . '">';
        foreach ($values as $i => $v) {
            $bh = (int) round($v / $mx * 88);
            $x = round($i * $step + ($step - $bw) / 2, 1);
            $svg .= '<rect x="' . $x . '" y="' . (96 - $bh) . '" width="' . round($bw, 1) . '" height="' . $bh . '" rx="2" fill="var(--ia-accent)" opacity=".9">'
                . ($fake ? '' : '<title>' . e($labels[$i]) . ': ' . number_format($v) . '</title>') . '</rect>';
        }
        $svg .= '<line x1="0" y1="96.5" x2="' . $w . '" y2="96.5" stroke="var(--ia-border)"/></svg>';
        if (!$fake) {
            $svg .= '<div class="rb-hint" style="display:flex;justify-content:space-between;max-width:640px"><span>' . e($labels[0]) . '</span><span>' . e($labels[$n - 1]) . '</span></div>';
        }
        return $svg;
    }

    /** Appointments by weekday and start hour, as a grid the page colours in. Placeholder pattern when locked. */
    private function densityGrid(array $c): array
    {
        $e = $this->e;
        $grid = null;
        if ($this->locked) {
            $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
            $cols = ['8a', '9a', '10a', '11a', '12p', '1p', '2p', '3p', '4p', '5p'];
            $rows = [];
            foreach ($days as $i => $d) {
                $v = [];
                foreach ($cols as $j => $_) $v[] = (($i * 5 + $j * 3) % 7);
                $rows[] = [$d, $v];
            }
            $grid = ['cols' => $cols, 'rows' => $rows, 'max' => 6, 'locked' => true];
        } else {
            $pv = $e->pivot($c);
            $byDow = [];
            foreach ($pv['rows'] as $row) {
                foreach ($pv['colKeys'] as $ci => $hk) $byDow[(int) $row['k']][(int) $hk] = (float) ($row['cells']['appts:ap'][$ci] ?? 0);
            }
            $hours = [];
            foreach ($byDow as $hs) foreach ($hs as $hk => $v) if ($hk >= 0 && $v > 0) $hours[] = $hk;
            $lo = $hours ? min(8, min($hours)) : 8; $hi = $hours ? max(17, max($hours)) : 17;
            $cols = []; $keys = [];
            for ($h = $lo; $h <= $hi; $h++) { $keys[] = $h; $cols[] = ($h % 12 === 0 ? 12 : $h % 12) . ($h < 12 ? 'a' : 'p'); }
            $hasNone = false;
            foreach ($byDow as $hs) if (($hs[-1] ?? 0) > 0) $hasNone = true;
            if ($hasNone) { $keys[] = -1; $cols[] = 'No time'; }
            $rows = []; $mx = 0.0;
            foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $i => $d) {
                $v = [];
                foreach ($keys as $k) { $x = (float) ($byDow[$i + 1][$k] ?? 0); $v[] = $x; $mx = max($mx, $x); }
                $rows[] = [$d, $v];
            }
            $grid = ['cols' => $cols, 'rows' => $rows, 'max' => max(1.0, $mx), 'locked' => false, 'empty' => $mx <= 0];
        }
        return $grid;
    }

    // ---------------------------------------------------------------- Staff

    public function staff(): array
    {
        $e = $this->e;
        $out = [];

        // Sales and hours: owners and managers only (hours are pay-adjacent)
        if ($this->isManager) {
            $calc = [['id' => 'calc:t1', 'name' => 'Sales per labor hour', 'f' => '[Revenue] / [Hours worked]', 't' => '$']];
            $c = $this->cfg('sales', ['staff:hrs', 'staff:ot', 'tickets', 'rev', 'calc:t1'], 'staff', ['calcs' => $calc]);
            $out[] = $this->listPanel('s-hours', 'Sales and hours', 'what each person sold against the hours they worked', $c, 'Staff', [
                ['Hours', 'staff:hrs', 'd'], ['Overtime', 'staff:ot', 'd'], ['Tickets', 'sales:tickets', 'n'], ['Revenue', 'sales:rev', '$'], ['Sales / hour', 'calc:t1', '$'],
            ], 'staff figures', 'No sales or hours in this range.', 'Hours come from the time clock, after any edits made on a timesheet. Overtime is the part of those hours that counted as overtime.');
        } else {
            $out[] = $this->panel('s-hours', 'Sales and hours', 'what each person sold against the hours they worked', null,
                ['note' => 'Sales and hours are shown to owners and managers.']);
        }

        // Booking density: a grid of appointments by weekday and start hour
        $c = $this->cfg('appts', ['ap'], 'dow', ['dim2' => 'hod', 'compare' => 'off']);
        $grid = $this->densityGrid($c);
        $out[] = $this->panel('s-dens', 'Booking density', 'appointments by day of the week and the hour they start', $c, ['grid' => $grid,
            'after' => 'Counts every appointment in the range by the hour it is booked to start, in the shop\'s time zone, whatever its status.']);

        // Appointments by staff or resource
        $c = $this->cfg('appts', ['ap', 'done', 'cancp', 'booked'], 'res');
        $out[] = $this->listPanel('s-appt', 'Appointments by staff', 'who the work was booked with', $c, 'Staff or resource', [
            ['Appointments', 'ap', 'n'], ['Completed', 'done', 'n'], ['Cancellation rate', 'cancp', '%'], ['Booked value', 'booked', '$'],
        ], 'appointments', 'No appointments in this range.', 'Appointments with nobody booked are shown as Unassigned, not dropped, so the totals match the Services tab. Tips by staff and estimate accuracy aren\'t reported yet.');

        $legend = [
            ['Who sees it:', 'hours, overtime and sales per hour are for owners and managers. Everyone else sees the appointment figures only.'],
            ['Same numbers:', 'sales are the same figures as Retail and Custom. Hours come from the time clock, after any edits made on a timesheet.'],
            ['Completed:', 'means completed, shipped or closed. Work still in progress is booked but not counted as completed yet.'],
            ['Not assigned:', 'appointments with nobody booked are shown, not dropped, so the totals match the Services tab.'],
        ];
        return ['panels' => $out, 'legend' => $legend];
    }

    // ---------------------------------------------------------------- Services

    public function services(): array
    {
        $e = $this->e;
        $out = [];

        // Throughput
        $c = $this->cfg('appts', ['ap', 'done', 'cancp', 'booked', 'aval'], $this->bucketFor($this->cfg('appts', ['ap'], 'day')));
        $t = $e->totals($c);
        $chart = null;
        if ($this->locked) {
            $v = []; $l = [];
            for ($i = 0; $i < 30; $i++) { $v[] = 4 + (($i * 7) % 11); $l[] = ''; }
            $chart = ['svg' => $this->bars($l, $v, true), 'locked' => true];
        } else {
            $cc = $this->cfg('appts', ['done'], $c['dim']);
            $r = $e->rows($cc);
            $labels = array_column($r['rows'], 'l');
            $vals = array_map(fn ($row) => (float) ($e->cellValue($cc, 'done', $row['vals']) ?? 0), $r['rows']);
            $chart = ['svg' => $this->bars($labels, $vals), 'locked' => false, 'per' => ['day' => 'day', 'week' => 'week', 'month' => 'month'][$c['dim']]];
        }
        $out[] = $this->panel('v-thru', 'Throughput', 'appointments finished each ' . ($c['dim'] === 'day' ? 'day' : $c['dim']), $c, ['figs' => [
            $this->figCmp('Appointments', $t, 'ap', 'n'),
            $this->figCmp('Completed', $t, 'done', 'n'),
            $this->figCmp('Cancellation rate', $t, 'cancp', '%', null, '', true),
            $this->figCmp('Booked value', $t, 'booked', '$'),
            $this->figCmp('Average value', $t, 'aval', '$'),
        ], 'chart' => $chart]);

        // Service mix
        $c = $this->cfg('appts', ['ap', 'booked', 'aval'], 'svc', ['limit' => 10, 'compare' => 'off']);
        $tot = $e->totals($c)['vals']['booked'] ?? 0.0;
        $rows = [];
        if (!$this->locked) {
            $r = $e->rows($c);
            foreach ($r['rows'] as $row) {
                $b = $e->cellValue($c, 'booked', $row['vals']);
                $rows[] = [e($row['l']), $this->fmt($e->cellValue($c, 'ap', $row['vals']), 'n'), self::share($tot > 0 && $b !== null ? $b / $tot * 100 : 0),
                    $this->fmt($b, '$'), $this->fmt($e->cellValue($c, 'aval', $row['vals']), '$')];
            }
        }
        $out[] = $this->panel('v-mix', 'Service mix', 'what you do most, and what it earns', $c, [
            'table' => $this->table(['Service', 'Appointments', 'Share of value', 'Booked value', 'Average value'], $rows, null, 'service mix', 'No appointments in this range.'),
            'after' => 'Top 10 by appointments. An appointment with several services counts once under each, so the rows can add up to more than the total.',
        ], 'Top 10, most appointments first');

        // Parts attach
        $c = $this->cfg('appts', ['partsp', 'partsn', 'parts', 'booked'], 'day');
        $t = $e->totals($c);
        $cp = $this->cfg('appts', ['ap', 'partsn', 'partsp', 'parts'], 'svc', ['limit' => 10, 'compare' => 'off']);
        $pan = $this->listPanel('v-parts', 'Parts attach', 'how often parts are added to the work, and what they earn', $cp, 'Service', [
            ['Appointments', 'ap', 'n'], ['With parts', 'partsn', 'n'], ['Attach rate', 'partsp', '%'], ['Parts revenue', 'parts', '$'],
        ], 'parts figures', 'No appointments in this range.', 'Booked value is the service lines only. Parts revenue is the parts added to those appointments, at the price charged.', 'Top 10, most appointments first');
        $pan['figs'] = [
            $this->figCmp('Attach rate', $t, 'partsp', '%'),
            $this->figCmp('Appointments with parts', $t, 'partsn', 'n'),
            $this->figCmp('Parts revenue', $t, 'parts', '$'),
            $this->figCmp('Service value', $t, 'booked', '$', 'before parts'),
        ];
        $out[] = $pan;

        // Comebacks
        $c = $this->cfg('appts', ['done', 'back', 'backp'], 'day');
        $t = $e->totals($c);
        $cb = $this->cfg('appts', ['back', 'done', 'backp'], 'svc', ['limit' => 10, 'compare' => 'off']);
        $pan = $this->listPanel('v-back', 'Comebacks', 'the same customer back within 30 days of finished work', $cb, 'Service', [
            ['Came back', 'back', 'n'], ['Completed', 'done', 'n'], ['Comeback rate', 'backp', '%'],
        ], 'comebacks', 'No finished appointments in this range.', 'A comeback is a completed appointment followed by another booked appointment for the same customer within 30 days. It may be for a different job, so read it as a prompt to look, not as a fault.', 'Top 10, most comebacks first');
        $pan['figs'] = [
            $this->figCmp('Completed', $t, 'done', 'n'),
            $this->figCmp('Came back within 30 days', $t, 'back', 'n', null, '', true),
            $this->figCmp('Comeback rate', $t, 'backp', '%', null, '', true),
        ];
        $out[] = $pan;

        // Production by bay, chair or person
        $c = $this->cfg('appts', ['ap', 'done', 'booked', 'hours'], 'res');
        $out[] = $this->listPanel('v-res', 'Production by bay or chair', 'what each station or person produced', $c, 'Staff or resource', [
            ['Appointments', 'ap', 'n'], ['Completed', 'done', 'n'], ['Booked value', 'booked', '$'], ['Booked hours', 'hours', 'd'],
        ], 'production', 'No appointments in this range.', 'Booked hours are the time booked on each appointment. Utilization against your opening hours is on the Operations tab.');

        $legend = [
            ['Completed:', 'means completed, shipped or closed. Work still in progress counts as booked, and is counted as completed once it is finished. This is the same list on the Staff tab and in Custom › Appointments.'],
            ['Booked value:', 'is the service lines on the appointment before tax, leaving out cancelled and refunded work. Parts are shown separately.'],
            ['Booked hours:', 'are added up from the duration on each appointment. Utilization against your opening hours is on the Operations tab.'],
            ['Not tracked:', 'Intake has no no-show status, so none is shown here. The Operations tab lists past appointments still pending or confirmed as possible no-shows. Estimate accuracy isn\'t recorded.'],
        ];
        return ['panels' => $out, 'legend' => $legend];
    }

    // ---------------------------------------------------------------- Operations

    /** A copy of the question limited to days that are over (yesterday at the latest), for the no-show estimate. Null when none of the range is over. */
    private function pastCfg(array $measures, string $dim, array $statuses): ?array
    {
        $base = $this->cfg('appts', ['ap'], 'day');
        [$a, $b] = $this->e->periods()->rangeFor($base);
        $yesterday = $this->tenant->localToday()->copy()->subDay();
        $end = $b->lt($yesterday) ? $b : $yesterday;
        if ($a->gt($end)) return null;
        $filters = [];
        foreach ($statuses as $s) $filters[] = ['f' => 'stat', 'op' => 'is', 'v' => $s];
        return $this->cfg('appts', $measures, $dim, ['range' => 'Custom', 'from' => $a->toDateString(), 'to' => $end->toDateString(), 'compare' => 'off', 'filters' => $filters]);
    }

    public function operations(): array
    {
        $e = $this->e;
        $out = [];
        $cmp = $this->q['compare'] !== 'off';
        $settings = (array) ($this->tenant->settings ?? []);
        $targets = (array) ($settings['report_targets'] ?? []);
        $spot = $settings['report_spotlight_category'] ?? null;

        // Sales
        $c = $this->cfg('sales', ['rev', 'tickets', 'avg', 'refunds', 'marginp'], 'day');
        $t = $e->totals($c);
        $refunds = $t['vals']['refunds'] ?? null;
        $bucket = $this->bucketFor($c);
        $cc = $this->cfg('sales', ['rev'], $bucket);
        $r = $e->rows($cc);
        $chart = ['svg' => $this->bars(array_column($r['rows'], 'l'), array_map(fn ($row) => (float) ($e->cellValue($cc, 'rev', $row['vals']) ?? 0), $r['rows']), false, 'Revenue per ' . $bucket), 'locked' => false];
        $out[] = $this->panel('o-sales', 'Sales', 'paid sales, refunds taken off, before tax and tips', $c, ['figs' => [
            $this->figCmp('Revenue', $t, 'rev', '$'),
            $this->figCmp('Tickets', $t, 'tickets', 'n'),
            $this->figCmp('Average ticket', $t, 'avg', '$'),
            $this->fig('Refunds', $this->fmt($refunds, '$'), $refunds ? 'already taken off revenue' : 'none in this range', $refunds ? 'warn' : ''),
            $this->figCmp('Margin %', $t, 'marginp', '%'),
        ], 'chart' => $chart]);
        $revTotal = (float) ($t['vals']['rev'] ?? 0);
        $avgTicket = $t['vals']['avg'] ?? null;
        $marginPct = $t['vals']['marginp'] ?? null;

        // Sales by category, labor and product
        $c = $this->cfg('sales', ['rev', 'margin', 'marginp'], 'cat');
        $r = $e->rows($c);
        $rows = []; $catNames = []; $labor = 0.0; $product = 0.0; $productCat = 0.0;
        foreach ($r['rows'] as $row) {
            $rev = $e->cellValue($c, 'rev', $row['vals']);
            $k = $row['k'];
            if ($k === 'svc') $labor += (float) $rev;
            elseif ($k !== 'other') { $product += (float) $rev; if ($k !== 'uncat') { $productCat += (float) $rev; $catNames[] = $row['l']; } }
            $name = e($row['l']) . ($spot !== null && $row['l'] === $spot ? ' ' . self::pill('Spotlight', 'info') : '');
            $cells = [$name, $this->fmt($rev, '$'), self::share($revTotal > 0 && $rev !== null ? $rev / $revTotal * 100 : 0), $this->fmt($e->cellValue($c, 'marginp', $row['vals']), '%')];
            if ($cmp) $cells[] = $this->delta($rev, $e->cellValue($c, 'rev', $row['prev']));
            $rows[] = $cells;
        }
        $total = ['Total', $this->fmt($r['totals']['sales:rev'] ?? null, '$'), '', $this->fmt($r['totals']['sales:marginp'] ?? null, '%')];
        if ($cmp) $total[] = '';
        $uncat = max(0.0, $product - $productCat);
        $coverage = $product > 0 ? $productCat / $product * 100 : 100.0;
        $after = round($coverage) . '% of product sales landed in a real category.';
        $afterHtml = false;
        if ($uncat > 0.004) {
            $after = e($after) . ' ' . e($this->fmt($uncat, '$')) . ' is uncategorised: <a class="rb-link" href="' . e(route('tenant.inventory.uncategorized')) . '">map those items</a> and these numbers get sharper.';
            $afterHtml = true;
        }
        $out[] = $this->panel('o-cat', 'Sales by category', 'where the revenue comes from', $c, [
            'table' => $this->table(['Category', 'Revenue', 'Share', 'Margin %'] + ($cmp ? [4 => 'Revenue vs before'] : []), $rows, $total, 'category sales', 'No sales in this range.'),
            'after' => $after, 'afterHtml' => $afterHtml,
        ]);

        // Labor and parts
        $c = $this->cfg('appts', ['partsp', 'partsn', 'parts'], 'day');
        $ta = $e->totals($c);
        $laborPct = $revTotal > 0 ? $labor / $revTotal * 100 : null;
        $out[] = $this->panel('o-labor', 'Labor and parts', 'service work against product sales, and how often parts ride along', $c, ['figs' => [
            $this->fig('Labor sold', $this->fmt($labor, '$'), $laborPct === null ? 'no sales in this range' : round($laborPct, 1) . '% of revenue'),
            $this->fig('Product sales', $this->fmt($product, '$'), 'excludes labor and other lines'),
            $this->figCmp('Parts attach rate', $ta, 'partsp', '%', 'of appointments'),
            $this->figCmp('Appointments with parts', $ta, 'partsn', 'n'),
            $this->figCmp('Parts revenue', $ta, 'parts', '$'),
        ]]);
        $attach = $ta['vals']['partsp'] ?? null;

        // Targets
        $acts = [
            ['avg_ticket', 'Average ticket', $avgTicket === null ? '—' : $this->fmt($avgTicket, '$'), $avgTicket],
            ['labor_pct', 'Labor share', $laborPct === null ? '—' : round($laborPct, 1) . '%', $laborPct],
            ['attach_pct', 'Attachment rate', $attach === null ? '—' : round($attach, 1) . '%', $attach],
            ['margin_pct', 'Margin', $marginPct === null ? '—' : round($marginPct, 1) . '%', $marginPct],
        ];
        $tr = [];
        foreach ($acts as [$key, $label, $text, $num]) {
            $tv = $targets[$key] ?? null;
            $status = ($tv === null || $num === null) ? null : ($num >= $tv ? 'on' : 'under');
            $tr[] = ['key' => $key, 'label' => $label, 'actual' => $text, 'target' => $tv, 'status' => $status];
        }
        $opts = array_values(array_unique($catNames));
        $opts = array_map(fn ($o) => ['v' => $o, 'l' => $o], $opts);
        if ($spot !== null && !in_array($spot, $catNames, true)) $opts[] = ['v' => $spot, 'l' => $spot . ' (no sales in this range)'];
        $out[] = $this->panel('o-targets', 'Targets', 'what good looks like for your shop', null, ['targets' => [
            'rows' => $tr, 'spot' => $spot, 'options' => $opts, 'action' => route('tenant.reports.targets'),
        ]]);

        // Bookings and cancellations
        $c = $this->cfg('appts', ['ap', 'done', 'canc', 'cancp'], 'day');
        $t = $e->totals($c);
        $ns = null;
        $nsCfg = $this->pastCfg(['ap'], 'day', ['pending', 'confirmed']);
        if ($nsCfg) $ns = $e->totals($nsCfg)['vals']['ap'] ?? 0.0;
        [$a, $b] = $e->periods()->rangeFor($c);
        $figs = [
            $this->figCmp('Appointments', $t, 'ap', 'n'),
            $this->figCmp('Completed', $t, 'done', 'n'),
            $this->figCmp('Cancelled', $t, 'canc', 'n', null, '', true),
            $this->figCmp('Cancellation rate', $t, 'cancp', '%', null, '', true),
            $this->fig('Possible no-shows', $ns === null ? '—' : $this->fmt($ns, 'n'), $ns === null ? 'none of this range is over yet' : 'past, still pending or confirmed', $ns ? 'warn' : ''),
        ];
        $bk = $this->bucketFor($c);
        $chart = null;
        if ($e->periods()->days($a, $b) > 1) {
            $cb = $this->cfg('appts', ['ap'], $bk);
            $rb = $e->rows($cb);
            $chart = ['svg' => $this->bars(array_column($rb['rows'], 'l'), array_map(fn ($row) => (float) ($e->cellValue($cb, 'ap', $row['vals']) ?? 0), $rb['rows']), false, 'Appointments booked per ' . $bk), 'locked' => false];
        }
        $out[] = $this->panel('o-book', 'Bookings and cancellations', 'appointments by the day they are booked for', $c, ['figs' => $figs, 'chart' => $chart,
            'after' => 'Intake has no no-show status. A possible no-show is a past appointment still marked pending or confirmed: it may be a no-show, or it may just not have been closed out.']);

        // Customers
        $c = $this->cfg('customers', ['custs', 'newc', 'active', 'visits', 'lapsed'], 'day');
        $t = $e->totals($c);
        $pan = $this->listPanel('o-cust', 'Customers', 'who is buying, and who spent the most', $this->cfg('customers', ['spend', 'visits', 'ltvt'], 'cust', ['limit' => 8, 'compare' => 'off']),
            'Customer', [['Spend', 'spend', '$'], ['Visits', 'visits', 'd'], ['Lifetime value', 'ltvt', '$']], 'top customers', 'No customer sales in this range.',
            'Spend is paid sales net of refunds, before tax and tips. The full lists (lapsed, missing a phone, highest lifetime value) are on the Customers tab.', 'Top 8 by spend in this range');
        $pan['figs'] = [
            $this->fig('Customers', $this->fmt($t['vals']['custs'] ?? null, 'n'), 'all customers on file'),
            $this->figCmp('New customers', $t, 'newc', 'n'),
            $this->figCmp('Customers who bought', $t, 'active', 'n'),
            $this->figCmp('Visits per customer', $t, 'visits', 'd'),
            $this->fig('Lapsed', $this->fmt($t['vals']['lapsed'] ?? null, 'n'), 'no purchase in 180 days'),
        ];
        $out[] = $pan;

        // Services
        $c = $this->cfg('appts', ['ap', 'booked', 'paid'], 'svc', ['limit' => 10, 'compare' => 'off']);
        $out[] = $this->listPanel('o-svc', 'Service popularity', 'what you do most, and what it earns', $c, 'Service', [
            ['Appointments', 'ap', 'n'], ['Booked value', 'booked', '$'], ['Paid so far', 'paid', '$'],
        ], 'service popularity', 'No appointments in this range.', 'An appointment with several services counts once under each. Paid so far is the part of each appointment already paid, split across its services.', 'Top 10, most appointments first');

        // Staff utilization
        $c = $this->cfg('appts', ['hours', 'ap', 'done'], 'res', ['compare' => 'off']);
        $r = $e->rows($c);
        [$a, $b] = $e->periods()->rangeFor($c);
        $open = (new ShopHours($this->tenant))->minutes($a, $b);
        $nsRes = []; $allRes = [];
        $n1 = $this->pastCfg(['ap'], 'res', ['pending', 'confirmed']);
        $n2 = $this->pastCfg(['ap'], 'res', []);
        if ($n1 && $n2) {
            foreach ($e->rows($n1)['rows'] as $row) $nsRes[$row['k']] = (float) ($e->cellValue($n1, 'ap', $row['vals']) ?? 0);
            foreach ($e->rows($n2)['rows'] as $row) $allRes[$row['k']] = (float) ($e->cellValue($n2, 'ap', $row['vals']) ?? 0);
        }
        $rows = [];
        foreach ($r['rows'] as $row) {
            $hrs = (float) ($e->cellValue($c, 'hours', $row['vals']) ?? 0);
            if ($row['k'] === '' || $open <= 0) {
                $util = '—';
            } else {
                $u = (int) min(100, round($hrs * 60 / $open * 100));
                $util = self::share($u) . ' ' . ($u > 85 ? self::pill('Overloaded', 'bad') : ($u >= 50 ? self::pill('Healthy', 'good') : self::pill('Underused', 'warn')));
            }
            $tot = $allRes[$row['k']] ?? 0.0;
            $rows[] = [e($row['l']), $this->fmt($hrs, 'd'), $util, $this->fmt($e->cellValue($c, 'ap', $row['vals']), 'n'), $tot > 0 ? round(($nsRes[$row['k']] ?? 0) / $tot * 100) . '%' : '—'];
        }
        $out[] = $this->panel('o-staff', 'Staff utilization', 'booked hours against the hours the shop was open', $c, [
            'table' => $this->table(['Staff or resource', 'Booked hours', 'Utilization', 'Appointments', 'Possible no-shows'], $rows, null, 'utilization', 'No appointments in this range.'),
            'after' => 'The shop was open ' . number_format($open / 60, 1) . ' hours in this range, from your opening hours. Every person is measured against the same shop hours. Overloaded is over 85%, healthy is 50 to 85%, underused is under 50%.',
        ]);

        // Capacity heatmap
        $c = $this->cfg('appts', ['ap'], 'dow', ['dim2' => 'hod', 'compare' => 'off']);
        $out[] = $this->panel('o-heat', 'Capacity heatmap', 'appointments by day of the week and the hour they start, darker is busier', $c, ['grid' => $this->densityGrid($c),
            'after' => 'Counts every appointment in the range by the hour it starts, whatever its status. Pick a longer range for a clearer picture: a few days will look patchy.']);

        $legend = [
            ['Sales basis:', 'revenue here is sales dated in the range, refunds taken off, before tax and tips. The old Operations revenue chart counted payments by the day money arrived, so the two can differ. The Money tab still shows what was received.'],
            ['Categories:', 'are the category each item is filed under. Services and Other are shown on their own, and labor is the Services line.'],
            ['Margin:', 'lines with no cost recorded are left out of the margin %, not counted as zero, so it is no longer understated by uncosted lines.'],
            ['Attachment rate:', 'is now the share of appointments that had parts added, the same figure as on the Services tab. It used to be the share of parts sold on a service ticket, so check any attachment target you set.'],
            ['Possible no-shows:', 'Intake has no no-show status. This counts past appointments still marked pending or confirmed, which may be a no-show or may just not have been closed.'],
            ['Targets:', 'a blank target stays blank and shows no status. Spotlight marks one category in the table above.'],
        ];
        return ['panels' => $out, 'legend' => $legend];
    }

    // ---------------------------------------------------------------- Customers

    /**
     * A list of customers. Each row's name carries the contact details, read for just the customers shown
     * (and not at all for a shop without extended reports).
     */
    private function customerList(string $id, string $title, string $hint, array $cfg, array $cols, string $what, string $empty, ?string $after, ?string $openExtra = null): array
    {
        $e = $this->e;
        $rows = []; $total = null;
        if (!$this->locked) {
            $r = $e->rows($cfg);
            $contact = [];
            $ids = array_column($r['rows'], 'k');
            if ($ids) {
                foreach (\Illuminate\Support\Facades\DB::table('tenant_customers')->where('tenant_id', $this->tenant->id)->whereIn('id', $ids)->get(['id', 'email', 'phone']) as $c) {
                    $contact[(string) $c->id] = trim(implode(' · ', array_filter([(string) $c->email, (string) $c->phone])));
                }
            }
            foreach ($r['rows'] as $row) {
                $meta = $contact[$row['k']] ?? '';
                $cells = [e($row['l']) . ($meta !== '' ? '<span class="rb-hint" style="display:block">' . e($meta) . '</span>' : '')];
                foreach ($cols as [, $m, $t]) $cells[] = $this->fmt($e->cellValue($cfg, $m, $row['vals']), $t);
                $rows[] = $cells;
            }
        }
        return $this->panel($id, $title, $hint, $cfg, [
            'table' => $this->table(array_merge(['Customer'], array_column($cols, 0)), $rows, $total, $what, $empty),
            'after' => $after,
        ], $openExtra);
    }

    public function customers(): array
    {
        $e = $this->e;
        $out = [];

        $c = $this->cfg('customers', ['custs', 'newc', 'active', 'ltv', 'lapsed'], 'day');
        $t = $e->totals($c);
        $out[] = $this->panel('c-over', 'Customers', 'who you have, who is new, and who is buying', $c, ['figs' => [
            $this->fig('Customers', $this->fmt($t['vals']['custs'] ?? null, 'n'), 'all customers on file'),
            $this->figCmp('New customers', $t, 'newc', 'n'),
            $this->figCmp('Customers who bought', $t, 'active', 'n'),
            $this->fig('Average lifetime value', $this->fmt($t['vals']['ltv'] ?? null, '$'), 'all time'),
            $this->fig('Lapsed', $this->fmt($t['vals']['lapsed'] ?? null, 'n'), 'no purchase in 180 days', ($t['vals']['lapsed'] ?? 0) > 0 ? 'warn' : ''),
        ]]);

        $c = $this->cfg('customers', ['ltvt', 'spend', 'visits'], 'cust', ['limit' => 50, 'compare' => 'off']);
        $out[] = $this->customerList('c-ltv', 'Highest lifetime value', 'your most valuable customers', $c, [
            ['Lifetime value', 'ltvt', '$'], ['Spend in range', 'spend', '$'], ['Visits in range', 'visits', 'd'],
        ], 'top customers', 'No customer sales yet. Once sales are tied to customers, the leaderboard fills in.',
            'Top 50. Lifetime value is every paid sale tied to the customer, net of refunds, before tax and tips, and does not follow the range.', 'Top 50, highest lifetime value first');

        $c = $this->cfg('customers', ['atrisk', 'lapsed'], 'day');
        $t = $e->totals($c);
        $cl = $this->cfg('customers', ['since', 'ltvt'], 'cust', ['limit' => 100, 'compare' => 'off', 'filters' => [['f' => 'lapsed', 'op' => 'is', 'v' => 'yes']]]);
        $pan = $this->customerList('c-lapsed', 'Lapsed customers', 'used to buy, haven\'t lately: a win-back list', $cl, [
            ['Days since last purchase', 'since', 'n'], ['Lifetime value', 'ltvt', '$'],
        ], 'lapsed customers', 'No lapsed customers. Either everyone is a regular, or you are just getting started.',
            'Top 100, longest lapsed first. Lapsed means they bought before but not in the 180 days up to the end of the range. At-risk is 90 to 180 days.', 'Lapsed only, longest first');
        $pan['figs'] = [
            $this->fig('Lapsed', $this->fmt($t['vals']['lapsed'] ?? null, 'n'), 'no purchase in 180+ days', ($t['vals']['lapsed'] ?? 0) > 0 ? 'warn' : ''),
            $this->fig('At-risk', $this->fmt($t['vals']['atrisk'] ?? null, 'n'), '90 to 180 days since last purchase'),
        ];
        $out[] = $pan;

        $c = $this->cfg('customers', ['nophone', 'custs'], 'day');
        $t = $e->totals($c);
        $n = $t['vals']['nophone'] ?? null; $all = $t['vals']['custs'] ?? null;
        $cm = $this->cfg('customers', ['ltvt', 'spend'], 'cust', ['limit' => 100, 'compare' => 'off', 'filters' => [['f' => 'phone', 'op' => 'is', 'v' => 'missing']]]);
        $pan = $this->customerList('c-phone', 'Missing a phone number', 'customers you can\'t text or call', $cm, [
            ['Lifetime value', 'ltvt', '$'], ['Spend in range', 'spend', '$'],
        ], 'customers missing a phone number', 'Every customer has a phone number.',
            'Top 100, highest lifetime value first, so the customers worth fixing come first. A phone number counts as missing when it is blank.', 'Missing a phone number only');
        $pan['figs'] = [
            $this->fig('Missing a phone number', $this->fmt($n, 'n'), $all ? round(($n ?? 0) / $all * 100, 1) . '% of customers' : '', ($n ?? 0) > 0 ? 'warn' : ''),
        ];
        $out[] = $pan;

        $legend = [
            ['What follows the range:', 'new customers, who bought, spend and visits. Total customers, lifetime value, and the lapsed and missing-phone lists describe the whole customer list as of the end of the range.'],
            ['Lapsed:', 'now means no paid purchase in 180 days. The old tab used appointments. Customers who only ever booked and never paid are no longer counted as lapsed.'],
            ['Lifetime value:', 'is paid sales net of refunds, before tax and tips. The old tab counted payments including tax and tips, so figures are slightly lower and match Custom › Customers.'],
            ['Erased customers:', 'are left out everywhere.'],
        ];
        return ['panels' => $out, 'legend' => $legend];
    }
}
