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
 * MARKER-PATCH-566 — Online Retail Wave 4: checkout + confirmation.
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
            && (bool) ((tenant()->settings['storefront']['enabled'] ?? true)), // MARKER-PATCH-569
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

        $orders = OrderService::forTenant($tenant);
        $pk = (new DirectPaymentsService($tenant))->publishableKey();

        return \\App\\Services\\Tenant\\SiteChromeService::render($tenant, 'shop_checkout', [ // MARKER-PATCH-579
            'tenant'   => $tenant,
            'cart'     => $cart->load('items'),
            'quotePickup'   => $orders->quote($cart, 'pickup'),
            'quoteDelivery' => $orders->quote($cart, 'local_delivery'),
            'config'   => $orders->config(),
            'stripePk' => $pk,
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
            'fulfillment_type' => ['required', 'in:pickup,local_delivery'],
            'address'          => ['nullable', 'string', 'max:300'],
            'notes'            => ['nullable', 'string', 'max:500'],
            'wants_install'    => ['nullable', 'boolean'],
        ]);

        if ($data['fulfillment_type'] === 'local_delivery') {
            abort_unless(OrderService::forTenant($tenant)->config()['local_delivery'], 422, 'Delivery is not available.');
            if (blank($data['address'] ?? null)) {
                return response()->json(['ok' => false, 'message' => 'Delivery needs an address.'], 422);
            }
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
                'address'       => filled($data['address'] ?? null) ? ['line' => $data['address']] : null,
                'notes'         => $data['notes'] ?? null,
                'wants_install' => (bool) ($data['wants_install'] ?? false),
            ]);
        } catch (\Throwable $e) {
            Log::error('checkout.place_failed', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'message' => 'Could not start payment — try again or give us a call.'], 500);
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

        return \\App\\Services\\Tenant\\SiteChromeService::render(tenant(), 'shop_confirmation', [ // MARKER-PATCH-579
            'tenant' => tenant(),
            'order'  => $order,
        ]);
    }
}
