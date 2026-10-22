<?php
// MARKER-OUTREACH-RESULTS
//
// Numbers for Email outreach > Results and the Email outreach block on
// Marketing traffic. Everything counts SHOPS, from emails sent one at a time
// (sales_outreach_sends: Email outreach and the Prospects card); bulk
// campaigns from Platform email are not in here.
//
//   opened   the email provider saw an open or a click (thread meta)
//   visited  the shop opened its own link after the email (sales_prospect_visits)
//   replied  a real answer came back into the Platform inbox on that thread
//            (MARKER-AUTO-REPLY: help-desk receipts and out-of-office don't count)
//   demo     the shop reached Demo booked, Demo done or Trial after the email
//   won      the shop reached Won after the email

namespace App\Services\Sales;

use App\Models\SalesEmailTemplate;
use App\Models\SalesProspect;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class OutreachResults
{
    public const DEMO_STAGES = ['demo_booked', 'demo_done', 'trial', 'won'];

    /**
     * @param array{industry?: string, template?: string, days?: string} $f
     *        template '' = all, 'none' = written by hand; days '7' | '30' | '90' | 'all'
     */
    public static function compute(array $f): array
    {
        $industry = (string) ($f['industry'] ?? '');
        $template = (string) ($f['template'] ?? '');
        $days     = (string) ($f['days'] ?? '30');
        $since    = $days === 'all' ? null : now()->subDays(max(1, (int) $days));

        $all = self::sends($industry, $template, null);        // every matching send, for follow-ups and the chart
        $rows = $since ? $all->filter(fn ($r) => $r->at->greaterThanOrEqualTo($since))->values() : $all;

        $ctx = self::context($all);

        $tplNames = SalesEmailTemplate::pluck('name', 'id')->all();
        $chNames  = \App\Models\SalesChannel::pluck('name', 'id')->all();

        $group = function ($rows, callable $key, callable $label) use ($ctx) {
            $out = [];
            foreach ($rows->groupBy($key) as $k => $g) {
                $out[] = ['key' => (string) $k, 'label' => $label((string) $k, $g)] + self::funnel($g, $ctx);
            }
            usort($out, fn ($a, $b) => $b['emailed'] <=> $a['emailed']);
            return $out;
        };

        return [
            'funnel'     => self::funnel($rows, $ctx),
            'lists'      => self::lists($rows, $ctx, $tplNames), // MARKER-OUTREACH-DRILL
            'byTemplate' => $group($rows, fn ($r) => $r->template_id ?: 'none', fn ($k, $g) => $k === 'none'
                ? 'Written by hand' : ($tplNames[$k] ?? 'Deleted template')),
            'byIndustry' => $group($rows, fn ($r) => $r->channel_id ?: 'none', fn ($k) => $k === 'none'
                ? 'No industry' : ($chNames[$k] ?? 'Unknown')),
            'byPage'     => $group($rows, fn ($r) => $r->page ?: '/', fn ($k) => ProspectOutreach::pageLabel($k)),
            'weeks'      => self::weeks($all, $ctx),
            'followUp'   => self::followUp($all, $ctx, 12),
        ];
    }

    /** One row per send, with the prospect's industry. */
    private static function sends(string $industry, string $template, $since)
    {
        $q = DB::table('sales_outreach_sends as s')
            ->join('sales_prospects as p', 'p.id', '=', 's.sales_prospect_id')
            ->when($industry !== '', fn ($q) => $q->where('p.channel_id', $industry))
            ->when($template === 'none', fn ($q) => $q->whereNull('s.template_id'))
            ->when($template !== '' && $template !== 'none', fn ($q) => $q->where('s.template_id', $template))
            ->when($since, fn ($q) => $q->where('s.created_at', '>=', $since))
            ->orderBy('s.created_at')
            ->get(['s.sales_prospect_id as pid', 's.template_id', 's.page', 's.thread_id', 's.created_at', 'p.channel_id']);
        return $q->map(function ($r) {
            $r->at = CarbonImmutable::parse($r->created_at);
            return $r;
        });
    }

    /** Everything the funnel needs about these shops, loaded once. */
    private static function context($rows): array
    {
        $pids = $rows->pluck('pid')->unique()->values()->all();
        $tids = $rows->pluck('thread_id')->filter()->unique()->values()->all();
        $ctx = ['visits' => [], 'opened' => [], 'replied' => [], 'replyText' => [], 'autoOnly' => [], 'stages' => [], 'prospects' => []];
        if (! $pids) return $ctx;

        foreach (array_chunk($pids, 500) as $chunk) {
            foreach (DB::table('sales_prospect_visits')->whereIn('sales_prospect_id', $chunk)->orderBy('first_at')
                ->get(['sales_prospect_id', 'first_at', 'last_at', 'paths', 'pages']) as $v) {
                $ctx['visits'][$v->sales_prospect_id][] = $v;
            }
            foreach (DB::table('sales_activities')->whereIn('sales_prospect_id', $chunk)->where('type', 'stage_change')
                ->whereIn('stage_to', self::DEMO_STAGES)->get(['sales_prospect_id', 'stage_to', 'occurred_at']) as $a) {
                $ctx['stages'][$a->sales_prospect_id][] = [$a->stage_to, CarbonImmutable::parse($a->occurred_at)];
            }
            foreach (SalesProspect::whereIn('id', $chunk)->get(['id', 'shop', 'city', 'state', 'stage', 'converted_at']) as $p) {
                $ctx['prospects'][$p->id] = $p;
            }
        }
        foreach (array_chunk($tids, 500) as $chunk) {
            foreach (\App\Models\PlatformInboxMessage::whereIn('id', $chunk)->get(['id', 'meta']) as $m) {
                $meta = (array) ($m->meta ?? []);
                $o = $meta['opened_at'] ?? ($meta['clicked_at'] ?? null);
                if ($o) $ctx['opened'][(string) $m->id] = CarbonImmutable::parse($o);
            }
            foreach (DB::table('platform_inbox_replies')->whereIn('message_id', $chunk)->where('direction', 'in')
                ->orderBy('created_at')->get(['message_id', 'created_at', 'body', 'is_auto']) as $r) {
                $id = (string) $r->message_id;
                if ($r->is_auto) { $ctx['autoOnly'][$id] = true; continue; }
                if (! isset($ctx['replied'][$id])) {
                    $ctx['replied'][$id] = CarbonImmutable::parse($r->created_at);
                    $ctx['replyText'][$id] = (string) $r->body;
                }
            }
            foreach (array_keys($ctx['replied']) as $id) unset($ctx['autoOnly'][$id]);
        }
        return $ctx;
    }

    /** Shops in these rows, each judged from its first email in the group. */
    private static function funnel($rows, array $ctx): array
    {
        $first = [];
        $threads = [];
        foreach ($rows as $r) {
            $first[$r->pid] ??= $r->at;
            if ($r->thread_id) $threads[$r->pid][] = (string) $r->thread_id;
        }
        $n = ['emailed' => count($first), 'opened' => 0, 'visited' => 0, 'replied' => 0, 'demo' => 0, 'won' => 0, 'pages' => 0];
        foreach ($first as $pid => $at) {
            $t = $threads[$pid] ?? [];
            if (array_filter($t, fn ($id) => isset($ctx['opened'][$id]))) $n['opened']++;
            if (array_filter($t, fn ($id) => isset($ctx['replied'][$id]))) $n['replied']++;
            $vis = array_filter($ctx['visits'][$pid] ?? [], fn ($v) => CarbonImmutable::parse($v->first_at)->greaterThanOrEqualTo($at));
            if ($vis) {
                $n['visited']++;
                $n['pages'] += array_sum(array_map(fn ($v) => (int) $v->pages, $vis)) / count($vis);
            }
            $st = array_filter($ctx['stages'][$pid] ?? [], fn ($s) => $s[1]->greaterThanOrEqualTo($at));
            $p  = $ctx['prospects'][$pid] ?? null;
            $convertedAfter = $p && $p->converted_at && CarbonImmutable::parse($p->converted_at)->greaterThanOrEqualTo($at);
            if ($st || $convertedAfter) $n['demo']++;
            if (array_filter($st, fn ($s) => $s[0] === 'won') || ($convertedAfter && $p->stage === 'won')) $n['won']++;
        }
        $n['pages_each'] = $n['visited'] ? round($n['pages'] / $n['visited'], 1) : 0;
        unset($n['pages']);
        return $n;
    }

    /** Last 8 weeks: shops emailed that week, and shops whose first visit after an email fell in it. */
    private static function weeks($rows, array $ctx): array
    {
        $tz = 'America/Los_Angeles';
        $start = now($tz)->startOfWeek()->subWeeks(7);
        $weeks = [];
        for ($i = 0; $i < 8; $i++) {
            $w = $start->copy()->addWeeks($i);
            $weeks[$w->format('Y-m-d')] = ['label' => $w->format('M j'), 'emailed' => [], 'visited' => []];
        }
        $firstSend = [];
        foreach ($rows as $r) {
            $firstSend[$r->pid] ??= $r->at;
            $k = $r->at->setTimezone($tz)->startOfWeek()->format('Y-m-d');
            if (isset($weeks[$k])) $weeks[$k]['emailed'][$r->pid] = true;
        }
        foreach ($firstSend as $pid => $at) {
            foreach ($ctx['visits'][$pid] ?? [] as $v) {
                $va = CarbonImmutable::parse($v->first_at);
                if ($va->lessThan($at)) continue;
                $k = $va->setTimezone($tz)->startOfWeek()->format('Y-m-d');
                if (isset($weeks[$k])) $weeks[$k]['visited'][$pid] = true;
                break;
            }
        }
        return array_values(array_map(fn ($w) => ['label' => $w['label'], 'emailed' => count($w['emailed']), 'visited' => count($w['visited'])], $weeks));
    }

    /** Opened or visited since their latest email, no reply, still open. Newest first. */
    public static function followUp($rows, array $ctx, ?int $limit = null): array
    {
        $last = [];
        $threads = [];
        foreach ($rows as $r) {
            $last[$r->pid] = $r->at;
            if ($r->thread_id) $threads[$r->pid][] = (string) $r->thread_id;
        }
        $out = [];
        foreach ($last as $pid => $at) {
            $p = $ctx['prospects'][$pid] ?? null;
            if (! $p || in_array($p->stage, ['won', 'lost', 'trial'], true)) continue;
            $t = $threads[$pid] ?? [];
            if (array_filter($t, fn ($id) => isset($ctx['replied'][$id]))) continue;

            $when = null; $kind = null; $what = '';
            foreach (array_reverse($ctx['visits'][$pid] ?? []) as $v) {
                if (CarbonImmutable::parse($v->first_at)->lessThan($at)) continue;
                $when = CarbonImmutable::parse($v->last_at ?: $v->first_at);
                $kind = 'visited';
                $paths = json_decode((string) $v->paths, true) ?: [];
                $what = implode(', ', array_map([ProspectOutreach::class, 'pageLabel'], array_slice($paths, 0, 3)));
                break;
            }
            if (! $when) {
                foreach ($t as $id) {
                    if (isset($ctx['opened'][$id]) && (! $when || $ctx['opened'][$id]->greaterThan($when))) $when = $ctx['opened'][$id];
                }
                if ($when) { $kind = 'opened'; $what = 'Opened the email'; }
            }
            if (! $when) continue;
            $out[] = ['id' => $pid, 'shop' => $p->shop, 'place' => trim($p->city . ', ' . $p->state, ', '), 'kind' => $kind, 'what' => $what, 'when' => $when];
        }
        usort($out, fn ($a, $b) => $b['when'] <=> $a['when']);
        return $limit ? array_slice($out, 0, $limit) : $out;
    }

    /** Every shop waiting on a follow-up (the top bar count). */
    public static function followUpCount(): int
    {
        $all = self::sends('', '', null);
        return count(self::followUp($all, self::context($all)));
    }

    /**
     * MARKER-OUTREACH-DRILL: the shops behind each funnel number, newest first,
     * with what happened and when. Same rules as funnel().
     */
    private static function lists($rows, array $ctx, array $tplNames): array
    {
        $first = []; $tplOf = []; $threads = [];
        foreach ($rows as $r) {
            if (! isset($first[$r->pid])) { $first[$r->pid] = $r->at; $tplOf[$r->pid] = $r->template_id; }
            if ($r->thread_id) $threads[$r->pid][] = (string) $r->thread_id;
        }
        $L = ['emailed' => [], 'opened' => [], 'visited' => [], 'replied' => [], 'demo' => [], 'won' => [], 'auto' => []];
        $stageNames = \App\Models\SalesProspect::STAGES;
        foreach ($first as $pid => $at) {
            $p = $ctx['prospects'][$pid] ?? null;
            if (! $p) continue;
            $base = ['id' => $pid, 'shop' => $p->shop, 'thread' => null];
            $tpl = $tplOf[$pid] ? ($tplNames[$tplOf[$pid]] ?? 'Deleted template') : 'Written by hand';
            $L['emailed'][] = $base + ['when' => $at, 'what' => $tpl];
            $t = $threads[$pid] ?? [];
            $op = null;
            foreach ($t as $id) if (isset($ctx['opened'][$id]) && (! $op || $ctx['opened'][$id]->lessThan($op))) $op = $ctx['opened'][$id];
            if ($op) $L['opened'][] = $base + ['when' => $op, 'what' => 'Opened the email'];
            foreach ($ctx['visits'][$pid] ?? [] as $v) {
                $va = CarbonImmutable::parse($v->first_at);
                if ($va->lessThan($at)) continue;
                $paths = json_decode((string) $v->paths, true) ?: [];
                $L['visited'][] = $base + ['when' => $va, 'what' => implode(' → ', array_map([ProspectOutreach::class, 'pageLabel'], array_slice($paths, 0, 4))) . ' · ' . (int) $v->pages . ' ' . ((int) $v->pages === 1 ? 'page' : 'pages')];
                break;
            }
            $rep = null; $repThread = null;
            foreach ($t as $id) if (isset($ctx['replied'][$id]) && (! $rep || $ctx['replied'][$id]->lessThan($rep))) { $rep = $ctx['replied'][$id]; $repThread = $id; }
            if ($rep) {
                $L['replied'][] = ['id' => $pid, 'shop' => $p->shop, 'thread' => $repThread, 'when' => $rep,
                    'what' => '"' . mb_strimwidth(trim(preg_replace('/\s+/', ' ', $ctx['replyText'][$repThread] ?? '')), 0, 110, '…') . '"'];
            } else {
                foreach ($t as $id) if (isset($ctx['autoOnly'][$id])) { $L['auto'][] = $p->shop; break; }
            }
            $st = array_values(array_filter($ctx['stages'][$pid] ?? [], fn ($s) => $s[1]->greaterThanOrEqualTo($at)));
            $conv = $p->converted_at ? CarbonImmutable::parse($p->converted_at) : null;
            if ($st) {
                usort($st, fn ($a, $b) => $a[1] <=> $b[1]);
                $L['demo'][] = $base + ['when' => $st[0][1], 'what' => $stageNames[$st[0][0]] ?? $st[0][0]];
                foreach ($st as $s) if ($s[0] === 'won') { $L['won'][] = $base + ['when' => $s[1], 'what' => 'Won']; break; }
            } elseif ($conv && $conv->greaterThanOrEqualTo($at)) {
                $L['demo'][] = $base + ['when' => $conv, 'what' => 'Started a trial'];
                if ($p->stage === 'won') $L['won'][] = $base + ['when' => $conv, 'what' => 'Won'];
            }
        }
        foreach ($L as $k => $list) {
            if ($k === 'auto') continue;
            usort($list, fn ($a, $b) => $b['when'] <=> $a['when']);
            $L[$k] = $list;
        }
        return $L;
    }

    // ------------------------------------------------------------ Marketing traffic

    /** The Email outreach block on Marketing traffic, for its date window. */
    public static function forTraffic($start, $end): array
    {
        $empty = ['visits' => 0, 'shops' => 0, 'pages_each' => 0, 'pricing' => 0, 'trials' => 0, 'recent' => []];
        try {
            $v = DB::table('sales_prospect_visits as v')
                ->join('sales_prospects as p', 'p.id', '=', 'v.sales_prospect_id')
                ->where('v.first_at', '>=', $start)->where('v.first_at', '<', $end)
                ->orderByDesc('v.first_at')
                ->get(['v.sales_prospect_id', 'v.first_at', 'v.paths', 'v.pages', 'p.shop', 'p.converted_at']);
        } catch (\Throwable $e) {
            report($e);
            return $empty;
        }
        if ($v->isEmpty()) return $empty;
        $pricing = $v->filter(fn ($r) => in_array('/pricing', json_decode((string) $r->paths, true) ?: [], true))->pluck('sales_prospect_id')->unique()->count();
        $trials = $v->filter(fn ($r) => $r->converted_at && CarbonImmutable::parse($r->converted_at)->greaterThanOrEqualTo(CarbonImmutable::parse($r->first_at)))
            ->pluck('sales_prospect_id')->unique()->count();
        $recent = $v->unique('sales_prospect_id')->take(6)->map(fn ($r) => [
            'id' => $r->sales_prospect_id, 'shop' => $r->shop, 'pages' => (int) $r->pages,
            'when' => CarbonImmutable::parse($r->first_at),
        ])->values()->all();
        return [
            'visits'     => $v->count(),
            'shops'      => $v->pluck('sales_prospect_id')->unique()->count(),
            'pages_each' => round($v->avg('pages'), 1),
            'pricing'    => $pricing,
            'trials'     => $trials,
            'recent'     => $recent,
        ];
    }

    /** session_id => ['id', 'shop', 'template'] for sessions that came from an outreach link. */
    public static function shopsForSessions(array $sessionIds): array
    {
        $sessionIds = array_values(array_filter(array_unique($sessionIds)));
        if (! $sessionIds) return [];
        try {
            $out = [];
            $tpl = SalesEmailTemplate::pluck('name', 'id')->all();
            foreach (array_chunk($sessionIds, 500) as $chunk) {
                $rows = DB::table('sales_prospect_visits as v')
                    ->join('sales_prospects as p', 'p.id', '=', 'v.sales_prospect_id')
                    ->whereIn('v.session_id', $chunk)
                    ->get(['v.session_id', 'v.first_at', 'p.id', 'p.shop']);
                foreach ($rows as $r) {
                    if (isset($out[$r->session_id])) continue;
                    $tid = DB::table('sales_outreach_sends')->where('sales_prospect_id', $r->id)
                        ->where('created_at', '<=', $r->first_at)->orderByDesc('created_at')->value('template_id');
                    $out[$r->session_id] = ['id' => $r->id, 'shop' => $r->shop, 'template' => $tid ? ($tpl[$tid] ?? null) : null];
                }
            }
            return $out;
        } catch (\Throwable $e) {
            report($e);
            return [];
        }
    }
}
