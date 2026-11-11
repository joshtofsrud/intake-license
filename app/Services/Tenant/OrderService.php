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
            // MARKER-ORDER-SHIPMENTS: ship to an address, a flat fee per shipment
            'ship_enabled'        => (bool) ($s['ship_enabled'] ?? false),
            'ship_fee_cents'      => max(0, (int) ($s['ship_fee_cents'] ?? 0)),
            'ship_fee_supplier_cents' => (isset($s['ship_fee_supplier_cents']) && $s['ship_fee_supplier_cents'] !== '' && $s['ship_fee_supplier_cents'] !== null)
                ? max(0, (int) $s['ship_fee_supplier_cents']) : null,
            'ship_free_over_cents' => (isset($s['ship_free_over_cents']) && $s['ship_free_over_cents'] !== '' && $s['ship_free_over_cents'] !== null)
                ? (int) $s['ship_free_over_cents'] : null,
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
    public function quote(TenantOrder $cart, string $fulfillmentType = 'pickup', array $ship = []): array
    {
        $rate = (float) ($this->tenant->default_tax_rate ?? 0);
        $tax = 0;
        // Tax-exempt items must be skipped exactly like SaleService does,
        // or the charged amount and the sale ledger diverge per line.
        $cart->loadMissing('items.inventoryItem:id,tax_class_code');

        // MARKER-ORDER-SHIPMENTS: when the order ships, a line can split across sources (the
        // shop's shelf and a supplier). Each piece is taxed on its own, exactly as the sale
        // will carry it, so the charge and the ledger match to the cent.
        $alloc = []; $meta = [];
        if ($fulfillmentType === 'ship') {
            [$alloc, $meta] = $this->planAllocations($cart, OrderStock::MODE_SHIP);
        }
        $pieces = $this->pieces($cart, $alloc, $meta);

        // a whole-cart discount reduces the taxable base, so spread it over the pieces
        // before taxing them (largest remainder, same as SaleService).
        $gross    = (int) $cart->items->sum('line_total_cents');
        $discount = max(0, min((int) ($cart->discount_cents ?? 0), $gross));
        $spread   = ShipmentMath::allocate($discount, array_map(fn ($p) => $p['gross'], $pieces));

        foreach ($pieces as $i => $p) {
            $exempt = ($p['line']->inventoryItem?->tax_class_code ?? null) === 'exempt';
            if ($rate > 0 && ! $exempt) {
                $base = max(0, (int) $p['gross'] - (int) ($spread[$i] ?? 0));
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
        $shipments = [];
        if ($fulfillmentType === 'ship') {
            $shipments = $this->shipmentSummary($cart, $alloc, $meta, max(0, $subtotal - $discount), $cfg, $ship);
            $shipping  = (int) array_sum(array_column($shipments, 'shipping_cents'));
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
            'shipments'      => $shipments,
            'total_cents'    => max(0, $subtotal - $discount) + $tax + $shipping,
            'tax_rate'       => $rate,
        ];
    }

    /**
     * MARKER-ORDER-SHIPMENTS: where every unit of every line comes from, for an order that
     * ships. Returns [alloc, meta]:
     *   alloc  lineId => [['key', 'qty', 'special'], ...]
     *   meta   key    => ['source' => shop|supplier, 'warehouse_id', 'warehouse', 'handling_days']
     * The shop's own stock goes first (or the supplier's, if the shop turned that around);
     * units neither can cover and the shop allows oversold ride with the shop's shipment.
     */
    protected function planAllocations(TenantOrder $order, string $mode): array
    {
        $order->loadMissing('items');
        $results = OrderStock::forTenant($this->tenant)->itemResults($order, $mode);
        $cfg     = \App\Services\Distributors\WarehouseRegistry::shopConfig($this->tenant);

        $whIds = [];
        foreach ($results as $r) {
            foreach (($r['plan']['segments'] ?? []) as $sg) {
                if ($sg['source'] === 'supplier' && ! empty($sg['warehouse_id'])) $whIds[$sg['warehouse_id']] = true;
            }
        }
        $hold = $whIds
            ? \App\Models\DistributionWarehouse::query()->whereIn('id', array_keys($whIds))->pluck('handling_days', 'id')->all()
            : [];

        $meta = [];
        $alloc = [];
        foreach ($order->items->groupBy('inventory_item_id') as $itemId => $lines) {
            $plan = $results[$itemId]['plan'] ?? null;
            if (! $plan) continue;
            $segments = [];
            foreach ($plan['segments'] as $sg) {
                if ($sg['source'] === 'shop') {
                    $key = 'shop';
                    $meta[$key] ??= ['source' => 'shop', 'warehouse_id' => null, 'warehouse' => null, 'handling_days' => 0];
                } else {
                    $key = 'wh:' . $sg['warehouse_id'];
                    $meta[$key] ??= ['source' => 'supplier', 'warehouse_id' => $sg['warehouse_id'], 'warehouse' => $sg['warehouse'],
                        'handling_days' => (int) ($hold[$sg['warehouse_id']] ?? 0) + (int) $cfg['handling_days']];
                }
                $segments[] = ['key' => $key, 'qty' => (int) $sg['qty'], 'special' => 0];
            }
            if ((int) $plan['special'] > 0) {
                $meta['shop'] ??= ['source' => 'shop', 'warehouse_id' => null, 'warehouse' => null, 'handling_days' => 0];
                $segments[] = ['key' => 'shop', 'qty' => (int) $plan['special'], 'special' => (int) $plan['special']];
            }
            $lineQty = $lines->mapWithKeys(fn ($l) => [$l->id => (int) ceil((float) $l->quantity)])->all();
            foreach (ShipmentMath::distribute($lineQty, $segments) as $lineId => $parts) {
                $alloc[$lineId] = $parts;
            }
        }
        return [$alloc, $meta];
    }

    /** The shipments of a saved order, in the same shape planAllocations() returns. */
    protected function savedAllocations(TenantOrder $order): array
    {
        $order->loadMissing('shipments.items');
        $alloc = []; $meta = [];
        foreach ($order->shipments as $sh) {
            $meta[$sh->id] = ['source' => $sh->source, 'warehouse_id' => $sh->warehouse_id, 'warehouse' => $sh->warehouse_name, 'handling_days' => (int) $sh->handling_days];
            foreach ($sh->items as $it) {
                $alloc[$it->order_item_id][] = ['key' => $sh->id, 'qty' => (int) $it->quantity, 'special' => (int) $it->special_quantity];
            }
        }
        return [$alloc, $meta];
    }

    /**
     * What gets taxed and rung up: one piece per order line, or several when a line is
     * split across shipments. [['line', 'qty', 'gross', 'key', 'meta'], ...]
     */
    protected function pieces(TenantOrder $order, array $alloc = [], array $meta = []): array
    {
        $order->loadMissing('items');
        $out = [];
        foreach ($order->items as $l) {
            $parts = $alloc[$l->id] ?? [];
            if (count($parts) <= 1) {
                $k = $parts[0]['key'] ?? null;
                $out[] = ['line' => $l, 'qty' => (float) $l->quantity, 'gross' => (int) $l->line_total_cents, 'key' => $k, 'meta' => $k !== null ? ($meta[$k] ?? null) : null];
                continue;
            }
            foreach ($parts as $pt) {
                $out[] = ['line' => $l, 'qty' => (float) $pt['qty'], 'gross' => (int) round($l->unit_price_cents * $pt['qty']), 'key' => $pt['key'], 'meta' => $meta[$pt['key']] ?? null];
            }
        }
        return $out;
    }

    /** One entry per shipment, priced on its own: what the shopper is shown and what is saved. */
    protected function shipmentSummary(TenantOrder $order, array $alloc, array $meta, int $baseAfterDiscount, array $cfg, array $ship = []): array
    {
        $order->loadMissing('items');
        $byId = $order->items->keyBy('id');
        $lines = [];
        foreach ($alloc as $lineId => $parts) {
            foreach ($parts as $pt) {
                $lines[$pt['key']][] = ['line_id' => $lineId, 'name' => $byId[$lineId]->name_snapshot ?? '', 'qty' => (int) $pt['qty'], 'special' => (int) $pt['special']];
            }
        }
        $fees = ShipmentMath::fees(array_map(fn ($m) => $m['source'], $meta), $baseAfterDiscount,
            $cfg['ship_free_over_cents'], $cfg['ship_fee_cents'], $cfg['ship_fee_supplier_cents']);

        $out = [];
        // the shop's own shipment first, then suppliers
        uksort($meta, fn ($a, $b) => [$a !== 'shop', $a] <=> [$b !== 'shop', $b]);
        $free = $cfg['ship_free_over_cents'] !== null && $baseAfterDiscount >= $cfg['ship_free_over_cents'];
        foreach ($meta as $key => $m) {
            $row = $m + ['key' => $key, 'shipping_cents' => (int) ($fees[$key] ?? 0), 'lines' => $lines[$key] ?? []];
            $out[] = $this->rateShipment($row, $free, (int) ($fees[$key] ?? 0), $ship);
        }
        return $out;
    }

    /**
     * MARKER-SHIPPING-RATES: price one shipment from its packed weight and size. Adds
     * options (what the shopper may pick), the choice, and the cost of that choice.
     * With $ship['strict'] (placing the order) a missing or stale choice is refused, so
     * the charge is always for an option the shopper actually saw.
     */
    protected function rateShipment(array $row, bool $free, int $flatCents, array $ship): array
    {
        $ids = array_unique(array_column($row['lines'], 'line_id'));
        $lineItems = \App\Models\Tenant\TenantOrderItem::query()->whereIn('id', $ids)->pluck('inventory_item_id', 'id');
        $items = \App\Models\Tenant\TenantInventoryItem::query()->with('distributorCatalog')
            ->whereIn('id', $lineItems->filter()->unique()->values())->get()->keyBy('id');
        $platform = (array) (\App\Models\PlatformSettings::current()->distribution_settings ?? []);

        $units = [];
        foreach ($row['lines'] as $ln) {
            $it = $items[$lineItems[$ln['line_id']] ?? null] ?? null;
            $spec = $it ? \App\Services\Shipping\ItemShipSpec::for($it, $platform) : ['oz' => null, 'l' => null, 'w' => null, 'h' => null];
            for ($i = 0; $i < min(500, max(1, (int) $ln['qty'])); $i++) {
                $units[] = ['oz' => $spec['oz'], 'l' => $spec['l'], 'w' => $spec['w'], 'h' => $spec['h']];
            }
        }

        $from = $row['source'] === 'supplier'
            ? (string) (\App\Models\DistributionWarehouse::query()->whereKey($row['warehouse_id'])->value('ship_from_zip') ?? '')
            : (string) (\App\Services\Shipping\ShippingRates::config($this->tenant)['from_zip'] ?? '');

        $q = \App\Services\Shipping\ShippingRates::quote($this->tenant, ['from_zip' => $from, 'units' => $units],
            (array) ($ship['address'] ?? []), $flatCents, $free);

        $opts = $q['options'];
        $chosen = null;
        $want = $ship['choices'][$row['key']] ?? null;
        foreach ($opts as $o) if ($o['id'] === $want) $chosen = $o;
        if (! $chosen && count($opts) === 1) $chosen = $opts[0];
        if (! $chosen && ! empty($ship['strict'])) {
            throw new \App\Exceptions\ShippingChoiceException($q['needs_address']
                ? 'Enter the shipping address to see shipping options.'
                : 'Shipping prices changed. Please choose a shipping option again.');
        }
        $chosen ??= $opts[0] ?? null;

        return $row + [
            'options'        => $opts,
            'choice'         => $chosen['id'] ?? null,
            'service_code'   => $chosen['id'] ?? null,
            'service_name'   => $chosen['name'] ?? null,
            'rate_source'    => $chosen['source'] ?? null,
            'packages'       => $q['packages'],
            'needs_address'  => $q['needs_address'],
            'rate_note'      => $q['note'],
            'shipping_cents' => $chosen ? (int) $chosen['cents'] : 0,
        ];
    }

    /** Save the shipments chosen at placement (replacing any from an earlier try at the same order). */
    protected function persistShipments(TenantOrder $order, array $shipments): void
    {
        $existing = \App\Models\Tenant\TenantOrderShipment::query()->where('order_id', $order->id)->pluck('id');
        if ($existing->isNotEmpty()) {
            \App\Models\Tenant\TenantOrderShipmentItem::query()->whereIn('shipment_id', $existing)->delete();
            \App\Models\Tenant\TenantOrderShipment::query()->whereIn('id', $existing)->delete();
        }
        foreach ($shipments as $sh) {
            $row = \App\Models\Tenant\TenantOrderShipment::create([
                'tenant_id' => $this->tenant->id, 'order_id' => $order->id, 'source' => $sh['source'],
                'warehouse_id' => $sh['warehouse_id'], 'warehouse_name' => $sh['warehouse'],
                'handling_days' => (int) $sh['handling_days'], 'shipping_cents' => (int) $sh['shipping_cents'], 'status' => 'pending',
                'service_code' => $sh['service_code'] ?? null, 'service_name' => $sh['service_name'] ?? null,
                'rate_source' => $sh['rate_source'] ?? null, 'packages' => $sh['packages'] ?? null,
            ]);
            foreach ($sh['lines'] as $ln) {
                \App\Models\Tenant\TenantOrderShipmentItem::create([
                    'shipment_id' => $row->id, 'order_item_id' => $ln['line_id'], 'quantity' => $ln['qty'], 'special_quantity' => $ln['special'],
                ]);
            }
        }
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
        $stock = OrderStock::forTenant($this->tenant)->inspect($cart, $fulfillment['type'] === 'ship' ? OrderStock::MODE_SHIP : OrderStock::MODE_PICKUP);
        if ($stock['blocked']) {
            throw new \App\Exceptions\OnlineOrderStockException($stock['blocked']);
        }

        $quote = $this->quote($cart, $fulfillment['type'], ['address' => $fulfillment['ship']['address'] ?? [], 'choices' => $fulfillment['ship']['choices'] ?? [], 'strict' => true]);

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

            // MARKER-ORDER-SHIPMENTS: remember exactly how this order will ship, so the sale and the
            // shipments list match what was priced, even if stock moves before payment lands
            if ($fulfillment['type'] === 'ship') {
                $this->persistShipments($cart, (array) ($quote['shipments'] ?? []));
            }

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
        // MARKER-ORDER-SHIPMENTS: for an order that ships, only the shop's own shipment draws on its shelf
        if ($order->fulfillment_type === 'ship' && $order->shipments()->exists()) {
            $need = [];
            foreach ($order->shipments()->where('source', 'shop')->with('items')->get() as $sh) {
                foreach ($sh->items as $it) {
                    $line = $order->items->firstWhere('id', $it->order_item_id);
                    if (! $line) continue;
                    $need[$line->inventory_item_id] ??= ['name' => $line->name_snapshot, 'qty' => 0];
                    $need[$line->inventory_item_id]['qty'] += (int) $it->quantity;
                }
            }
            $stock = OrderStock::forTenant($this->tenant);
            $loc   = $stock->locationId($order);
            $items = \App\Models\Tenant\TenantInventoryItem::query()->where('tenant_id', $this->tenant->id)->whereIn('id', array_keys($need))->get()->keyBy('id');
            foreach ($need as $itemId => $n) {
                $short = $n['qty'] - $stock->onHand($items[$itemId], $loc);
                if ($short > 0 && ($items[$itemId]->allow_oversell ?? false)) $out[$itemId] = ['name' => $n['name'], 'qty' => $short];
            }
            return $out;
        }
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
        // MARKER-ORDER-SHIPMENTS: an order that ships is rung up piece by piece, as priced
        [$sAlloc, $sMeta] = ($order->fulfillment_type === 'ship') ? $this->savedAllocations($order) : [[], []];
        $pieces   = $this->pieces($order, $sAlloc, $sMeta);
        $gross    = (int) $order->items->sum('line_total_cents');
        $discount = max(0, min((int) ($order->discount_cents ?? 0), $gross));
        $alloc    = ShipmentMath::allocate($discount, array_map(fn ($p) => $p['gross'], $pieces));
        $lines = [];
        foreach ($pieces as $i => $p) {
            $l = $p['line'];
            $row = [
                'type'              => 'product',
                'inventory_item_id' => $l->inventory_item_id,
                'name_snapshot'     => $l->name_snapshot,
                'quantity'          => (float) $p['qty'],
                'unit_price_cents'  => $l->unit_price_cents,
                'discount_cents'    => (int) ($alloc[$i] ?? 0),
            ];
            if (($p['meta']['source'] ?? null) === 'supplier') {
                // shipped by the supplier: the shop's shelf is never touched
                $row['dropship'] = ['warehouse_id' => $p['meta']['warehouse_id'], 'warehouse' => $p['meta']['warehouse']];
            }
            $lines[] = $row;
        }
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
                        'fulfillment_line' => $result->fulfillment_type === 'ship'
                            ? 'Shipping to ' . e((string) (($result->fulfillment_address['line'] ?? '') ?: 'your address'))
                                . ($result->shipments->count() > 1 ? ' — it will arrive in ' . $result->shipments->count() . ' shipments' : '')
                            : ($result->fulfillment_type === 'local_delivery'
                            ? 'Local delivery — we\'ll reach out to set a window'
                            : 'Pickup — we\'ll text you when it\'s ready'
                                . ($this->config()['pickup_instructions'] !== '' ? '. ' . e($this->config()['pickup_instructions']) : '')),
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
