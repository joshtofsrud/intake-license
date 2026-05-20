#!/usr/bin/env bash
# ============================================================================
# Auth Refactor — Chunk 8
# Admin screens: Security page + PIN status in Team admin
#
# CONTEXT
#   The auth model is now working in production (chunks 1–7). This chunk
#   adds the owner-facing admin surfaces so tenants can manage their
#   trusted devices, see PIN status across their team, and tune the
#   sign-in security policy.
#
# WHAT THIS PATCH ADDS
#   1. SecurityController (new) — index/revokeDevice/revokeAllDevices/
#      updateSettings methods. Lives at /admin/security.
#
#   2. /admin/security view — two-tab page (Trusted Devices + Sign-in
#      Security). Owner-only.
#
#   3. PIN status column in Team admin — Set / Not set / Locked badge,
#      with row actions "Unlock" (when locked) and "Force re-set"
#      (always).
#
#   4. TeamController::update — two new ops: pin_unlock, pin_force_reset
#
#   5. Nav entry for Security (sidebar manage group, gated on owner role
#      AND additional_users_enabled).
#
#   6. Per-tenant overrides storage — for now, write to the tenant
#      `settings` JSON column (existing pattern). Server-side helpers
#      that currently read `config('intake.auth.*')` are not changed
#      in this chunk — the values are stored but not yet consumed.
#      Chunk 8.1 (a follow-up patch) will wire reads.
#
# WHY DEFER THE READ WIRING
#   The reads touch hot-path middleware (EnsurePinFresh, PinGateService).
#   I want to land the admin UI first, verify the values save and display
#   correctly, then a tight follow-up patch swaps the reads from config()
#   to a tenant-scoped helper. Lower blast radius if anything goes wrong.
#
# IDEMPOTENT — every step checks before acting.
# ============================================================================

set -euo pipefail

APP_ROOT="${INTAKE_APP_ROOT:-/var/www/intake}"
if [ ! -d "$APP_ROOT" ]; then
    if [ -f "./artisan" ] && [ -d "./app/Models" ]; then
        APP_ROOT="$(pwd)"
    else
        echo "ERROR: APP_ROOT '$APP_ROOT' does not exist." >&2
        exit 1
    fi
fi
cd "$APP_ROOT"

echo "=========================================="
echo "Auth Refactor — Chunk 8 (admin screens)"
echo "Running in: $(pwd)"
echo "=========================================="

# ----------------------------------------------------------------------------
# STEP 1 — SecurityController
# ----------------------------------------------------------------------------

CTRL_FILE=app/Http/Controllers/Tenant/SecurityController.php

if [ -f "$CTRL_FILE" ]; then
    echo "STEP 1: SKIP (SecurityController already exists)"
else
    cat > "$CTRL_FILE" <<'PHP'
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
 * Subdomain trap: every method takes string $subdomain first.
 */
class SecurityController extends Controller
{
    public function __construct(protected DeviceTrustService $devices) {}

    /**
     * GET /admin/security
     */
    public function index(string $subdomain, Request $request)
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
    public function revokeDevice(string $subdomain, Request $request, string $deviceId)
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
    public function revokeAllDevices(string $subdomain, Request $request)
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
    public function updateSettings(string $subdomain, Request $request)
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
PHP
    echo "STEP 1: OK (created SecurityController)"
fi

# ----------------------------------------------------------------------------
# STEP 2 — Routes for security
# ----------------------------------------------------------------------------

python3 <<'PY'
from pathlib import Path
p = Path('routes/web.php')
s = p.read_text()

if "SecurityController" in s:
    print("STEP 2: SKIP (security routes already wired)")
else:
    # Place these inside the auth'd tenant group, near the existing
    # settings.* routes. Anchor: the settings update route.
    old = """            Route::patch('/settings',           [TenantControllers\\SettingsController::class, 'update'])->name('settings.update');"""

    new = """            Route::patch('/settings',           [TenantControllers\\SettingsController::class, 'update'])->name('settings.update');

            // Sign-in security admin (chunk 8) — owner-only enforced in the controller.
            Route::get('/security',                       [TenantControllers\\SecurityController::class, 'index'])->name('security.index');
            Route::patch('/security/settings',            [TenantControllers\\SecurityController::class, 'updateSettings'])->name('security.settings.update');
            Route::post('/security/device/{id}/revoke',   [TenantControllers\\SecurityController::class, 'revokeDevice'])->name('security.device.revoke');
            Route::post('/security/devices/revoke-all',   [TenantControllers\\SecurityController::class, 'revokeAllDevices'])->name('security.devices.revoke-all');"""

    if s.count(old) != 1:
        print(f"STEP 2: ABORT (anchor matches {s.count(old)} times)")
        raise SystemExit(1)
    s = s.replace(old, new)
    p.write_text(s)
    print("STEP 2: OK (security routes wired)")
PY

# ----------------------------------------------------------------------------
# STEP 3 — Security view (two tabs: Trusted Devices + Sign-in Security)
# ----------------------------------------------------------------------------

VIEW_DIR=resources/views/tenant/security
VIEW_FILE=$VIEW_DIR/index.blade.php

if [ -f "$VIEW_FILE" ]; then
    echo "STEP 3: SKIP (security view already exists)"
else
    mkdir -p "$VIEW_DIR"
    cat > "$VIEW_FILE" <<'BLADE'
@extends('layouts.tenant.app')
@php
  $pageTitle = 'Security';
@endphp

@push('styles')
<style>
.sec-tabs { display:flex; gap:8px; border-bottom:0.5px solid var(--ia-border); margin-bottom:20px; overflow-x:auto }
.sec-tabs::-webkit-scrollbar { display:none }
.sec-tab { padding:10px 16px; background:transparent; border:none; border-bottom:2px solid transparent; color:var(--ia-muted, rgba(255,255,255,.55)); font-size:13px; font-weight:500; cursor:pointer; white-space:nowrap; font-family:inherit }
.sec-tab:hover { color:var(--ia-text) }
.sec-tab.active { color:var(--ia-text); border-bottom-color:var(--ia-accent) }
.sec-pane { display:none }
.sec-pane.active { display:block }

.sec-device-row { display:grid; grid-template-columns: 1fr auto; gap:14px; padding:14px 16px; border-bottom:0.5px solid var(--ia-border); align-items:center }
.sec-device-row:last-child { border-bottom:none }
.sec-device-label { font-weight:500; font-size:14px }
.sec-device-meta { font-size:12px; opacity:.55; margin-top:4px }

.sec-form-row { display:grid; grid-template-columns: 1fr 200px; gap:20px; padding:16px 0; border-bottom:0.5px solid var(--ia-border); align-items:start }
.sec-form-row:last-child { border-bottom:none }
.sec-form-row label { font-size:13px; font-weight:500; display:block; margin-bottom:4px }
.sec-form-row .hint { font-size:11.5px; opacity:.55; line-height:1.45 }
.sec-form-row input[type=number] { width:100%; padding:8px 12px; background:rgba(255,255,255,.04); border:0.5px solid var(--ia-border); border-radius:6px; color:var(--ia-text); font-family:inherit; font-size:13px }
.sec-form-row input[type=number]:focus { outline:none; border-color:var(--ia-accent) }
.sec-suffix { display:inline-block; font-size:12px; opacity:.5; margin-left:8px }
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Security</h1>
    <p class="ia-page-subtitle">Trusted devices and sign-in policy</p>
  </div>
</div>

@if(session('success'))
  <div class="ia-notice ia-notice--success" style="margin-bottom:16px">{{ session('success') }}</div>
@endif
@if(session('error'))
  <div class="ia-notice ia-notice--error" style="margin-bottom:16px">{{ session('error') }}</div>
@endif

<div class="sec-tabs">
  <button type="button" class="sec-tab active" data-sec-tab="devices">Trusted devices</button>
  <button type="button" class="sec-tab" data-sec-tab="policy">Sign-in policy</button>
</div>

{{-- ==== Trusted devices pane ==== --}}
<div class="sec-pane active" id="sec-pane-devices">

  @if($devices->isEmpty())
    <div class="ia-empty-state" style="padding:40px 20px;text-align:center;border:0.5px dashed var(--ia-border);border-radius:8px">
      <div style="font-size:14px;font-weight:500;margin-bottom:6px">No trusted devices</div>
      <div style="font-size:12px;opacity:.5">When staff check "Trust this device" at sign-in, the browser appears here.</div>
    </div>
  @else
    <div style="margin-bottom:12px;display:flex;justify-content:space-between;align-items:center">
      <div style="font-size:13px;opacity:.65">{{ $devices->count() }} active {{ Str::plural('device', $devices->count()) }}</div>
      <form method="POST" action="{{ route('tenant.security.devices.revoke-all') }}">
        @csrf
        <button type="submit" class="ia-btn ia-btn--ghost ia-btn--sm" data-confirm="Revoke ALL trusted devices? Every browser will require email + password on next visit.">Revoke all</button>
      </form>
    </div>

    <div class="ia-table-wrap">
      @foreach($devices as $d)
      <div class="sec-device-row">
        <div>
          <div class="sec-device-label">{{ $d->label ?: 'Unnamed device' }}</div>
          <div class="sec-device-meta">
            Last used {{ $d->last_used_at?->diffForHumans() ?? '—' }}
            · IP {{ $d->ip_last_seen ?? '—' }}
            @if($d->expires_at)
              · Expires {{ $d->expires_at->diffForHumans() }}
            @endif
          </div>
          @if($d->user_agent_seen)
            <div class="sec-device-meta" style="margin-top:2px;font-family:monospace;font-size:10.5px;opacity:.4">{{ Str::limit($d->user_agent_seen, 90) }}</div>
          @endif
        </div>
        <form method="POST" action="{{ route('tenant.security.device.revoke', $d->id) }}">
          @csrf
          <button type="submit" class="ia-btn ia-btn--danger ia-btn--sm" data-confirm="Revoke this device?">Revoke</button>
        </form>
      </div>
      @endforeach
    </div>
  @endif
</div>

{{-- ==== Sign-in policy pane ==== --}}
<div class="sec-pane" id="sec-pane-policy">
  <form method="POST" action="{{ route('tenant.security.settings.update') }}">
    @csrf @method('PATCH')

    <div class="sec-form-row">
      <div>
        <label>Idle lock threshold</label>
        <div class="hint">After this much inactivity, signed-in staff see the PIN unlock overlay. The page they're on stays intact underneath. Range: 30–3600 seconds.</div>
      </div>
      <div>
        <input type="number" name="pin_idle_threshold_sec" min="30" max="3600" step="10" value="{{ old('pin_idle_threshold_sec', $policy['pin_idle_threshold_sec']) }}" required>
        <span class="sec-suffix">seconds</span>
      </div>
    </div>

    <div class="sec-form-row">
      <div>
        <label>Device trust duration</label>
        <div class="hint">How long a "Trust this device" cookie stays valid before requiring email + password again. Each use slides the window forward. Range: 1–365 days.</div>
      </div>
      <div>
        <input type="number" name="device_trust_expiry_days" min="1" max="365" step="1" value="{{ old('device_trust_expiry_days', $policy['device_trust_expiry_days']) }}" required>
        <span class="sec-suffix">days</span>
      </div>
    </div>

    <div class="sec-form-row">
      <div>
        <label>Switch-location PIN sticky window</label>
        <div class="hint">After a successful PIN re-prompt for location switch, how long until the next switch also re-prompts. Set to 0 to always prompt. Range: 0–3600 seconds.</div>
      </div>
      <div>
        <input type="number" name="switch_location_sticky_sec" min="0" max="3600" step="10" value="{{ old('switch_location_sticky_sec', $policy['switch_location_sticky_sec']) }}" required>
        <span class="sec-suffix">seconds</span>
      </div>
    </div>

    <div style="margin-top:20px;display:flex;justify-content:flex-end">
      <button type="submit" class="ia-btn ia-btn--primary">Save policy</button>
    </div>

    <div style="margin-top:18px;padding:12px 14px;background:rgba(255,255,255,.03);border:0.5px solid var(--ia-border);border-radius:6px;font-size:11.5px;opacity:.65;line-height:1.5">
      <strong>Note:</strong> Sign-in policy reads will be wired in a follow-up patch. Until then, these values save successfully but the platform defaults remain in effect.
    </div>
  </form>
</div>

@endsection

@push('scripts')
<script>
document.querySelectorAll('.sec-tab').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.sec-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.sec-pane').forEach(p => p.classList.remove('active'));
    tab.classList.add('active');
    document.getElementById('sec-pane-' + tab.dataset.secTab).classList.add('active');
  });
});
</script>
@endpush
BLADE
    echo "STEP 3: OK (created security/index.blade.php)"
fi

# ----------------------------------------------------------------------------
# STEP 4 — Add Security link to nav (sidebar manage group, gated)
# ----------------------------------------------------------------------------

python3 <<'PY'
from pathlib import Path
p = Path('resources/views/layouts/tenant/_nav-items.blade.php')
s = p.read_text()

if "tenant.security.index" in s:
    print("STEP 4: SKIP (Security already in nav)")
else:
    # Place after Team in the manage group.
    old = """    [
      'route'  => 'tenant.team.index',
      'label'  => 'Team',"""

    # Insert Security AFTER Team. We anchor on the Team block opening,
    # find its close, and insert after.
    # Simpler: insert before the Services entry (Team and Security both
    # appear before Services in the manage group).
    old_marker = """    [
      'route'  => 'tenant.services.index',
      'label'  => 'Services',
      'icon'   => '<svg width=\"14\" height=\"14\" viewBox=\"0 0 14 14\" fill=\"none\"><path d=\"M2 4h10M2 7h7M2 10h5\" stroke=\"currentColor\" stroke-width=\"1.2\" stroke-linecap=\"round\"/></svg>',
      'group'  => 'manage',
    ],"""

    new = """    [
      'route'  => 'tenant.security.index',
      'label'  => 'Security',
      'icon'   => '<svg width=\"14\" height=\"14\" viewBox=\"0 0 14 14\" fill=\"none\"><path d=\"M7 1L2 3.5v3.5c0 3.3 2.2 6 5 6s5-2.7 5-6V3.5L7 1z\" stroke=\"currentColor\" stroke-width=\"1.2\" stroke-linejoin=\"round\"/></svg>',
      'group'  => 'manage',
      'gate'   => 'additional_users_enabled',
    ],
    [
      'route'  => 'tenant.services.index',
      'label'  => 'Services',
      'icon'   => '<svg width=\"14\" height=\"14\" viewBox=\"0 0 14 14\" fill=\"none\"><path d=\"M2 4h10M2 7h7M2 10h5\" stroke=\"currentColor\" stroke-width=\"1.2\" stroke-linecap=\"round\"/></svg>',
      'group'  => 'manage',
    ],"""

    if s.count(old_marker) != 1:
        print(f"STEP 4: ABORT (anchor matches {s.count(old_marker)} times)")
        raise SystemExit(1)
    s = s.replace(old_marker, new)
    p.write_text(s)
    print("STEP 4: OK (Security added to nav)")
PY

# ----------------------------------------------------------------------------
# STEP 5 — Add Security to More drawer
# ----------------------------------------------------------------------------

python3 <<'PY'
from pathlib import Path
p = Path('resources/views/layouts/tenant/_more-drawer.blade.php')
s = p.read_text()

if "tenant.security.index" in s:
    print("STEP 5: SKIP (Security already in More drawer)")
else:
    old = "    ['route' => 'tenant.team.index',               'label' => 'Team',         'gate' => 'additional_users_enabled'],"
    new = old + "\n    ['route' => 'tenant.security.index',           'label' => 'Security',     'gate' => 'additional_users_enabled'],"

    if s.count(old) != 1:
        print(f"STEP 5: ABORT (anchor matches {s.count(old)} times)")
        raise SystemExit(1)
    s = s.replace(old, new)
    p.write_text(s)
    print("STEP 5: OK (Security added to More drawer)")
PY

# ----------------------------------------------------------------------------
# STEP 6 — Add PIN status column to Team admin view
# ----------------------------------------------------------------------------

python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/team/index.blade.php')
s = p.read_text()

if "PIN status" in s:
    print("STEP 6a: SKIP (PIN column already in team view)")
else:
    # Add header column "PIN" between Status and the actions cell.
    old_header = """        <th>Role</th>
        <th>Status</th>
        @if($me->isManager()) <th></th> @endif"""

    new_header = """        <th>Role</th>
        <th>Status</th>
        @if($currentTenant->pin_tier_active)
          <th>PIN status</th>
        @endif
        @if($me->isManager()) <th></th> @endif"""

    if s.count(old_header) != 1:
        print(f"STEP 6a: ABORT (header anchor matches {s.count(old_header)} times)")
        raise SystemExit(1)

    s = s.replace(old_header, new_header)
    p.write_text(s)
    print("STEP 6a: OK (added PIN status header)")
PY

python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/team/index.blade.php')
s = p.read_text()

if "pin_force_reset" in s:
    print("STEP 6b: SKIP (PIN row cell already injected)")
else:
    # Add the row cell + admin actions for unlock/force-reset.
    # Anchor: the closing </td> of the Status cell, before the actions cell.
    old = """        <td>
          @if($member->is_active)
            <span class=\"ia-badge ia-badge--completed\">Active</span>
          @else
            <span class=\"ia-badge ia-badge--cancelled\">Inactive</span>
          @endif
        </td>

        @if($me->isManager())
        <td>"""

    new = """        <td>
          @if($member->is_active)
            <span class=\"ia-badge ia-badge--completed\">Active</span>
          @else
            <span class=\"ia-badge ia-badge--cancelled\">Inactive</span>
          @endif
        </td>

        @if($currentTenant->pin_tier_active)
        <td>
          @php
            $pinLocked = $member->pin_locked_until && $member->pin_locked_until->isFuture();
            $hasPin    = (bool) $member->pin_hash;
          @endphp
          @if($pinLocked)
            <span class=\"ia-badge ia-badge--cancelled\">Locked</span>
          @elseif($hasPin)
            <span class=\"ia-badge ia-badge--completed\">Set</span>
          @else
            <span class=\"ia-badge ia-badge--pending\">Not set</span>
          @endif
          @if($member->pin_last_used_at)
            <div style=\"font-size:10.5px;opacity:.4;margin-top:2px\">last used {{ $member->pin_last_used_at->diffForHumans() }}</div>
          @endif
        </td>
        @endif

        @if($me->isManager())
        <td>"""

    if s.count(old) != 1:
        print(f"STEP 6b: ABORT (row anchor matches {s.count(old)} times)")
        raise SystemExit(1)
    s = s.replace(old, new)
    p.write_text(s)
    print("STEP 6b: OK (PIN status cell + lock badge injected)")
PY

# Now add Unlock + Force-reset action buttons in the actions cell.
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/team/index.blade.php')
s = p.read_text()

if "pin_unlock" in s:
    print("STEP 6c: SKIP (PIN action buttons already injected)")
else:
    # Insert PIN actions right before the Remove button. Anchor on the
    # opening of the Remove form.
    old = """              {{-- Remove --}}
              <form method=\"POST\" action=\"{{ route('tenant.team.destroy', $member->id) }}\">"""

    new = """              {{-- PIN unlock (only when locked) --}}
              @if($currentTenant->pin_tier_active && $member->pin_locked_until && $member->pin_locked_until->isFuture())
              <form method=\"POST\" action=\"{{ route('tenant.team.update', $member->id) }}\">
                @csrf @method('PATCH')
                <input type=\"hidden\" name=\"op\" value=\"pin_unlock\">
                <button type=\"submit\" class=\"ia-btn ia-btn--ghost ia-btn--sm\"
                  data-confirm=\"Unlock {{ $member->name }}'s PIN?\">
                  Unlock PIN
                </button>
              </form>
              @endif

              {{-- PIN force reset --}}
              @if($currentTenant->pin_tier_active && $member->pin_hash)
              <form method=\"POST\" action=\"{{ route('tenant.team.update', $member->id) }}\">
                @csrf @method('PATCH')
                <input type=\"hidden\" name=\"op\" value=\"pin_force_reset\">
                <button type=\"submit\" class=\"ia-btn ia-btn--ghost ia-btn--sm\"
                  data-confirm=\"Force {{ $member->name }} to set a new PIN on next sign-in?\">
                  Force PIN reset
                </button>
              </form>
              @endif

              {{-- Remove --}}
              <form method=\"POST\" action=\"{{ route('tenant.team.destroy', $member->id) }}\">"""

    if s.count(old) != 1:
        print(f"STEP 6c: ABORT (Remove button anchor matches {s.count(old)} times)")
        raise SystemExit(1)
    s = s.replace(old, new)
    p.write_text(s)
    print("STEP 6c: OK (Unlock + Force-reset buttons added)")
PY

# ----------------------------------------------------------------------------
# STEP 7 — Add pin_unlock + pin_force_reset ops to TeamController::update
# ----------------------------------------------------------------------------

python3 <<'PY'
from pathlib import Path
p = Path('app/Http/Controllers/Tenant/TeamController.php')
s = p.read_text()

if "pin_force_reset" in s:
    print("STEP 7: SKIP (PIN ops already in TeamController::update)")
else:
    # Look for the change_role case as a sibling to slot the new ops after.
    # Need to find the switch/match statement that handles op.
    # Read the file shape first.
    if "'change_role'" in s and "'reset_password'" in s:
        # There's a match() or switch on op. Find the toggle_active case
        # and add new cases right after it.
        old = """            case 'toggle_active':
                $member->forceFill(['is_active' => ! $member->is_active])->save();
                return back()->with('success', $member->name . ($member->is_active ? ' reactivated.' : ' deactivated.'));"""

        new = """            case 'toggle_active':
                $member->forceFill(['is_active' => ! $member->is_active])->save();
                return back()->with('success', $member->name . ($member->is_active ? ' reactivated.' : ' deactivated.'));

            case 'pin_unlock':
                app(\\App\\Services\\PinService::class)->unlockUser($member, $me);
                return back()->with('success', $member->name . \"'s PIN unlocked.\");

            case 'pin_force_reset':
                app(\\App\\Services\\PinService::class)->forceReset($member, $me);
                return back()->with('success', $member->name . ' will set a new PIN on next sign-in.');"""

        if s.count(old) != 1:
            print(f"STEP 7: ABORT (toggle_active anchor matches {s.count(old)} times)")
            raise SystemExit(1)
        s = s.replace(old, new)
        p.write_text(s)
        print("STEP 7: OK (pin_unlock + pin_force_reset ops added to TeamController)")
    else:
        print("STEP 7: ABORT (TeamController shape unexpected — no change_role/reset_password ops found)")
        raise SystemExit(1)
PY

# ----------------------------------------------------------------------------
# STEP 8 — Add 'pin_unlock' and 'pin_force_reset' to TeamController's
#          allowed ops validation list.
# ----------------------------------------------------------------------------

python3 <<'PY'
from pathlib import Path
p = Path('app/Http/Controllers/Tenant/TeamController.php')
s = p.read_text()

if "pin_unlock,pin_force_reset" in s or "'pin_unlock'" in s.split("'op' => [")[-1].split("]")[0] if "'op' => [" in s else False:
    print("STEP 8: SKIP (ops already in validation)")
else:
    # Look for the validation rules string.
    import re
    candidates = [
        ("'op' => 'required|in:change_role,reset_password,toggle_active'",
         "'op' => 'required|in:change_role,reset_password,toggle_active,pin_unlock,pin_force_reset'"),
        ("'op' => ['required', 'in:change_role,reset_password,toggle_active']",
         "'op' => ['required', 'in:change_role,reset_password,toggle_active,pin_unlock,pin_force_reset']"),
        ("'in:change_role,reset_password,toggle_active'",
         "'in:change_role,reset_password,toggle_active,pin_unlock,pin_force_reset'"),
    ]
    replaced = False
    for old, new in candidates:
        if s.count(old) == 1:
            s = s.replace(old, new)
            replaced = True
            break

    if not replaced:
        print("STEP 8: WARN — couldn't find exact validation rule for 'op'. ")
        print("         Manual check needed in TeamController::update — ensure 'pin_unlock' and ")
        print("         'pin_force_reset' are listed in the allowed ops, or the validator will ")
        print("         reject the new ops as 'invalid'.")
    else:
        p.write_text(s)
        print("STEP 8: OK (added pin_unlock + pin_force_reset to allowed ops)")
PY

# ----------------------------------------------------------------------------
# Verification
# ----------------------------------------------------------------------------

echo ""
echo "----------------------------------------"
echo "VERIFY: new files"
echo "----------------------------------------"
for f in app/Http/Controllers/Tenant/SecurityController.php \
         resources/views/tenant/security/index.blade.php; do
    if [ -f "$f" ]; then
        echo "  ✓ $f ($(wc -l < $f) lines)"
    else
        echo "  ✗ $f MISSING"
    fi
done

echo ""
echo "----------------------------------------"
echo "VERIFY: routes wired"
echo "----------------------------------------"
grep -n "SecurityController\|tenant.security" routes/web.php | head -6

echo ""
echo "----------------------------------------"
echo "VERIFY: nav entries"
echo "----------------------------------------"
grep -n "tenant.security.index" resources/views/layouts/tenant/_nav-items.blade.php resources/views/layouts/tenant/_more-drawer.blade.php

echo ""
echo "----------------------------------------"
echo "VERIFY: PIN status in team view"
echo "----------------------------------------"
grep -n "PIN status\|pin_unlock\|pin_force_reset" resources/views/tenant/team/index.blade.php | head -5

echo ""
echo "----------------------------------------"
echo "VERIFY: TeamController has new ops"
echo "----------------------------------------"
grep -n "pin_unlock\|pin_force_reset\|PinService" app/Http/Controllers/Tenant/TeamController.php | head -5

echo ""
echo "=========================================="
echo "Chunk 8 application complete."
echo ""
echo "Server steps:"
echo "  git pull && composer install --no-interaction --no-scripts && \\"
echo "  php artisan view:clear && php artisan optimize:clear && \\"
echo "  systemctl stop php8.3-fpm && sleep 2 && systemctl start php8.3-fpm"
echo "  (no migrations)"
echo ""
echo "Verify on thebikehub.intake.works (signed in as owner, with 2 users):"
echo ""
echo "  1. Sidebar (manage group): 'Security' link appears (gated on"
echo "     additional_users_enabled, so only Branded+)."
echo ""
echo "  2. Click Security → /admin/security loads. Two tabs:"
echo "     - Trusted devices: list of devices you've trusted (probably"
echo "       one from your earlier test). Revoke + Revoke all work."
echo "     - Sign-in policy: form with idle threshold, device trust"
echo "       duration, switch-location sticky. Save shows success notice."
echo ""
echo "  3. Click Team → new 'PIN status' column. Shows Set / Not set /"
echo "     Locked per row."
echo ""
echo "  4. If you previously soft-locked yourself in chunk 5 testing,"
echo "     'Unlock PIN' button appears on the locked row. Clicking it"
echo "     clears the lockout."
echo ""
echo "  5. 'Force PIN reset' button appears on any user with a PIN set."
echo "     Clicking it nulls their pin_hash so they re-set on next sign-in."
echo ""
echo "Server-side check before declaring victory (lesson from chunk 6):"
echo "  ls -la /var/www/intake/app/Http/Controllers/Tenant/SecurityController.php"
echo "  ls -la /var/www/intake/resources/views/tenant/security/index.blade.php"
echo "=========================================="
