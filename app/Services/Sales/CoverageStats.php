<?php
// MARKER-COVERAGE: the numbers behind Sales > Coverage. Lost shops are left out.

namespace App\Services\Sales;

use App\Models\SalesPlacesSearch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CoverageStats
{
    public const STAGES = ['prospect', 'verifying', 'contacted', 'demo_booked', 'demo_done', 'trial', 'won'];

    /** What the map can show; each counts shops that reached that stage or went further. */
    public const REACHED = [
        'shops'     => ['prospect', 'verifying', 'contacted', 'demo_booked', 'demo_done', 'trial', 'won'],
        'contacted' => ['contacted', 'demo_booked', 'demo_done', 'trial', 'won'],
        'demo'      => ['demo_booked', 'demo_done', 'trial', 'won'],
        'trial'     => ['trial', 'won'],
        'won'       => ['won'],
    ];

    /** Limit a prospects query to these industries ([] = all; 'none' = no industry). */
    public static function scope($q, array $ids)
    {
        if (! $ids) return $q;
        $real = array_values(array_filter($ids, fn ($i) => $i !== 'none'));
        return $q->where(function ($w) use ($real, $ids) {
            if ($real) $w->whereIn('channel_id', $real);
            if (in_array('none', $ids, true)) $w->orWhereNull('channel_id');
        });
    }

    /** state code ('??' when unknown) => stage => count */
    public static function byState(array $ids): array
    {
        $q = self::scope(DB::table('sales_prospects')->where('stage', '!=', 'lost'), $ids);
        $out = [];
        foreach ($q->selectRaw('state, stage, COUNT(*) AS n')->groupBy('state', 'stage')->get() as $r) {
            $st = ShopListImporter::stateCode((string) $r->state);
            if ($st === '' || ! isset(TownCoverage::STATES[$st])) $st = '??';
            $out[$st][$r->stage] = ($out[$st][$r->stage] ?? 0) + (int) $r->n;
        }
        return $out;
    }

    /**
     * state => share (0-100) of its towns inside a Find shops search circle for any of
     * these industries. A search that came back full (Google's 60 limit) doesn't count.
     */
    public static function townsSearched(array $ids): array
    {
        return Cache::remember('sales:coverage:towns:' . md5(json_encode($ids)), 300, function () use ($ids) {
            $s = SalesPlacesSearch::query()->whereNotNull('lat');
            $real = array_values(array_filter($ids, fn ($i) => $i !== 'none'));
            if ($ids) { if (! $real) return []; $s->whereIn('channel_id', $real); } else { $s->whereNotNull('channel_id'); }
            $cap = defined(TownCoverage::class . '::CAP') ? constant(TownCoverage::class . '::CAP') : PHP_INT_MAX;
            $grid = [];
            foreach ($s->get(['lat', 'lng', 'radius_miles', 'found']) as $x) {
                if ((int) $x->found >= $cap) continue;
                $grid[(int) floor((float) $x->lat)][(int) floor((float) $x->lng)][] = [(float) $x->lat, (float) $x->lng, max(2, (int) $x->radius_miles)];
            }
            $tot = []; $cov = [];
            foreach (DB::table('sales_towns')->select(['state', 'lat', 'lng'])->cursor() as $t) {
                $tot[$t->state] = ($tot[$t->state] ?? 0) + 1;
                if (! $grid) continue;
                $la = (float) $t->lat; $lo = (float) $t->lng; $hit = false;
                for ($i = -1; $i <= 1 && ! $hit; $i++) {
                    for ($j = -1; $j <= 1 && ! $hit; $j++) {
                        foreach ($grid[(int) floor($la) + $i][(int) floor($lo) + $j] ?? [] as $c) {
                            if (TownCoverage::miles($la, $lo, $c[0], $c[1]) <= $c[2]) { $hit = true; break; }
                        }
                    }
                }
                if ($hit) $cov[$t->state] = ($cov[$t->state] ?? 0) + 1;
            }
            $out = [];
            foreach ($tot as $st => $n) $out[$st] = (int) floor(($cov[$st] ?? 0) * 100 / max(1, $n));
            return $out;
        });
    }

    /** Shops with a map location in one state: [id, shop, city, stage, lat, lng]. */
    public static function points(string $st, array $ids, int $limit = 4000): array
    {
        $q = self::scope(DB::table('sales_prospects')->where('stage', '!=', 'lost')->whereNotNull('lat')->whereNotNull('lng')
            ->whereIn('state', [$st, TownCoverage::STATES[$st] ?? $st]), $ids);
        return $q->orderByRaw("CASE stage WHEN 'won' THEN 0 WHEN 'trial' THEN 1 WHEN 'demo_done' THEN 2 WHEN 'demo_booked' THEN 3 WHEN 'contacted' THEN 4 ELSE 5 END")
            ->limit($limit)->get(['id', 'shop', 'city', 'stage', 'lat', 'lng'])
            ->map(fn ($p) => [(string) $p->id, (string) $p->shop, (string) $p->city, (string) $p->stage, (float) $p->lat, (float) $p->lng])->all();
    }

    /** Find shops search circles near one state: [lat, lng, miles, full]. */
    public static function circles(string $st, array $ids): array
    {
        $b = DB::table('sales_towns')->where('state', $st)->selectRaw('MIN(lat) a, MAX(lat) b, MIN(lng) c, MAX(lng) d')->first();
        if (! $b || $b->a === null) return [];
        $s = SalesPlacesSearch::query()->whereNotNull('lat')
            ->whereBetween('lat', [(float) $b->a - .6, (float) $b->b + .6])->whereBetween('lng', [(float) $b->c - .8, (float) $b->d + .8]);
        $real = array_values(array_filter($ids, fn ($i) => $i !== 'none'));
        if ($ids) { if (! $real) return []; $s->whereIn('channel_id', $real); } else { $s->whereNotNull('channel_id'); }
        $cap = defined(TownCoverage::class . '::CAP') ? constant(TownCoverage::class . '::CAP') : PHP_INT_MAX;
        return $s->get(['lat', 'lng', 'radius_miles', 'found'])
            ->map(fn ($x) => [(float) $x->lat, (float) $x->lng, (int) $x->radius_miles, (int) $x->found >= $cap])->all();
    }
}
