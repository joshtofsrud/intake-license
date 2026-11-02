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

    /** @return array{l: string, v: string, d: string, cls: string} */
    private function fig(string $label, string $value, ?string $sub = null, string $cls = '', ?float $delta = null): array
    {
        if ($delta !== null && $this->q['compare'] !== 'off') {
            if (round($delta, 1) == 0.0) {
                $sub = 'no change ' . $this->word();
            } else {
                $sub = ($delta >= 0 ? '+' : '−') . number_format(abs($delta), 1) . '% ' . $this->word();
                $cls = $cls ?: ($delta >= 0 ? 'good' : 'bad');
            }
        }
        return ['l' => $label, 'v' => $value, 'd' => (string) $sub, 'cls' => $cls];
    }

    /** A figure with its change on the period compared with. */
    private function figCmp(string $label, array $t, string $m, string $type, ?string $sub = null, string $cls = ''): array
    {
        $cur = $t['vals'][$m] ?? null;
        $delta = ($t['prev'] ?? null) !== null ? self::pct($cur, $t['prev'][$m] ?? null) : null;
        return $this->fig($label, $this->fmt($cur, $type), $sub, $cls, $delta);
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
        $pub = array_intersect_key($cfg, array_flip(['ds', 'measures', 'dim', 'dim2', 'filters', 'range', 'from', 'to', 'compare', 'sortDir', 'limit']));
        $code = rtrim(strtr(base64_encode(json_encode($pub)), '+/', '-_'), '=');
        return ['rows' => $rows, 'url' => route('tenant.reports.custom', ['cfg' => $code])];
    }

    /** One panel. $body holds 'figs', 'bar', 'table', 'html', 'after' as needed. */
    private function panel(string $id, string $title, string $hint, ?array $cfg, array $body = [], ?string $openExtra = null, bool $snapshot = false): array
    {
        $p = ['id' => $id, 'title' => $title, 'hint' => $hint, 'figs' => [], 'bar' => null, 'table' => null, 'html' => null, 'after' => null, 'note' => null, 'cfg' => null, 'open' => null];
        if ($cfg) {
            $p['cfg'] = array_intersect_key($cfg, array_flip(['ds', 'measures', 'dim', 'dim2', 'filters', 'range', 'from', 'to', 'compare', 'sortDir', 'limit']));
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

        // Items that will run out within two weeks at the current pace. Lowest first, so the row limit can't hide any.
        $ci = $this->cfg('inv', ['dleft'], 'item', ['compare' => 'off', 'sortDir' => 'asc']);
        $under = 0;
        foreach ($e->rows($ci)['rows'] as $row) {
            $d = $e->cellValue($ci, 'dleft', $row['vals']);
            if ($d !== null && $d < 14) $under++;
        }

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
}
