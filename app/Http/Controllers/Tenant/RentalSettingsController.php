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
            'rentalsVisible'   => (bool) ($s['rentals_visible'] ?? true), // MARKER-PATCH-228B
            // MARKER-PATCH-233 — late & overdue policy (return flow suggestions).
            'lateGraceMinutes' => (int) ($s['rental_late_grace_minutes'] ?? 30),
            'lateFeePerHour'   => number_format(((int) ($s['rental_late_fee_cents_per_hour'] ?? 0)) / 100, 2, '.', ''),
            'lateFeeCap'       => (string) ($s['rental_late_fee_cap'] ?? 'day_rate'),
            // MARKER-PATCH-237 — agreement templates + deposit behavior.
            'agreementTemplates' => \App\Models\Tenant\TenantRentalAgreementTemplate::where('tenant_id', $tenant->id)
                ->orderByDesc('version')->get(),
            'depositAutoRelease' => (bool) ($s['rental_deposit_autorelease_quick'] ?? true),
        ]);
    }


    /**
     * MARKER-PATCH-237 — publish a new agreement version. Publish-only by
     * design: rentals snapshot the version they signed, so editing history
     * would lie. The latest version is what the check-out flow presents —
     * and once any version exists, the flow's signature gate is armed.
     */
    public function storeAgreementTemplate(Request $request)
    {
        $tenant = tenant();

        $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'body'  => ['required', 'string', 'max:50000'],
        ]);

        $next = (int) \App\Models\Tenant\TenantRentalAgreementTemplate::where('tenant_id', $tenant->id)
            ->max('version') + 1;

        \App\Models\Tenant\TenantRentalAgreementTemplate::create([
            'tenant_id'          => $tenant->id,
            'version'            => $next,
            'title'              => $request->input('title'),
            'body'               => $request->input('body'),
            'created_by_user_id' => auth('tenant')->id(),
        ]);

        return redirect()->route('tenant.rentals.settings')
            ->with('flash', "Agreement v{$next} published — every guided check-out now requires a signature.");
    }

    public function save(Request $request)
    {
        $tenant = tenant();

        $request->validate([
            'season_start'  => ['required', 'regex:/^\d{2}-\d{2}$/'],
            'season_end'    => ['required', 'regex:/^\d{2}-\d{2}$/'],
            'leases_enabled'  => ['nullable', 'boolean'],
            'rentals_visible' => ['nullable', 'boolean'], // MARKER-PATCH-228B
            // MARKER-PATCH-233 — late & overdue policy.
            'late_grace_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'late_fee_per_hour'  => ['nullable', 'numeric', 'min:0', 'max:999'],
            'late_fee_cap'       => ['nullable', 'in:day_rate,none'],
            // MARKER-PATCH-237 — deposit behavior.
            'deposit_autorelease_quick' => ['nullable', 'boolean'],
        ]);

        $settings = $tenant->settings ?? [];
        $settings['season_start'] = $request->input('season_start');
        $settings['season_end']   = $request->input('season_end');
        $settings['rentals_visible'] = (bool) $request->input('rentals_visible'); // MARKER-PATCH-228B
        // MARKER-PATCH-233 — late & overdue policy.
        $settings['rental_late_grace_minutes']     = (int) $request->input('late_grace_minutes', 30);
        $settings['rental_late_fee_cents_per_hour'] = (int) round(((float) $request->input('late_fee_per_hour', 0)) * 100);
        $settings['rental_late_fee_cap']           = $request->input('late_fee_cap', 'day_rate');
        // MARKER-PATCH-237 — deposit behavior.
        $settings['rental_deposit_autorelease_quick'] = (bool) $request->input('deposit_autorelease_quick');

        // The leasing toggle only takes effect when the plan tier makes
        // leasing available; otherwise it's forced off regardless of input.
        $settings['leases_enabled'] = $tenant->leasing_available
            ? (bool) $request->input('leases_enabled')
            : false;

        $tenant->update(['settings' => $settings]);

        return redirect()->route('tenant.rentals.settings')->with('flash', 'Rental settings saved.');
    }
}
