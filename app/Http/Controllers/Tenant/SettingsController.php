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

        return view('tenant.settings.index', compact('receivingMethods'));
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
            default         => back()->with('error', 'Unknown tab.'),
        };
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
            'sms_enabled'        => ['nullable', 'boolean'],
            'sms_from_number'    => ['nullable', 'string', 'max:32'],
            'twilio_account_sid' => ['nullable', 'string', 'max:64'],
            'twilio_auth_token'  => ['nullable', 'string', 'max:128'],
            // Notifications (only the wired ones validate)
            'notify_booking_confirmation_email' => ['nullable', 'boolean'],
            'notify_booking_confirmation_sms'   => ['nullable', 'boolean'],
            // MARKER-PATCH-152C
            'notify_delivery_scheduled_email'   => ['nullable', 'boolean'],
            'notify_delivery_scheduled_sms'     => ['nullable', 'boolean'],
            // MARKER-PATCH-154
            'notify_appointment_reminder_email' => ['nullable', 'boolean'],
            'notify_appointment_reminder_sms'   => ['nullable', 'boolean'],
            // MARKER-PATCH-155
            'notify_delivery_reminder_email'    => ['nullable', 'boolean'],
            'notify_delivery_reminder_sms'      => ['nullable', 'boolean'],
        ]);

        // Don't overwrite an existing token with empty input — the form posts
        // an empty value when the user didn't touch the field (since it's a
        // password field, we don't render the existing value).
        $newToken = $request->input('twilio_auth_token');
        $tokenToSave = $newToken !== null && $newToken !== ''
            ? $newToken
            : $tenant->twilio_auth_token;

        $tenant->update([
            'email_from_name'    => $request->input('email_from_name'),
            'email_from_address' => $request->input('email_from_address'),
            'email_reply_to'     => $request->input('email_reply_to'),
            'notification_email' => $request->input('notification_email'),
            'sms_enabled'        => (bool) $request->input('sms_enabled'),
            'sms_from_number'    => $request->input('sms_from_number') ?: null,
            'twilio_account_sid' => $request->input('twilio_account_sid') ?: null,
            'twilio_auth_token'  => $tokenToSave,
        ]);

        // Notification toggles live in settings JSON. Defaults are "on" via
        // notificationEnabled() — the UI sends 0/1 explicitly so no defaulting issues.
        $settings = $tenant->settings ?? [];
        $settings['notify_booking_confirmation_email'] = (bool) $request->input('notify_booking_confirmation_email');
        $settings['notify_booking_confirmation_sms']   = (bool) $request->input('notify_booking_confirmation_sms');
        // MARKER-PATCH-152C
        $settings['notify_delivery_scheduled_email']   = (bool) $request->input('notify_delivery_scheduled_email');
        $settings['notify_delivery_scheduled_sms']     = (bool) $request->input('notify_delivery_scheduled_sms');
        // MARKER-PATCH-154
        $settings['notify_appointment_reminder_email'] = (bool) $request->input('notify_appointment_reminder_email');
        $settings['notify_appointment_reminder_sms']   = (bool) $request->input('notify_appointment_reminder_sms');
        // MARKER-PATCH-155
        $settings['notify_delivery_reminder_email']    = (bool) $request->input('notify_delivery_reminder_email');
        $settings['notify_delivery_reminder_sms']      = (bool) $request->input('notify_delivery_reminder_sms');
        $tenant->update(['settings' => $settings]);

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

        $settings['stripe_enabled']        = (bool) $request->input('stripe_enabled');
        $settings['stripe_mode']           = $request->input('stripe_mode', 'test');
        $settings['stripe_test_pk']        = $request->input('stripe_test_pk', '');
        $settings['stripe_test_sk']        = $request->input('stripe_test_sk', '');
        $settings['stripe_live_pk']        = $request->input('stripe_live_pk', '');
        $settings['stripe_live_sk']        = $request->input('stripe_live_sk', '');
        $settings['stripe_webhook_secret'] = $request->input('stripe_webhook_secret', '');

        // MARKER-PATCH-169 — Direct Payments bridge feature.
        // Register card-sale keys, namespaced separately from the booking-deposit
        // Stripe keys above (which power BookingController via App\Services\StripeService).
        // Only saved if the tenant has direct_payments_enabled set by master admin;
        // otherwise the form fields don\'t render and the inputs come back empty,
        // which is fine.
        if ($tenant->direct_payments_enabled) {
            $settings['register_payments_mode']           = $request->input('register_payments_mode', 'test');
            $settings['register_payments_test_pk']        = $request->input('register_payments_test_pk', '');
            $settings['register_payments_test_sk']        = $request->input('register_payments_test_sk', '');
            $settings['register_payments_live_pk']        = $request->input('register_payments_live_pk', '');
            $settings['register_payments_live_sk']        = $request->input('register_payments_live_sk', '');
            $settings['register_payments_webhook_secret'] = $request->input('register_payments_webhook_secret', '');
        }

        $settings['paypal_enabled']        = (bool) $request->input('paypal_enabled');
        $settings['paypal_mode']           = $request->input('paypal_mode', 'sandbox');
        $settings['paypal_test_client_id'] = $request->input('paypal_test_client_id', '');
        $settings['paypal_test_secret']    = $request->input('paypal_test_secret', '');
        $settings['paypal_live_client_id'] = $request->input('paypal_live_client_id', '');
        $settings['paypal_live_secret']    = $request->input('paypal_live_secret', '');

        $tenant->update(['settings' => $settings]);
        return back()->with('success', 'Payment settings saved.');
    }

    // -------------------------------------------------------------------
    // POST endpoint: send a test SMS to verify Twilio configuration.
    // Uses the tenant's *saved* credentials, so user must save before testing.
    // -------------------------------------------------------------------
    public function sendTestSms(Request $request): JsonResponse
    {
        $request->validate([
            'to' => ['required', 'string', 'max:32'],
        ]);

        $tenant = tenant();

        if (! $tenant->sms_enabled || ! $tenant->twilio_account_sid || ! $tenant->twilio_auth_token) {
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
