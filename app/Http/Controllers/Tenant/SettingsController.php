<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index()
    {
        return view('tenant.settings.index');
    }

    public function update(Request $request)
    {
        $tenant = tenant();
        $tab    = $request->input('tab', 'general');

        if ($tab === 'general') {
            $request->validate([
                'currency'        => ['required', 'string', 'size:3'],
                'currency_symbol' => ['required', 'string', 'max:5'],
                'timezone'        => ['required', 'string', 'max:64'],
            ]);
            $tenant->update($request->only([
                'currency', 'currency_symbol', 'timezone',
            ]));
            return back()->with('success', 'General settings saved.');
        }

        if ($tab === 'booking') {
            $request->validate([
                'booking_window_days' => ['required', 'integer', 'min:1', 'max:365'],
                'min_notice_hours'    => ['required', 'integer', 'min:0', 'max:168'],
            ]);
            $tenant->update($request->only([
                'booking_window_days', 'min_notice_hours',
            ]));
            return back()->with('success', 'Booking settings saved.');
        }

        if ($tab === 'payments') {
            $settings = $tenant->settings ?? [];

            // Stripe
            $settings['stripe_enabled']       = (bool) $request->input('stripe_enabled');
            $settings['stripe_mode']           = $request->input('stripe_mode', 'test');
            $settings['stripe_test_pk']        = $request->input('stripe_test_pk', '');
            $settings['stripe_test_sk']        = $request->input('stripe_test_sk', '');
            $settings['stripe_live_pk']        = $request->input('stripe_live_pk', '');
            $settings['stripe_live_sk']        = $request->input('stripe_live_sk', '');
            $settings['stripe_webhook_secret'] = $request->input('stripe_webhook_secret', '');

            // PayPal
            $settings['paypal_enabled']          = (bool) $request->input('paypal_enabled');
            $settings['paypal_mode']             = $request->input('paypal_mode', 'sandbox');
            $settings['paypal_test_client_id']   = $request->input('paypal_test_client_id', '');
            $settings['paypal_test_secret']      = $request->input('paypal_test_secret', '');
            $settings['paypal_live_client_id']   = $request->input('paypal_live_client_id', '');
            $settings['paypal_live_secret']      = $request->input('paypal_live_secret', '');

            $tenant->update(['settings' => $settings]);
            return back()->with('success', 'Payment settings saved.');
        }

        if ($tab === 'domain') {
            // Custom domain — Branded/Custom tier only
            if (in_array($tenant->plan_tier, ['branded', 'custom'])) {
                $request->validate([
                    'custom_domain' => ['nullable', 'string', 'max:253',
                        'regex:/^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/'],
                ]);
                $tenant->update(['custom_domain' => $request->input('custom_domain') ?: null]);
            }
            return back()->with('success', 'Domain settings saved.');
        }

        return back()->with('error', 'Unknown tab.');
    }
}
