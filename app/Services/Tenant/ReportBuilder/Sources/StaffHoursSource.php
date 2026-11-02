<?php

namespace App\Services\Tenant\ReportBuilder\Sources;

use App\Services\Tenant\OvertimeCalculator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Staff hours — time clock punches. A punch counts on the day it started.
 * Overtime uses the shop's own overtime rules (OvertimeCalculator), worked
 * out per person per week, and lands on the latest punches of that week.
 * Owner and managers only.
 */
class StaffHoursSource extends Source
{
    public function meta(): array
    {
        return [
            'label' => 'Staff hours',
            'note'  => 'Time clock punches, by the day each shift started. Overtime follows your time clock overtime settings. A shift still open counts up to now.',
            'snap'  => false,
            'lock'  => 'Owner and managers only',
            'measures' => [
                'hrs'      => ['Hours worked', 'h'],
                'reg'      => ['Regular hours', 'h'],
                'ot'       => ['Overtime hours', 'h'],
                'shifts'   => ['Shifts', 'n'],
                'avgshift' => ['Average shift', 'd'],
                'sph'      => ['Sales per hour', '$'],
            ],
            'dims' => [
                'staff' => ['Staff', 'c'], 'day' => ['Day', 't'], 'week' => ['Week', 't'], 'month' => ['Month', 't'],
                'pp' => ['Pay period', 't'], 'loc' => ['Location', 'c'],
            ],
            'filters' => ['staff' => 'Staff', 'loc' => 'Location'],
        ];
    }

    public function options(string $key): array
    {
        return match ($key) {
            'staff' => $this->names('user'),
            'loc'   => $this->names('loc'),
            default => [],
        };
    }

    public function run(array $dims, array $filters, Carbon $from, Carbon $to): array
    {
        $tz = $this->p->tz();
        $f = $this->byKey($filters);
        // Read from the Monday of the first week so weekly overtime sees the whole week.
        [$a] = $this->p->utcBounds($from->copy()->startOfWeek(Carbon::MONDAY), $to);
        [, $b] = $this->p->utcBounds($from, $to->copy()->addDay());

        $rows = DB::table('tenant_time_punches')
            ->where('tenant_id', $this->tid())
            ->whereBetween('clock_in_at', [$a, $b])
            ->orderBy('clock_in_at')
            ->get(['id', 'tenant_user_id', 'location_id', 'clock_in_at', 'clock_out_at']);

        $now = Carbon::now('UTC');
        $punches = [];
        $byWeek = [];
        foreach ($rows as $r) {
            $in = Carbon::parse($r->clock_in_at, 'UTC');
            $out = $r->clock_out_at ? Carbon::parse($r->clock_out_at, 'UTC') : $now;
            $mins = max(0, (int) $in->diffInMinutes($out, false));
            $local = $in->copy()->setTimezone($tz);
            $date = $local->toDateString();
            $p = ['staff' => (string) $r->tenant_user_id, 'loc' => (string) ($r->location_id ?? ''), 'date' => $date,
                'at' => $local->timestamp, 'mins' => $mins, 'ot' => 0];
            $i = count($punches);
            $punches[] = $p;
            $wk = Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->toDateString();
            $byWeek[$p['staff'] . '|' . $wk][] = $i;
        }

        // Overtime per person per week, taken off the latest punches first.
        $calc = new OvertimeCalculator($this->tenant);
        foreach ($byWeek as $idx) {
            $days = [];
            foreach ($idx as $i) {
                $days[$punches[$i]['date']] = ($days[$punches[$i]['date']] ?? 0) + $punches[$i]['mins'];
            }
            $split = $calc->split($days);
            $left = (int) ($split['ot'] ?? 0) + (int) ($split['dt'] ?? 0);
            usort($idx, fn ($x, $y) => $punches[$y]['at'] <=> $punches[$x]['at']);
            foreach ($idx as $i) {
                if ($left <= 0) break;
                $take = min($left, $punches[$i]['mins']);
                $punches[$i]['ot'] = $take;
                $left -= $take;
            }
        }

        $groups = [];
        $total = ['mins' => 0, 'ot' => 0, 'shifts' => 0];
        $fromS = $from->toDateString(); $toS = $to->toDateString();
        foreach ($punches as $p) {
            if ($p['date'] < $fromS || $p['date'] > $toS) continue;
            if (!$this->passes($p['staff'], $f['staff'] ?? null) || !$this->passes($p['loc'], $f['loc'] ?? null)) continue;
            $keys = []; $labels = [];
            foreach ($dims as $n => $d) {
                if (in_array($d, ['day', 'week', 'month', 'pp'], true)) {
                    $keys[$n] = $this->p->key($d, $p['date']);
                    $labels[$n] = $this->p->label($d, $keys[$n]);
                } elseif ($d === 'loc') {
                    $keys[$n] = $p['loc'];
                    $labels[$n] = $p['loc'] === '' ? 'No location' : ($this->names('loc')[$p['loc']] ?? 'Removed location');
                } else {
                    $keys[$n] = $p['staff'];
                    $labels[$n] = $this->names('user')[$p['staff']] ?? 'Former staff';
                }
            }
            $c = ['mins' => $p['mins'], 'ot' => $p['ot'], 'shifts' => 1];
            $this->add($groups, $keys, $labels, $c);
            foreach ($c as $k => $v) $total[$k] += $v;
        }

        // Sales per hour: revenue split the same way (staff = who rang it up).
        $sales = new SalesSource($this->tenant, $this->p);
        $sFilters = array_values(array_filter($filters, fn ($x) => in_array($x['f'] ?? '', ['staff', 'loc'], true)));
        $sr = $sales->run($dims, $sFilters, $from, $to);
        foreach ($sr['groups'] as $gk => $g) {
            if (isset($groups[$gk])) {
                $groups[$gk]['c']['rev'] = $g['c']['rev'] ?? 0;
            }
        }
        $total['rev'] = $sr['total']['rev'] ?? 0;

        return ['groups' => $groups, 'total' => $total];
    }

    public function value(string $m, array $c): ?float
    {
        $c += ['mins' => 0, 'ot' => 0, 'shifts' => 0, 'rev' => 0];
        $hrs = $c['mins'] / 60;
        return match ($m) {
            'hrs'      => $hrs,
            'reg'      => ($c['mins'] - $c['ot']) / 60,
            'ot'       => $c['ot'] / 60,
            'shifts'   => (float) $c['shifts'],
            'avgshift' => self::ratio($hrs, $c['shifts']),
            'sph'      => $hrs > 0 ? ($c['rev'] / 100) / $hrs : null,
            default    => null,
        };
    }
}
