<?php
// MARKER-PATCH-228

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Rentals settings — season window + the leasing visibility toggle.
 * The season window feeds lease due-date defaults; the leasing toggle is
 * the visibility switch (the tier gate is the entitlement, enforced by
 * Tenant::leases_enabled / leasing_available from patch 226).
 */
class RentalSettingsController extends Controller
{
    public function index()
    {
        $tenant = tenant();
        $s = $tenant->settings ?? [];

        return view('tenant.rentals.settings', [
            'seasonStart'      => $s['season_start'] ?? '11-01',
            'seasonEnd'        => $s['season_end'] ?? '04-15',
            'leasesEnabled'    => (bool) ($s['leases_enabled'] ?? false),
            'leasingAvailable' => $tenant->leasing_available,
        ]);
    }

    public function save(Request $request)
    {
        $tenant = tenant();

        $request->validate([
            'season_start'  => ['required', 'regex:/^\d{2}-\d{2}$/'],
            'season_end'    => ['required', 'regex:/^\d{2}-\d{2}$/'],
            'leases_enabled' => ['nullable', 'boolean'],
        ]);

        $settings = $tenant->settings ?? [];
        $settings['season_start'] = $request->input('season_start');
        $settings['season_end']   = $request->input('season_end');

        // The leasing toggle only takes effect when the plan tier makes
        // leasing available; otherwise it's forced off regardless of input.
        $settings['leases_enabled'] = $tenant->leasing_available
            ? (bool) $request->input('leases_enabled')
            : false;

        $tenant->update(['settings' => $settings]);

        return redirect()->route('tenant.rentals.settings')->with('flash', 'Rental settings saved.');
    }
}
