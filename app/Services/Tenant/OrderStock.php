<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantInventoryItemLocation;
use App\Models\Tenant\TenantOrder;

/**
 * MARKER-ECOM-STOCK-GUARD
 *
 * One answer to "can the shop actually hand this over?" for the online store.
 * Cart add, cart quantity change, the checkout page and the moment before a
 * PaymentIntent is created all ask here, so a shopper is told BEFORE paying
 * when something is gone — never after.
 *
 * Counts against the same location the sale will be rung at, because that is
 * the stock InventoryService checks. A line is:
 *   - ok        : enough on hand
 *   - special   : not enough, but the item is set to oversell → becomes a special order
 *   - blocked   : not enough (or no longer sold online) and oversell is off
 */
class OrderStock
{
    public const REASON_UNAVAILABLE = 'unavailable'; // hidden, inactive or deleted
    public const REASON_SOLD_OUT    = 'sold_out';    // none left
    public const REASON_SHORT       = 'short';       // some left, fewer than wanted

    public function __construct(private Tenant $tenant) {}

    public static function forTenant(Tenant $tenant): self
    {
        return new self($tenant);
    }

    public function locationId(?TenantOrder $order = null): ?string
    {
        return $order?->location_id ?? $this->tenant->defaultLocation?->id;
    }

    /** Units on the shelf at the sale location (never negative). */
    public function onHand(TenantInventoryItem $item, ?string $locationId = null): int
    {
        $locationId = $locationId ?? $this->locationId();
        if ($locationId) {
            $row = TenantInventoryItemLocation::query()
                ->where('tenant_id', $this->tenant->id)
                ->where('inventory_item_id', $item->id)
                ->where('location_id', $locationId)
                ->first();

            return max(0, (int) ($row?->computed_stock_count ?? 0));
        }

        return max(0, (int) $item->computed_stock_count);
    }

    /** Is the item still offered online at all? */
    public function offered(?TenantInventoryItem $item): bool
    {
        return $item !== null && (bool) $item->is_active && (bool) $item->show_online;
    }

    /**
     * Check one wanted quantity. Returns
     *   ['status' => ok|special|blocked, 'reason' => ?string, 'have' => int, 'wanted' => int, 'short' => int]
     * `short` is how many units would have to be special-ordered.
     */
    public function checkItem(?TenantInventoryItem $item, int $wanted, ?string $locationId = null): array
    {
        if (! $this->offered($item)) {
            return ['status' => 'blocked', 'reason' => self::REASON_UNAVAILABLE, 'have' => 0, 'wanted' => $wanted, 'short' => 0];
        }

        $have = $this->onHand($item, $locationId);
        if ($have >= $wanted) {
            return ['status' => 'ok', 'reason' => null, 'have' => $have, 'wanted' => $wanted, 'short' => 0];
        }
        if ($item->allow_oversell) {
            return ['status' => 'special', 'reason' => null, 'have' => $have, 'wanted' => $wanted, 'short' => $wanted - $have];
        }

        return [
            'status' => 'blocked',
            'reason' => $have <= 0 ? self::REASON_SOLD_OUT : self::REASON_SHORT,
            'have'   => $have, 'wanted' => $wanted, 'short' => 0,
        ];
    }

    /** Shopper-facing sentence for a blocked result. */
    public static function message(string $name, array $r): string
    {
        return match ($r['reason'] ?? null) {
            self::REASON_UNAVAILABLE => "{$name} is no longer available online. Remove it from your cart to continue.",
            self::REASON_SOLD_OUT    => "{$name} just sold out, so we can't sell it to you right now. Remove it from your cart to continue.",
            self::REASON_SHORT       => "We only have {$r['have']} of {$name} left, and you have {$r['wanted']} in your cart. Lower the quantity to {$r['have']} (or remove it) to continue.",
            default                  => "{$name} isn't available in that quantity.",
        };
    }

    /**
     * Inspect a whole cart/order.
     * Returns ['blocked' => [lineId => [...result, name, message]], 'special' => [lineId => result]].
     */
    public function inspect(TenantOrder $order): array
    {
        $order->loadMissing('items');
        $loc  = $this->locationId($order);
        $ids  = $order->items->pluck('inventory_item_id')->filter()->unique()->all();
        $byId = TenantInventoryItem::query()
            ->where('tenant_id', $this->tenant->id)
            ->whereIn('id', $ids)
            ->get()->keyBy('id');

        // Two lines of the same item must be judged together.
        $wantedByItem = [];
        foreach ($order->items as $l) {
            $wantedByItem[$l->inventory_item_id] = ($wantedByItem[$l->inventory_item_id] ?? 0) + (int) ceil((float) $l->quantity);
        }

        $blocked = [];
        $special = [];
        foreach ($order->items as $l) {
            $item = $byId[$l->inventory_item_id] ?? null;
            $r    = $this->checkItem($item, $wantedByItem[$l->inventory_item_id] ?? (int) ceil((float) $l->quantity), $loc);
            if ($r['status'] === 'blocked') {
                $blocked[$l->id] = $r + ['name' => $l->name_snapshot, 'message' => self::message($l->name_snapshot, $r)];
            } elseif ($r['status'] === 'special') {
                $special[$l->id] = $r;
            }
        }

        return ['blocked' => $blocked, 'special' => $special];
    }
}
