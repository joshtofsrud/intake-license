<?php

namespace App\Services\Tenant\ReportBuilder\Sources;

use App\Services\Distributors\StockVelocityRecorder;
use App\Services\Tenant\ReportBuilder\ReportCatalog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Distributor stock — items THIS shop carries, matched to what the distributor
 * each came from has left (Distributor velocity in master admin). Never
 * another shop's data. Which figures show is set in master admin
 * (Distributor velocity > Shop visibility).
 */
class DistributorSource extends Source
{
    public function meta(): array
    {
        $v = ReportCatalog::velocity();
        $m = ['dstock' => ['In stock at distributor', 'n'], 'dmine' => ['Your stock on hand', 'n']];
        if ($v['pace'])    { $m['dpace'] = ['Pace per day', 'd']; }
        if ($v['days'])    { $m['ddays'] = ['Days left', 'd']; $m['dlow'] = ['Items under 14 days left', 'n']; }
        if ($v['arrival']) { $m['dnext'] = ['Days until next arrival', 'd']; }
        $cav = ['Pace is how fast the distributor\'s stock is dropping. It is not your sales.', 'Covers only items already in your inventory.'];
        if ($v['good']) { $cav[] = 'Items with under 3 weeks of readings show a dash for pace and days left.'; }
        if ($v['arrival']) { $cav[] = 'Some distributors do not send arrival dates; those show a dash.'; }
        $cav[] = 'Which figures appear is set by Intake.';
        return [
            'label' => 'Distributor stock',
            'note'  => 'What distributors have left of items you carry, and how fast it is going. Read from each distributor every 4 hours.',
            'snap'  => true,
            'cav'   => $cav,
            'measures' => $m,
            'dims' => ['dist' => ['Distributor', 'c'], 'brand' => ['Brand', 'c'], 'cat' => ['Category', 'c'], 'item' => ['Item', 'c'], 'hist' => ['History', 'c']],
            'filters' => ['dist' => 'Distributor', 'brand' => 'Brand', 'cat' => 'Category', 'hist' => 'History'],
        ];
    }

    public function options(string $key): array
    {
        $v = ReportCatalog::velocity();
        $tid = $this->tid();
        return match ($key) {
            'dist'  => $this->codes(),
            'brand' => Cache::remember('rb:brands:' . $tid . ':' . md5(json_encode($v['vendors'])), 600, function () use ($tid, $v) {
                $rows = DB::table('tenant_inventory_items as it')
                    ->join('platform_distributor_catalogs as pc', 'pc.id', '=', 'it.distributor_catalog_id')
                    ->join('distributor_stock_summary as s', 's.distributor_catalog_id', '=', 'it.distributor_catalog_id')
                    ->when(is_array($v['vendors']), fn ($q) => $q->whereIn('s.distributor_code', $v['vendors'] ?: ['-none-']))
                    ->where('it.tenant_id', $tid)->where('it.is_active', 1)->whereNull('it.deleted_at')
                    ->whereNotNull('pc.manufacturer')->where('pc.manufacturer', '!=', '')
                    ->distinct()->orderBy('pc.manufacturer')->pluck('pc.manufacturer')->all();
                $out = [];
                foreach ($rows as $b) { $out[mb_strtolower(trim($b))] = trim($b); }
                return $out;
            }),
            'cat'   => $this->activeCategories() + ['uncat' => 'Uncategorized'],
            'hist'  => ['good' => 'Good', 'building' => 'Building', 'early' => 'Too early'],
            default => [],
        };
    }

    private function codes(): array
    {
        $v = ReportCatalog::velocity();
        $codes = Cache::remember('rb:dist-codes', 600, fn () =>
            DB::table('distributor_stock_summary')->distinct()->orderBy('distributor_code')->pluck('distributor_code')->all());
        if (is_array($v['vendors'])) {
            $codes = array_values(array_intersect($codes, $v['vendors']));
        }
        return $codes ? array_combine($codes, $codes) : [];
    }

    public function run(array $dims, array $filters, Carbon $from, Carbon $to): array
    {
        $v = ReportCatalog::velocity();
        $f = $this->byKey($filters);
        $q = DB::table('tenant_inventory_items as it')
            ->join('distributor_stock_summary as s', 's.distributor_catalog_id', '=', 'it.distributor_catalog_id')
            ->join('platform_distributor_catalogs as pc', 'pc.id', '=', 'it.distributor_catalog_id')
            ->where('it.tenant_id', $this->tid())->where('it.is_active', 1)->whereNull('it.deleted_at')
            ->select('it.id', 'it.name as item', 'it.category_id', 'it.computed_stock_count as mine', 'pc.manufacturer as brand',
                's.distributor_code as dist', 's.avail', 's.rate_per_day', 's.next_arrival_on', 's.first_seen_at', 's.last_reading_at');
        if (is_array($v['vendors'])) {
            $q->whereIn('s.distributor_code', $v['vendors'] ?: ['-none-']);
        }
        if (isset($f['dist'])) $this->sqlFilter($q, 's.distributor_code', $f['dist']);

        $today = $this->p->today();
        $catN = $this->names('cat');
        $groups = []; $total = ['avail' => 0, 'mine' => 0, 'pace' => 0, 'paceavail' => 0, 'low' => 0, 'next' => null];
        foreach ($q->cursor() as $r) {
            $conf = StockVelocityRecorder::confidence($r);
            $brandName = trim((string) $r->brand);
            $brand = $brandName === '' ? 'none' : mb_strtolower($brandName);
            $cat = $r->category_id ? (string) $r->category_id : 'uncat';
            if (!$this->passes($conf, $f['hist'] ?? null) || !$this->passes($brand, $f['brand'] ?? null) || !$this->passes($cat, $f['cat'] ?? null)) continue;
            $usable = $r->rate_per_day !== null && (!$v['good'] || $conf === 'good') && (float) $r->rate_per_day > 0;
            $avail = max(0, (int) $r->avail);
            $c = ['avail' => $avail, 'mine' => max(0, (int) $r->mine), 'pace' => 0, 'paceavail' => 0, 'low' => 0];
            if ($usable) {
                $rate = (float) $r->rate_per_day;
                $c['pace'] = $rate; $c['paceavail'] = $avail; $c['low'] = $avail / $rate < 14 ? 1 : 0;
            }
            $next = $r->next_arrival_on ? max(0, (int) $today->diffInDays(Carbon::parse($r->next_arrival_on)->startOfDay(), false)) : null;
            $keys = []; $labels = [];
            foreach ($dims as $n => $d) {
                [$keys[$n], $labels[$n]] = match ($d) {
                    'dist'  => [(string) $r->dist, (string) $r->dist],
                    'brand' => [$brand, $brand === 'none' ? 'No brand' : $brandName],
                    'cat'   => [$cat, $cat === 'uncat' ? 'Uncategorized' : ($catN[$cat] ?? 'Removed category')],
                    'hist'  => [$conf, ['good' => 'Good', 'building' => 'Building', 'early' => 'Too early'][$conf]],
                    default => [(string) $r->id, (string) $r->item],
                };
            }
            $this->add($groups, $keys, $labels, $c);
            $gk = implode("\x1f", $keys);
            if ($next !== null) {
                $groups[$gk]['c']['next'] = isset($groups[$gk]['c']['next']) ? min($groups[$gk]['c']['next'], $next) : $next;
                $total['next'] = $total['next'] === null ? $next : min($total['next'], $next);
            }
            foreach ($c as $k => $val) $total[$k] += $val;
        }
        return ['groups' => $groups, 'total' => $total];
    }

    public function value(string $m, array $c): ?float
    {
        $pace = (float) ($c['pace'] ?? 0);
        return match ($m) {
            'dstock' => (float) ($c['avail'] ?? 0),
            'dmine'  => (float) ($c['mine'] ?? 0),
            'dpace'  => $pace > 0 ? $pace : null,
            'ddays'  => $pace > 0 ? (float) ($c['paceavail'] ?? 0) / $pace : null,
            'dlow'   => (float) ($c['low'] ?? 0),
            'dnext'  => isset($c['next']) ? (float) $c['next'] : null,
            default  => null,
        };
    }
}
