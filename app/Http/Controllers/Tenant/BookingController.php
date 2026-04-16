<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantAddon;
use App\Models\Tenant\TenantFormSection;
use App\Models\Tenant\TenantReceivingMethod;
use App\Models\Tenant\TenantServiceCategory;
use App\Services\BookingService;
use App\Services\PayPalService;
use App\Services\StripeService;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    // ----------------------------------------------------------------
    // GET /book — render the booking form
    // ----------------------------------------------------------------
    public function index()
    {
        $tenant = tenant();

        // Services catalog
        $catalog = TenantServiceCategory::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->with(['items' => function ($q) {
                $q->where('is_active', true)
                  ->orderBy('sort_order')
                  ->with(['tierPrices.tier', 'addons.addon']);
            }])
            ->get();

        // All active tiers (for display)
        $tiers = \App\Models\Tenant\TenantServiceTier::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        // Global add-ons
        $addons = TenantAddon::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        // Custom form fields
        $formSections = TenantFormSection::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->with(['fields' => function ($q) {
                $q->orderBy('sort_order');
            }])
            ->get();

        // Receiving methods
        $receivingMethods = TenantReceivingMethod::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        // Payment providers
        $s              = $tenant->settings ?? [];
        $stripeEnabled  = !empty($s['stripe_enabled'])  && !empty($s['stripe_test_sk'] ?? $s['stripe_live_sk'] ?? '');
        $paypalEnabled  = !empty($s['paypal_enabled'])  && !empty($s['paypal_test_client_id'] ?? $s['paypal_live_client_id'] ?? '');
        $stripePublishableKey = '';
        if ($stripeEnabled) {
            $mode = $s['stripe_mode'] ?? 'test';
            $stripePublishableKey = $mode === 'live' ? ($s['stripe_live_pk'] ?? '') : ($s['stripe_test_pk'] ?? '');
        }
        $paypalClientId = '';
        if ($paypalEnabled) {
            $mode = $s['paypal_mode'] ?? 'sandbox';
            $paypalClientId = $mode === 'live' ? ($s['paypal_live_client_id'] ?? '') : ($s['paypal_test_client_id'] ?? '');
        }

        $bookingMode = $tenant->booking_mode ?? 'drop_off';

        return view('public.booking', compact(
            'catalog', 'tiers', 'addons', 'formSections', 'receivingMethods',
            'stripeEnabled', 'paypalEnabled', 'stripePublishableKey', 'paypalClientId',
            'bookingMode'
        ));
    }

    // ----------------------------------------------------------------
    // GET /book/availability — AJAX: available dates for a month
    // ----------------------------------------------------------------
    public function availability(Request $request)
    {
        $request->validate([
            'year'  => ['required', 'integer', 'min:2024', 'max:2030'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $tenant = tenant();
        $mode   = $tenant->booking_mode ?? 'drop_off';
        $dates  = BookingService::availableDates($tenant, (int) $request->input('year'), (int) $request->input('month'));

        // In time_slots mode, also return slots per available date
        $slots = [];
        if ($mode === 'time_slots') {
            foreach ($dates as $date) {
                $slots[$date] = BookingService::availableSlotsForDate($tenant, $date);
            }
        }

        return response()->json(['dates' => $dates, 'slots' => $slots, 'mode' => $mode]);
    }

    // ----------------------------------------------------------------
    // POST /book/submit — create appointment + initiate payment
    // ----------------------------------------------------------------
    public function submit(Request $request)
    {
        $request->validate([
            'first_name'  => ['required', 'string', 'max:100'],
            'last_name'   => ['required', 'string', 'max:100'],
            'email'       => ['required', 'email', 'max:191'],
            'date'        => ['required', 'date', 'after_or_equal:today'],
            'items'       => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'string'],
            'items.*.tier_id' => ['required', 'string'],
            'payment_method'  => ['required', 'in:stripe,paypal,none'],
        ]);

        $tenant      = tenant();
        $appointment = BookingService::createAppointment($tenant, $request->all());

        $paymentMethod = $request->input('payment_method');

        // No payment — go straight to confirmation
        if ($paymentMethod === 'none' || $appointment->total_cents === 0) {
            return response()->json([
                'success'      => true,
                'redirect'     => url("/confirm?ra={$appointment->ra_number}"),
                'ra_number'    => $appointment->ra_number,
            ]);
        }

        // Stripe — return client secret for Elements
        if ($paymentMethod === 'stripe') {
            $stripe = new StripeService($tenant);
            if (!$stripe->isConfigured()) {
                return response()->json(['success' => false, 'message' => 'Stripe is not configured.'], 422);
            }
            $intent = $stripe->createPaymentIntent($appointment);
            return response()->json([
                'success'       => true,
                'payment'       => 'stripe',
                'client_secret' => $intent['client_secret'],
                'ra_number'     => $appointment->ra_number,
            ]);
        }

        // PayPal — return approve URL
        if ($paymentMethod === 'paypal') {
            $paypal = new PayPalService($tenant);
            if (!$paypal->isConfigured()) {
                return response()->json(['success' => false, 'message' => 'PayPal is not configured.'], 422);
            }
            $order = $paypal->createOrder($appointment);
            return response()->json([
                'success'      => true,
                'payment'      => 'paypal',
                'approve_url'  => $order['approve_url'],
                'ra_number'    => $appointment->ra_number,
            ]);
        }

        return response()->json(['success' => false, 'message' => 'Unknown payment method.'], 422);
    }

    // ----------------------------------------------------------------
    // GET /book/paypal/return — PayPal redirects back here after approval
    // ----------------------------------------------------------------
    public function paypalReturn(Request $request)
    {
        $orderId = $request->query('token'); // PayPal sends ?token=ORDER_ID
        if (!$orderId) {
            return redirect('/book')->with('error', 'PayPal payment was cancelled.');
        }

        try {
            $appointment = PayPalService::handleReturn(tenant(), $orderId);
            return redirect("/confirm?ra={$appointment->ra_number}");
        } catch (\Throwable $e) {
            logger()->error('PayPal return error: ' . $e->getMessage());
            return redirect('/book')->with('error', 'Payment could not be completed. Please try again.');
        }
    }

    // ----------------------------------------------------------------
    // Webhooks
    // ----------------------------------------------------------------
    public function stripeWebhook(Request $request)
    {
        try {
            StripeService::handleWebhook(
                tenant(),
                $request->getContent(),
                $request->header('Stripe-Signature', '')
            );
        } catch (\Throwable $e) {
            logger()->error('Stripe webhook error: ' . $e->getMessage());
            return response('error', 400);
        }
        return response('ok');
    }

    public function paypalWebhook(Request $request)
    {
        // PayPal webhooks — basic handler, expand as needed
        logger()->info('PayPal webhook received', $request->all());
        return response('ok');
    }
}
