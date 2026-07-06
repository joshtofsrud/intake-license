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
        foreach ($cart->items as $line) {
            $exempt = ($line->inventoryItem?->tax_class_code ?? null) === 'exempt';
            if ($rate > 0 && ! $exempt) {
                $tax += (int) round($line->line_total_cents * ($rate / 100));
            }
        }
        $shipping = $fulfillmentType === 'local_delivery'
            ? $this->config()['delivery_fee_cents']
            : 0;

        $subtotal = (int) $cart->items->sum('line_total_cents');

        return [
            'subtotal_cents' => $subtotal,
            'tax_cents'      => $tax,
            'shipping_cents' => $shipping,
            'total_cents'    => $subtotal + $tax + $shipping,
            'tax_rate'       => $rate,
        ];
    }

    /**
     * Cart -> pending_payment order with a PaymentIntent.
     * Returns [order, client_secret].
     */
    public function place(TenantOrder $cart, array $contact, array $fulfillment): array
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
                'tax_cents'           => $quote['tax_cents'],
                'shipping_cents'      => $quote['shipping_cents'],
                'total_cents'         => $quote['total_cents'],
            ])->save();
            return $cart;
        });

        $pi = (new DirectPaymentsService($this->tenant))->createPaymentIntent(
            $order->total_cents,
            'usd',
            ['intake_order_id' => $order->id, 'intake_kind' => 'online_order'],
        );

        $order->forceFill(['stripe_payment_intent_id' => $pi->id])->save();

        return [$order, $pi->client_secret];
    }

    /**
     * Verified-succeeded PI -> sale -> paid order. Idempotent: the first
     * caller (browser return or webhook) does the work; repeats no-op.
     */
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

        return DB::transaction(function () use ($order, $pi) {
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
    }
}
