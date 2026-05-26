<?php
// MARKER-PATCH-150

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

        $tenant->settings = $settings;
        $tenant->save();

        Log::info('Analytics settings updated', [
            'tenant_id' => $tenant->id,
            'ga4_set'   => $newId !== '',
            'by'        => $me->email,
        ]);

        return back()->with('success', 'Analytics settings saved.');
    }
}
