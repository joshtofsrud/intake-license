<?php

namespace App\Services\Tenant\ReportBuilder;

use App\Models\Tenant;
use App\Services\Anthropic\AnthropicClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ReportExplainer — "Explain this" in the report builder.
 * (MARKER-REPORT-EXPLAIN)
 *
 * Sends the rows and totals the person is looking at — nothing else — to
 * Claude and gets back a few plain sentences. Claude only describes; it never
 * changes a number. When Claude can't be reached (no key, an error, or the
 * shop's daily allowance is used up) a built-in summary is written from the
 * same numbers instead, and the page says which one it is.
 */
class ReportExplainer
{
    public const MODEL = 'claude-haiku-4-5';
    public const DAILY_CAP = 300;     // per shop
    private const ROWS = 40;          // rows sent; the rest are summarised as "N more"

    public function __construct(private readonly Tenant $tenant, private readonly ReportEngine $e, private readonly bool $isManager) {}

    /** @return array{text: string, by: string, note: ?string} */
    public function explain(array $cfg, string $title): array
    {
        $title = trim(mb_substr($title, 0, 120)) ?: 'Report';
        $facts = $this->facts($cfg, $title);
        if ($facts['empty']) {
            return ['text' => 'There is nothing to explain yet — no rows match these settings.', 'by' => 'builtin', 'note' => null];
        }

        $day = $this->e->periods()->today()->toDateString();
        $key = 'rb:explain:' . $this->tenant->id . ':' . ($this->isManager ? 'm' : 's') . ':' . md5($day . $title . json_encode($cfg));
        if ($hit = Cache::get($key)) {
            return $hit;
        }

        $countKey = 'rb:explain:n:' . $this->tenant->id . ':' . $day;
        $used = (int) Cache::get($countKey, 0);
        if ($used >= self::DAILY_CAP) {
            return ['text' => $this->builtin($facts), 'by' => 'builtin', 'note' => 'Your shop has used today\'s Claude summaries, so this is the built-in one. Claude is back tomorrow.'];
        }

        try {
            $client = app(AnthropicClient::class);
            $res = $client->messages(self::MODEL, 450, [['role' => 'user', 'content' => $facts['text']]], [
                'system' => $this->system(),
                'temperature' => 0.2,
            ]);
            $text = trim(preg_replace('/\s+/', ' ', str_replace(['**', '__', '#'], '', $client->extractText($res))));
            if ($text === '') {
                throw new \RuntimeException('Claude returned no text.');
            }
            Cache::put($countKey, $used + 1, now()->addDay());
            $out = ['text' => $text, 'by' => 'claude', 'note' => null];
            Cache::put($key, $out, now()->addMinutes(30));
            return $out;
        } catch (\Throwable $ex) {
            Log::warning('Report explain fell back to the built-in summary', ['tenant' => $this->tenant->id, 'error' => $ex->getMessage()]);
            return ['text' => $this->builtin($facts), 'by' => 'builtin', 'note' => 'Claude couldn\'t be reached just now, so this is the built-in summary.'];
        }
    }

    private function system(): string
    {
        return 'You explain a report from a small business\'s own software to the owner, in plain words. '
            . 'Use only the figures given. Never invent a number, a cause, a reason or advice; if something stands out, say what it is, not why. '
            . 'Write 3 to 5 short sentences of plain text: no lists, no headings, no markdown. '
            . 'Start with the overall total or average for the period, then what stands out — the largest and smallest rows, '
            . 'big changes against the comparison period if there is one, and any row far above or below the rest. '
            . 'Round where it reads better ("about $4,500"). Use the row and measure names as given. '
            . 'Don\'t describe the table\'s layout and don\'t mention that you are an AI.';
    }

    /** The numbers on screen, as text, plus what the built-in summary needs. */
    public function facts(array $cfg, string $title): array
    {
        $cat = $this->e->catalog();
        $ds = $cfg['ds'];
        $p = $this->e->periods();
        [$from, $to] = $p->rangeFor($cfg);
        $lines = [];
        $lines[] = 'Report: ' . $title;
        $lines[] = 'Data: ' . ($cat[$ds]['l'] ?? $ds) . '. Period: ' . $cfg['range'] . ' (' . $from->format('M j, Y') . ' – ' . $to->format('M j, Y') . ')'
            . (!empty($cfg['span']['l']) ? ', narrowed to ' . $cfg['span']['l'] : '') . '.';
        if ($cfg['compare'] !== 'off' && $cfg['dim2'] === '') {
            $lines[] = 'Compared with: ' . ($cfg['compare'] === 'yoy' ? 'the same dates last year' : 'the same length of time just before') . '.';
        }
        $fl = [];
        foreach ($cfg['filters'] as $f) {
            foreach ($cat[$ds]['f'] as $fd) {
                if ($fd[0] !== $f['f']) continue;
                $vl = $f['v'];
                foreach ($fd[2] as $o) if ((string) $o[0] === (string) $f['v']) $vl = $o[1];
                $fl[] = $fd[1] . ($f['op'] === 'not' ? ' is not ' : ' is ') . $vl;
            }
        }
        if ($fl) $lines[] = 'Only including: ' . implode('; ', $fl) . '.';
        if ($cfg['limit'] > 0) $lines[] = 'Showing the ' . ($cfg['sortDir'] === 'asc' ? 'lowest ' : 'top ') . $cfg['limit'] . ' rows.';

        $fmt = fn ($v, $t) => ReportEngine::format($v === null ? null : (float) $v, $t);

        if ($cfg['dim2'] !== '') {
            $t = $this->e->table($cfg);
            $types = $t['types'];
            $lines[] = '';
            $lines[] = 'A grid: ' . $t['head'][0] . '. Columns: ' . implode(', ', array_slice($t['head'], 1)) . '.';
            foreach (array_slice($t['rows'], 0, self::ROWS) as $r) {
                $cells = [];
                foreach (array_slice($r, 1) as $i => $v) $cells[] = $fmt($v, $types[$i + 1]);
                $lines[] = $r[0] . ': ' . implode(' | ', $cells);
            }
            if ($t['count'] > self::ROWS) $lines[] = '(' . ($t['count'] - self::ROWS) . ' more rows not shown)';
            if ($t['total']) {
                $cells = [];
                foreach (array_slice($t['total'], 1) as $i => $v) $cells[] = $fmt($v, $types[$i + 1]);
                $lines[] = 'Total: ' . implode(' | ', $cells);
            }
            [$ml, $mt] = $this->e->measureDef($cfg, $cfg['measures'][0]);
            $grand = end($t['total']) ?: null;
            return ['text' => implode("\n", $lines), 'empty' => !$t['rows'], 'pivot' => true, 'measure' => $ml, 'type' => $mt,
                'rows' => [], 'total' => $grand, 'prevTotal' => null, 'dimLabel' => $t['head'][0], 'time' => false, 'range' => $cfg['range'], 'more' => count($cfg['measures']) - 1];
        }

        $res = $this->e->rows($cfg);
        $defs = [];
        foreach ($cfg['measures'] as $m) $defs[$m] = $this->e->measureDef($cfg, $m);
        $cmp = $cfg['compare'] !== 'off';
        $lines[] = '';
        $lines[] = 'Rows, by ' . $this->e->dimLabel($cfg, $cfg['dim']) . ($res['time'] ? ' (in date order)' : '') . '. Columns: '
            . implode(', ', array_map(fn ($d) => $d[0], $defs)) . ($cmp ? ', each followed by the comparison period\'s figure in brackets' : '') . '.';
        $rows = $res['rows'];
        $send = count($rows) > self::ROWS ? array_merge(array_slice($rows, 0, self::ROWS - 5), array_slice($rows, -5)) : $rows;
        foreach ($send as $i => $r) {
            if (count($rows) > self::ROWS && $i === self::ROWS - 5) $lines[] = '(' . (count($rows) - self::ROWS) . ' more rows in between)';
            $cells = [];
            foreach ($defs as $m => [$l, $t]) {
                $c = $fmt($this->e->cellValue($cfg, $m, $r['vals']), $t);
                if ($cmp) $c .= ' (' . $fmt($this->e->cellValue($cfg, $m, $r['prev']), $t) . ')';
                $cells[] = $c;
            }
            $lines[] = $r['l'] . ': ' . implode(' | ', $cells);
        }
        if (!$res['truncated']) {
            $cells = [];
            foreach ($defs as $m => [$l, $t]) {
                $c = $fmt($this->e->cellValue($cfg, $m, $res['totals']), $t);
                if ($cmp) $c .= ' (' . $fmt($this->e->cellValue($cfg, $m, $res['prevTotals']), $t) . ')';
                $cells[] = $c;
            }
            $lines[] = 'Total for the whole period (worked out from all the data, not by adding rows): ' . implode(' | ', $cells);
        }
        foreach ($res['notes'] as $n) $lines[] = 'Note: ' . $n;

        $m0 = $cfg['measures'][0] ?? null;
        $vals = [];
        foreach ($rows as $r) $vals[] = ['l' => $r['l'], 'v' => $m0 ? $this->e->cellValue($cfg, $m0, $r['vals']) : null];
        return ['text' => implode("\n", $lines), 'empty' => !$rows || !$m0, 'pivot' => false,
            'measure' => $m0 ? $defs[$m0][0] : '', 'type' => $m0 ? $defs[$m0][1] : 'n', 'rows' => $vals,
            'total' => $m0 ? $this->e->cellValue($cfg, $m0, $res['totals']) : null,
            'prevTotal' => $m0 && $cmp ? $this->e->cellValue($cfg, $m0, $res['prevTotals']) : null,
            'cmp' => $cfg['compare'], 'dimLabel' => $this->e->dimLabel($cfg, $cfg['dim']), 'time' => $res['time'], 'range' => $cfg['range'],
            'more' => count($cfg['measures']) - 1];
    }

    /** Plain sentences from the same numbers, without Claude. */
    public function builtin(array $f): string
    {
        $t = $f['type'];
        $L = mb_strtolower($f['measure']);
        $add = in_array($t, ['n', '$', 'h'], true);
        $fmt = fn ($v) => ReportEngine::format($v === null ? null : (float) $v, $t);
        if ($f['pivot']) {
            return 'This report has two breakdowns, so it shows ' . $L . ' as a grid' . ($f['total'] !== null ? ', ' . ($add ? 'coming to ' : 'at ') . $fmt($f['total']) . ' overall' : '') . '. Darker cells are higher; click a row to look inside it.';
        }
        $rows = array_values(array_filter($f['rows'], fn ($r) => $r['v'] !== null));
        if (!$rows) return 'There is nothing to explain yet — no rows have a value for ' . $L . '.';
        $n = count($f['rows']);
        $dim = mb_strtolower($f['dimLabel']);
        $rl = mb_strtolower($f['range']);
        $txt = (str_starts_with($rl, 'last ') ? 'Over the ' : 'For ') . $rl . ', ' . ($add ? 'the total for ' . $L . ' is ' : $L . ' is ') . $fmt($f['total']) . ' across ' . $n . ' ' . ($n === 1 ? $dim : self::plural($dim)) . '. ';
        if (!$f['time'] && count($rows) > 1) {
            usort($rows, fn ($a, $b) => $b['v'] <=> $a['v']);
            $hi = $rows[0]; $lo = end($rows);
            $sum = array_sum(array_column($rows, 'v'));
            $txt .= $hi['l'] . ' is highest at ' . $fmt($hi['v']) . ($add && $sum > 0 ? ' (' . round($hi['v'] / $sum * 100) . '% of the total)' : '')
                . ' and ' . $lo['l'] . ' is lowest at ' . $fmt($lo['v']) . '. ';
        } elseif ($f['time'] && count($rows) > 1) {
            $a = $rows[count($rows) - 1]['v']; $b = $rows[count($rows) - 2]['v'];
            $txt .= 'The latest period is ' . $fmt($a) . ($b ? ', ' . ($a >= $b ? 'up ' : 'down ') . number_format(abs(($a - $b) / $b * 100), 1) . '% on the one before' : '') . '. ';
        }
        if ($f['prevTotal'] !== null && $f['total'] !== null && $f['prevTotal'] != 0) {
            $ch = ($f['total'] - $f['prevTotal']) / abs($f['prevTotal']) * 100;
            $txt .= 'Overall that is ' . ($ch >= 0 ? 'up ' : 'down ') . number_format(abs($ch), 1) . '% ' . (($f['cmp'] ?? '') === 'yoy' ? 'on last year' : 'on the period before') . '. ';
        }
        if ($f['more'] > 0) $txt .= 'The other ' . $f['more'] . ' measure' . ($f['more'] > 1 ? 's are' : ' is') . ' in the table.';
        return trim($txt);
    }

    private static function plural(string $w): string
    {
        if (str_starts_with($w, 'staff') || str_ends_with($w, 's')) return $w === 'staff' ? 'staff' : $w;
        if (preg_match('/[^aeiou]y$/', $w)) return substr($w, 0, -1) . 'ies';
        return $w . 's';
    }
}
