<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantTrustedDevice;
use App\Services\DeviceTrustService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * SecurityController
 *
 * Owner-facing administration of the auth refactor. Two surfaces:
 *   - Trusted Devices: list active devices, revoke individually or all.
 *   - Sign-in Security: tune the per-tenant policy (idle threshold,
 *     device trust expiry, action sticky windows).
 *
 * Tier-gated by additional_users_enabled — Starter never sees the menu
 * item; if a Starter user navigates directly, the controller redirects.
 *
 * Subdomain trap: every method takes  first.
 */
class SecurityController extends Controller
{
    public function __construct(protected DeviceTrustService $devices) {}

    /**
     * GET /admin/security
     */
    public function index(Request $request)
    {
        $tenant = tenant();
        $user = Auth::guard('tenant')->user();

        if (! $user || ! $user->isOwner()) {
            return redirect()->route('tenant.dashboard')
                ->with('error', 'Security settings are owner-only.');
        }

        if (! $tenant->additional_users_enabled) {
            // Capability not on for this tier. Page is meaningless.
            return redirect()->route('tenant.dashboard')
                ->with('info', 'Sign-in security settings are available on Branded and Scale plans.');
        }

        $devices = TenantTrustedDevice::activeForTenant($tenant->id)
            ->with('revokedBy')
            ->orderBy('last_used_at', 'desc')
            ->get();

        // Per-tenant security policy lives in the settings JSON column.
        // Defaults fall back to config('intake.auth.*').
        $s = $tenant->settings ?? [];
        $policy = [
            'pin_idle_threshold_sec'       => $s['security']['pin_idle_threshold_sec']       ?? config('intake.auth.pin_idle_threshold_sec', 120),
            'device_trust_expiry_days'     => $s['security']['device_trust_expiry_days']     ?? config('intake.auth.device_trust_expiry_days', 90),
            'switch_location_sticky_sec'   => $s['security']['switch_location_sticky_sec']   ?? (config('intake.auth.pin_action_sticky_sec.switch_location', 0)),
        ];

        return view('tenant.security.index', [
            'devices' => $devices,
            'policy'  => $policy,
        ]);
    }

    /**
     * POST /admin/security/device/{id}/revoke
     */
    public function revokeDevice(Request $request, string $deviceId)
    {
        $tenant = tenant();
        $user = Auth::guard('tenant')->user();
        if (! $user || ! $user->isOwner()) {
            return back()->with('error', 'Owner only.');
        }

        $device = TenantTrustedDevice::where('tenant_id', $tenant->id)
            ->where('id', $deviceId)
            ->first();

        if (! $device) {
            return back()->with('error', 'Device not found.');
        }

        $this->devices->revoke($device, $user);

        return back()->with('success', 'Device revoked. It will require email + password on next visit.');
    }

    /**
     * POST /admin/security/devices/revoke-all
     */
    public function revokeAllDevices(Request $request)
    {
        $tenant = tenant();
        $user = Auth::guard('tenant')->user();
        if (! $user || ! $user->isOwner()) {
            return back()->with('error', 'Owner only.');
        }

        $count = $this->devices->revokeAllForTenant($tenant, $user);

        return back()->with('success', "Revoked {$count} trusted device" . ($count === 1 ? '.' : 's.'));
    }

    /**
     * PATCH /admin/security/settings
     *
     * Saves policy overrides to tenant.settings.security.
     * Note: this chunk only saves; chunk 8.1 wires the reads. Until then
     * the saved values are visible in the form but not yet enforced.
     */
    public function updateSettings(Request $request)
    {
        $tenant = tenant();
        $user = Auth::guard('tenant')->user();
        if (! $user || ! $user->isOwner()) {
            return back()->with('error', 'Owner only.');
        }

        $validated = $request->validate([
            'pin_idle_threshold_sec'     => ['required', 'integer', 'min:30', 'max:3600'],
            'device_trust_expiry_days'   => ['required', 'integer', 'min:1', 'max:365'],
            'switch_location_sticky_sec' => ['required', 'integer', 'min:0', 'max:3600'],
        ]);

        $settings = $tenant->settings ?? [];
        $settings['security'] = $validated;

        $tenant->forceFill(['settings' => $settings])->save();

        Log::info('Security.settingsUpdated', [
            'tenant_id' => $tenant->id,
            'by_user'   => $user->id,
            'values'    => $validated,
        ]);

        return back()->with('success', 'Security settings saved.');
    }
}
