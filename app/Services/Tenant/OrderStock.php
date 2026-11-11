<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Services\Distributors\WarehouseRegistry;
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
    public const REASON_SHIP_ONLY   = 'ship_only';   // only a supplier has it, and this order isn't shipping

    /**
     * How an order will leave, for judging stock:
     *   pickup  the shop's own shelf only (pickup and local delivery)
     *   cart    not chosen yet: supplier stock counts when the shop offers shipping
     *   ship    shipping to an address: supplier stock counts when the shop allows it
     */
    public const MODE_PICKUP = 'pickup';
    public const MODE_CART   = 'cart';
    public const MODE_SHIP   = 'ship';

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

    /** May supplier stock be used for an order leaving this way? */
    public function supplierUsable(string $mode): bool
    {
        if ($mode === self::MODE_PICKUP) return false;
        if (WarehouseRegistry::access($this->tenant) !== 'on') return false;
        if (! WarehouseRegistry::shopConfig($this->tenant)['dropship_on']) return false;
        return $mode === self::MODE_SHIP || (bool) OrderService::forTenant($this->tenant)->config()['ship_enabled'];
    }

    /** Could a shipped order use supplier stock? (Used to say "ships only" instead of "sold out".) */
    private function shippingCouldUseSupplier(): bool
    {
        return $this->supplierUsable(self::MODE_CART);
    }

    /** @param string[] $itemIds @return array<string,array> itemId => warehouses to try, in order */
    public function offers(array $itemIds, string $mode): array
    {
        if (! $itemIds || ! $this->supplierUsable($mode)) return [];
        $out = [];
        foreach (WarehouseRegistry::forItems($this->tenant, $itemIds) as $id => $m) {
            if (! empty($m['offers'])) $out[$id] = $m['offers'];
        }
        return $out;
    }

    /** Items (by id) that a shopper could have shipped from a supplier even with none on the shelf. */
    public function supplierBacked(array $itemIds): array
    {
        $o = $this->offers($itemIds, self::MODE_CART);
        return array_fill_keys(array_keys($o), true);
    }

    /**
     * Check one wanted quantity. Returns
     *   ['status' => ok|special|blocked, 'reason' => ?string, 'have' => int, 'wanted' => int, 'short' => int,
     *    'plan' => ItemSourcing result]
     * `short` is how many units would have to be special-ordered; `have` counts the shelf
     * plus any supplier stock this order may use.
     *
     * @param array|null $offers supplier warehouses for this item (null = look them up)
     */
    public function checkItem(?TenantInventoryItem $item, int $wanted, ?string $locationId = null, string $mode = self::MODE_PICKUP, ?array $offers = null): array
    {
        if (! $this->offered($item)) {
            return ['status' => 'blocked', 'reason' => self::REASON_UNAVAILABLE, 'have' => 0, 'wanted' => $wanted, 'short' => 0, 'plan' => null];
        }

        $shelf = $this->onHand($item, $locationId);
        $offers ??= $this->offers([$item->id], $mode)[$item->id] ?? [];
        $cfg   = WarehouseRegistry::shopConfig($this->tenant);
        $opts  = ['ownFirst' => $cfg['own_first'], 'oversell' => (bool) $item->allow_oversell, 'oversellMode' => $cfg['oversell_mode']];
        $plan  = ItemSourcing::plan($wanted, $shelf, $offers, $opts);

        $have = $shelf + array_sum(array_column($offers, 'qty'));
        if ($plan['blocked'] === 0 && $plan['special'] === 0) {
            return ['status' => 'ok', 'reason' => null, 'have' => $have, 'wanted' => $wanted, 'short' => 0, 'plan' => $plan];
        }
        if ($plan['special'] > 0) {
            return ['status' => 'special', 'reason' => null, 'have' => $have, 'wanted' => $wanted, 'short' => $plan['special'], 'plan' => $plan];
        }

        // Blocked. If only a supplier could cover it and this order isn't shipping, say that.
        $reason = $have <= 0 ? self::REASON_SOLD_OUT : self::REASON_SHORT;
        if ($mode === self::MODE_PICKUP && $this->shippingCouldUseSupplier()) {
            $lenient = $this->offers([$item->id], self::MODE_CART)[$item->id] ?? [];
            if ($lenient && ItemSourcing::plan($wanted, $shelf, $lenient, $opts)['blocked'] === 0) {
                $reason = self::REASON_SHIP_ONLY;
            }
        }

        return ['status' => 'blocked', 'reason' => $reason, 'have' => $have, 'wanted' => $wanted, 'short' => 0, 'plan' => $plan];
    }

    /** Shopper-facing sentence for a blocked result. */
    public static function message(string $name, array $r): string
    {
        return match ($r['reason'] ?? null) {
            self::REASON_UNAVAILABLE => "{$name} is no longer available online. Remove it from your cart to continue.",
            self::REASON_SOLD_OUT    => "{$name} just sold out, so we can't sell it to you right now. Remove it from your cart to continue.",
            self::REASON_SHORT       => "We only have {$r['have']} of {$name} left, and you have {$r['wanted']} in your cart. Lower the quantity to {$r['have']} (or remove it) to continue.",
            self::REASON_SHIP_ONLY   => "{$name} can only be shipped to you, not picked up or delivered. Choose shipping, or remove it from your cart to continue.",
            default                  => "{$name} isn't available in that quantity.",
        };
    }

    /**
     * Per item (judged across all its lines together): the check result, with the sourcing plan.
     * @return array<string,array> inventory_item_id => checkItem result
     */
    public function itemResults(TenantOrder $order, string $mode = self::MODE_PICKUP): array
    {
        $order->loadMissing('items');
        $loc  = $this->locationId($order);
        $ids  = $order->items->pluck('inventory_item_id')->filter()->unique()->all();
        $byId = TenantInventoryItem::query()
            ->where('tenant_id', $this->tenant->id)
            ->whereIn('id', $ids)
            ->get()->keyBy('id');

        $wanted = [];
        foreach ($order->items as $l) {
            $wanted[$l->inventory_item_id] = ($wanted[$l->inventory_item_id] ?? 0) + (int) ceil((float) $l->quantity);
        }

        $offers = $this->offers($ids, $mode);
        $out = [];
        foreach ($wanted as $itemId => $qty) {
            $out[$itemId] = $this->checkItem($byId[$itemId] ?? null, $qty, $loc, $mode, $offers[$itemId] ?? []);
        }
        return $out;
    }

    /**
     * Inspect a whole cart/order.
     * Returns ['blocked' => [lineId => [...result, name, message]], 'special' => [lineId => result]].
     */
    public function inspect(TenantOrder $order, string $mode = self::MODE_PICKUP): array
    {
        $order->loadMissing('items');
        $results = $this->itemResults($order, $mode);

        $blocked = [];
        $special = [];
        foreach ($order->items as $l) {
            $r = $results[$l->inventory_item_id] ?? null;
            if (! $r) continue;
            if ($r['status'] === 'blocked') {
                $blocked[$l->id] = $r + ['name' => $l->name_snapshot, 'message' => self::message($l->name_snapshot, $r)];
            } elseif ($r['status'] === 'special') {
                $special[$l->id] = $r;
            }
        }

        // MARKER-ORDER-SHIPMENTS: where each line's units would come from (only when a supplier can be used)
        $sources = [];
        if ($this->supplierUsable($mode)) {
            foreach ($order->items->groupBy('inventory_item_id') as $itemId => $lines) {
                $plan = $results[$itemId]['plan'] ?? null;
                if (! $plan) continue;
                $segs = []; $info = [];
                foreach ($plan['segments'] as $sg) {
                    $k = $sg['source'] . ':' . ($sg['warehouse_id'] ?? '');
                    $segs[] = ['key' => $k, 'qty' => $sg['qty'], 'special' => 0];
                    $info[$k] = ['source' => $sg['source'], 'warehouse' => $sg['warehouse'] ?? null];
                }
                if ($plan['special'] > 0) {
                    $segs[] = ['key' => 'special', 'qty' => $plan['special'], 'special' => $plan['special']];
                    $info['special'] = ['source' => 'special', 'warehouse' => null];
                }
                $lineQty = $lines->mapWithKeys(fn ($l) => [$l->id => (int) ceil((float) $l->quantity)])->all();
                foreach (ShipmentMath::distribute($lineQty, $segs) as $lineId => $parts) {
                    foreach ($parts as $pt) $sources[$lineId][] = $info[$pt['key']] + ['qty' => $pt['qty']];
                }
            }
        }

        return ['blocked' => $blocked, 'special' => $special, 'sources' => $sources];
    }
}
