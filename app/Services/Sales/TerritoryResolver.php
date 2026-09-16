<?php
// MARKER-SALES-FIND

namespace App\Services\Sales;

use App\Models\SalesProspect;
use App\Models\SalesTerritory;

class TerritoryResolver
{
    /** @var SalesTerritory[]|null */
    private static ?array $cache = null;

    public static function resolve(?string $state, ?float $lat): ?SalesTerritory
    {
        self::$cache ??= SalesTerritory::query()->where('is_active', true)->orderBy('priority')->orderBy('name')->get()->all();
        foreach (self::$cache as $t) {
            if ($t->matches($state, $lat)) return $t;
        }
        return null;
    }

    /** Sets territory/agency/rep on the row. Returns true when something changed. Never clears a rep that was set by hand unless $force. */
    public static function apply(SalesProspect $p, bool $force = false): bool
    {
        $t = self::resolve($p->state, $p->lat !== null ? (float) $p->lat : null);
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

    public static function forget(): void { self::$cache = null; }
}
