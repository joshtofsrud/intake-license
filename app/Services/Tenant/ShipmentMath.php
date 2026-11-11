<?php

namespace App\Services\Tenant;

/**
 * MARKER-ORDER-SHIPMENTS: the arithmetic behind split shipments, with no database,
 * so the cart, the order and the tests all use the same numbers.
 */
class ShipmentMath
{
    /**
     * Spread a whole amount over weights, largest remainder, so the parts add up to the
     * amount exactly. Keys are kept. (Same method the discount spread has always used.)
     *
     * @param  array<int|string,int|float> $weights
     * @return array<int|string,int>
     */
    public static function allocate(int $total, array $weights): array
    {
        $sum = array_sum($weights);
        $out = array_fill_keys(array_keys($weights), 0);
        if ($total <= 0 || $sum <= 0) return $out;
        $rem = [];
        $run = 0;
        foreach ($weights as $k => $w) {
            $exact = $w * $total / $sum;
            $out[$k] = (int) floor($exact);
            $rem[$k] = $exact - $out[$k];
            $run += $out[$k];
        }
        arsort($rem);
        $left = $total - $run;
        foreach (array_keys($rem) as $k) {
            if ($left <= 0) break;
            $out[$k]++;
            $left--;
        }
        return $out;
    }

    /**
     * Shipping fee per shipment. Each shipment is priced on its own: the shop's flat fee,
     * or the supplier fee for a supplier shipment when the shop set one. Free over a
     * threshold, judged on the order after any discount, applies to every shipment.
     *
     * @param  array<string,string> $sources shipment key => 'shop'|'supplier'
     * @return array<string,int>
     */
    public static function fees(array $sources, int $baseAfterDiscount, ?int $freeOver, int $shopFee, ?int $supplierFee): array
    {
        $free = $freeOver !== null && $freeOver > 0 && $baseAfterDiscount >= $freeOver;
        $out = [];
        foreach ($sources as $key => $source) {
            $fee = $source === 'supplier' ? ($supplierFee ?? $shopFee) : $shopFee;
            $out[$key] = $free ? 0 : max(0, $fee);
        }
        return $out;
    }

    /**
     * Hand each shipment's segments back to the order lines that want them, in line order.
     *
     * @param  array<int,int>   $lineQty  lineId => units wanted on that line (one item's lines only)
     * @param  array<int,array> $segments [['key' =>, 'qty' =>, 'special' => 0|qty], ...]
     * @return array<int,array>  lineId => [['key' =>, 'qty' =>, 'special' =>], ...]
     */
    public static function distribute(array $lineQty, array $segments): array
    {
        $out = [];
        $queue = array_values($segments);
        $i = 0;
        foreach ($lineQty as $lineId => $need) {
            $need = (int) $need;
            while ($need > 0 && isset($queue[$i])) {
                $take = min($need, (int) $queue[$i]['qty']);
                if ($take > 0) {
                    $special = min($take, (int) ($queue[$i]['special'] ?? 0));
                    $out[$lineId][] = ['key' => $queue[$i]['key'], 'qty' => $take, 'special' => $special];
                    $queue[$i]['qty'] -= $take;
                    $queue[$i]['special'] = (int) ($queue[$i]['special'] ?? 0) - $special;
                    $need -= $take;
                }
                if ((int) $queue[$i]['qty'] <= 0) $i++;
            }
        }
        return $out;
    }
}
