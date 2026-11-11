<?php

namespace App\Services\Shipping;

/**
 * MARKER-SHIPPING-RATES: put the units of one shipment into boxes. Pure arithmetic.
 *
 * Unit   ['oz' => float, 'l' => ?float, 'w' => ?float, 'h' => ?float]   one physical piece
 * Box    ['name' =>, 'l' =>, 'w' =>, 'h' =>, 'max_oz' =>, 'tare_oz' =>]  a box the shop ships in
 *
 * A piece goes into the first open package it fits (by weight, by volume at FILL, and
 * with every side inside the box); otherwise it opens a new package in the smallest
 * box that holds it. A piece with no size is packed by weight alone. With no boxes set,
 * packing is by weight alone up to MAX_OZ per package.
 *
 * Output  [['box' => name|null, 'oz' => total incl. tare, 'l','w','h' => ?float], ...]
 */
class PackageMath
{
    public const FILL   = 0.85;   // share of a box's volume that can really be used
    public const MAX_OZ = 1120;   // 70 lb, the usual single-package carrier limit

    public static function pack(array $units, array $boxes, float $defaultOz = 16.0): array
    {
        $boxes = self::cleanBoxes($boxes);
        $units = array_map(fn ($u) => self::norm($u, $defaultOz), $units);
        // biggest first, so small pieces fill the gaps
        usort($units, fn ($a, $b) => self::vol($b) <=> self::vol($a) ?: $b['oz'] <=> $a['oz']);

        $pk = [];
        foreach ($units as $u) {
            $placed = false;
            foreach ($pk as &$p) {
                if (self::tryAdd($p, $u, $boxes)) { $placed = true; break; }
            }
            unset($p);
            if ($placed) continue;

            $p = ['pieces' => [], 'oz' => 0.0, 'vol' => 0.0, 'box' => null];
            if (! self::tryAdd($p, $u, $boxes)) {
                // nothing holds it: ship it alone, as it is
                $p['pieces'][] = $u; $p['oz'] += $u['oz']; $p['vol'] += self::vol($u);
            }
            $pk[] = $p;
        }

        return array_map(function ($p) {
            $b = $p['box'];
            return [
                'box' => $b['name'] ?? null,
                'oz'  => round($p['oz'] + ($b['tare_oz'] ?? 0), 2),
                'l'   => $b['l'] ?? self::loneDim($p, 'l'),
                'w'   => $b['w'] ?? self::loneDim($p, 'w'),
                'h'   => $b['h'] ?? self::loneDim($p, 'h'),
            ];
        }, $pk);
    }

    public static function cleanBoxes(array $boxes): array
    {
        $out = [];
        foreach ($boxes as $b) {
            $d = [(float) ($b['l'] ?? 0), (float) ($b['w'] ?? 0), (float) ($b['h'] ?? 0)];
            if (min($d) <= 0) continue;
            $out[] = [
                'name' => trim((string) ($b['name'] ?? '')) ?: 'Box',
                'l' => $d[0], 'w' => $d[1], 'h' => $d[2],
                'max_oz'  => (float) ($b['max_oz'] ?? 0) > 0 ? (float) $b['max_oz'] : self::MAX_OZ,
                'tare_oz' => max(0.0, (float) ($b['tare_oz'] ?? 0)),
            ];
        }
        usort($out, fn ($a, $b) => $a['l'] * $a['w'] * $a['h'] <=> $b['l'] * $b['w'] * $b['h']);
        return $out;
    }

    private static function norm(array $u, float $defaultOz): array
    {
        $oz = (float) ($u['oz'] ?? 0);
        $dims = [(float) ($u['l'] ?? 0), (float) ($u['w'] ?? 0), (float) ($u['h'] ?? 0)];
        $known = min($dims) > 0;
        return ['oz' => $oz > 0 ? $oz : $defaultOz, 'l' => $known ? $dims[0] : null, 'w' => $known ? $dims[1] : null, 'h' => $known ? $dims[2] : null];
    }

    private static function vol(array $u): float
    {
        return $u['l'] ? $u['l'] * $u['w'] * $u['h'] : 0.0;
    }

    private static function fits(array $u, array $box): bool
    {
        if (! $u['l']) return true;
        $a = [$u['l'], $u['w'], $u['h']]; sort($a);
        $b = [$box['l'], $box['w'], $box['h']]; sort($b);
        return $a[0] <= $b[0] && $a[1] <= $b[1] && $a[2] <= $b[2];
    }

    /** Add a piece to a package, growing to a bigger box if that is what it takes. */
    private static function tryAdd(array &$p, array $u, array $boxes): bool
    {
        $oz  = $p['oz'] + $u['oz'];
        $vol = $p['vol'] + self::vol($u);

        if (! $boxes) {
            if ($oz > self::MAX_OZ && $p['pieces']) return false;
            $p['pieces'][] = $u; $p['oz'] = $oz; $p['vol'] = $vol;
            return true;
        }
        foreach ($boxes as $b) {                       // smallest first
            if ($oz > $b['max_oz'] - $b['tare_oz']) continue;
            if ($vol > $b['l'] * $b['w'] * $b['h'] * self::FILL) continue;
            if (! self::fits($u, $b)) continue;
            foreach ($p['pieces'] as $q) if (! self::fits($q, $b)) continue 2;
            $p['pieces'][] = $u; $p['oz'] = $oz; $p['vol'] = $vol; $p['box'] = $b;
            return true;
        }
        return false;
    }

    private static function loneDim(array $p, string $k): ?float
    {
        return count($p['pieces']) === 1 ? ($p['pieces'][0][$k] ?? null) : null;
    }
}
