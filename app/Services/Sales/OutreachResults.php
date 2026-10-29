<?php
// MARKER-OUTREACH-RESULTS
//
// Numbers for Email outreach > Results and the Email outreach block on
// Marketing traffic. Everything counts SHOPS, from emails sent one at a time
// (sales_outreach_sends: Email outreach and the Prospects card); bulk
// campaigns from Platform email are not in here.
//
//   opened   the email provider saw an open or a click (thread meta)
//   visited  a person at the shop opened its own link after the email
//            (sales_prospect_visits judged 'person'; mail filters don't count)
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
     * @param array{industry?: string, template?: string, days?: string, from?: string, to?: string, tz?: string} $f
     *        template '' = all, 'none' = written by hand;
     *        days 'today' | '7' | '30' | '90' | 'all' | 'custom' (custom reads from/to, Y-m-d, in tz)
     */
    public static function compute(array $f): array
    {
        $industry = (string) ($f['industry'] ?? '');
        $template = (string) ($f['template'] ?? '');
        [$since, $until] = self::window($f);

        $all = self::sends($industry, $template, null);        // every matching send, for follow-ups and the chart
        $rows = ($since || $until)
            ? $all->filter(fn ($r) => (! $since || $r->at->greaterThanOrEqualTo($since)) && (! $until || $r->at->lessThanOrEqualTo($until)))->values()
            : $all;

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

    /** The time window the filters ask for: [since|null, until|null]. */
    private static function window(array $f): array
    {
        $days     = (string) ($f['days'] ?? '30');
        $tz       = (string) ($f['tz'] ?? admin_tz());
        $until    = null;

        // MARKER-OUTREACH-RANGE: Today is the local calendar day, and a custom
        // range runs from the start of its first day to the end of its last.
        $dayOf = fn (?string $d) => $d && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? CarbonImmutable::createFromFormat('Y-m-d', $d, $tz) : null;
        if ($days === 'today') {
            $since = CarbonImmutable::now($tz)->startOfDay();
        } elseif ($days === 'custom' && ($a = $dayOf($f['from'] ?? null))) {
            $b     = $dayOf($f['to'] ?? null) ?? $a;
            [$a, $b] = $a->greaterThan($b) ? [$b, $a] : [$a, $b];
            $since = $a->startOfDay();
            $until = $b->endOfDay();
        } else {
            $since = $days === 'all' ? null : now()->subDays(max(1, (int) $days));
        }


        return [$since, $until];
    }

    /**
     * MARKER-OUTREACH-SCANNERS: what happened after each batch of sends (sends
     * less than 45 minutes apart are one batch): who actually looked, and how
     * many link checks by mail filters there were. Newest first.
     */
    public static function afterSends(array $f, int $limit = 12): array
    {
        [$since, $until] = self::window($f);
        $rows = self::sends((string) ($f['industry'] ?? ''), (string) ($f['template'] ?? ''), null)
            ->filter(fn ($r) => (! $since || $r->at->greaterThanOrEqualTo($since)) && (! $until || $r->at->lessThanOrEqualTo($until)))->values();
        if ($rows->isEmpty()) return [];

        $batches = [];
        $cur = null;
        foreach ($rows as $r) {
            if (! $cur || abs($r->at->getTimestamp() - $cur['end']->getTimestamp()) > 45 * 60) {
                if ($cur) $batches[] = $cur;
                $cur = ['start' => $r->at, 'end' => $r->at, 'pids' => []];
            }
            $cur['end'] = $r->at;
            $cur['pids'][$r->pid] = true;
        }
        $batches[] = $cur;
        $batches = array_slice(array_reverse($batches), 0, $limit);

        $out = [];
        foreach ($batches as $b) {
            $pids = array_keys($b['pids']);
            $visits = DB::table('sales_prospect_visits as v')->join('sales_prospects as p', 'p.id', '=', 'v.sales_prospect_id')
                ->whereIn('v.sales_prospect_id', $pids)
                ->whereBetween('v.sent_at', [$b['start']->subSecond(), $b['end']->addSecond()])
                ->orderBy('v.first_at')
                ->get(['v.*', 'p.shop']);
            $people = [];
            $why = [];
            $scanShops = [];
            $unsure = 0;
            foreach ($visits as $v) {
                $secs = max(0, CarbonImmutable::parse($v->last_at ?? $v->first_at)->getTimestamp() - CarbonImmutable::parse($v->first_at)->getTimestamp());
                if ($v->verdict === 'person') {
                    $paths = json_decode((string) $v->paths, true) ?: [];
                    $people[] = ['id' => $v->sales_prospect_id, 'shop' => $v->shop, 'pages' => (int) $v->pages, 'time' => OutreachVisitJudge::duration($secs),
                        'device' => ['mobile' => 'phone', 'tablet' => 'tablet', 'desktop' => 'computer'][$v->device] ?? null,
                        'what' => implode(' → ', array_map([ProspectOutreach::class, 'pageLabel'], array_slice($paths, 0, 4))),
                        'after' => $v->secs_after_send !== null ? self::ago((int) $v->secs_after_send) : null, 'why' => $v->verdict_reason];
                } elseif ($v->verdict === 'scanner') {
                    $scanShops[$v->sales_prospect_id] = true;
                    $k = self::scanKind($v);
                    $why[$k] = ($why[$k] ?? 0) + 1;
                } else {
                    $unsure++;
                }
            }
            arsort($why);
            $out[] = [
                'key' => $b['start']->getTimestamp() . '-' . $b['end']->getTimestamp(),
                'at' => $b['start'], 'sent' => count($pids), 'people' => $people,
                'peopleShops' => count(array_unique(array_column($people, 'id'))),
                'scanners' => array_sum($why), 'scannerShops' => count($scanShops), 'scanWhy' => $why, 'unsure' => $unsure,
            ];
        }

        return $out;
    }

    /**
     * MARKER-OUTREACH-SEND-DETAIL: one batch opened up: every shop emailed and
     * where it stands, the filter checks with their reasons, and, when nobody
     * looked, suggestions drawn from the numbers. $key is "startTs-endTs".
     */
    public static function batchDetail(array $f, string $key): ?array
    {
        if (! preg_match('/^(\d+)-(\d+)$/', $key, $m)) return null;
        $start = CarbonImmutable::createFromTimestamp((int) $m[1]);
        $end   = CarbonImmutable::createFromTimestamp((int) $m[2]);
        $rows = self::sends((string) ($f['industry'] ?? ''), (string) ($f['template'] ?? ''), null)
            ->filter(fn ($r) => $r->at->getTimestamp() >= $start->getTimestamp() && $r->at->getTimestamp() <= $end->getTimestamp())->values();
        if ($rows->isEmpty()) return null;

        $ctx = self::context($rows);
        $pids = $rows->pluck('pid')->unique()->values()->all();
        $visits = DB::table('sales_prospect_visits')->whereIn('sales_prospect_id', $pids)
            ->whereBetween('sent_at', [$start->subSecond(), $end->addSecond()])->orderBy('first_at')->get();
        $byPid = $visits->groupBy('sales_prospect_id');
        $emails = SalesProspect::whereIn('id', $pids)->pluck('email', 'id')->all();

        $order = ['replied' => 0, 'looked' => 1, 'opened' => 2, 'filter' => 3, 'nothing' => 4];
        $shops = [];
        foreach ($rows->groupBy('pid') as $pid => $sends) {
            $p = $ctx['prospects'][$pid] ?? null;
            $vs = $byPid[$pid] ?? collect();
            $tids = $sends->pluck('thread_id')->filter()->all();
            $replied = array_filter($tids, fn ($t) => isset($ctx['replied'][(string) $t]));
            $opened  = array_filter($tids, fn ($t) => isset($ctx['opened'][(string) $t]));
            $person  = $vs->firstWhere('verdict', 'person');
            $state = $replied ? 'replied' : ($person ? 'looked' : ($opened ? 'opened' : ($vs->where('verdict', 'scanner')->isNotEmpty() ? 'filter' : 'nothing')));
            $shops[] = [
                'id' => $pid, 'shop' => $p->shop ?? '(deleted)', 'place' => trim(implode(', ', array_filter([$p->city ?? null, $p->state ?? null]))),
                'email' => $emails[$pid] ?? '', 'state' => $state,
                'note' => match ($state) {
                    'looked' => ($person->verdict_reason ?? ''),
                    'filter' => $vs->where('verdict', 'scanner')->count() . ' ' . ($vs->where('verdict', 'scanner')->count() === 1 ? 'check' : 'checks') . ': ' . ($vs->firstWhere('verdict', 'scanner')->verdict_reason ?? ''),
                    default => '',
                },
            ];
        }
        usort($shops, fn ($a, $b) => [$order[$a['state']], $a['shop']] <=> [$order[$b['state']], $b['shop']]);
        $count = array_count_values(array_column($shops, 'state'));

        return [
            'shops' => $shops,
            'count' => $count + ['replied' => 0, 'looked' => 0, 'opened' => 0, 'filter' => 0, 'nothing' => 0],
            'suggest' => ($count['looked'] ?? 0) + ($count['replied'] ?? 0) === 0 ? self::suggestions($rows, $end, $count, $emails) : [],
        ];
    }

    /** What to try when a batch drew nobody, from the outreach numbers so far. */
    private static function suggestions($rows, CarbonImmutable $end, array $count, array $emails): array
    {
        $out = [];
        $hours = max(0, (int) floor((time() - $end->getTimestamp()) / 3600));

        // 1. too soon? how long real people have taken to look
        $delays = DB::table('sales_prospect_visits')->where('verdict', 'person')->whereNotNull('secs_after_send')
            ->orderBy('secs_after_send')->pluck('secs_after_send')->map(fn ($x) => (int) $x)->all();
        if ($hours < 48) {
            $median = $delays ? $delays[intdiv(count($delays), 2)] : null;
            $within2d = $delays ? count(array_filter($delays, fn ($d) => $d <= 172800)) : 0;
            $out[] = ['kind' => 'wait', 'text' => 'It\'s been ' . ($hours < 1 ? 'under an hour' : $hours . ' ' . ($hours === 1 ? 'hour' : 'hours')) . ' since this send.'
                . ($median !== null
                    ? ' People who looked at earlier sends took ' . self::span($median) . ' (middle of ' . count($delays) . '), and ' . $within2d . ' of ' . count($delays) . ' looked within two days.'
                    : ' Give it two days before judging it.')
                . ' Check back ' . $end->addDays(2)->setTimezone(admin_tz())->format('D M j') . '.'];
        }

        // 2. opened but didn't click
        if (($count['opened'] ?? 0) > 0) {
            $n = $count['opened'];
            $out[] = ['kind' => 'followup', 'text' => $n . ' ' . ($n === 1 ? 'shop' : 'shops') . ' opened the email but didn\'t click (opens run high, so some are previews). They\'re listed below and on Follow up next: a short personal follow-up in a few days is the best next step.'];
        }

        // 3. template: another one drawing more people per send?
        $tplIds = $rows->pluck('template_id')->filter()->unique()->values()->all();
        $rates = self::templateRates();
        if ($tplIds && $rates) {
            $mine = $rates[$tplIds[0]] ?? null;
            $best = collect($rates)->filter(fn ($r) => $r['sent'] >= 30)->sortByDesc('rate')->first();
            if ($best && $best['id'] !== ($tplIds[0] ?? null) && $best['rate'] > 0 && (! $mine || $best['rate'] >= 2 * max($mine['rate'], 0.0001))) {
                $out[] = ['kind' => 'template', 'text' => '"' . $best['name'] . '" has drawn ' . self::pct($best['rate']) . ' people per send over ' . $best['sent'] . ' sends'
                    . ($mine ? ', against ' . self::pct($mine['rate']) . ' for "' . $mine['name'] . '" used here' : '') . '. Try it for the next batch.'];
            }
        }

        // 4. mostly business addresses: filters quarantine cold email
        $biz = count(array_filter($emails, fn ($e) => $e && ! OutreachQueue::isFree($e)));
        if ($emails && $biz / count($emails) >= 0.6) {
            $out[] = ['kind' => 'placement', 'text' => $biz . ' of ' . count($emails) . ' were business addresses, whose mail filters often quarantine cold email even when it passes every check. Run a placement test (type a mail-tester address in Send test) and try a plainer version: one link, no images, written like a personal note.'];
        }

        if (! $out) {
            $out[] = ['kind' => 'wait', 'text' => 'Nothing stands out about this send. Follow up with a short personal note in two or three days; second emails often do better than the first.'];
        }

        return $out;
    }

    /** People per send for each template, all time: [id => [id, name, sent, people, rate]]. */
    private static function templateRates(): array
    {
        $sent = DB::table('sales_outreach_sends')->whereNotNull('template_id')->groupBy('template_id')
            ->selectRaw('template_id, COUNT(DISTINCT sales_prospect_id) n')->pluck('n', 'template_id')->all();
        if (! $sent) return [];
        $people = DB::table('sales_prospect_visits as v')->join('sales_outreach_sends as s', function ($j) {
                $j->on('s.sales_prospect_id', '=', 'v.sales_prospect_id')->on('s.created_at', '=', 'v.sent_at');
            })->where('v.verdict', 'person')->whereNotNull('s.template_id')
            ->groupBy('s.template_id')->selectRaw('s.template_id, COUNT(DISTINCT v.sales_prospect_id) n')->pluck('n', 'template_id')->all();
        $names = SalesEmailTemplate::pluck('name', 'id')->all();
        $out = [];
        foreach ($sent as $id => $n) {
            $out[$id] = ['id' => $id, 'name' => $names[$id] ?? 'Deleted template', 'sent' => (int) $n, 'people' => (int) ($people[$id] ?? 0), 'rate' => ($people[$id] ?? 0) / max(1, $n)];
        }
        return $out;
    }

    private static function pct(float $r): string
    {
        $p = $r * 100;
        return ($p < 1 ? number_format($p, 1) : (string) round($p)) . '%';
    }

    private static function span(int $secs): string
    {
        if ($secs < 3600) return max(1, intdiv($secs, 60)) . ' min';
        if ($secs < 172800) return round($secs / 3600) . ' hours';
        return round($secs / 86400) . ' days';
    }

    /** A short name for why a visit was a filter, for the summary line. */
    private static function scanKind(object $v): string
    {
        if (in_array($v->network, OutreachVisitJudge::FILTER_NETWORKS, true)) return OutreachVisitJudge::NETWORK_LABELS[$v->network];
        $r = (string) $v->verdict_reason;
        if (str_contains($r, 'times within')) return 'several at once';
        if (str_contains($r, 'after the email')) return 'within 2 min of sending';
        if (str_contains($r, 're-check')) return 'quick re-checks';
        return 'other';
    }

    private static function ago(int $secs): string
    {
        if ($secs < 120) return $secs . ' s after';
        if ($secs < 7200) return intdiv($secs, 60) . ' min after';
        if ($secs < 172800) return intdiv($secs, 3600) . ' h after';
        return intdiv($secs, 86400) . ' days after';
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
            // MARKER-OUTREACH-SCANNERS: only visits a person made; mail filters opening the link don't count
            foreach (DB::table('sales_prospect_visits')->whereIn('sales_prospect_id', $chunk)->where('verdict', 'person')->orderBy('first_at')
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
        $tz = admin_tz();
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
                ->where('v.verdict', 'person') // MARKER-OUTREACH-SCANNERS
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
