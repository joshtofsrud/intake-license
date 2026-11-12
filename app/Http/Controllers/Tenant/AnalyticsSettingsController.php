<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Analytics settings for the tenant.
 *
 * Currently just GA-4 measurement ID. Plausible/Umami/etc go here when added.
 * Stored under tenant.settings.analytics_ga4_id (JSON column, no schema needed).
 */
class AnalyticsSettingsController extends Controller
{
    /**
     * POST /admin/settings/analytics
     */
    public function update(Request $request)
    {
        $me = Auth::guard('tenant')->user();
        if (! $me || ! $me->isManager()) {
            return back()->with('error', 'Manager or owner access required.');
        }

        $data = $request->validate([
            'analytics_ga4_id' => ['nullable', 'string', 'max:32', 'regex:/^(G-|UA-)[A-Z0-9]{4,20}$/i'],
        ], [
            'analytics_ga4_id.regex' => 'GA-4 measurement IDs start with G- (or UA- for legacy). Example: G-XXXXXXXXXX',
        ]);

        $tenant   = tenant();
        $settings = $tenant->settings ?? [];

        $newId = trim((string) ($data['analytics_ga4_id'] ?? ''));
        if ($newId === '') {
            unset($settings['analytics_ga4_id']);
        } else {
            $settings['analytics_ga4_id'] = $newId;
        }

        // MARKER-SHOP-GA4: the shop events switch and the API secret live on the E-commerce > Google tab
        $fromGoogleTab = $request->boolean('ecom_form');
        if ($fromGoogleTab) {
            $request->validate(['analytics_ga4_secret' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]*$/']]);
            $settings['analytics_ecom_on'] = $request->boolean('analytics_ecom_on');
            $secret = trim((string) $request->input('analytics_ga4_secret', ''));
            if ($request->boolean('clear_secret')) {
                unset($settings['analytics_ga4_secret']);
            } elseif ($secret !== '') {
                $settings['analytics_ga4_secret'] = \Illuminate\Support\Facades\Crypt::encryptString($secret);
            }
        }

        $tenant->settings = $settings;
        $tenant->save();

        Log::info('Analytics settings updated', [
            'tenant_id' => $tenant->id,
            'ga4_set'   => $newId !== '',
            'by'        => $me->email,
        ]);

        $back = back()->with('success', 'Analytics settings saved.');
        return $fromGoogleTab ? $back->with('ecom_tab', 'goo') : $back;
    }
}
