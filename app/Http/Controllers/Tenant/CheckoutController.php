<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantOrder;
use App\Services\Tenant\CartService;
use App\Services\Tenant\DirectPaymentsService;
use App\Services\Tenant\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Online Retail Wave 4: checkout + confirmation.
 * One staged page (contact -> fulfillment -> Payment Element), a place
 * endpoint that returns the PI client_secret, a return leg that verifies
 * with Stripe (never trusting the browser), and the /order/{token} receipt.
 */
class CheckoutController extends Controller
{
    private function guard(): void
    {
        abort_unless(
            app(\App\Services\FeatureAccessService::class)->hasAddon(tenant(), 'online_store')
            && (bool) ((tenant()->settings['storefront']['enabled'] ?? true)),
            404
        );
    }

    public function show()
    {
        $this->guard();
        $tenant = tenant();
        $cart = CartService::forTenant($tenant)->current();
        if (! $cart || $cart->items->isEmpty()) {
            return redirect('/cart');
        }

        // MARKER-ECOM-STOCK-GUARD: something sold out since it went in the cart —
        // stop here, before any payment form, and say exactly what.
        $stock = \App\Services\Tenant\OrderStock::forTenant($tenant)->inspect($cart, \App\Services\Tenant\OrderStock::MODE_CART);
        if ($stock['blocked']) {
            return redirect('/cart')->with('shop_error', \App\Exceptions\OnlineOrderStockException::summary($stock['blocked']));
        }

        $orders = OrderService::forTenant($tenant);
        $pk = (new DirectPaymentsService($tenant))->publishableKey();

        // MARKER-ORDER-SHIPMENTS: lines only a supplier can supply can't be picked up or delivered locally
        $pickupCheck = \App\Services\Tenant\OrderStock::forTenant($tenant)->inspect($cart, \App\Services\Tenant\OrderStock::MODE_PICKUP);
        $shipOnly = collect($pickupCheck['blocked'])->filter(fn ($b) => ($b['reason'] ?? null) === \App\Services\Tenant\OrderStock::REASON_SHIP_ONLY)->pluck('message')->values()->all();
        $cfgNow = $orders->config();

        return \App\Services\Tenant\SiteChromeService::render($tenant, 'shop_checkout', [
            'tenant'   => $tenant,
            'cart'     => $cart->load('items'),
            'quotePickup'   => $orders->quote($cart, 'pickup'),
            'quoteDelivery' => $orders->quote($cart, 'local_delivery'),
            'quoteShip'     => $cfgNow['ship_enabled'] ? $orders->quote($cart, 'ship') : null,
            'shipOnly'      => $shipOnly,
            'shipMode'      => \App\Services\Shipping\ShippingRates::config($tenant)['mode'], // MARKER-SHIPPING-RATES
            'taxDest'       => $cfgNow['tax_mode'] === 'stripe', // MARKER-DESTINATION-TAX
            'config'   => $orders->config(),
            'special'  => $stock['special'],
            'stripePk' => $pk,
        ]);
    }

    /**
     * POST /checkout/shipping-rates — MARKER-SHIPPING-RATES. The shipments the cart splits
     * into, with the shipping options for each to this ZIP, and the order totals under the
     * options picked so far. Nothing is saved.
     */
    public function shippingRates(Request $request)
    {
        $this->guard();
        $tenant = tenant();
        $in = $request->validate([
            'zip'       => ['required', 'regex:/^\d{5}(-\d{4})?$/'],
            'state'     => ['nullable', 'string', 'max:40'],
            'line1'     => ['nullable', 'string', 'max:120'],
            'city'      => ['nullable', 'string', 'max:80'],
            'choices'   => ['nullable', 'array'],
            'choices.*' => ['nullable', 'string', 'max:80'],
        ], ['zip.regex' => 'Please enter a 5-digit ZIP code.', 'zip.required' => 'Enter a ZIP code to see shipping options.']);

        $cart = CartService::forTenant($tenant)->current();
        if (! $cart || $cart->items->isEmpty()) {
            return response()->json(['ok' => false, 'message' => 'Your cart is empty.'], 422);
        }
        $orders = OrderService::forTenant($tenant);
        abort_unless($orders->config()['ship_enabled'], 422, 'Shipping is not available.');

        $q = $orders->quote($cart, 'ship', ['address' => ['zip' => substr($in['zip'], 0, 5), 'state' => strtoupper(trim((string) ($in['state'] ?? ''))), 'line1' => trim((string) ($in['line1'] ?? '')), 'city' => trim((string) ($in['city'] ?? ''))], 'choices' => (array) ($in['choices'] ?? [])]);
        return response()->json([
            'ok'        => true,
            'shipments' => array_map(fn ($s) => [
                'key' => $s['key'], 'source' => $s['source'], 'warehouse' => $s['warehouse'] ?? null, 'handling_days' => $s['handling_days'] ?? 0,
                'lines' => $s['lines'], 'options' => $s['options'] ?? [], 'choice' => $s['choice'] ?? null,
                'shipping_cents' => $s['shipping_cents'], 'note' => $s['rate_note'] ?? null,
                'packages' => count($s['packages'] ?? []),
            ], $q['shipments']),
            'totals'    => ['subtotal' => $q['subtotal_cents'], 'discount' => $q['discount_cents'], 'tax' => $q['tax_cents'], 'shipping' => $q['shipping_cents'], 'total' => $q['total_cents']],
        ]);
    }

    /** POST /checkout/place — cart -> pending_payment + PI; returns client_secret. */
    public function place(Request $request)
    {
        $this->guard();
        $tenant = tenant();

        $data = $request->validate([
            'first_name'       => ['required', 'string', 'max:80'],
            'last_name'        => ['required', 'string', 'max:80'],
            'email'            => ['required', 'email', 'max:160'],
            'phone'            => ['nullable', 'string', 'max:40'],
            'fulfillment_type' => ['required', 'in:pickup,local_delivery,ship'],
            'address'          => ['nullable', 'string', 'max:300'],
            'ship_line1'       => ['nullable', 'string', 'max:120'],
            'ship_line2'       => ['nullable', 'string', 'max:120'],
            'ship_city'        => ['nullable', 'string', 'max:80'],
            'ship_state'       => ['nullable', 'string', 'max:40'],
            'ship_zip'         => ['nullable', 'string', 'max:12'],
            'notes'            => ['nullable', 'string', 'max:500'],
            'wants_install'    => ['nullable', 'boolean'],
            'payment_method'   => ['nullable', 'string', 'max:40'],
            'ship_choices'     => ['nullable', 'array'],           // MARKER-SHIPPING-RATES: shipment key => option id
            'ship_choices.*'   => ['nullable', 'string', 'max:80'],
        ]);

        // manual methods: validate against the tenant's
        // enabled online methods; anything else falls through to card.
        $manualMethod = null;
        $pmKey = $data['payment_method'] ?? 'card';
        if ($pmKey !== 'card') {
            $pm = \App\Models\Tenant\TenantPaymentMethod::where('tenant_id', $tenant->id)
                ->where('method_key', $pmKey)
                ->where('enabled', true)
                ->where('kind', 'manual')
                ->first();
            abort_unless($pm && $pm->enabledOn('online') && !($pm->method_key === 'cash_app' && $pm->mode === 'stripe'), 422, 'That payment method is not available.');
            $manualMethod = $pm->method_key;
        }

        $cfg = OrderService::forTenant($tenant)->config();
        if ($data['fulfillment_type'] === 'pickup' && ! $cfg['pickup_enabled']) {
            return response()->json(['ok' => false, 'message' => 'Pickup is not available right now. Please choose delivery.'], 422);
        }
        if ($data['fulfillment_type'] === 'local_delivery') {
            abort_unless($cfg['local_delivery'], 422, 'Delivery is not available.');
            if (blank($data['address'] ?? null)) {
                return response()->json(['ok' => false, 'message' => 'Delivery needs an address.'], 422);
            }
            // MARKER-ECOM-SETTINGS: only deliver inside the ZIP codes the shop listed
            if ($cfg['delivery_zips']) {
                $zip = OrderService::zipFromAddress($data['address']);
                if ($zip === null || ! in_array($zip, $cfg['delivery_zips'], true)) {
                    return response()->json(['ok' => false, 'message' => $zip === null
                        ? 'Please include your ZIP code in the delivery address.'
                        : "Sorry, we don't deliver to {$zip}. Choose a different address" . ($cfg['pickup_enabled'] ? ', or pick it up in store.' : '.')], 422);
                }
            }
        }

        // MARKER-ORDER-SHIPMENTS: shipping needs a full street address
        $shipAddress = null;
        if ($data['fulfillment_type'] === 'ship') {
            abort_unless($cfg['ship_enabled'], 422, 'Shipping is not available.');
            foreach (['ship_line1' => 'street address', 'ship_city' => 'city', 'ship_state' => 'state', 'ship_zip' => 'ZIP code'] as $k => $label) {
                if (blank($data[$k] ?? null)) {
                    return response()->json(['ok' => false, 'message' => "Shipping needs a {$label}."], 422);
                }
            }
            $zip = trim((string) $data['ship_zip']);
            if (! preg_match('/^\d{5}(-\d{4})?$/', $zip)) {
                return response()->json(['ok' => false, 'message' => 'Please enter a 5-digit ZIP code.'], 422);
            }
            $line1 = trim((string) $data['ship_line1']); $line2 = trim((string) ($data['ship_line2'] ?? ''));
            $city = trim((string) $data['ship_city']); $st = strtoupper(trim((string) $data['ship_state']));
            $shipAddress = [
                'line' => trim($line1 . ($line2 !== '' ? ', ' . $line2 : '') . ", {$city}, {$st} {$zip}"),
                'line1' => $line1, 'line2' => $line2, 'city' => $city, 'state' => $st, 'zip' => $zip,
            ];
        }

        $cart = CartService::forTenant($tenant)->current();
        if (! $cart || $cart->items->isEmpty()) {
            return response()->json(['ok' => false, 'message' => 'Your cart is empty.'], 422);
        }

        try {
            [$order, $clientSecret] = OrderService::forTenant($tenant)->place($cart, [
                'first_name' => $data['first_name'],
                'last_name'  => $data['last_name'],
                'email'      => $data['email'],
                'phone'      => $data['phone'] ?? null,
            ], [
                'type'          => $data['fulfillment_type'],
                'address'       => $shipAddress ?? (filled($data['address'] ?? null) ? ['line' => $data['address']] : null),
                'notes'         => $data['notes'] ?? null,
                'wants_install' => (bool) ($data['wants_install'] ?? false),
                'ship'          => $shipAddress ? ['address' => ['zip' => substr($shipAddress['zip'], 0, 5), 'state' => $shipAddress['state'], 'line1' => $shipAddress['line1'], 'city' => $shipAddress['city']], 'choices' => (array) ($data['ship_choices'] ?? [])] : null,
            ], $manualMethod);
        } catch (\App\Exceptions\ShippingChoiceException $e) {
            // MARKER-SHIPPING-RATES: nothing was charged; the page re-fetches the options
            return response()->json(['ok' => false, 'rates' => true, 'message' => $e->getMessage()], 409);
        } catch (\App\Exceptions\OnlineOrderStockException $e) {
            // MARKER-ECOM-STOCK-GUARD: nothing was charged; tell the shopper what changed.
            return response()->json([
                'ok'       => false,
                'stock'    => true,
                'message'  => $e->getMessage(),
                'redirect' => '/cart',
            ], 409);
        } catch (\Throwable $e) {
            Log::error('checkout.place_failed', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'message' => 'Could not start payment — try again or give us a call.'], 500);
        }

        if ($manualMethod !== null) { // no Stripe leg; straight to confirmation
            return response()->json([
                'ok'       => true,
                'manual'   => true,
                'redirect' => route('tenant.order.confirmation', ['token' => $order->token]),
            ]);
        }

        return response()->json([
            'ok'            => true,
            'client_secret' => $clientSecret,
            'order_token'   => $order->token,
            'total_cents'   => $order->total_cents,
        ]);
    }

    /** GET /checkout/return — Stripe redirects here; verify then finalize. */
    public function returnLeg(Request $request)
    {
        $this->guard();
        $tenant = tenant();
        $token = (string) $request->query('order');
        $piId  = (string) $request->query('payment_intent');

        $order = TenantOrder::query()
            ->where('tenant_id', $tenant->id)
            ->where('token', $token)
            ->with('items')
            ->firstOrFail();

        if (! $order->sale_id && $piId) {
            try {
                $pi = (new DirectPaymentsService($tenant))->retrievePaymentIntent($piId);
                if ($pi->status === 'succeeded') {
                    OrderService::forTenant($tenant)->finalize($order, $pi);
                }
            } catch (\Throwable $e) {
                Log::error('checkout.return_finalize_failed', ['order' => $order->id, 'error' => $e->getMessage()]);
            }
        }

        // The cart is spent either way it went — a failed payment keeps the
        // order pending and the webhook can still finalize it later.
        session()->forget('shop_cart_token');

        return redirect('/order/' . $order->token);
    }

    /** GET /order/{token} — confirmation / status page. */
    public function confirmation(string $token)
    {
        $this->guard();
        $order = TenantOrder::query()
            ->where('tenant_id', tenant()->id)
            ->where('token', $token)
            ->with('items')
            ->firstOrFail();

        return \App\Services\Tenant\SiteChromeService::render(tenant(), 'shop_confirmation', [
            'tenant' => tenant(),
            'order'  => $order,
        ]);
    }
}

