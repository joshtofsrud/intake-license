<?php

namespace App\Services\Sales;

/**
 * gives a Washington shop its loop (L1–L9, see
 * SalesProspect::LOOPS) from its coordinates: the nearest loop center wins.
 * Shops outside Washington, or without coordinates, get no loop.
 */
class LoopLocator
{
    /** Rough center of each loop: [lat, lng]. */
    public const CENTERS = [
        1 => [47.66, -117.42], // Spokane / Inland NW
        2 => [46.40, -118.45], // Palouse / Tri-Cities / SE WA
        3 => [47.70, -120.30], // Central WA / Wenatchee / Methow
        4 => [48.62, -122.40], // North Sound / Bellingham / Skagit / Islands
        5 => [47.62, -122.33], // Seattle Core
        6 => [47.63, -122.12], // Eastside / I-90 Corridor
        7 => [47.08, -122.75], // South Sound / Olympia
        8 => [47.65, -122.80], // Kitsap / Olympic Peninsula / Coast
        9 => [45.90, -122.60], // Southwest WA / Columbia River
    ];

    public static function forPoint(?string $state, $lat, $lng): ?int
    {
        if (strtoupper((string) $state) !== 'WA' || ! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }
        $best = null; $bestMiles = INF;
        foreach (self::CENTERS as $loop => [$la, $ln]) {
            $m = self::miles((float) $lat, (float) $lng, $la, $ln);
            if ($m < $bestMiles) { $bestMiles = $m; $best = $loop; }
        }
        return $best;
    }

    public static function miles(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 3958.8;
        $dLat = deg2rad($lat2 - $lat1); $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $r * asin(min(1, sqrt($a)));
    }
}
