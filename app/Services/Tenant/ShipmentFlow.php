<?php

namespace App\Services\Tenant;

use App\Models\Tenant\TenantOrder;

/**
 * MARKER-SHIPMENT-WORKFLOW: the rules for a shipment's life, with no database.
 *
 *   pending -> shipped -> out_for_delivery -> delivered
 *   pending / special_order -> cancelled      pending -> special_order -> shipped
 */
class ShipmentFlow
{
    public const STATUSES = ['pending', 'shipped', 'out_for_delivery', 'delivered', 'cancelled', 'special_order'];

    public const LABELS = [
        'pending' => 'To ship', 'shipped' => 'Shipped', 'out_for_delivery' => 'Out for delivery',
        'delivered' => 'Delivered', 'cancelled' => 'Cancelled', 'special_order' => 'Special order',
    ];

    /** What staff can do next from a status. */
    public static function actions(string $status): array
    {
        return match ($status) {
            'pending'          => ['ship', 'special_order', 'cancel'],
            'special_order'    => ['ship', 'cancel'],
            'shipped'          => ['ship', 'out_for_delivery', 'delivered'],   // 'ship' again = fix the tracking
            'out_for_delivery' => ['delivered'],
            default            => [],
        };
    }

    public static function carriers(): array
    {
        return ['ups' => 'UPS', 'usps' => 'USPS', 'fedex' => 'FedEx', 'other' => 'Other'];
    }

    public static function trackingUrl(?string $carrier, ?string $number): ?string
    {
        $n = trim((string) $number);
        if ($n === '') return null;
        $q = rawurlencode($n);
        return match (strtolower((string) $carrier)) {
            'ups'   => "https://www.ups.com/track?tracknum={$q}",
            'usps'  => "https://tools.usps.com/go/TrackConfirmAction?tLabels={$q}",
            'fedex' => "https://www.fedex.com/fedextrack/?trknbr={$q}",
            default => null,
        };
    }

    /**
     * Where the whole order stands, from its shipments. Only ever moves forward, and only
     * for an order that is in the shipping queue.
     *
     * @param string[] $shipmentStatuses
     */
    public static function orderStatus(array $shipmentStatuses, string $current): string
    {
        if (! in_array($current, [TenantOrder::STATUS_PAID, TenantOrder::STATUS_FULFILLING, TenantOrder::STATUS_FULFILLED], true)) {
            return $current;
        }
        $live = array_values(array_filter($shipmentStatuses, fn ($s) => $s !== 'cancelled'));
        if (! $live) return $current;

        $gone = ['shipped', 'out_for_delivery', 'delivered'];
        $next = $current;
        if (count(array_diff($live, ['delivered'])) === 0)      $next = TenantOrder::STATUS_COMPLETED;
        elseif (count(array_diff($live, $gone)) === 0)          $next = TenantOrder::STATUS_FULFILLED;
        elseif (array_intersect($live, $gone))                  $next = TenantOrder::STATUS_FULFILLING;

        $rank = [TenantOrder::STATUS_PAID => 0, TenantOrder::STATUS_FULFILLING => 1, TenantOrder::STATUS_FULFILLED => 2, TenantOrder::STATUS_COMPLETED => 3];
        return ($rank[$next] ?? 0) > ($rank[$current] ?? 0) ? $next : $current;
    }
}
