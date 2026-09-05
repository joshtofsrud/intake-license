<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantOrder;
use App\Models\Tenant\TenantOrderItem;

/**
 * MARKER-PATCH-564 — Online Retail Wave 3: the cart.
 *
 * A cart IS a TenantOrder in status 'cart', resolved by a token held in
 * the session. Nothing here touches stock or money movement — totals are
 * recomputed sums; tax/shipping stay 0 until checkout (Wave 4) knows the
 * fulfillment context.
 */
class CartService
{
    private const SESSION_KEY = 'shop_cart_token';

    public function __construct(private Tenant $tenant) {}

    public static function forTenant(Tenant $tenant): self
    {
        return new self($tenant);
    }

    /** The session's cart, or null if none exists yet. */
    public function current(): ?TenantOrder
    {
        $token = session(self::SESSION_KEY);
        if (! $token) return null;

        return TenantOrder::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('token', $token)
            ->where('status', TenantOrder::STATUS_CART)
            ->with('items')
            ->first();
    }

    /** The session's cart, created on first need. */
    public function currentOrCreate(): TenantOrder
    {
        if ($cart = $this->current()) return $cart;

        $cart = TenantOrder::create([
            'tenant_id' => $this->tenant->id,
            'token'     => TenantOrder::newToken(),
            'status'    => TenantOrder::STATUS_CART,
        ]);
        session([self::SESSION_KEY => $cart->token]);

        return $cart->load('items');
    }

    /**
     * Add an item (or bump its quantity if already in the cart).
     * Snapshots name/image/price at add time.
     */
    public function addItem(TenantInventoryItem $item, int $qty = 1): TenantOrder
    {
        $qty  = max(1, min(99, $qty));
        $cart = $this->currentOrCreate();

        $line = $cart->items->firstWhere('inventory_item_id', $item->id);
        if ($line) {
            $line->quantity = min(99, (float) $line->quantity + $qty);
            $line->line_total_cents = (int) round($line->unit_price_cents * (float) $line->quantity);
            $line->save();
        } else {
            $images = (array) ($item->distributorCatalog?->images ?? []);
            // MARKER-QBP-IMAGES-EVERYWHERE
            $imgUrl = \App\Support\CatalogImages::urls(
                $images,
                $item->distributorCatalog?->distributor_code ?? null,
                $item->tenant_id ?? null,
                1,
            )[0] ?? null;
            $price  = (int) ($item->effectiveSellPriceCents() ?? 0);

            TenantOrderItem::create([
                'tenant_id'              => $this->tenant->id,
                'order_id'               => $cart->id,
                'inventory_item_id'      => $item->id,
                'distributor_catalog_id' => $item->distributor_catalog_id,
                'name_snapshot'          => $item->name,
                'image_snapshot'         => $imgUrl,
                'variant_snapshot'       => $item->display_subtitle ? mb_substr($item->display_subtitle, 0, 120) : null,
                'unit_price_cents'       => $price,
                'quantity'               => $qty,
                'line_total_cents'       => $price * $qty,
                'position'               => ($cart->items->max('position') ?? 0) + 1,
            ]);
        }

        return $this->recompute($cart->fresh('items'));
    }

    /** Set a line's quantity (0 removes it). */
    public function updateQty(TenantOrder $cart, string $lineId, int $qty): TenantOrder
    {
        $line = $cart->items()->where('id', $lineId)->firstOrFail();
        if ($qty <= 0) {
            $line->delete();
        } else {
            $line->quantity = min(99, $qty);
            $line->line_total_cents = (int) round($line->unit_price_cents * $line->quantity);
            $line->save();
        }
        return $this->recompute($cart->fresh('items'));
    }

    public function removeLine(TenantOrder $cart, string $lineId): TenantOrder
    {
        $cart->items()->where('id', $lineId)->delete();
        return $this->recompute($cart->fresh('items'));
    }

    /** Sum the lines; tax/shipping arrive at checkout. */
    public function recompute(TenantOrder $cart): TenantOrder
    {
        $subtotal = (int) $cart->items->sum('line_total_cents');
        $cart->forceFill([
            'subtotal_cents' => $subtotal,
            'total_cents'    => $subtotal + $cart->tax_cents + $cart->shipping_cents - $cart->discount_cents,
        ])->save();
        return $cart;
    }

    public function itemCount(): int
    {
        $cart = $this->current();
        return $cart ? (int) $cart->items->sum(fn ($i) => (float) $i->quantity) : 0;
    }
}
