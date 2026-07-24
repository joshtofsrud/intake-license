<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Sms\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Unified settings controller. Absorbs the previous BrandingController so the
 * settings page is a single tabbed view. The `tab` request input discriminates
 * which group of fields to validate and persist.
 *
 * Tabs:
 *  - business      currency, timezone, booking, tax, drop-off methods (CRUD via ReceivingMethodController)
 *  - branding      shop name, tagline, logos, colors, typography
 *  - communication email sender details, SMS provider config, notification toggles
 *  - account       custom domain (booking URL is read-only)
 *  - appearance    admin theme
 *  - payments      Stripe + PayPal API keys
 */
class SettingsController extends Controller
{
    public function index()
    {
        $tenant = tenant();
        $receivingMethods = \App\Models\Tenant\TenantReceivingMethod::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $paymentMethods = \App\Models\Tenant\TenantPaymentMethod::bootstrapFor($tenant); // MARKER-PATCH-629
        return view('tenant.settings.index', compact('receivingMethods', 'paymentMethods'));
    }

    public function update(Request $request)
    {
        $tenant = tenant();
        $tab    = $request->input('tab', 'business');

        return match ($tab) {
            'business'      => $this->updateBusiness($request, $tenant),
            'branding'      => $this->updateBranding($request, $tenant),
            'communication' => $this->updateCommunication($request, $tenant),
            'account'       => $this->updateAccount($request, $tenant),
            'appearance'    => $this->updateAppearance($request, $tenant),
            'payments'      => $this->updatePayments($request, $tenant),
            'tags'          => $this->updateTags($request, $tenant), // MARKER-PATCH-315
            'ordering'      => $this->updateOrdering($request, $tenant), // MARKER-SO-AUTOVENDOR
            default         => back()->with('error', 'Unknown tab.'),
        };
    }

    // -------------------------------------------------------------------
    // MARKER-SO-AUTOVENDOR — how special orders choose a vendor.
    // -------------------------------------------------------------------
    private function updateOrdering(Request $request, $tenant)
    {
        $request->validate([
            'so_auto_assign_vendor' => ['required', 'in:preferred,lowest_price,off'],
        ]);

        $settings = $tenant->settings ?? [];
        $so = (array) ($settings['special_orders'] ?? []);
        $so['auto_assign_vendor'] = $request->input('so_auto_assign_vendor');
        $settings['special_orders'] = $so;
        $tenant->update(['settings' => $settings]);

        return back()->with('success', 'Ordering settings saved.');
    }

    // -------------------------------------------------------------------
    // MARKER-PATCH-315 — Work-order tag settings (toggles, lead time,
    // paper width, thermal logo). Stored in the tenant settings JSON.
    // -------------------------------------------------------------------
    private function updateTags(Request $request, $tenant)
    {
        $request->validate([
            'wot_lead_days' => ['nullable', 'integer', 'min:0', 'max:30'],
            'wot_paper'     => ['nullable', 'in:80mm,58mm'],
            'wot_header_text' => ['nullable', 'string', 'max:500'], // MARKER-PATCH-330
            'wot_footer_text' => ['nullable', 'string', 'max:500'], // MARKER-PATCH-330
            'wot_logo'      => ['nullable', 'image', 'max:2048'],
        ]);

        $settings = $tenant->settings ?? [];
        $wot = (array) ($settings['work_order_tag'] ?? []);

        $wot['enabled']       = (bool) $request->input('wot_enabled');
        $wot['show_header']   = (bool) $request->input('wot_show_header');
        $wot['show_phone']    = (bool) $request->input('wot_show_phone');
        $wot['show_bike']     = (bool) $request->input('wot_show_bike');
        $wot['show_services'] = (bool) $request->input('wot_show_services');
        $wot['show_note']     = (bool) $request->input('wot_show_note');
        $wot['show_qr']       = (bool) $request->input('wot_show_qr');
        $wot['show_stub']     = (bool) $request->input('wot_show_stub');
        $wot['lead_days']     = $request->filled('wot_lead_days') ? (int) $request->input('wot_lead_days') : 3;
        $wot['paper']         = $request->input('wot_paper', '80mm');
        $wot['logo_size']     = in_array($request->input('wot_logo_size'), ['small', 'medium', 'large', 'xl'], true) ? $request->input('wot_logo_size') : 'medium'; // MARKER-PATCH-317
        $wot['feed_mm']       = max(0, min(40, (int) $request->input('wot_feed_mm', 0))); // MARKER-PATCH-320
        $wot['header_text']   = trim((string) $request->input('wot_header_text', '')); // MARKER-PATCH-330
        $wot['footer_text']   = trim((string) $request->input('wot_footer_text', '')); // MARKER-PATCH-330

        if ($request->hasFile('wot_logo')) {
            $wot['logo_path'] = $request->file('wot_logo')->store("tenants/{$tenant->id}/work-order-tag", 'public');
        } elseif ($request->input('wot_logo_remove') === '1') {
            $wot['logo_path'] = null;
        }

        $settings['work_order_tag'] = $wot;
        $tenant->update(['settings' => $settings]);

        return back()->with('success', 'Work-order tag settings saved.');
    }

    // -------------------------------------------------------------------
    // Business: currency, timezone, booking window, classes, tax
    // -------------------------------------------------------------------
    private function updateBusiness(Request $request, $tenant)
    {
        $request->validate([
            'currency'             => ['required', 'string', 'size:3'],
            'currency_symbol'      => ['required', 'string', 'max:5'],
            'timezone'             => ['required', 'string', 'max:64'],
            'booking_window_days'  => ['required', 'integer', 'min:1', 'max:365'],
            'min_notice_hours'     => ['required', 'integer', 'min:0', 'max:168'],
            'classes_enabled'      => ['nullable', 'boolean'],
            'deliveries_enabled'   => ['nullable', 'boolean'], // MARKER-PATCH-156
            'multi_asset_enabled'  => ['nullable', 'boolean'], // MARKER-PATCH-158-B
            'asset_label_singular' => ['nullable', 'string', 'max:30'], // MARKER-PATCH-215
            'asset_label_plural'   => ['nullable', 'string', 'max:30'], // MARKER-PATCH-215
            'asset_label_singular' => ['nullable', 'string', 'max:30'], // MARKER-PATCH-215
            'asset_label_plural'   => ['nullable', 'string', 'max:30'], // MARKER-PATCH-215
            'asset_label_singular' => ['nullable', 'string', 'max:30'], // MARKER-PATCH-215
            'asset_label_plural'   => ['nullable', 'string', 'max:30'], // MARKER-PATCH-215
            'default_tax_rate'     => ['nullable', 'numeric', 'min:0', 'max:25'],
            'tax_services_default' => ['nullable', 'boolean'],
            'tax_supports_exempt'  => ['nullable', 'boolean'],
        ]);

        $tenant->update([
            'currency'             => $request->input('currency'),
            'currency_symbol'      => $request->input('currency_symbol'),
            'timezone'             => $request->input('timezone'),
            'booking_window_days'  => (int) $request->input('booking_window_days'),
            'min_notice_hours'     => (int) $request->input('min_notice_hours'),
            'classes_enabled'      => (bool) $request->input('classes_enabled'),
            'deliveries_enabled'   => (bool) $request->input('deliveries_enabled'), // MARKER-PATCH-156
            'multi_asset_enabled'  => (bool) $request->input('multi_asset_enabled'), // MARKER-PATCH-158-B
            'asset_label_singular' => $request->filled('asset_label_singular') ? trim($request->input('asset_label_singular')) : 'item',  // MARKER-PATCH-215
            'asset_label_plural'   => $request->filled('asset_label_plural')   ? trim($request->input('asset_label_plural'))   : 'items', // MARKER-PATCH-215
            'asset_label_singular' => $request->filled('asset_label_singular') ? trim($request->input('asset_label_singular')) : 'item',  // MARKER-PATCH-215
            'asset_label_plural'   => $request->filled('asset_label_plural')   ? trim($request->input('asset_label_plural'))   : 'items', // MARKER-PATCH-215
            'asset_label_singular' => $request->filled('asset_label_singular') ? trim($request->input('asset_label_singular')) : 'item',  // MARKER-PATCH-215
            'asset_label_plural'   => $request->filled('asset_label_plural')   ? trim($request->input('asset_label_plural'))   : 'items', // MARKER-PATCH-215
            'default_tax_rate'     => $request->filled('default_tax_rate')
                ? (float) $request->input('default_tax_rate')
                : null,
            'tax_services_default' => (bool) $request->input('tax_services_default'),
            'tax_supports_exempt'  => (bool) $request->input('tax_supports_exempt'),
        ]);

        return back()->with('success', 'Business settings saved.');
    }

    // -------------------------------------------------------------------
    // Branding: shop identity, logos, colors, typography
    // (formerly BrandingController::update tab=appearance, file uploads + colors)
    // -------------------------------------------------------------------
    private function updateBranding(Request $request, $tenant)
    {
        $request->validate([
            'name'              => ['required', 'string', 'max:255'],
            'tagline'           => ['nullable', 'string', 'max:255'],
            'accent_color'      => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'text_color'        => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'bg_color'          => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'font_heading'      => ['nullable', 'string', 'max:100'],
            'font_body'         => ['nullable', 'string', 'max:100'],
            'logo_size_admin'   => ['nullable', 'integer', 'min:16', 'max:80'],
            'logo_size_booking' => ['nullable', 'integer', 'min:16', 'max:120'],
        ]);

        $data = $request->only([
            'name', 'tagline', 'accent_color', 'text_color',
            'bg_color', 'font_heading', 'font_body',
            'logo_size_admin', 'logo_size_booking',
        ]);

        if ($request->hasFile('logo')) {
            $request->validate(['logo' => ['image', 'max:2048']]);
            $path = $request->file('logo')->store("tenants/{$tenant->id}/logo", 'public');
            $data['logo_url'] = asset('storage/' . $path);
        }

        if ($request->hasFile('logo_light')) {
            $request->validate(['logo_light' => ['image', 'max:2048']]);
            $path = $request->file('logo_light')->store("tenants/{$tenant->id}/logo", 'public');
            $data['logo_light_url'] = asset('storage/' . $path);
        }

        if ($request->hasFile('favicon')) {
            $request->validate(['favicon' => ['image', 'max:512']]);
            $path = $request->file('favicon')->store("tenants/{$tenant->id}/favicon", 'public');
            $data['favicon_url'] = asset('storage/' . $path);
        }

        $tenant->update($data);

        return back()->with('success', 'Branding saved.');
    }

    // -------------------------------------------------------------------
    // Communication: email sender, SMS provider, notification toggles
    // -------------------------------------------------------------------
    private function updateCommunication(Request $request, $tenant)
    {
        $request->validate([
            // Email
            'email_from_name'    => ['nullable', 'string', 'max:255'],
            'email_from_address' => ['nullable', 'email', 'max:255'],
            'email_reply_to'     => ['nullable', 'email', 'max:255'],
            'notification_email' => ['nullable', 'email', 'max:255'],
            // SMS
            // MARKER-PATCH-224 — sms_* moved to Settings\MessagingController.
            // MARKER-PATCH-406 — notification toggles moved to Communication Center
        ]);

        // Don't overwrite an existing token with empty input — the form posts
        // MARKER-PATCH-224 — sms_*/twilio_* are owned by
        // Settings\MessagingController now. Writing them here would null
        // the messaging config on every unrelated settings save.
        $tenant->update([
            'email_from_name'    => $request->input('email_from_name'),
            'email_from_address' => $request->input('email_from_address'),
            'email_reply_to'     => $request->input('email_reply_to'),
            'notification_email' => $request->input('notification_email'),
        ]);

        // MARKER-PATCH-406 — notification toggles now owned by CommunicationController

        return back()->with('success', 'Communication settings saved.');
    }

    // -------------------------------------------------------------------
    // Account: custom domain
    // (booking URL is read-only display; subscription/billing also read-only)
    // -------------------------------------------------------------------
    private function updateAccount(Request $request, $tenant)
    {
        if (in_array($tenant->plan_tier, ['branded', 'scale', 'custom'])) {
            $request->validate([
                // MARKER-PATCH-120-SETTINGS-CONTROLLER - tenant_domains is the new source of truth
                // 'custom_domain' => ['nullable', 'string', 'max:253',
                //     'regex:/^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/'],
            ]);
            // $tenant->update(['custom_domain' => $request->input('custom_domain') ?: null]); // MARKER-PATCH-120-SETTINGS-CONTROLLER
        }
        return back()->with('success', 'Account settings saved.');
    }

    // -------------------------------------------------------------------
    // Appearance: admin theme
    // -------------------------------------------------------------------
    private function updateAppearance(Request $request, $tenant)
    {
        $request->validate([
            'admin_theme' => ['required', 'in:b,c'],
        ]);
        $settings = $tenant->settings ?? [];
        $settings['admin_theme'] = $request->input('admin_theme');
        $tenant->update(['settings' => $settings]);
        return back()->with('success', 'Appearance saved.');
    }

    // -------------------------------------------------------------------
    // Payments: Stripe + PayPal API keys (preserved verbatim from old controller)
    // -------------------------------------------------------------------
    private function updatePayments(Request $request, $tenant)
    {
        $settings = $tenant->settings ?? [];

        // MARKER-PATCH-388 — legacy booking-deposit stripe_* keys retired.
        // Booking deposits now run on Direct Payments (register_payments_* keys).

        // MARKER-PATCH-169 — Direct Payments bridge feature.
        // Register card-sale keys, namespaced separately from the booking-deposit
        // Stripe keys above (which power BookingController via App\Services\StripeService).
        // Only saved if the tenant has direct_payments_enabled set by master admin;
        // otherwise the form fields don\'t render and the inputs come back empty,
        // which is fine.
        if ($tenant->direct_payments_enabled) {
            // MARKER-PATCH-618 — tenant-level on/off for card + payment-link tenders
            // (master flag stays the capability gate; this is the tenant's switch).
            $settings['stripe_register_enabled'] = (bool) $request->input('stripe_register_enabled');
            $settings['square_enabled']          = (bool) $request->input('square_enabled');

            $settings['register_payments_mode']           = $request->input('register_payments_mode', 'test');
            $settings['register_payments_test_pk']        = $request->input('register_payments_test_pk', '');
            $settings['register_payments_test_sk']        = $request->input('register_payments_test_sk', '');
            $settings['register_payments_live_pk']        = $request->input('register_payments_live_pk', '');
            $settings['register_payments_live_sk']        = $request->input('register_payments_live_sk', '');
            $settings['register_payments_webhook_secret'] = $request->input('register_payments_webhook_secret', '');

            // MARKER-PATCH-473 — Square (tenant-connected) credentials
            $settings['square_payments_mode']           = $request->input('square_payments_mode', 'sandbox');
            $settings['square_sandbox_app_id']          = $request->input('square_sandbox_app_id', '');
            $settings['square_sandbox_location_id']     = $request->input('square_sandbox_location_id', '');
            $settings['square_sandbox_access_token']    = $request->input('square_sandbox_access_token', '');
            $settings['square_production_app_id']       = $request->input('square_production_app_id', '');
            $settings['square_production_location_id']  = $request->input('square_production_location_id', '');
            $settings['square_production_access_token'] = $request->input('square_production_access_token', '');
            $settings['square_webhook_signature_key']   = $request->input('square_webhook_signature_key', '');
        }

        $settings['paypal_enabled']        = (bool) $request->input('paypal_enabled');
        $settings['paypal_mode']           = $request->input('paypal_mode', 'sandbox');
        $settings['paypal_test_client_id'] = $request->input('paypal_test_client_id', '');
        $settings['paypal_test_secret']    = $request->input('paypal_test_secret', '');
        $settings['paypal_live_client_id'] = $request->input('paypal_live_client_id', '');
        $settings['paypal_live_secret']    = $request->input('paypal_live_secret', '');

        // MARKER-PATCH-618 — Venmo / Cash App manual tenders (peer-to-peer pay links).
        // Handles are stored bare (no @ / $); the link helper adds the scheme.
        // MARKER-PATCH-629 — venmo/cashapp keys retired here: owned by
        // tenant_payment_methods and written back via syncLegacyKeys().

        $tenant->update(['settings' => $settings]);
        return back()->with('success', 'Payment settings saved.');
    }

    // -------------------------------------------------------------------
    // POST endpoint: send a test SMS to verify Twilio configuration.
    // Uses the tenant's *saved* credentials, so user must save before testing.
    // -------------------------------------------------------------------
    // MARKER-PATCH-468 — toggle asset tracking from the Services-page banner
    public function toggleAssetTracking(Request $request): JsonResponse
    {
        $tenant = tenant();
        $enabled = (bool) $request->input('enabled');
        $tenant->update(['multi_asset_enabled' => $enabled]);
        return response()->json(['ok' => true, 'enabled' => $enabled]);
    }

    // MARKER-PATCH-473 — verify the tenant's pasted Square credentials
    public function verifySquareConnection(Request $request): JsonResponse
    {
        $tenant = tenant();
        if (! ($tenant->direct_payments_enabled ?? false)) {
            return response()->json(['ok' => false, 'message' => 'Payments are not enabled for this account.'], 403);
        }
        $result = (new \App\Services\Tenant\SquarePaymentsService($tenant))->verifyConnection();
        return response()->json($result);
    }

    public function sendTestSms(Request $request): JsonResponse
    {
        $request->validate([
            'to' => ['required', 'string', 'max:32'],
        ]);

        $tenant = tenant();

        // MARKER-PATCH-224 — managed numbers send on platform creds; only
        // require tenant creds when no platform fallback exists.
        $hasCreds = ($tenant->twilio_account_sid && $tenant->twilio_auth_token)
            || (config('services.twilio.sid') && config('services.twilio.token')); // MARKER-PATCH-224B
        if (! $tenant->sms_enabled || ! $tenant->sms_from_number || ! $hasCreds) {
            return response()->json([
                'ok'    => false,
                'error' => 'SMS is not enabled or credentials are missing. Save your settings first, then try again.',
            ], 422);
        }

        try {
            SmsService::send(
                $tenant,
                $request->input('to'),
                sprintf('Intake test message from %s. SMS is configured correctly.', $tenant->name)
            );
            return response()->json(['ok' => true, 'message' => 'Test SMS sent. Check the recipient phone.']);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'    => false,
                'error' => 'Send failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}

