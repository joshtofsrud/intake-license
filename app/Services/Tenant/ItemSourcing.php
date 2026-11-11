<?php

namespace App\Services\Tenant;

/**
 * MARKER-DISTRIBUTION-WAREHOUSES
 *
 * Where does a wanted quantity of one item come from? Pure arithmetic — no database —
 * so the Availability badge, the cart and the tests all use the same answer.
 *
 * Inputs
 *   $wanted      units wanted
 *   $shopHave    units on the shop's own shelf (the sale location)
 *   $offers      supplier warehouses the shop may use, already in the order to try:
 *                [['id' =>, 'name' =>, 'qty' =>], ...]  (qty = what the supplier shows)
 *   $o           ownFirst        bool  true = shop stock first, supplier fills the rest;
 *                                      false = supplier ships the whole line when it can
 *                oversell        bool  the item may be oversold
 *                oversellMode    'supplier'|'special'  (special = an oversell item never uses a supplier)
 *
 * Output
 *   segments  [['source' => 'shop'|'supplier', 'qty' =>, 'warehouse_id' =>?, 'warehouse' =>?], ...]
 *   special   units that become a special order
 *   blocked   units nobody can supply and oversell is off
 */
class ItemSourcing
{
    public static function plan(int $wanted, int $shopHave, array $offers, array $o = []): array
    {
        $wanted   = max(0, $wanted);
        $shopHave = max(0, $shopHave);
        $ownFirst = (bool) ($o['ownFirst'] ?? true);
        $oversell = (bool) ($o['oversell'] ?? false);
        $mode     = ($o['oversellMode'] ?? 'supplier') === 'special' ? 'special' : 'supplier';

        // An oversell item set to "always a special order" never reaches a supplier.
        if ($oversell && $mode === 'special') {
            $offers = [];
        }
        $offers = array_values(array_filter($offers, fn ($w) => (int) ($w['qty'] ?? 0) > 0));

        $segments = [];
        $left     = $wanted;

        $fromShop = function (int $n) use (&$segments, &$left, &$shopHave) {
            $take = min($n, $left, $shopHave);
            if ($take > 0) {
                $segments[] = ['source' => 'shop', 'qty' => $take];
                $left -= $take;
                $shopHave -= $take;
            }
        };
        $fromSuppliers = function () use (&$segments, &$left, &$offers) {
            if ($left <= 0 || ! $offers) return;
            // One warehouse that can cover everything left beats splitting across two.
            foreach ($offers as $i => $w) {
                if ((int) $w['qty'] >= $left) {
                    $segments[] = self::seg($w, $left);
                    $offers[$i]['qty'] = (int) $w['qty'] - $left;
                    $left = 0;
                    return;
                }
            }
            foreach ($offers as $i => $w) {
                if ($left <= 0) break;
                $take = min($left, (int) $w['qty']);
                if ($take > 0) {
                    $segments[] = self::seg($w, $take);
                    $offers[$i]['qty'] = (int) $w['qty'] - $take;
                    $left -= $take;
                }
            }
        };

        if ($ownFirst) {
            $fromShop($left);
            $fromSuppliers();
        } else {
            $fromSuppliers();
            $fromShop($left);
        }

        return [
            'segments' => self::merge($segments),
            'special'  => $oversell ? $left : 0,
            'blocked'  => $oversell ? 0 : $left,
        ];
    }

    /** "Where would one of these come from right now": shop | supplier | special | out, plus the warehouse. */
    public static function headline(array $plan): array
    {
        $first = $plan['segments'][0] ?? null;
        if ($first) {
            return ['kind' => $first['source'], 'warehouse' => $first['warehouse'] ?? null];
        }
        return ['kind' => $plan['special'] > 0 ? 'special' : 'out', 'warehouse' => null];
    }

    private static function seg(array $w, int $qty): array
    {
        return ['source' => 'supplier', 'qty' => $qty, 'warehouse_id' => $w['id'] ?? null, 'warehouse' => $w['name'] ?? null];
    }

    private static function merge(array $segments): array
    {
        $out = [];
        foreach ($segments as $s) {
            $k = $s['source'] . '|' . ($s['warehouse_id'] ?? '');
            if (isset($out[$k])) { $out[$k]['qty'] += $s['qty']; } else { $out[$k] = $s; }
        }
        return array_values($out);
    }
}
