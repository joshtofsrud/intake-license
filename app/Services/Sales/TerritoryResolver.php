<?php
// MARKER-SALES-FIND
// MARKER-SALES-TERRITORY2

namespace App\Services\Sales;

use App\Models\SalesProspect;
use App\Models\SalesTerritory;

class TerritoryResolver
{
    /** @var SalesTerritory[]|null */
    private static ?array $cache = null;

    public static function resolve(?string $state, ?float $lat, ?float $lng = null, ?int $loop = null): ?SalesTerritory
    {
        self::$cache ??= SalesTerritory::query()->where('is_active', true)->orderBy('priority')->orderBy('name')->get()->all();
        foreach (self::$cache as $t) {
            if ($t->matches($state, $lat, $lng, $loop)) return $t;
        }
        return null;
    }

    public static function resolveFor(SalesProspect $p): ?SalesTerritory
    {
        return self::resolve(
            $p->state,
            $p->lat !== null ? (float) $p->lat : null,
            $p->lng !== null ? (float) $p->lng : null,
            $p->loop ? (int) $p->loop : null,
        );
    }

    /** Sets territory/agency/rep on the row. Returns true when something changed. Never clears a rep that was set by hand unless $force. */
    public static function apply(SalesProspect $p, bool $force = false): bool
    {
        $t = self::resolveFor($p);
        $changes = [];
        if ($t) {
            if ($p->territory_id !== $t->id) $changes['territory_id'] = $t->id;
            if ($force || ! $p->sales_rep_id) {
                if ($t->agency_id && $p->agency_id !== $t->agency_id)     $changes['agency_id']    = $t->agency_id;
                if ($t->sales_rep_id && $p->sales_rep_id !== $t->sales_rep_id) $changes['sales_rep_id'] = $t->sales_rep_id;
            }
        } elseif ($force && $p->territory_id) {
            $changes['territory_id'] = null;
        }
        if ($changes) { $p->update($changes); return true; }
        return false;
    }

    /** Runs the rules over every open prospect without a rep. Returns how many changed. */
    public static function applyToUnassigned(): int
    {
        self::forget();
        $n = 0;
        SalesProspect::query()->open()->whereNull('sales_rep_id')->chunkById(500, function ($rows) use (&$n) {
            foreach ($rows as $p) if (self::apply($p)) $n++;
        });
        return $n;
    }

    /** Open prospects no active territory matches. */
    public static function unmatchedCount(): int
    {
        self::forget();
        $n = 0;
        SalesProspect::query()->open()->select(['id', 'state', 'lat', 'lng', 'loop'])->chunkById(1000, function ($rows) use (&$n) {
            foreach ($rows as $p) if (! self::resolveFor($p)) $n++;
        });
        return $n;
    }

    /**
     * What saving this territory would do, before it's saved.
     * @return array{total:int, assign:int, keep:int, overlap:int}
     */
    public static function preview(SalesTerritory $t): array
    {
        $loops  = array_map('intval', (array) $t->loops);
        $states = array_map('strtoupper', (array) $t->states);
        $circle = $t->hasCircle();
        if (! $loops && ! $states && ! $circle) {
            return ['total' => 0, 'assign' => 0, 'keep' => 0, 'overlap' => 0];
        }

        $q = SalesProspect::query()->open()->where(function ($w) use ($loops, $states, $circle, $t) {
            if ($loops)  $w->orWhereIn('loop', $loops);
            if ($states) $w->orWhereIn('state', $states);
            if ($circle) {
                $dLat = $t->radius_miles / 69.0;
                $dLng = $t->radius_miles / max(1.0, 69.0 * cos(deg2rad((float) $t->center_lat)));
                $w->orWhere(fn ($b) => $b
                    ->whereBetween('lat', [(float) $t->center_lat - $dLat, (float) $t->center_lat + $dLat])
                    ->whereBetween('lng', [(float) $t->center_lng - $dLng, (float) $t->center_lng + $dLng]));
            }
        });

        $others = SalesTerritory::query()->where('is_active', true)
            ->when($t->exists, fn ($w) => $w->where('id', '!=', $t->id))
            ->where('priority', '<=', (int) ($t->priority ?? 100))
            ->get();

        $out = ['total' => 0, 'assign' => 0, 'keep' => 0, 'overlap' => 0];
        foreach ($q->get(['id', 'state', 'lat', 'lng', 'loop', 'sales_rep_id']) as $p) {
            $lat = $p->lat !== null ? (float) $p->lat : null;
            $lng = $p->lng !== null ? (float) $p->lng : null;
            $loop = $p->loop ? (int) $p->loop : null;
            if (! $t->matches($p->state, $lat, $lng, $loop)) continue;
            $out['total']++;
            if ($others->contains(fn ($o) => $o->matches($p->state, $lat, $lng, $loop))) { $out['overlap']++; continue; }
            $p->sales_rep_id ? $out['keep']++ : $out['assign']++;
        }
        return $out;
    }

    public static function forget(): void { self::$cache = null; }
}
