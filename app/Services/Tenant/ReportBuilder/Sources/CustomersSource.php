<?php

namespace App\Services\Tenant\ReportBuilder\Sources;

use Carbon\Carbon;
use App\Services\Tenant\ReportBuilder\Periods;
use Illuminate\Support\Facades\DB;

/**
 * Customers — who they are, when they joined, what they spend, and how often
 * they get in touch. Erased customers are never counted.
 */
class CustomersSource extends Source
{
    public function meta(): array
    {
        return [
            'label' => 'Customers',
            'note'  => 'Your customers, their spending and their messages. Spend is paid sales net of refunds, before tax and tips. Erased customers are left out.',
            'snap'  => false,
            'measures' => [
                'custs'  => ['Customers', 'n'],
                'newc'   => ['New customers', 'n'],
                'growth' => ['Growth rate', '%'],
                'active' => ['Customers who bought', 'n'],
                'spend'  => ['Spend', '$'],
                'visits' => ['Visits per customer', 'd'],
                'ltv'    => ['Average lifetime value', '$'],
                'ltvt'   => ['Total lifetime value', '$'],
                'inbox'  => ['Inbox contacts', 'n'],
            ],
            'dims' => [
                'day' => ['Day', 't'], 'week' => ['Week', 't'], 'month' => ['Month', 't'],
                'tag' => ['Tag', 'c'], 'vip' => ['VIP', 'c'], 'cust' => ['Customer', 'c'],
            ],
            'filters' => ['tag' => 'Tag', 'vip' => 'VIP'],
        ];
    }

    public function options(string $key): array
    {
        return match ($key) {
            'tag' => DB::table('tenant_customer_tags')->where('tenant_id', $this->tid())->orderBy('name')->pluck('name', 'id')
                ->map(fn ($v) => (string) $v)->all() + ['none' => 'No tag'],
            'vip' => ['vip' => 'VIP', 'regular' => 'Not VIP'],
            default => [],
        };
    }

    public function run(array $dims, array $filters, Carbon $from, Carbon $to): array
    {
        $tid = $this->tid();
        $tz = $this->p->tz();
        $f = $this->byKey($filters);
        $fromS = $from->toDateString(); $toS = $to->toDateString();

        $tagNames = $this->options('tag');
        $tags = [];
        foreach (DB::table('tenant_customer_tag_pivot')->where('tenant_id', $tid)->get(['customer_id', 'tag_id']) as $t) {
            $tags[(string) $t->customer_id][] = (string) $t->tag_id;
        }

        $cust = [];
        $q = DB::table('tenant_customers')->where('tenant_id', $tid)->whereNull('erased_at')
            ->select('id', 'first_name', 'last_name', 'created_at', 'is_vip');
        foreach ($q->cursor() as $c) {
            $id = (string) $c->id;
            $ctags = $tags[$id] ?? ['none'];
            $vip = $c->is_vip ? 'vip' : 'regular';
            if (isset($f['tag'])) {
                if (!empty($f['tag']['is']) && !array_intersect($ctags, $f['tag']['is'])) continue;
                if (!empty($f['tag']['not']) && array_intersect($ctags, $f['tag']['not'])) continue;
            }
            if (!$this->passes($vip, $f['vip'] ?? null)) continue;
            $created = $c->created_at ? Carbon::parse($c->created_at, 'UTC')->setTimezone($tz)->toDateString() : '2000-01-01';
            $cust[$id] = ['name' => trim($c->first_name . ' ' . $c->last_name) ?: 'Unnamed customer', 'created' => $created,
                'tags' => $ctags, 'vip' => $vip];
        }

        // Paid sales in the range, per customer per day (refunds count negative).
        $sales = DB::table('tenant_sales')->where('tenant_id', $tid)->whereNotNull('customer_id')
            ->where('payment_status', 'paid')->whereBetween('sale_date', [$fromS, $toS])
            ->selectRaw('customer_id, sale_date')
            ->selectRaw('COUNT(DISTINCT CASE WHEN refund_of_sale_id IS NULL THEN id END) as tickets')
            ->selectRaw('SUM((CASE WHEN refund_of_sale_id IS NULL THEN 1 ELSE -1 END) * (subtotal_cents - discount_cents)) as spend')
            ->groupBy('customer_id', 'sale_date')->get();

        $life = DB::table('tenant_sales')->where('tenant_id', $tid)->whereNotNull('customer_id')->where('payment_status', 'paid')
            ->selectRaw('customer_id, SUM((CASE WHEN refund_of_sale_id IS NULL THEN 1 ELSE -1 END) * (subtotal_cents - discount_cents)) as spend')
            ->groupBy('customer_id')->pluck('spend', 'customer_id')->all();

        [$ua, $ub] = $this->p->utcBounds($from, $to);
        $msgs = DB::table('tenant_messages as m')->join('tenant_threads as t', 't.id', '=', 'm.thread_id')
            ->where('t.tenant_id', $tid)->where('m.direction', 'in')->whereBetween('m.created_at', [$ua, $ub])
            ->selectRaw("t.customer_id, t.id as thread_id, DATE_FORMAT(m.created_at, '%Y-%m-%d %H') as h")
            ->groupByRaw("t.customer_id, t.id, DATE_FORMAT(m.created_at, '%Y-%m-%d %H')")->get();

        $ti = null;
        foreach ($dims as $n => $d) {
            if (in_array($d, Periods::TIME_DIMS, true)) $ti = $n;
        }

        $groups = []; $active = []; $threads = [];
        $total = ['custs' => count($cust), 'newc' => 0, 'active' => 0, 'tickets' => 0, 'spend' => 0, 'ltv' => 0, 'inbox' => 0];
        $tActive = []; $tThreads = [];

        // Static keys (tag / vip / customer) for one customer, as [[keys...], [labels...]] combinations.
        $combos = function (string $id, ?string $bucket) use ($dims, $cust, $tagNames, $ti) {
            $lists = [];
            foreach ($dims as $n => $d) {
                if ($n === $ti) { $lists[$n] = [[$bucket, $this->p->label($d, $bucket)]]; continue; }
                $lists[$n] = match ($d) {
                    'tag'  => array_map(fn ($t) => [$t, $tagNames[$t] ?? 'Removed tag'], $cust[$id]['tags']),
                    'vip'  => [[$cust[$id]['vip'], $cust[$id]['vip'] === 'vip' ? 'VIP' : 'Not VIP']],
                    default => [[$id, $cust[$id]['name']]],
                };
            }
            $out = [[[], []]];
            foreach ($lists as $n => $opts) {
                $next = [];
                foreach ($out as [$k, $l]) {
                    foreach ($opts as [$ok, $ol]) { $next[] = [$k + [$n => $ok], $l + [$n => $ol]]; }
                }
                $out = $next;
            }
            return $out;
        };
        $bucketOf = fn (string $date) => $ti === null ? null : $this->p->key($dims[$ti], $date);

        foreach ($cust as $id => $c) {
            $isNew = $c['created'] >= $fromS && $c['created'] <= $toS;
            if ($isNew) $total['newc']++;
            $total['ltv'] += (float) ($life[$id] ?? 0);
            if ($ti === null) {
                foreach ($combos($id, null) as [$k, $l]) {
                    $this->add($groups, array_values($k), array_values($l), ['custs' => 1, 'newc' => $isNew ? 1 : 0, 'ltv' => (float) ($life[$id] ?? 0)]);
                }
            } elseif ($isNew) {
                foreach ($combos($id, $bucketOf($c['created'])) as [$k, $l]) {
                    $this->add($groups, array_values($k), array_values($l), ['newc' => 1]);
                }
            }
        }

        foreach ($sales as $s) {
            $id = (string) $s->customer_id;
            if (!isset($cust[$id])) continue;
            $total['tickets'] += (float) $s->tickets;
            $total['spend'] += (float) $s->spend;
            $tActive[$id] = true;
            foreach ($combos($id, $bucketOf(substr((string) $s->sale_date, 0, 10))) as [$k, $l]) {
                $gk = implode("\x1f", $k);
                $this->add($groups, array_values($k), array_values($l), ['tickets' => (float) $s->tickets, 'spend' => (float) $s->spend]);
                $active[$gk][$id] = true;
            }
        }

        foreach ($msgs as $m) {
            $id = (string) $m->customer_id;
            if (!isset($cust[$id])) continue;
            [$date] = $this->p->localFromUtcHour((string) $m->h);
            if ($date < $fromS || $date > $toS) continue;
            $tThreads[(string) $m->thread_id] = true;
            foreach ($combos($id, $bucketOf($date)) as [$k, $l]) {
                $gk = implode("\x1f", $k);
                if (!isset($threads[$gk][(string) $m->thread_id])) {
                    $threads[$gk][(string) $m->thread_id] = true;
                    $this->add($groups, array_values($k), array_values($l), ['inbox' => 1]);
                }
            }
        }

        foreach ($groups as $gk => &$g) {
            $g['c']['active'] = isset($active[$gk]) ? count($active[$gk]) : 0;
        }
        unset($g);
        $total['active'] = count($tActive);
        $total['inbox'] = count($tThreads);

        // Over time, "Customers" is the running total at the end of each period.
        if ($ti !== null) {
            $d = $dims[$ti];
            $created = [];
            foreach ($cust as $id => $c) {
                foreach ($combos($id, '') as [$k, $l]) {
                    $sk = $k; unset($sk[$ti]);
                    $created[implode("\x1f", $sk)][] = [$c['created'], $k, $l];
                }
            }
            $bks = $this->p->buckets($d, $from, $to);
            foreach ($created as $list) {
                usort($list, fn ($a, $b) => strcmp($a[0], $b[0]));
                $i = 0; $n = count($list);
                foreach ($bks as $bk) {
                    $end = match ($d) {
                        'week'  => Carbon::parse($bk)->addDays(6)->toDateString(),
                        'month' => Carbon::parse($bk . '-01')->endOfMonth()->toDateString(),
                        default => $bk,
                    };
                    while ($i < $n && $list[$i][0] <= $end) $i++;
                    if ($i === 0) continue;
                    $k = $list[0][1]; $l = $list[0][2];
                    $k[$ti] = $bk; $l[$ti] = $this->p->label($d, $bk);
                    ksort($k); ksort($l);
                    $this->add($groups, array_values($k), array_values($l), ['custs' => $i]);
                }
            }
        }

        return ['groups' => $groups, 'total' => $total];
    }

    public function value(string $m, array $c): ?float
    {
        $has = fn ($k) => array_key_exists($k, $c);
        $c += ['custs' => 0, 'newc' => 0, 'active' => 0, 'tickets' => 0, 'spend' => 0, 'inbox' => 0];
        return match ($m) {
            'custs'  => (float) $c['custs'],
            'newc'   => (float) $c['newc'],
            'growth' => ($c['custs'] - $c['newc']) > 0 ? $c['newc'] / ($c['custs'] - $c['newc']) * 100 : null,
            'active' => (float) $c['active'],
            'spend'  => $c['spend'] / 100,
            'visits' => self::ratio($c['tickets'], $c['active']),
            'ltv'    => $has('ltv') && $c['custs'] > 0 ? $c['ltv'] / $c['custs'] / 100 : null,
            'ltvt'   => $has('ltv') ? $c['ltv'] / 100 : null,
            'inbox'  => (float) $c['inbox'],
            default  => null,
        };
    }
}
