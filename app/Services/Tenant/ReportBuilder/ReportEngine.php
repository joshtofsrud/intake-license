<?php

namespace App\Services\Tenant\ReportBuilder;

use App\Models\Tenant;

/**
 * ReportEngine — runs one cleaned config and returns rows the page renders.
 * (MARKER-REPORT-BUILDER-V2)
 *
 * Measures from other data ("ds:measure") are run on their own source with
 * the same breakdowns and merged on the same keys. Totals come from each
 * source's own total, never from adding rows, so a ticket in two categories
 * is counted once and averages are true averages.
 */
class ReportEngine
{
    private array $sources;
    private array $catalog;
    private array $labels;
    private Periods $p;

    public function __construct(private readonly Tenant $tenant, bool $isManager)
    {
        $this->p = new Periods($tenant);
        $this->sources = ReportCatalog::sources($tenant, $this->p, $isManager);
        $this->catalog = ReportCatalog::client($this->sources, $isManager);
        $this->labels = ReportCatalog::labelMap($this->catalog);
    }

    public function catalog(): array { return $this->catalog; }
    public function periods(): Periods { return $this->p; }
    public function clean(array $cfg): array { return ReportCatalog::clean($cfg, $this->catalog); }

    /** Every "ds:measure" a config needs, including those inside calculations. */
    public function keysFor(array $cfg): array
    {
        $keys = [];
        foreach ($cfg['measures'] as $m) {
            if (str_starts_with($m, 'calc:')) {
                foreach ($cfg['calcs'] as $c) {
                    if ($c['id'] !== $m) continue;
                    foreach (Formula::tokens($c['f']) as $l) {
                        if (isset($this->labels[$l])) $keys[] = $this->labels[$l];
                    }
                }
            } else {
                $keys[] = str_contains($m, ':') ? $m : $cfg['ds'] . ':' . $m;
            }
        }
        return array_values(array_unique($keys));
    }

    /** The report's dates: its range, narrowed to one period when it was opened from a drill-down. */
    private function dates(array $cfg): array
    {
        [$from, $to] = $this->p->rangeFor($cfg);
        if (!empty($cfg['span']) && ($b = $this->p->bucketRange($cfg['span']['dim'], $cfg['span']['key']))) {
            $from = $b[0]->gt($from) ? $b[0] : $from;
            $to = $b[1]->lt($to) ? $b[1] : $to;
            if ($from->gt($to)) $to = $from->copy();
        }
        return [$from, $to];
    }

    private function dimsOf(string $ds): array
    {
        return array_column($this->catalog[$ds]['d'] ?? [], 0);
    }

    private function isTime(string $ds, string $dim): bool
    {
        foreach ($this->catalog[$ds]['d'] as $d) {
            if ($d[0] === $dim) return $d[2] === 't';
        }
        return false;
    }

    /** Run every needed source for these breakdowns over one range. */
    private function gather(array $cfg, array $keys, array $dims, $from, $to, array &$notes): array
    {
        $byDs = [];
        foreach ($keys as $k) {
            [$ds, $m] = explode(':', $k, 2);
            if (!isset($this->sources[$ds])) continue;
            if (array_diff($dims, $this->dimsOf($ds))) {
                $notes[] = $this->catalog[$ds]['l'] . ' can\'t be split this way, so its measures are left out.';
                continue;
            }
            $byDs[$ds][] = $m;
        }
        $out = [];
        foreach ($byDs as $ds => $ms) {
            $fkeys = array_column($this->catalog[$ds]['f'], 0);
            $filters = array_values(array_filter($cfg['filters'], fn ($f) => in_array($f['f'], $fkeys, true)));
            $src = $this->sources[$ds];
            $src->setRangeDays($this->p->days($from, $to));
            $out[$ds] = ['ms' => array_unique($ms), 'res' => $src->run($dims, $filters, $from, $to)];
        }
        return $out;
    }

    private function val(string $ds, string $m, array $c): ?float
    {
        $v = $this->sources[$ds]->value($m, $c);
        return ($v === null || !is_finite($v)) ? null : round($v, 6);
    }

    /** @return array the table: rows, totals, and the same for the period compared with */
    public function rows(array $cfg): array
    {
        $notes = [];
        $keys = $this->keysFor($cfg);
        $dim = $cfg['dim'];
        $time = $this->isTime($cfg['ds'], $dim);
        [$from, $to] = $this->dates($cfg);

        $cur = $this->gather($cfg, $keys, [$dim], $from, $to, $notes);
        $prev = null;
        if ($cfg['compare'] !== 'off') {
            [$pf, $pt] = $this->p->previous($from, $to, $cfg['compare']);
            $prev = $this->gather($cfg, $keys, [$dim], $pf, $pt, $notes);
        }

        // Row keys: every group from any source; over time, every period in the range.
        $rowKeys = [];
        if ($time) {
            foreach ($this->p->buckets($dim, $from, $to) as $b) $rowKeys[$b] = $this->p->label($dim, $b);
        }
        foreach ($cur as $g) {
            foreach ($g['res']['groups'] as $gk => $grp) $rowKeys[$gk] = $rowKeys[$gk] ?? $grp['l'][0];
        }
        $prevKey = [];
        if ($time && $prev) {
            [$pf, $pt] = $this->p->previous($from, $to, $cfg['compare']);
            $pb = $this->p->buckets($dim, $pf, $pt);
            foreach (array_keys($rowKeys) as $i => $k) $prevKey[$k] = $pb[$i] ?? null;
        }

        $rows = [];
        foreach ($rowKeys as $rk => $label) {
            $vals = []; $pv = [];
            foreach ($cur as $ds => $g) {
                $c = $g['res']['groups'][$rk]['c'] ?? [];
                $pc = null;
                if ($prev) {
                    $pk = $time ? ($prevKey[$rk] ?? null) : $rk;
                    $pc = $pk !== null ? ($prev[$ds]['res']['groups'][$pk]['c'] ?? []) : [];
                }
                foreach ($g['ms'] as $m) {
                    $vals["$ds:$m"] = $this->val($ds, $m, $c);
                    if ($prev) $pv["$ds:$m"] = $this->val($ds, $m, $pc);
                }
            }
            $rows[] = ['k' => (string) $rk, 'l' => (string) $label, 'vals' => $vals, 'prev' => $pv];
        }

        $totals = []; $ptotals = [];
        foreach ($cur as $ds => $g) {
            foreach ($g['ms'] as $m) {
                $totals["$ds:$m"] = $this->val($ds, $m, $g['res']['total']);
                if ($prev) $ptotals["$ds:$m"] = $this->val($ds, $m, $prev[$ds]['res']['total'] ?? []);
            }
        }

        // Order: dates and ranges keep their natural order; the rest by the first measure.
        if (!$time && !in_array($dim, ReportCatalog::ORDINAL, true) && $cfg['measures']) {
            $first = $cfg['measures'][0];
            $sk = fn ($r) => $this->cellValue($cfg, $first, $r['vals']);
            usort($rows, function ($a, $b) use ($sk, $cfg) {
                $x = $sk($a); $y = $sk($b);
                if ($x === $y) return strcmp($a['l'], $b['l']);
                if ($x === null) return 1;
                if ($y === null) return -1;
                return $cfg['sortDir'] === 'asc' ? $x <=> $y : $y <=> $x;
            });
        } elseif (in_array($dim, ReportCatalog::ORDINAL, true)) {
            usort($rows, fn ($a, $b) => self::ordinal($a['k']) <=> self::ordinal($b['k']));
        }

        $truncated = count($rows) > ReportCatalog::ROW_CAP;
        $rows = array_slice($rows, 0, ReportCatalog::ROW_CAP);
        if ($cfg['limit'] > 0) $rows = array_slice($rows, 0, $cfg['limit']);

        return ['rows' => $rows, 'totals' => $totals, 'prevTotals' => $ptotals, 'time' => $time, 'truncated' => $truncated,
            'notes' => array_values(array_unique($notes)), 'from' => $from->toDateString(), 'to' => $to->toDateString()];
    }

    /**
     * Totals only, with no rows: the figures at the top of a Reports tab. Every number comes from the same
     * source totals the builder's own total row uses, so a tab and the builder can't disagree.
     * Measures are read with cellValue(), so calculations work here too.
     * @return array{vals: array, prev: ?array, from: string, to: string, pfrom: ?string, pto: ?string}
     */
    public function totals(array $cfg): array
    {
        $notes = [];
        $keys = $this->keysFor($cfg);
        [$from, $to] = $this->dates($cfg);
        $cur = $this->gather($cfg, $keys, [], $from, $to, $notes);
        $vals = [];
        foreach ($cur as $ds => $g) {
            foreach ($g['ms'] as $m) $vals["$ds:$m"] = $this->val($ds, $m, $g['res']['total'] ?? []);
        }
        $prev = null; $pf = $pt = null;
        if (($cfg['compare'] ?? 'off') !== 'off') {
            [$pf, $pt] = $this->p->previous($from, $to, $cfg['compare']);
            $pc = $this->gather($cfg, $keys, [], $pf, $pt, $notes);
            $prev = [];
            foreach ($pc as $ds => $g) {
                foreach ($g['ms'] as $m) $prev["$ds:$m"] = $this->val($ds, $m, $g['res']['total'] ?? []);
            }
        }
        $out = ['vals' => [], 'prev' => $prev === null ? null : [], 'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'pfrom' => $pf?->toDateString(), 'pto' => $pt?->toDateString()];
        foreach ($cfg['measures'] as $m) {
            $out['vals'][$m] = $this->cellValue($cfg, $m, $vals);
            if ($prev !== null) $out['prev'][$m] = $this->cellValue($cfg, $m, $prev);
        }
        return $out;
    }

    /** A grid: one breakdown down the side, the other across the top. */
    public function pivot(array $cfg): array
    {
        $notes = [];
        $keys = $this->keysFor($cfg);
        [$d1, $d2] = [$cfg['dim'], $cfg['dim2']];
        [$from, $to] = $this->dates($cfg);
        $grid = $this->gather($cfg, $keys, [$d1, $d2], $from, $to, $notes);
        $byRow = $this->gather($cfg, $keys, [$d1], $from, $to, $notes);
        $byCol = $this->gather($cfg, $keys, [$d2], $from, $to, $notes);
        $t1 = $this->isTime($cfg['ds'], $d1); $t2 = $this->isTime($cfg['ds'], $d2);

        $rowL = []; $colL = [];
        if ($t1) foreach ($this->p->buckets($d1, $from, $to) as $b) $rowL[$b] = $this->p->label($d1, $b);
        if ($t2) foreach ($this->p->buckets($d2, $from, $to) as $b) $colL[$b] = $this->p->label($d2, $b);
        foreach ($grid as $g) {
            foreach ($g['res']['groups'] as $grp) {
                $rowL[$grp['k'][0]] = $rowL[$grp['k'][0]] ?? $grp['l'][0];
                $colL[$grp['k'][1]] = $colL[$grp['k'][1]] ?? $grp['l'][1];
            }
        }
        if (in_array($d2, ReportCatalog::ORDINAL, true)) uksort($colL, fn ($a, $b) => self::ordinal($a) <=> self::ordinal($b));

        $colTotals = [];
        foreach ($colL as $ck => $_) {
            foreach ($byCol as $ds => $g) {
                foreach ($g['ms'] as $m) $colTotals["$ds:$m"][] = $this->val($ds, $m, $g['res']['groups'][$ck]['c'] ?? []);
            }
        }
        $rows = [];
        foreach ($rowL as $rk => $label) {
            $cells = []; $rt = [];
            foreach ($grid as $ds => $g) {
                foreach ($g['ms'] as $m) {
                    foreach ($colL as $ck => $_) {
                        $cells["$ds:$m"][] = $this->val($ds, $m, $g['res']['groups'][$rk . "\x1f" . $ck]['c'] ?? []);
                    }
                    $rt["$ds:$m"] = $this->val($ds, $m, $byRow[$ds]['res']['groups'][$rk]['c'] ?? []);
                }
            }
            $rows[] = ['k' => (string) $rk, 'l' => (string) $label, 'cells' => $cells, 'total' => $rt];
        }
        if (!$t1 && !in_array($d1, ReportCatalog::ORDINAL, true) && $cfg['measures']) {
            $first = $cfg['measures'][0];
            usort($rows, function ($a, $b) use ($cfg, $first) {
                $x = $this->cellValue($cfg, $first, $a['total']); $y = $this->cellValue($cfg, $first, $b['total']);
                if ($x === $y) return strcmp($a['l'], $b['l']);
                if ($x === null) return 1;
                if ($y === null) return -1;
                return $cfg['sortDir'] === 'asc' ? $x <=> $y : $y <=> $x;
            });
        } elseif (in_array($d1, ReportCatalog::ORDINAL, true)) {
            usort($rows, fn ($a, $b) => self::ordinal($a['k']) <=> self::ordinal($b['k']));
        }
        $rows = array_slice($rows, 0, $cfg['limit'] > 0 ? $cfg['limit'] : 500);

        $grand = [];
        foreach ($grid as $ds => $g) {
            foreach ($g['ms'] as $m) $grand["$ds:$m"] = $this->val($ds, $m, $byRow[$ds]['res']['total'] ?? []);
        }
        return ['rows' => $rows, 'cols' => array_values(array_map('strval', $colL)), 'colKeys' => array_map('strval', array_keys($colL)),
            'colTotals' => $colTotals, 'grand' => $grand, 'timeRows' => $t1, 'timeCols' => $t2,
            'notes' => array_values(array_unique($notes))];
    }

    /** A measure's value from a row's values: a plain measure, or a calculation worked out from them. */
    public function cellValue(array $cfg, string $m, array $vals): ?float
    {
        if (str_starts_with($m, 'calc:')) {
            foreach ($cfg['calcs'] as $c) {
                if ($c['id'] === $m) {
                    return Formula::evaluate($c['f'], fn ($l) => isset($this->labels[$l]) ? ($vals[$this->labels[$l]] ?? null) : null);
                }
            }
            return null;
        }
        return $vals[str_contains($m, ':') ? $m : $cfg['ds'] . ':' . $m] ?? null;
    }

    /** Measure name and type, for exports. */
    public function measureDef(array $cfg, string $m): array
    {
        if (str_starts_with($m, 'calc:')) {
            foreach ($cfg['calcs'] as $c) if ($c['id'] === $m) return [$c['name'], $c['t']];
            return [$m, 'd'];
        }
        [$ds, $mk] = str_contains($m, ':') ? explode(':', $m, 2) : [$cfg['ds'], $m];
        foreach ($this->catalog[$ds]['m'] ?? [] as $d) if ($d[0] === $mk) return [$d[1], $d[2]];
        return [$m, 'd'];
    }

    public function dimLabel(array $cfg, string $dim): string
    {
        foreach ($this->catalog[$cfg['ds']]['d'] as $d) if ($d[0] === $dim) return str_replace(' (rang up by)', '', $d[1]);
        return $dim;
    }

    /**
     * The report as plain rows — what export, emails and dashboard pins show.
     * Measure values are worked out here (calculations included), so every
     * place that shows a report shows the same numbers. (MARKER-REPORT-DELIVERY)
     *
     * @return array{head: string[], types: string[], rows: array, total: ?array, count: int, pivot: bool}
     */
    public function table(array $cfg): array
    {
        if ($cfg['dim2'] !== '') {
            $p = $this->pivot($cfg);
            $m = $cfg['measures'][0];
            [$ml, $mt] = $this->measureDef($cfg, $m);
            $head = array_merge([$this->dimLabel($cfg, $cfg['dim']) . ' / ' . $this->dimLabel($cfg, $cfg['dim2']) . ' (' . $ml . ')'], $p['cols'], ['Total']);
            $rows = [];
            foreach ($p['rows'] as $r) {
                $line = [$r['l']];
                foreach (array_keys($p['cols']) as $j) {
                    $vals = [];
                    foreach ($r['cells'] as $k => $arr) $vals[$k] = $arr[$j];
                    $line[] = $this->cellValue($cfg, $m, $vals);
                }
                $line[] = $this->cellValue($cfg, $m, $r['total']);
                $rows[] = $line;
            }
            $total = ['Total'];
            foreach (array_keys($p['cols']) as $j) {
                $vals = [];
                foreach ($p['colTotals'] as $k => $arr) $vals[$k] = $arr[$j];
                $total[] = $this->cellValue($cfg, $m, $vals);
            }
            $total[] = $this->cellValue($cfg, $m, $p['grand']);
            return ['head' => $head, 'types' => array_merge(['text'], array_fill(0, count($p['cols']) + 1, $mt)), 'rows' => $rows,
                'total' => $total, 'count' => count($rows), 'pivot' => true];
        }

        $res = $this->rows($cfg);
        $head = [$this->dimLabel($cfg, $cfg['dim'])];
        $types = ['text'];
        foreach ($cfg['measures'] as $m) {
            [$l, $t] = $this->measureDef($cfg, $m);
            $head[] = $l;
            $types[] = $t;
        }
        $rows = [];
        foreach ($res['rows'] as $r) {
            $line = [$r['l']];
            foreach ($cfg['measures'] as $m) $line[] = $this->cellValue($cfg, $m, $r['vals']);
            $rows[] = $line;
        }
        $total = null;
        if (!$res['truncated']) {
            $total = ['Total'];
            foreach ($cfg['measures'] as $m) $total[] = $this->cellValue($cfg, $m, $res['totals']);
        }
        return ['head' => $head, 'types' => $types, 'rows' => $rows, 'total' => $total, 'count' => count($rows), 'pivot' => false,
            'prevTotals' => $res['prevTotals'], 'totals' => $res['totals']];
    }

    /** The report as CSV text. Text that would run as a spreadsheet formula is made safe. */
    public function csv(array $cfg): string
    {
        $t = $this->table($cfg);
        $safe = fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;
        $h = fopen('php://temp', 'r+');
        fputcsv($h, array_map($safe, $t['head']));
        foreach (array_merge($t['rows'], $t['total'] ? [$t['total']] : []) as $r) {
            $line = [$safe($r[0])];
            foreach (array_slice($r, 1) as $v) $line[] = $v === null ? '' : round((float) $v, 2);
            fputcsv($h, $line);
        }
        rewind($h);
        $out = stream_get_contents($h);
        fclose($h);
        return (string) $out;
    }

    /** Does this report read distributor stock (which master admin may keep out of emails)? */
    public function usesDistributor(array $cfg): bool
    {
        if ($cfg['ds'] === 'dist') return true;
        foreach ($this->keysFor($cfg) as $k) {
            if (str_starts_with($k, 'dist:')) return true;
        }
        return false;
    }

    public static function format(?float $v, string $t): string
    {
        if ($v === null || !is_finite($v)) return '—';
        return match ($t) {
            '$'      => ($v < 0 ? '−$' : '$') . number_format(abs($v), abs($v) < 100 ? 2 : 0),
            '%'      => number_format($v, 1) . '%',
            'd', 'h' => number_format($v, 1),
            default  => number_format(round($v)),
        };
    }

    private static function ordinal(string $k): int
    {
        return match (true) {
            is_numeric($k) => (int) $k,
            $k === 'good' => 0, $k === 'building' => 1, $k === 'early' => 2,
            default => 99,
        };
    }
}
