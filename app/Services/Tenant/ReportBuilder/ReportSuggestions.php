<?php

namespace App\Services\Tenant\ReportBuilder;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * ReportSuggestions — "Suggested builds" on Reports › Custom.
 * (MARKER-REPORT-SUGGEST)
 *
 * Once a night each shop's recent figures are compared with the period
 * before. Anything that moved enough to be worth a look becomes a tile that
 * opens a ready-made report with the reason it was suggested. Every check
 * runs through ReportEngine, so a tile's numbers are the numbers the report
 * shows when it's opened. Read-only: nothing here changes shop data.
 */
class ReportSuggestions
{
    public const MAX = 8;

    private ReportEngine $e;
    private array $cat;
    /** @var string[] checks that failed this run, for the failure report */
    public array $errors = [];

    public function __construct(private readonly Tenant $tenant)
    {
        // Built with owner/manager access; tiles that need it are marked and
        // only shown to owners and managers.
        $this->e = new ReportEngine($tenant, true);
        $this->cat = $this->e->catalog();
    }

    /** Run every check and keep the strongest. */
    public function build(): array
    {
        $out = [];
        foreach (['marginDrop', 'categoryRevenue', 'itemsPerTicket', 'cancellations', 'overtime', 'newCustomers',
                  'lowMarginReceived', 'sellThroughBand', 'distributorLow', 'agedStock'] as $check) {
            try {
                foreach ($this->$check() as $s) {
                    if ($s) $out[] = $s;
                }
            } catch (\Throwable $ex) {
                $this->errors[] = $check . ': ' . $ex->getMessage();
            }
        }
        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($out, 0, self::MAX);
    }

    /** Store a run's results for the shop, replacing the last run's. */
    public function store(array $items, string $localDate): void
    {
        $tid = $this->tenant->id;
        DB::transaction(function () use ($tid, $items, $localDate) {
            DB::table('tenant_report_suggestions')->where('tenant_id', $tid)->delete();
            foreach (array_values($items) as $i => $s) {
                DB::table('tenant_report_suggestions')->insert([
                    'tenant_id' => $tid, 'position' => $i, 'icon' => $s['icon'], 'tone' => $s['tone'],
                    'amount' => $s['amount'], 'amount_label' => $s['amountLabel'], 'title' => $s['title'], 'sub' => $s['sub'],
                    'why' => $s['why'], 'dataset' => $s['cfg']['ds'], 'config' => json_encode($s['cfg']),
                    'managers_only' => $s['mgr'] ? 1 : 0, 'created_at' => now(),
                ]);
            }
            DB::table('tenant_report_suggest_checks')->updateOrInsert(['tenant_id' => $tid],
                ['checked_on' => $localDate, 'checked_at' => now(), 'found' => count($items)]);
        });
    }

    /** What one person sees: only tiles whose data they can open. */
    public static function forUser(Tenant $tenant, array $catalog, bool $isManager): array
    {
        $check = DB::table('tenant_report_suggest_checks')->where('tenant_id', $tenant->id)->first();
        $items = [];
        foreach (DB::table('tenant_report_suggestions')->where('tenant_id', $tenant->id)->orderBy('position')->get() as $r) {
            if ($r->managers_only && !$isManager) continue;
            if (!isset($catalog[$r->dataset])) continue;
            $cfg = json_decode((string) $r->config, true) ?: [];
            $items[] = ['icon' => $r->icon, 'tone' => $r->tone, 'a' => $r->amount, 'al' => $r->amount_label, 't' => $r->title,
                's' => $r->sub, 'why' => $r->why, 'cfg' => ReportCatalog::clean($cfg, $catalog)];
        }
        $on = $check?->checked_on ? \Carbon\Carbon::parse($check->checked_on) : null;
        return ['items' => $items, 'checked' => $on && $check->checked_at ? $on->format('M j') : null, 'running' => $check && !$check->checked_at];
    }

    // ------------------------------------------------------------ helpers

    private function has(string $ds): bool
    {
        return isset($this->cat[$ds]);
    }

    private function run(array $cfg): array
    {
        return $this->e->rows($this->e->clean($cfg));
    }

    private function v(array $vals, string $ds, string $m): ?float
    {
        return $vals["$ds:$m"] ?? null;
    }

    private static function money(float $v): string
    {
        $a = abs($v);
        $s = $a >= 1000000 ? number_format($a / 1000000, 1) . 'm' : ($a >= 10000 ? number_format($a / 1000) . 'k' : ($a >= 1000 ? number_format($a / 1000, 1) . 'k' : number_format($a)));
        return ($v < 0 ? '−$' : '$') . str_replace('.0k', 'k', str_replace('.0m', 'm', $s));
    }

    private static function pts(float $v): string
    {
        return ($v < 0 ? '−' : '+') . number_format(abs($v), 1) . ' pts';
    }

    private static function pctChange(float $v): string
    {
        return ($v < 0 ? '−' : '+') . number_format(abs($v)) . '%';
    }

    private function filterLabel(string $ds, string $f, string $v, string $fallback): string
    {
        foreach ($this->cat[$ds]['f'] as $fd) {
            if ($fd[0] !== $f) continue;
            foreach ($fd[2] as $o) if ((string) $o[0] === $v) return $o[1];
        }
        return $fallback;
    }

    private function card(string $icon, string $tone, string $amount, string $amountLabel, string $title, string $sub, string $why, array $cfg, float $score, bool $mgr = false): array
    {
        return compact('icon', 'tone', 'amount', 'amountLabel', 'title', 'sub', 'why', 'cfg', 'score', 'mgr');
    }

    // ------------------------------------------------------------- checks

    /** The category whose margin % fell furthest against the 90 days before. */
    private function marginDrop(): array
    {
        if (!$this->has('sales')) return [];
        $r = $this->run(['ds' => 'sales', 'measures' => ['marginp', 'rev'], 'dim' => 'cat', 'range' => 'Last 90 days', 'compare' => 'prev']);
        $tot = (float) ($this->v($r['totals'], 'sales', 'rev') ?? 0);
        $best = null;
        foreach ($r['rows'] as $row) {
            $rev = $this->v($row['vals'], 'sales', 'rev');
            $prevRev = $this->v($row['prev'], 'sales', 'rev');
            $m = $this->v($row['vals'], 'sales', 'marginp');
            $pm = $this->v($row['prev'], 'sales', 'marginp');
            if ($m === null || $pm === null || $rev === null || $prevRev === null) continue;
            if ($rev < max(500, $tot * 0.05) || $prevRev < 300) continue;
            $drop = $pm - $m;
            if ($drop >= 3 && (!$best || $drop > $best[0])) $best = [$drop, $row, $m, $pm, $rev];
        }
        if (!$best) return [];
        [$drop, $row, $m, $pm, $rev] = $best;
        $l = $row['l'];
        return [$this->card('trending-down', 'bad', '−' . number_format($drop, 1) . ' pts', 'margin %',
            'Margin on ' . $l . ' fell ' . number_format($drop, 1) . ' points against the 90 days before',
            'Now ' . number_format($m, 1) . '%, down from ' . number_format($pm, 1) . '%, on ' . self::money($rev) . ' of sales.',
            'Margin % on ' . $l . ' is ' . number_format($m, 1) . '%, down from ' . number_format($pm, 1) . '% in the 90 days before. Compare is on so you can see each month against the one before.',
            ['ds' => 'sales', 'measures' => ['marginp', 'margin', 'rev'], 'dim' => 'month', 'filters' => [['f' => 'cat', 'op' => 'is', 'v' => $row['k']]],
                'range' => 'Last 90 days', 'compare' => 'prev'],
            40 + $drop * 4)];
    }

    /** The categories whose revenue moved most against the 30 days before — one drop, one rise. */
    private function categoryRevenue(): array
    {
        if (!$this->has('sales')) return [];
        $r = $this->run(['ds' => 'sales', 'measures' => ['rev'], 'dim' => 'cat', 'range' => 'Last 30 days', 'compare' => 'prev']);
        $ptot = (float) ($this->v($r['prevTotals'], 'sales', 'rev') ?? 0);
        $down = null; $up = null;
        foreach ($r['rows'] as $row) {
            $v = (float) ($this->v($row['vals'], 'sales', 'rev') ?? 0);
            $p = (float) ($this->v($row['prev'], 'sales', 'rev') ?? 0);
            if ($p < max(300, $ptot * 0.03)) continue;
            $ch = ($v - $p) / $p * 100;
            if ($ch <= -25 && (!$down || $ch < $down[0])) $down = [$ch, $row, $v, $p];
            if ($ch >= 40 && (!$up || $ch > $up[0])) $up = [$ch, $row, $v, $p];
        }
        $out = [];
        foreach (['down' => $down, 'up' => $up] as $dir => $x) {
            if (!$x) continue;
            [$ch, $row, $v, $p] = $x;
            $cfg = ['ds' => 'sales', 'measures' => ['rev', 'tickets', 'margin'], 'dim' => 'week', 'filters' => [['f' => 'cat', 'op' => 'is', 'v' => $row['k']]],
                'range' => 'Last 30 days', 'compare' => 'prev'];
            $out[] = $this->card($dir === 'down' ? 'trending-down' : 'trending-up', $dir === 'down' ? 'bad' : 'good', self::pctChange($ch), 'revenue',
                'Revenue from ' . $row['l'] . ' is ' . ($dir === 'down' ? 'down ' : 'up ') . number_format(abs($ch)) . '% on the 30 days before',
                self::money($v) . ' in the last 30 days, against ' . self::money($p) . '.',
                'Revenue, tickets and margin for ' . $row['l'] . ', week by week, against the 30 days before.',
                $cfg, ($dir === 'down' ? 35 : 25) + min(40, abs($ch) / 2));
        }
        return $out;
    }

    /** How many staff sold fewer items per ticket than the 30 days before. */
    private function itemsPerTicket(): array
    {
        if (!$this->has('sales')) return [];
        $r = $this->run(['ds' => 'sales', 'measures' => ['ipt', 'tickets'], 'dim' => 'staff', 'range' => 'Last 30 days', 'compare' => 'prev']);
        $n = 0; $m = 0;
        foreach ($r['rows'] as $row) {
            $t = $this->v($row['vals'], 'sales', 'tickets'); $pt = $this->v($row['prev'], 'sales', 'tickets');
            $v = $this->v($row['vals'], 'sales', 'ipt'); $p = $this->v($row['prev'], 'sales', 'ipt');
            if ($t === null || $pt === null || $t < 10 || $pt < 10 || !$v || !$p) continue;
            $m++;
            if (($v - $p) / $p <= -0.10) $n++;
        }
        $avg = $this->v($r['totals'], 'sales', 'ipt'); $pavg = $this->v($r['prevTotals'], 'sales', 'ipt');
        if ($n < 1 || $m < 2 || $avg === null || $pavg === null) return [];
        return [$this->card('trending-down', 'warn', ($avg - $pavg < 0 ? '−' : '+') . number_format(abs($avg - $pavg), 1), 'items / ticket',
            'Items per ticket fell for ' . $n . ' of ' . $m . ' staff in the last 30 days',
            'The team average is ' . ($avg <= $pavg ? 'down' : 'up') . ' from ' . number_format($pavg, 1) . ' to ' . number_format($avg, 1) . '.',
            'Staff who dropped more than 10% against the 30 days before. Sorted lowest first so they\'re at the top. Only people with at least 10 tickets in both periods are counted.',
            ['ds' => 'sales', 'measures' => ['ipt', 'tickets', 'items'], 'dim' => 'staff', 'range' => 'Last 30 days', 'compare' => 'prev', 'sortDir' => 'asc'],
            20 + $n / $m * 40, true)];
    }

    /** The service whose cancellation rate rose most. */
    private function cancellations(): array
    {
        if (!$this->has('appts')) return [];
        $r = $this->run(['ds' => 'appts', 'measures' => ['cancp', 'ap', 'canc'], 'dim' => 'svc', 'range' => 'Last 30 days', 'compare' => 'prev']);
        $best = null;
        foreach ($r['rows'] as $row) {
            $ap = $this->v($row['vals'], 'appts', 'ap'); $pap = $this->v($row['prev'], 'appts', 'ap');
            $c = $this->v($row['vals'], 'appts', 'cancp'); $pc = $this->v($row['prev'], 'appts', 'cancp');
            if ($ap === null || $ap < 10 || $pap === null || $pap < 5 || $c === null || $pc === null) continue;
            $rise = $c - $pc;
            if ($rise >= 3 && $c >= 5 && (!$best || $rise > $best[0])) $best = [$rise, $row, $c, $pc, $ap, (float) ($this->v($row['vals'], 'appts', 'canc') ?? 0)];
        }
        if (!$best) return [];
        [$rise, $row, $c, $pc, $ap, $canc] = $best;
        return [$this->card('calendar-x', 'bad', self::pts($rise), 'cancellation rate',
            'Cancellations on ' . $row['l'] . ' reached ' . number_format($c, 1) . '% in the last 30 days',
            'Up from ' . number_format($pc, 1) . '%. ' . number_format($canc) . ' of ' . number_format($ap) . ' appointments were cancelled.',
            'Cancellation rate for ' . $row['l'] . ', split by staff or resource, against the 30 days before.',
            ['ds' => 'appts', 'measures' => ['cancp', 'canc', 'ap'], 'dim' => 'res', 'filters' => [['f' => 'svc', 'op' => 'is', 'v' => $row['k']]],
                'range' => 'Last 30 days', 'compare' => 'prev'],
            30 + $rise * 3)];
    }

    /** Overtime against the 30 days before. Owners and managers only. */
    private function overtime(): array
    {
        if (!$this->has('staff')) return [];
        $r = $this->run(['ds' => 'staff', 'measures' => ['ot', 'hrs'], 'dim' => 'staff', 'range' => 'Last 30 days', 'compare' => 'prev']);
        $ot = (float) ($this->v($r['totals'], 'staff', 'ot') ?? 0);
        $pot = (float) ($this->v($r['prevTotals'], 'staff', 'ot') ?? 0);
        if ($ot < 5 || $ot <= $pot * 1.2) return [];
        $per = array_filter(array_map(fn ($row) => (float) ($this->v($row['vals'], 'staff', 'ot') ?? 0), $r['rows']));
        rsort($per);
        $people = count($per);
        $topTwo = array_sum(array_slice($per, 0, 2));
        $sub = $people === 1 ? 'All of it is one person.' : ($people > 2 && $topTwo / $ot >= 0.6 ? 'Two people account for most of it.' : $people . ' people worked overtime.');
        $ch = $pot > 0 ? ($ot - $pot) / $pot * 100 : null;
        return [$this->card('clock', 'bad', $ch === null ? number_format($ot, 1) . ' h' : self::pctChange($ch), 'overtime hours',
            $ch === null ? number_format($ot, 1) . ' hours of overtime in the last 30 days, up from none' : 'Overtime is up ' . number_format($ch) . '% on the 30 days before',
            number_format($ot, 1) . ' hours, against ' . number_format($pot, 1) . '. ' . $sub,
            'Overtime and hours worked by person, against the 30 days before.',
            ['ds' => 'staff', 'measures' => ['ot', 'hrs', 'shifts'], 'dim' => 'staff', 'range' => 'Last 30 days', 'compare' => 'prev'],
            30 + min(40, ($ch ?? 100) / 3), true)];
    }

    /** New customers against the 30 days before. */
    private function newCustomers(): array
    {
        if (!$this->has('customers')) return [];
        $r = $this->run(['ds' => 'customers', 'measures' => ['newc'], 'dim' => 'week', 'range' => 'Last 30 days', 'compare' => 'prev']);
        $v = (float) ($this->v($r['totals'], 'customers', 'newc') ?? 0);
        $p = (float) ($this->v($r['prevTotals'], 'customers', 'newc') ?? 0);
        if ($p < 10) return [];
        $ch = ($v - $p) / $p * 100;
        if ($ch > -20) return [];
        return [$this->card('user-x', 'warn', self::pctChange($ch), 'new customers',
            'New customers are down ' . number_format(abs($ch)) . '% on the 30 days before',
            number_format($v) . ' new in the last 30 days, against ' . number_format($p) . '.',
            'New customers and total customers, week by week, against the 30 days before.',
            ['ds' => 'customers', 'measures' => ['newc', 'custs'], 'dim' => 'week', 'range' => 'Last 30 days', 'compare' => 'prev'],
            25 + min(40, abs($ch) / 2))];
    }

    /** Share of units received under 30% margin, against the 90 days before. */
    private function lowMarginReceived(): array
    {
        if (!$this->has('recv')) return [];
        $r = $this->run(['ds' => 'recv', 'measures' => ['runits'], 'dim' => 'mband', 'range' => 'Last 90 days', 'compare' => 'prev']);
        $tot = (float) ($this->v($r['totals'], 'recv', 'runits') ?? 0);
        $ptot = (float) ($this->v($r['prevTotals'], 'recv', 'runits') ?? 0);
        if ($tot < 20) return [];
        $low = 0.0; $plow = 0.0;
        foreach ($r['rows'] as $row) {
            if ($row['k'] !== '0') continue;
            $low = (float) ($this->v($row['vals'], 'recv', 'runits') ?? 0);
            $plow = (float) ($this->v($row['prev'], 'recv', 'runits') ?? 0);
        }
        $share = $low / $tot * 100;
        $pshare = $ptot > 0 ? $plow / $ptot * 100 : null;
        if ($share < 15 || ($pshare !== null && $share - $pshare < 5)) return [];
        // Which vendor most of it came from.
        $byV = $this->run(['ds' => 'recv', 'measures' => ['runits'], 'dim' => 'vend', 'range' => 'Last 90 days',
            'filters' => [['f' => 'mband', 'op' => 'is', 'v' => '0']]]);
        $topV = $byV['rows'][0] ?? null;
        $tv = $topV ? (float) ($this->v($topV['vals'], 'recv', 'runits') ?? 0) : 0;
        $sub = ($pshare !== null ? 'Up from ' . number_format($pshare) . '% in the 90 days before' : 'Nothing to compare with in the 90 days before')
            . ($topV && $low > 0 && $tv / $low >= 0.5 ? ', mostly from ' . $topV['l'] . '.' : '.');
        return [$this->card('percent', 'warn', number_format($share) . '%', 'of units received',
            number_format($share) . '% of units received in the last 90 days came in under 30% margin',
            $sub,
            'Units and cost received by margin range, against the 90 days before, with sell-through so you can see whether the low-margin stock is moving.',
            ['ds' => 'recv', 'measures' => ['runits', 'rcost', 'rst'], 'dim' => 'mband', 'range' => 'Last 90 days', 'compare' => 'prev'],
            25 + ($share - ($pshare ?? 0)))];
    }

    /** A price band selling through at under half the rate of the best one. */
    private function sellThroughBand(): array
    {
        if (!$this->has('recv')) return [];
        $r = $this->run(['ds' => 'recv', 'measures' => ['rst', 'runits'], 'dim' => 'pband', 'range' => 'Last 90 days']);
        $rows = array_values(array_filter($r['rows'], fn ($row) => $row['k'] !== 'none'
            && ($this->v($row['vals'], 'recv', 'runits') ?? 0) >= 10 && $this->v($row['vals'], 'recv', 'rst') !== null));
        if (count($rows) < 2) return [];
        usort($rows, fn ($a, $b) => $this->v($a['vals'], 'recv', 'rst') <=> $this->v($b['vals'], 'recv', 'rst'));
        $lo = $rows[0]; $hi = end($rows);
        $l = (float) $this->v($lo['vals'], 'recv', 'rst'); $h = (float) $this->v($hi['vals'], 'recv', 'rst');
        if ($h <= 0 || $l > $h * 0.5 || $h - $l < 15) return [];
        return [$this->card('tags', 'warn', number_format($l) . '%', 'sell-through, ' . mb_strtolower($lo['l']),
            'Sell-through is ' . number_format($l) . '% on items priced ' . mb_strtolower($lo['l']),
            'Received in the last 90 days. Items priced ' . mb_strtolower($hi['l']) . ' are selling through at ' . number_format($h) . '%.',
            'Units received and sold, by price band, for the last 90 days.',
            ['ds' => 'recv', 'measures' => ['runits', 'rsold', 'rst'], 'dim' => 'pband', 'range' => 'Last 90 days'],
            20 + ($h - $l) / 2)];
    }

    /** Items the shop carries with under two weeks left at the distributor. */
    private function distributorLow(): array
    {
        if (!$this->has('dist')) return [];
        $r = $this->run(['ds' => 'dist', 'measures' => ['dlow'], 'dim' => 'cat']);
        $n = (int) round((float) ($this->v($r['totals'], 'dist', 'dlow') ?? 0));
        if ($n < 1) return [];
        $cats = array_values(array_filter($r['rows'], fn ($row) => ($this->v($row['vals'], 'dist', 'dlow') ?? 0) > 0));
        $sub = count($cats) <= 1 ? 'All in ' . ($cats[0]['l'] ?? 'one category') . '.'
            : 'Spread across ' . count($cats) . ' categories, with ' . $cats[0]['l'] . ' highest.';
        return [$this->card('alarm-clock', 'warn', number_format($n), 'items under 14 days',
            $n . ' item' . ($n === 1 ? '' : 's') . ' you carry ' . ($n === 1 ? 'has' : 'have') . ' under two weeks left at the distributor',
            $sub,
            'Items you carry with under 14 days left at the distributor, by category. Pace is the distributor\'s stock dropping, not your sales.',
            ['ds' => 'dist', 'measures' => ['dlow', 'ddays', 'dstock'], 'dim' => 'cat'],
            25 + min(30, $n * 2))];
    }

    /** Stock at cost that has been on hand over 180 days. */
    private function agedStock(): array
    {
        if (!$this->has('inv')) return [];
        $r = $this->run(['ds' => 'inv', 'measures' => ['a4', 'cost'], 'dim' => 'vend']);
        $a4 = (float) ($this->v($r['totals'], 'inv', 'a4') ?? 0);
        $cost = (float) ($this->v($r['totals'], 'inv', 'cost') ?? 0);
        if ($a4 < 500 || $cost <= 0 || $a4 / $cost < 0.10) return [];
        $share = $a4 / $cost * 100;
        $top = $r['rows'][0] ?? null;
        $tv = $top ? (float) ($this->v($top['vals'], 'inv', 'a4') ?? 0) : 0;
        return [$this->card('package-open', 'warn', self::money($a4), 'over 180 days',
            self::money($a4) . ' of stock has been on hand for over 180 days',
            'That\'s ' . number_format($share) . '% of your stock at cost' . ($top && $tv / $a4 >= 0.4 ? ', with the most from ' . $top['l'] . '.' : '.'),
            'Stock older than 180 days, by vendor, with each vendor\'s total stock at cost.',
            ['ds' => 'inv', 'measures' => ['a4', 'cost', 'dead'], 'dim' => 'vend'],
            20 + min(40, $share))];
    }
}
