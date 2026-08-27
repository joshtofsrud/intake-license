<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MARKER-PATCH-566 — Online Retail Wave 4: place + finalize.
 *
 * place():    cart -> pending_payment order + Stripe PaymentIntent.
 * finalize(): verified PI -> customer resolved/created -> TenantSale via
 *             SaleService (the ONLY stock/ledger writer) -> order paid.
 * Both idempotent; finalize is called from the browser return AND the
 * Stripe webhook, whichever lands first wins, the other no-ops.
 */
class OrderService
{
    public function __construct(private Tenant $tenant) {}

    public static function forTenant(Tenant $tenant): self
    {
        return new self($tenant);
    }

    /** Storefront settings block with safe defaults. */
    public function config(): array
    {
        $s = (array) (($this->tenant->settings['storefront'] ?? []) ?: []);
        return [
            'local_delivery'     => (bool) ($s['local_delivery'] ?? false),
            'delivery_fee_cents' => (int) ($s['delivery_fee_cents'] ?? 0),
            'install_offer'      => (bool) ($s['install_offer'] ?? true), // MARKER-PATCH-569
        ];
    }

    /**
     * Tax + fee quote for a cart under a fulfillment choice. Per-line tax
     * rounding matches SaleService::recomputeTotals exactly so the PI amount
     * equals the sale total to the cent.
     */
    public function quote(TenantOrder $cart, string $fulfillmentType = 'pickup'): array
    {
        $rate = (float) ($this->tenant->default_tax_rate ?? 0);
        $tax = 0;
        // Tax-exempt items must be skipped exactly like SaleService does,
        // or the charged amount and the sale ledger diverge per line.
        $cart->loadMissing('items.inventoryItem:id,tax_class_code');

        // MARKER-SHOP-DISCOUNT — a whole-cart discount reduces the taxable
        // base, so spread it over the lines before taxing them. Largest
        // remainder, same as SaleService, so the parts sum to the discount
        // exactly and the Stripe charge matches the sale ledger to the cent.
        $gross    = (int) $cart->items->sum('line_total_cents');
        $discount = max(0, min((int) ($cart->discount_cents ?? 0), $gross));
        $alloc    = [];

        if ($discount > 0 && $gross > 0) {
            $rem = [];
            $run = 0;
            foreach ($cart->items as $line) {
                $exact              = $line->line_total_cents * $discount / $gross;
                $alloc[$line->id]   = (int) floor($exact);
                $rem[$line->id]     = $exact - $alloc[$line->id];
                $run               += $alloc[$line->id];
            }
            arsort($rem);
            $left = $discount - $run;
            foreach (array_keys($rem) as $lineId) {
                if ($left <= 0) break;
                $alloc[$lineId]++;
                $left--;
            }
        }

        foreach ($cart->items as $line) {
            $exempt = ($line->inventoryItem?->tax_class_code ?? null) === 'exempt';
            if ($rate > 0 && ! $exempt) {
                $base = max(0, (int) $line->line_total_cents - (int) ($alloc[$line->id] ?? 0));
                $tax += (int) round($base * ($rate / 100));
            }
        }
        $shipping = $fulfillmentType === 'local_delivery'
            ? $this->config()['delivery_fee_cents']
            : 0;

        $subtotal = $gross;

        return [
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discount, // MARKER-SHOP-DISCOUNT
            'discount_code'  => $cart->discount_code,
            'tax_cents'      => $tax,
            'shipping_cents' => $shipping,
            'total_cents'    => max(0, $subtotal - $discount) + $tax + $shipping,
            'tax_rate'       => $rate,
        ];
    }

    /**
     * Cart -> pending_payment order with a PaymentIntent.
     * Returns [order, client_secret].
     */
    public function place(TenantOrder $cart, array $contact, array $fulfillment, ?string $manualMethod = null): array
    {
        abort_if($cart->items->isEmpty(), 422, 'Cart is empty.');

        $quote = $this->quote($cart, $fulfillment['type']);

        // Existing customer by email — link now; creation waits for money.
        $customer = TenantCustomer::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('email', $contact['email'])
            ->first();

        $order = DB::transaction(function () use ($cart, $contact, $fulfillment, $quote, $customer) {
            $cart->forceFill([
                'order_number'        => $cart->order_number ?: TenantOrder::nextOrderNumber($this->tenant->id),
                'status'              => TenantOrder::STATUS_PENDING_PAYMENT,
                'customer_id'         => $customer?->id,
                'contact_first_name'  => $contact['first_name'],
                'contact_last_name'   => $contact['last_name'],
                'contact_email'       => $contact['email'],
                'contact_phone'       => $contact['phone'] ?? null,
                'fulfillment_type'    => $fulfillment['type'],
                'fulfillment_address' => $fulfillment['address'] ?? null,
                'fulfillment_notes'   => $fulfillment['notes'] ?? null,
                'wants_install'       => (bool) ($fulfillment['wants_install'] ?? false),
                'location_id'         => $this->tenant->defaultLocation?->id,
                'subtotal_cents'      => $quote['subtotal_cents'],
                'discount_cents'      => $quote['discount_cents'] ?? 0, // MARKER-SHOP-DISCOUNT
                'tax_cents'           => $quote['tax_cents'],
                'shipping_cents'      => $quote['shipping_cents'],
                'total_cents'         => $quote['total_cents'],
                'payment_method'      => $manualMethod, // MARKER-PATCH-631 — null for card
            ])->save();

            // MARKER-SHOP-DISCOUNT — redeem here, inside the same
            // transaction as the order. A code held only in a cart never
            // consumed a use; this is the moment it is actually spent, and
            // if the limit ran out in the meantime the order fails rather
            // than quietly charging full price.
            if (filled($cart->discount_code) && (int) ($cart->discount_cents ?? 0) > 0) {
                $redeem = app(\App\Services\Tenant\DiscountService::class)->redeem(
                    $this->tenant->id,
                    (string) $cart->discount_code,
                    (int) $cart->items->sum('line_total_cents'),
                    (string) $order->id,
                    $order->customer_id,
                    null
                );

                if (! $redeem['ok']) {
                    throw new \RuntimeException($redeem['reason'] ?? 'That discount code can no longer be used.');
                }

                $order->forceFill([
                    'discount_code'          => (string) $cart->discount_code,
                    'discount_redemption_id' => $redeem['redemption']->id,
                ])->save();
            }

            return $cart;
        });

        // MARKER-PATCH-631 — manual methods skip Stripe entirely: the order
        // stays pending_payment with instructions; staff mark it paid when
        // the money lands. No PaymentIntent, no client secret.
        if ($manualMethod !== null) {
            return [$order, null];
        }

        // MARKER-SHOP-DISCOUNT — if payment setup fails the order never
        // happens, so the code must not stay spent.
        try {
            $pi = (new DirectPaymentsService($this->tenant))->createPaymentIntent(
                $order->total_cents,
                'usd',
                ['intake_order_id' => $order->id, 'intake_kind' => 'online_order'],
            );
        } catch (\Throwable $e) {
            if ($order->discount_redemption_id) {
                $r = \App\Models\Tenant\TenantDiscountRedemption::find($order->discount_redemption_id);
                if ($r) {
                    app(\App\Services\Tenant\DiscountService::class)->releaseRedemption($r);
                }
            }
            throw $e;
        }

        $order->forceFill(['stripe_payment_intent_id' => $pi->id])->save();

        return [$order, $pi->client_secret];
    }

    /**
     * Verified-succeeded PI -> sale -> paid order. Idempotent: the first
     * caller (browser return or webhook) does the work; repeats no-op.
     */
    /**
     * MARKER-PATCH-631 — staff-confirmed manual payment (Venmo/Cash App/custom):
     * build the sale and mark the order paid, same shape as finalize() minus
     * the PaymentIntent. Idempotent under lock like finalize().
     */
    public function finalizeManual(TenantOrder $order, ?string $staffUserId = null): TenantOrder
    {
        abort_if($order->payment_method === null, 422, 'Not a manual-payment order.');

        return DB::transaction(function () use ($order, $staffUserId) {
            $fresh = TenantOrder::query()->whereKey($order->id)->lockForUpdate()->first();
            if ($fresh->sale_id) return $fresh;

            $customer = $fresh->customer_id
                ? TenantCustomer::find($fresh->customer_id)
                : TenantCustomer::query()
                    ->where('tenant_id', $this->tenant->id)
                    ->where('email', $fresh->contact_email)
                    ->first();
            if (! $customer) {
                $customer = TenantCustomer::create([
                    'tenant_id'  => $this->tenant->id,
                    'first_name' => $fresh->contact_first_name,
                    'last_name'  => $fresh->contact_last_name,
                    'email'      => $fresh->contact_email,
                    'phone'      => $fresh->contact_phone,
                ]);
            }

            $sale = app(SaleService::class)->createSale([
                'tenant_id'          => $this->tenant->id,
                'rang_up_by_user_id' => $staffUserId,
                'location_id'        => $fresh->location_id ?? $this->tenant->defaultLocation?->id,
                'customer_id'        => $customer->id,
                'status'             => 'completed',
                'payment_status'     => 'paid',
                'payment_method'     => $fresh->payment_method,
                'paid_at'            => now(),
                'notes'              => 'Online order ' . $fresh->order_number
                    . ' — paid by ' . tender_label($fresh->payment_method) . ', confirmed by staff'
                    . ($fresh->wants_install ? ' — customer requested installation' : ''),
                'items'              => $fresh->items->map(fn ($l) => [
                    'type'              => 'product',
                    'inventory_item_id' => $l->inventory_item_id,
                    'name'              => $l->name_snapshot,
                    'quantity'          => (float) $l->quantity,
                    'unit_price_cents'  => $l->unit_price_cents,
                ])->values()->all(),
            ]);

            $fresh->forceFill([
                'customer_id'    => $customer->id,
                'sale_id'        => $sale->id,
                'status'         => TenantOrder::STATUS_PAID,
                'payment_status' => 'paid',
                'paid_at'        => now(),
            ])->save();

            return $fresh;
        });
    }

    public function finalize(TenantOrder $order, \Stripe\PaymentIntent $pi): TenantOrder
    {
        if ($order->sale_id) return $order; // already finalized

        if ($pi->status !== 'succeeded' || (int) $pi->amount !== (int) $order->total_cents) {
            Log::warning('online_order.finalize_rejected', [
                'order_id' => $order->id, 'pi' => $pi->id,
                'pi_status' => $pi->status, 'pi_amount' => $pi->amount, 'order_total' => $order->total_cents,
            ]);
            abort(422, 'Payment not confirmed.');
        }

        $result = DB::transaction(function () use ($order, $pi) {
            // Re-check under lock so browser-return and webhook can't both build a sale.
            $fresh = TenantOrder::query()->whereKey($order->id)->lockForUpdate()->first();
            if ($fresh->sale_id) return $fresh;

            // 1) resolve or create the customer
            $customer = $fresh->customer_id
                ? TenantCustomer::find($fresh->customer_id)
                : TenantCustomer::query()
                    ->where('tenant_id', $this->tenant->id)
                    ->where('email', $fresh->contact_email)
                    ->first();
            if (! $customer) {
                $customer = TenantCustomer::create([
                    'tenant_id'  => $this->tenant->id,
                    'first_name' => $fresh->contact_first_name,
                    'last_name'  => $fresh->contact_last_name,
                    'email'      => $fresh->contact_email,
                    'phone'      => $fresh->contact_phone,
                ]);
            }

            // 2) card details off the PI
            $card = (new DirectPaymentsService($this->tenant))->extractCardDetails($pi);

            // 3) the sale — SaleService owns stock + ledger + receipts
            $sale = app(SaleService::class)->createSale([
                'tenant_id'                => $this->tenant->id,
                'rang_up_by_user_id'       => null, // online
                'location_id'              => $fresh->location_id ?? $this->tenant->defaultLocation?->id,
                'customer_id'              => $customer->id,
                'status'                   => 'completed',
                'payment_status'           => 'paid',
                'payment_method'           => 'card',
                'stripe_payment_intent_id' => $pi->id,
                'card_brand'               => $card['brand'] ?? null,
                'card_last4'               => $card['last4'] ?? null,
                'paid_at'                  => now(),
                'notes'                    => 'Online order ' . $fresh->order_number
                    . ($fresh->wants_install ? ' — customer requested installation' : ''),
                'items'                    => $fresh->items->map(fn ($l) => [
                    'type'              => 'product',
                    'inventory_item_id' => $l->inventory_item_id,
                    'name'              => $l->name_snapshot,
                    'quantity'          => (float) $l->quantity,
                    'unit_price_cents'  => $l->unit_price_cents,
                ])->values()->all(),
            ]);

            // 4) link + transition
            $fresh->forceFill([
                'customer_id' => $customer->id,
                'sale_id'     => $sale->id,
                'status'      => TenantOrder::STATUS_PAID,
                'payment_status' => 'paid',
                'card_brand'  => $card['brand'] ?? null,
                'card_last4'  => $card['last4'] ?? null,
                'paid_at'     => now(),
            ])->save();

            return $fresh;
        });

        // MARKER-PATCH-574 — order confirmation email, outside the
        // transaction so mail latency/failures never touch the money path.
        if ($result->sale_id && filled($result->contact_email)) {
            try {
                $rows = $result->items->map(fn ($l) =>
                    "<tr><td style='padding:3px 14px 3px 0'>" . e($l->name_snapshot)
                    . " ×" . (int) $l->quantity . "</td><td style='text-align:right'>$"
                    . number_format($l->line_total_cents / 100, 2) . "</td></tr>"
                )->implode('');
                (new \App\Services\EmailService($this->tenant))->send(
                    'order_confirmation',
                    $result->contact_email,
                    [
                        'first_name'       => $result->contact_first_name ?: 'there',
                        'order_number'     => $result->order_number,
                        'total'            => '$' . number_format($result->total_cents / 100, 2),
                        'items_rows'       => "<table style='font-size:14px'>{$rows}</table>",
                        'fulfillment_line' => $result->fulfillment_type === 'local_delivery'
                            ? 'Local delivery — we\'ll reach out to set a window'
                            : 'Pickup — we\'ll text you when it\'s ready',
                        'order_url'        => 'https://' . request()->getHost() . '/order/' . $result->token,
                        'whats_next'       => $result->wants_install
                            ? 'You asked about installation — we\'ll be in touch to get it scheduled.'
                            : 'Questions? Just reply to this email.',
                    ]
                );
            } catch (\Throwable $e) {
                Log::error('online_order.confirmation_email_failed', [
                    'order' => $result->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }
}

