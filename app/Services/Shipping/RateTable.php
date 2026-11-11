<?php

namespace App\Services\Shipping;

/** MARKER-SHIPPING-RATES: a shop's weight table — "up to N oz costs X". Pure. */
class RateTable
{
    /** @return array<int,array{upto_oz:float,cents:int}> sorted, bad rows dropped */
    public static function clean(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $oz = (float) ($r['upto_oz'] ?? 0);
            if ($oz <= 0 || ! isset($r['cents']) || $r['cents'] === '') continue;
            $out[] = ['upto_oz' => $oz, 'cents' => max(0, (int) $r['cents'])];
        }
        usort($out, fn ($a, $b) => $a['upto_oz'] <=> $b['upto_oz']);
        return $out;
    }

    /** Price for a total weight; null when it is heavier than the last row (caller falls back). */
    public static function price(array $rows, float $oz): ?int
    {
        foreach (self::clean($rows) as $r) {
            if ($oz <= $r['upto_oz'] + 0.0001) return $r['cents'];
        }
        return null;
    }
}
