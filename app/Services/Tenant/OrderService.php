<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Online Retail Wave 4: place + finalize.
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
            'install_offer'      => (bool) ($s['install_offer'] ?? true),
            // MARKER-ECOM-STOCK-GUARD: refund automatically when a paid order can't be completed
            'auto_refund'        => (bool) ($s['auto_refund'] ?? true),
            // MARKER-ECOM-SETTINGS
            'pickup_enabled'      => (bool) ($s['pickup_enabled'] ?? true),
            'pickup_instructions' => trim((string) ($s['pickup_instructions'] ?? '')),
            'free_delivery_over_cents' => (isset($s['free_delivery_over_cents']) && $s['free_delivery_over_cents'] !== '' && $s['free_delivery_over_cents'] !== null)
                ? (int) $s['free_delivery_over_cents'] : null,
            'delivery_zips'       => self::parseZips((string) ($s['delivery_zips'] ?? '')),
            'tax_delivery_fee'    => (bool) ($s['tax_delivery_fee'] ?? false),
            'install_label'       => trim((string) ($s['install_label'] ?? '')) ?: self::DEFAULT_INSTALL_LABEL,
            'hold_hours'          => max(1, (int) ($s['hold_hours'] ?? 24)),
        ];
    }

    public const DEFAULT_INSTALL_LABEL = "I'd like this installed — we'll reach out to get it scheduled.";

    /** "99201, 99202 99203" -> ['99201','99202','99203'] (5-digit ZIPs only). */
    public static function parseZips(string $raw): array
    {
        preg_match_all('/\b\d{5}\b/', $raw, $m);
        return array_values(array_unique($m[0] ?? []));
    }

    /** MARKER-ECOM-SETTINGS: the 5-digit ZIP in a free-text address, or null. */
    public static function zipFromAddress(?string $address): ?string
    {
        if ($address !== null && preg_match_all('/\b(\d{5})(?:-\d{4})?\b/', $address, $m) && $m[1]) {
            return end($m[1]); // the ZIP is last in an address; a street number can also be 5 digits
        }
        return null;
    }

    /**
     * MARKER-ECOM-SETTINGS: what shoppers read in the store, with the defaults
     * the store has always used when the shop hasn't changed them.
     */
    public static function wording(Tenant $tenant): array
    {
        $s = (array) (($tenant->settings['storefront'] ?? []) ?: []);
        $pick = fn (string $k, string $d) => trim((string) ($s[$k] ?? '')) !== '' ? trim((string) $s[$k]) : $d;
        return [
            'stock_label'     => $pick('stock_label', 'In stock — ready for pickup'),
            'special_label'   => $pick('special_label', 'Special order — usually a few days'),
            'out_label'       => $pick('out_label', 'Out of stock'),
            'special_notice'  => $pick('special_notice', "We'll order this in for you and let you know when it arrives."),
            'intro'           => $pick('intro', 'Shop online — pickup and local delivery available.'),
            'hide_out'        => ($s['out_display'] ?? 'show') === 'hide',
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

        // a whole-cart discount reduces the taxable
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
        $subtotal = $gross;

        // MARKER-ECOM-SETTINGS: free over a threshold (judged after any discount),
        // and the fee can be taxed at the shop rate if the shop's state requires it.
        $cfg      = $this->config();
        $shipping = 0;
        if ($fulfillmentType === 'local_delivery') {
            $shipping = (int) $cfg['delivery_fee_cents'];
            if ($cfg['free_delivery_over_cents'] !== null && max(0, $subtotal - $discount) >= $cfg['free_delivery_over_cents']) {
                $shipping = 0;
            }
        }
        $shippingTaxed = $shipping > 0 && $cfg['tax_delivery_fee'] && $rate > 0;
        if ($shippingTaxed) {
            $tax += (int) round($shipping * ($rate / 100));
        }

        return [
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discount,
            'discount_code'  => $cart->discount_code,
            'tax_cents'      => $tax,
            'shipping_cents' => $shipping,
            'shipping_taxed' => $shippingTaxed,
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

        // MARKER-ECOM-STOCK-GUARD: the last look before anything is created or
        // charged. If a line can't be handed over, say so now (no quiet failing).
        $stock = OrderStock::forTenant($this->tenant)->inspect($cart);
        if ($stock['blocked']) {
            throw new \App\Exceptions\OnlineOrderStockException($stock['blocked']);
        }

        $quote = $this->quote($cart, $fulfillment['type']);

        // Existing customer by email — link now; creation waits for money.
        $customer = TenantCustomer::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('email', $contact['email'])
            ->first();

        // MARKER-SEC-AUDIT A08: $manualMethod was used inside without being passed in, and the
        // discount branch used $order, which only exists after the closure returns.
        $order = DB::transaction(function () use ($cart, $contact, $fulfillment, $quote, $customer, $manualMethod) {
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
                'discount_cents'      => $quote['discount_cents'] ?? 0,
                'tax_cents'           => $quote['tax_cents'],
                'shipping_cents'      => $quote['shipping_cents'],
                'total_cents'         => $quote['total_cents'],
                'payment_method'      => $manualMethod, // null for card
                // MARKER-ECOM-SETTINGS: remember whether delivery was taxed, so the sale matches what was charged even if the setting changes before payment lands
                'metadata'            => array_merge((array) ($cart->metadata ?? []), ['delivery_taxed' => (bool) ($quote['shipping_taxed'] ?? false)]),
            ])->save();

            // redeem here, inside the same
            // transaction as the order. A code held only in a cart never
            // consumed a use; this is the moment it is actually spent, and
            // if the limit ran out in the meantime the order fails rather
            // than quietly charging full price.
            if (filled($cart->discount_code) && (int) ($cart->discount_cents ?? 0) > 0) {
                $redeem = app(\App\Services\Tenant\DiscountService::class)->redeem(
                    $this->tenant->id,
                    (string) $cart->discount_code,
                    (int) $cart->items->sum('line_total_cents'),
                    (string) $cart->id,
                    $cart->customer_id,
                    null
                );

                if (! $redeem['ok']) {
                    throw new \RuntimeException($redeem['reason'] ?? 'That discount code can no longer be used.');
                }

                $cart->forceFill([
                    'discount_code'          => (string) $cart->discount_code,
                    'discount_redemption_id' => $redeem['redemption']->id,
                ])->save();
            }

            return $cart;
        });

        // manual methods skip Stripe entirely: the order
        // stays pending_payment with instructions; staff mark it paid when
        // the money lands. No PaymentIntent, no client secret.
        if ($manualMethod !== null) {
            return [$order, null];
        }

        // if payment setup fails the order never
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
     * staff-confirmed manual payment (Venmo/Cash App/custom):
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

            $shorts = $this->shortfalls($fresh); // MARKER-ECOM-STOCK-GUARD

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
                // MARKER-ORDER-SALE-PARITY
                'discount_redemption_id' => $fresh->discount_redemption_id,
                'items'              => $this->saleLines($fresh),
            ]);

            $this->checkParity($fresh, $sale);

            $fresh->forceFill([
                'customer_id'    => $customer->id,
                'sale_id'        => $sale->id,
                'status'         => TenantOrder::STATUS_PAID,
                'payment_status' => 'paid',
                'paid_at'        => now(),
            ])->save();

            $this->createSpecialOrders($fresh, $sale, $customer, $shorts);

            return $fresh;
        });
    }

    /**
     * MARKER-ECOM-STOCK-GUARD: units an oversell-enabled item is short by, per
     * item, judged just before the sale is rung (inventory_item_id => [name, qty]).
     */
    protected function shortfalls(TenantOrder $order): array
    {
        $order->loadMissing('items');
        $out = [];
        foreach (OrderStock::forTenant($this->tenant)->inspect($order)['special'] as $lineId => $r) {
            $line = $order->items->firstWhere('id', $lineId);
            if ($line && $r['short'] > 0 && ! isset($out[$line->inventory_item_id])) {
                $out[$line->inventory_item_id] = ['name' => $line->name_snapshot, 'qty' => (int) $r['short']];
            }
        }
        return $out;
    }

    /**
     * MARKER-ECOM-STOCK-GUARD: the part of an oversold line that wasn't on the
     * shelf becomes a special order for the shop to source, tied to this sale
     * and customer. Never lets a problem here undo a paid sale.
     */
    protected function createSpecialOrders(TenantOrder $order, $sale, TenantCustomer $customer, array $shorts): void
    {
        foreach ($shorts as $itemId => $s) {
            try {
                app(SpecialOrderService::class)->create([
                    'tenant_id'          => $this->tenant->id,
                    'inventory_item_id'  => $itemId,
                    'item_name_snapshot' => $s['name'],
                    'quantity'           => $s['qty'],
                    'customer_id'        => $customer->id,
                    'sale_id'            => $sale->id,
                    'status'             => \App\Models\Tenant\TenantSpecialOrder::STATUS_NEEDED,
                    'created_from'       => 'online',
                    'notes'              => 'Online order ' . $order->order_number . ' — ' . $s['qty']
                        . ' more than we had on the shelf. Customer was told it is a special order.',
                ]);
            } catch (\Throwable $e) {
                Log::error('online_order.special_order_failed', [
                    'order' => $order->id, 'item' => $itemId, 'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * MARKER-ORDER-SALE-PARITY (audit A07): the sale an online order becomes,
     * line for line. The order's discount is spread over the product lines
     * exactly as quote() spread it (largest remainder), so each line's tax is
     * figured on the same base; delivery is its own untaxed line. The sale then
     * totals what the customer was charged, to the cent.
     */
    protected function saleLines(TenantOrder $order): array
    {
        $order->loadMissing('items');
        $gross    = (int) $order->items->sum('line_total_cents');
        $discount = max(0, min((int) ($order->discount_cents ?? 0), $gross));
        $alloc = [];
        if ($discount > 0 && $gross > 0) {
            $rem = []; $run = 0;
            foreach ($order->items as $l) {
                $exact = $l->line_total_cents * $discount / $gross;
                $alloc[$l->id] = (int) floor($exact); $rem[$l->id] = $exact - $alloc[$l->id]; $run += $alloc[$l->id];
            }
            arsort($rem); $left = $discount - $run;
            foreach (array_keys($rem) as $id) { if ($left <= 0) break; $alloc[$id]++; $left--; }
        }
        $lines = $order->items->map(fn ($l) => [
            'type'              => 'product',
            'inventory_item_id' => $l->inventory_item_id,
            'name_snapshot'     => $l->name_snapshot,
            'quantity'          => (float) $l->quantity,
            'unit_price_cents'  => $l->unit_price_cents,
            'discount_cents'    => (int) ($alloc[$l->id] ?? 0),
        ])->values()->all();
        if ((int) ($order->shipping_cents ?? 0) > 0) {
            $lines[] = [
                'type'             => 'open_item',
                'name_snapshot'    => $order->fulfillment_type === 'local_delivery' ? 'Local delivery' : 'Shipping',
                'quantity'         => 1,
                'unit_price_cents' => (int) $order->shipping_cents,
                // MARKER-ECOM-SETTINGS: taxed only if it was taxed when the order was priced
                'is_taxable'       => (bool) (((array) ($order->metadata ?? []))['delivery_taxed'] ?? false),
            ];
        }
        return $lines;
    }

    /** If the sale still doesn't total what was charged (say, a tax-exempt customer), log it for a look. */
    protected function checkParity(TenantOrder $order, $sale): void
    {
        if ((int) $sale->total_cents !== (int) $order->total_cents) {
            Log::error('online_order.sale_total_mismatch', [
                'order_id' => $order->id, 'order_number' => $order->order_number, 'sale_id' => $sale->id,
                'order_total' => (int) $order->total_cents, 'sale_total' => (int) $sale->total_cents,
                'order_tax' => (int) $order->tax_cents, 'sale_tax' => (int) $sale->tax_cents,
            ]);
        }
    }

    public function finalize(TenantOrder $order, \Stripe\PaymentIntent $pi): TenantOrder
    {
        if ($order->sale_id || $order->payment_status === 'refunded') return $order; // already finalized (or already refunded by the safety net)

        // MARKER-SEC-AUDIT A01: the payment must belong to THIS order. Its metadata
        // carries the order id we set when creating it; the id, currency and the
        // amount actually received must all match, or it settles nothing.
        $piOrder = (string) ($pi->metadata->intake_order_id ?? '');
        $piCur   = strtolower((string) ($pi->currency ?? ''));
        $piGot   = (int) ($pi->amount_received ?? $pi->amount);
        if ($piOrder === '' || ! hash_equals((string) $order->id, $piOrder) || ($piCur !== '' && $piCur !== 'usd') || $piGot !== (int) $order->total_cents) {
            Log::warning('online_order.finalize_wrong_payment', [
                'order_id' => $order->id, 'pi' => $pi->id, 'pi_order' => $piOrder,
                'stored_pi' => $order->stripe_payment_intent_id, 'currency' => $piCur, 'received' => $piGot,
            ]);
            abort(422, 'Payment not confirmed.');
        }
        if ($order->stripe_payment_intent_id && $order->stripe_payment_intent_id !== $pi->id) {
            // a retried checkout made a newer intent; this older one is still this order's money
            Log::notice('online_order.finalize_earlier_intent', ['order_id' => $order->id, 'pi' => $pi->id, 'stored_pi' => $order->stripe_payment_intent_id]);
        }

        if ($pi->status !== 'succeeded' || (int) $pi->amount !== (int) $order->total_cents) {
            Log::warning('online_order.finalize_rejected', [
                'order_id' => $order->id, 'pi' => $pi->id,
                'pi_status' => $pi->status, 'pi_amount' => $pi->amount, 'order_total' => $order->total_cents,
            ]);
            abort(422, 'Payment not confirmed.');
        }

        $shorts = [];
        try {
        $result = DB::transaction(function () use ($order, $pi, &$shorts) {
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

            // MARKER-ECOM-STOCK-GUARD: what an oversell-enabled item is short by, judged under the lock
            $shorts = $this->shortfalls($fresh);

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
                // MARKER-ORDER-SALE-PARITY: discount, delivery and tax as the customer paid them
                'discount_redemption_id'   => $fresh->discount_redemption_id,
                'items'                    => $this->saleLines($fresh),
            ]);

            $this->checkParity($fresh, $sale);

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
        } catch (\Throwable $e) {
            // MARKER-ECOM-STOCK-GUARD: the card is charged but no sale could be built.
            // Never leave that quiet: refund, tell the shopper, tell the shop.
            return $this->failedAfterPayment($order, $pi, $e);
        }

        // MARKER-ECOM-STOCK-GUARD: oversold part of a line -> special order
        if ($result->sale_id && $shorts) {
            $sale     = \App\Models\Tenant\TenantSale::find($result->sale_id);
            $customer = $result->customer_id ? TenantCustomer::find($result->customer_id) : null;
            if ($sale && $customer) {
                $this->createSpecialOrders($result, $sale, $customer, $shorts);
            }
        }

        // order confirmation email, outside the
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
                            : 'Pickup — we\'ll text you when it\'s ready'
                                . ($this->config()['pickup_instructions'] !== '' ? '. ' . e($this->config()['pickup_instructions']) : ''),
                        'order_url'        => 'https://' . request()->getHost() . '/order/' . $result->token,
                        'whats_next'       => ($shorts ? e(self::wording($this->tenant)['special_notice']) . ' ' : '')
                            . ($result->wants_install
                                ? 'You asked about installation — we\'ll be in touch to get it scheduled.'
                                : 'Questions? Just reply to this email.'),
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
    /**
     * MARKER-ECOM-STOCK-GUARD (payment safety net): a PaymentIntent succeeded but
     * the sale could not be built (most often: the last unit sold to someone else
     * a moment earlier). Under a lock, so the browser return and the webhook can't
     * both act: refund the card (unless the shop turned that off), cancel the
     * order, free any discount use, email the shopper, and put it in Issues.
     * Returns the order; never throws.
     */
    protected function failedAfterPayment(TenantOrder $order, \Stripe\PaymentIntent $pi, \Throwable $e): TenantOrder
    {
        $refunded = false;
        $attempted = false;
        $handled = false;
        $fresh = $order;

        try {
            DB::transaction(function () use ($order, $pi, &$refunded, &$attempted, &$fresh, &$handled) {
                $fresh = TenantOrder::query()->whereKey($order->id)->lockForUpdate()->first() ?? $order;
                if ($fresh->sale_id || $fresh->payment_status === 'refunded') {
                    $handled = true; // a concurrent call already finished or refunded it
                    return;
                }

                if ($this->config()['auto_refund']) {
                    $attempted = true;
                    $refund = (new DirectPaymentsService($this->tenant))
                        ->refundPaymentIntent($pi->id, 'order_could_not_be_completed');
                    $refunded = $refund !== null;
                }

                $meta = (array) ($fresh->metadata ?? []);
                $meta['payment_problem'] = [
                    'at'        => now()->toIso8601String(),
                    'pi'        => $pi->id,
                    'error'     => mb_substr($e->getMessage(), 0, 300),
                    'refunded'  => $refunded,
                ];
                $fresh->forceFill($refunded
                    ? ['status' => TenantOrder::STATUS_CANCELLED, 'payment_status' => 'refunded', 'metadata' => $meta]
                    : ['metadata' => $meta]
                )->save();

                if ($refunded && $fresh->discount_redemption_id) {
                    $r = \App\Models\Tenant\TenantDiscountRedemption::find($fresh->discount_redemption_id);
                    if ($r) {
                        app(\App\Services\Tenant\DiscountService::class)->releaseRedemption($r);
                    }
                }
            });
        } catch (\Throwable $inner) {
            Log::error('online_order.safety_net_failed', ['order' => $order->id, 'error' => $inner->getMessage()]);
        }

        if ($handled || $fresh->sale_id) {
            return $fresh; // already dealt with — don't report or email twice
        }

        $stockGone = $e instanceof \App\Exceptions\Pos\InsufficientStockException;
        $amount    = '$' . number_format($order->total_cents / 100, 2);

        // Issues: always, and in words about money.
        try {
            \App\Support\JobFailureReporter::report(
                self::class,
                ($refunded
                    ? "Online order {$order->order_number} could not be completed — {$amount} was refunded automatically"
                    : "ACTION NEEDED: online order {$order->order_number} was paid ({$amount}) but could not be completed — "
                        . ($attempted ? 'the automatic refund failed' : 'automatic refunds are off') . '; refund it in Stripe'),
                $e,
                ['order_id' => $order->id, 'order_number' => $order->order_number, 'pi' => $pi->id, 'stock' => $stockGone, 'refunded' => $refunded],
                $this->tenant->id
            );
        } catch (\Throwable $ignored) {
            Log::error('online_order.safety_net_report_failed', ['order' => $order->id, 'error' => $ignored->getMessage()]);
        }

        // The shopper: what happened, that they're whole, what to do next.
        if (filled($order->contact_email)) {
            try {
                (new \App\Services\EmailService($this->tenant))->send(
                    'order_refunded_unavailable',
                    $order->contact_email,
                    [
                        'first_name'   => $order->contact_first_name ?: 'there',
                        'order_number' => $order->order_number,
                        'total'        => $amount,
                        'what_happened' => $stockGone
                            ? 'An item in your order sold out just as you were paying, so we weren\'t able to complete it.'
                            : 'Something went wrong on our end just as you were paying, so we weren\'t able to complete your order.',
                        'money_line'   => $refunded
                            ? "We've refunded the full {$amount} to your card — it can take 5–10 business days to show up."
                            : "We're sorting out your {$amount} refund by hand and will be in touch shortly. You won't be charged for this order.",
                        'shop_name'    => $this->tenant->name,
                    ]
                );
            } catch (\Throwable $mailErr) {
                Log::error('online_order.refund_email_failed', ['order' => $order->id, 'error' => $mailErr->getMessage()]);
            }
        }

        return $fresh;
    }
}
