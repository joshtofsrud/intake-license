#!/usr/bin/env python3
"""
Patch 129 — Consolidated Team & Access section.

Replaces the separate "Team" and "Security" nav items with a single
"Team" section that covers everything access-related. New surfaces:

  /admin/team                    — list of all members (was /admin/team)
  /admin/team/{id}               — person detail (NEW)
  /admin/team/devices            — owner-only all-devices audit (was the
                                   devices tab on /admin/security)
  /admin/team/policy             — owner-only sign-in policy (was the
                                   policy tab on /admin/security)
  /admin/account                 — your own account (NEW, redirects to
                                   /admin/team/{me.id}?self=1)

Self-service surfaces (new):
  - Change own name, password, PIN (with password second factor)
  - View + revoke own trusted devices
  - "Sign out everywhere" — revokes all trusted devices

Admin-on-other actions, all on the detail page:
  - Change name, email, role, active status, locations
  - Reset password (generates temp; flashes once to admin)
  - Force PIN reset, unlock PIN
  - Sign out everywhere (for that user)
  - Remove member

Old /admin/security/* URLs redirect to the new equivalents.

This patch:
  - Adds 3 new endpoints in TeamController: show, destroy-session, etc.
  - Adds AccountController for /admin/account self-service flows
  - Folds SecurityController's logic into TeamController (devices, policy)
    leaving the old controller in place for the redirects only
  - New views: team/show.blade.php (detail), team/devices.blade.php,
                team/policy.blade.php, account/index.blade.php
  - Rewrites team/index.blade.php with the new richer table
  - Updates routes/web.php
  - Updates nav: removes "Security" entry, keeps "Team" entry
  - Adds compatibility redirects for /admin/security/*

Out of scope (deferred to later patches):
  - Outbound email (invite emails, email-change verification)
  - Cleanup of unused patch-125 schema columns

Usage:
    python3 patch-129.py /path/to/intake-license             # dry-run
    python3 patch-129.py /path/to/intake-license --apply     # write

Idempotent.
"""

import argparse
import pathlib
import re
import sys
import shutil

MARKER = 'MARKER-PATCH-129'


# ====================================================================
# NEW FILE CONTENTS
# ====================================================================

# --------------------------------------------------------------------
# app/Http/Controllers/Tenant/AccountController.php
# Self-service surfaces for the current user.
# --------------------------------------------------------------------
ACCOUNT_CONTROLLER = """<?php
// MARKER-PATCH-129

namespace App\\Http\\Controllers\\Tenant;

use App\\Http\\Controllers\\Controller;
use App\\Models\\Tenant\\TenantTrustedDevice;
use App\\Services\\PinService;
use App\\Services\\DeviceTrustService;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Auth;
use Illuminate\\Support\\Facades\\Hash;

/**
 * AccountController
 *
 * Surfaces a person's own account. Same template as TeamController's
 * person-detail view, but the form actions point at routes that act
 * on the signed-in user (no id in the URL) and the writeable fields
 * are constrained to what self-service is allowed to change.
 */
class AccountController extends Controller
{
    public function __construct(
        protected PinService $pins,
        protected DeviceTrustService $devices,
    ) {}

    public function index(Request $request)
    {
        $me = Auth::guard('tenant')->user();
        $devices = TenantTrustedDevice::activeForTenant($me->tenant_id)
            ->where('tenant_user_id', $me->id)
            ->orderBy('last_used_at', 'desc')
            ->get();
        return view('tenant.account.index', [
            'me'      => $me,
            'devices' => $devices,
        ]);
    }

    public function updateName(Request $request)
    {
        $me = Auth::guard('tenant')->user();
        $data = $request->validate(['name' => ['required','string','max:255']]);
        $me->update(['name' => $data['name']]);
        return back()->with('success', 'Name updated.');
    }

    public function updatePassword(Request $request)
    {
        $me = Auth::guard('tenant')->user();
        $data = $request->validate([
            'current_password' => ['required','string'],
            'new_password'     => ['required','string','min:10','confirmed'],
        ]);
        if (! Hash::check($data['current_password'], $me->password)) {
            return back()->withErrors(['current_password' => 'Wrong password.']);
        }
        $me->update(['password' => Hash::make($data['new_password'])]);
        return back()->with('success', 'Password updated.');
    }

    public function setPin(Request $request)
    {
        $me = Auth::guard('tenant')->user();
        $data = $request->validate([
            'pin'              => ['required','string','regex:/^\\d{4}$/'],
            'pin_confirm'      => ['required','string','same:pin'],
            'current_password' => ['required','string'],
        ]);
        if (! Hash::check($data['current_password'], $me->password)) {
            return back()->withErrors(['current_password' => 'Wrong password.']);
        }
        $this->pins->setPin($me, $data['pin']);
        return back()->with('success', 'PIN saved.');
    }

    public function clearPin(Request $request)
    {
        $me = Auth::guard('tenant')->user();
        $this->pins->forceReset($me, $me);
        return back()->with('success', 'PIN cleared. You will be prompted to set a new one next time.');
    }

    public function revokeDevice(Request $request, string $deviceId)
    {
        $me = Auth::guard('tenant')->user();
        $device = TenantTrustedDevice::where('tenant_id', $me->tenant_id)
            ->where('tenant_user_id', $me->id)
            ->where('id', $deviceId)
            ->first();
        if (! $device) {
            return back()->with('error', 'Device not found.');
        }
        $this->devices->revoke($device, $me);
        return back()->with('success', 'Device revoked.');
    }

    public function signOutEverywhere(Request $request)
    {
        $me = Auth::guard('tenant')->user();
        $this->devices->revokeAllForUser($me, $me);
        Auth::guard('tenant')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('tenant.login')
            ->with('success', 'Signed out from every browser.');
    }
}
"""


# --------------------------------------------------------------------
# Replacement TeamController with full action set
# --------------------------------------------------------------------
TEAM_CONTROLLER = """<?php
// MARKER-PATCH-129

namespace App\\Http\\Controllers\\Tenant;

use App\\Http\\Controllers\\Controller;
use App\\Models\\Tenant\\TenantLocation;
use App\\Models\\Tenant\\TenantTrustedDevice;
use App\\Models\\Tenant\\TenantUser;
use App\\Services\\DeviceTrustService;
use App\\Services\\PinService;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Auth;
use Illuminate\\Support\\Facades\\Hash;
use Illuminate\\Support\\Facades\\Log;
use Illuminate\\Support\\Str;

/**
 * TeamController — single home for team + access management.
 *
 * Routes split into three groups:
 *   - List + per-person CRUD: /admin/team, /admin/team/{id}
 *   - Owner-only all-devices audit: /admin/team/devices
 *   - Owner-only sign-in policy: /admin/team/policy
 */
class TeamController extends Controller
{
    public function __construct(
        protected PinService $pins,
        protected DeviceTrustService $devicesSvc,
    ) {}

    // ───────────────────────────── List ─────────────────────────────

    public function index()
    {
        $tenant = tenant();
        $members = TenantUser::where('tenant_id', $tenant->id)
            ->orderByRaw(\"FIELD(role,'owner','manager','staff')\")
            ->orderBy('name')
            ->get();

        // Per-member device count, attached for the table cell.
        $deviceCounts = TenantTrustedDevice::activeForTenant($tenant->id)
            ->selectRaw('tenant_user_id, COUNT(*) as c')
            ->groupBy('tenant_user_id')
            ->pluck('c', 'tenant_user_id');

        foreach ($members as $m) {
            $m->setAttribute('device_count', (int) ($deviceCounts[$m->id] ?? 0));
        }

        return view('tenant.team.index', compact('members'));
    }

    // ─────────────────────────── Invite ─────────────────────────────

    public function store(Request $request)
    {
        $this->requireManager();
        $tenant = tenant();

        $data = $request->validate([
            'name'         => ['required','string','max:255'],
            'email'        => ['required','email','max:255'],
            'role'         => ['required','in:manager,staff'],
            'location_ids' => ['nullable','array'],
            'location_ids.*' => ['uuid'],
        ]);

        $exists = TenantUser::where('tenant_id', $tenant->id)
            ->where('email', $data['email'])->exists();
        if ($exists) {
            return back()->with('error', 'A team member with that email already exists.');
        }

        $tempPassword = Str::random(12);
        $newUser = TenantUser::create([
            'tenant_id' => $tenant->id,
            'name'      => $data['name'],
            'email'     => $data['email'],
            'password'  => Hash::make($tempPassword),
            'role'      => $data['role'],
            'is_active' => true,
        ]);

        // Locations: explicit set if provided, else default location.
        $locationIds = $data['location_ids'] ?? [];
        if (empty($locationIds)) {
            $default = $tenant->locations()
                ->where('is_active', true)
                ->orderBy('is_default', 'desc')
                ->orderBy('sort_order')
                ->first();
            if ($default) $locationIds = [$default->id];
        }
        $this->syncLocations($newUser, $locationIds);

        return back()->with('success', \"Team member added. Temporary password: {$tempPassword}\");
    }

    // ─────────────────────────── Detail ─────────────────────────────

    public function show(string $id)
    {
        $tenant = tenant();
        $member = TenantUser::where('tenant_id', $tenant->id)
            ->where('id', $id)->firstOrFail();

        $me = Auth::guard('tenant')->user();
        if ($member->id === $me->id) {
            return redirect()->route('tenant.account.index');
        }

        $this->requireManager();

        $devices = TenantTrustedDevice::activeForTenant($tenant->id)
            ->where('tenant_user_id', $member->id)
            ->orderBy('last_used_at', 'desc')
            ->get();

        $allLocations = $tenant->locations()->orderBy('sort_order')->get();
        $memberLocationIds = $member->locations()->pluck('tenant_locations.id')->all();

        return view('tenant.team.show', [
            'member'            => $member,
            'devices'           => $devices,
            'allLocations'      => $allLocations,
            'memberLocationIds' => $memberLocationIds,
        ]);
    }

    // ─────────── Admin-acting-on-other update operations ────────────

    public function update(Request $request, string $id)
    {
        $this->requireManager();
        $tenant  = tenant();
        $member  = TenantUser::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
        $me      = Auth::guard('tenant')->user();
        $op      = $request->input('op');

        if ($member->id === $me->id) {
            return back()->with('error', 'Use your account page to edit yourself.');
        }

        switch ($op) {
            case 'update_account': {
                $data = $request->validate([
                    'name'  => ['required','string','max:255'],
                    'email' => ['required','email','max:255'],
                ]);
                // Email uniqueness within tenant
                $clash = TenantUser::where('tenant_id', $tenant->id)
                    ->where('email', $data['email'])
                    ->where('id', '!=', $member->id)->exists();
                if ($clash) {
                    return back()->with('error', 'Another member already uses that email.');
                }
                $member->update($data);
                return back()->with('success', 'Account updated.');
            }

            case 'change_role': {
                if ($member->role === 'owner' && $me->role !== 'owner') {
                    return back()->with('error', \"Only owners can change another owner's role.\");
                }
                $data = $request->validate(['role' => ['required','in:owner,manager,staff']]);
                $member->update(['role' => $data['role']]);
                return back()->with('success', 'Role updated.');
            }

            case 'reset_password': {
                $newPassword = Str::random(12);
                $member->update(['password' => Hash::make($newPassword)]);
                return back()->with('success', \"Password reset. New temporary password: {$newPassword}\");
            }

            case 'toggle_active': {
                $member->update(['is_active' => ! $member->is_active]);
                return back()->with('success', $member->is_active ? 'Member reactivated.' : 'Member deactivated.');
            }

            case 'pin_unlock': {
                $this->pins->unlockUser($member, $me);
                return back()->with('success', $member->name . \"'s PIN unlocked.\");
            }

            case 'pin_force_reset': {
                $this->pins->forceReset($member, $me);
                return back()->with('success', $member->name . ' will set a new PIN on next sign-in.');
            }

            case 'sign_out_everywhere': {
                $this->devicesSvc->revokeAllForUser($member, $me);
                return back()->with('success', $member->name . ' has been signed out from every browser.');
            }

            case 'update_locations': {
                $data = $request->validate([
                    'location_ids'   => ['nullable','array'],
                    'location_ids.*' => ['uuid'],
                ]);
                $this->syncLocations($member, $data['location_ids'] ?? []);
                return back()->with('success', 'Locations updated.');
            }
        }

        return back();
    }

    public function destroy(Request $request, string $id)
    {
        $this->requireManager();
        $tenant = tenant();
        $member = TenantUser::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
        $me     = Auth::guard('tenant')->user();

        if ($member->id === $me->id) {
            return back()->with('error', 'You cannot remove yourself.');
        }
        if ($member->role === 'owner') {
            $owners = TenantUser::where('tenant_id', $tenant->id)
                ->where('role', 'owner')->count();
            if ($owners <= 1) {
                return back()->with('error', 'Cannot remove the last owner.');
            }
        }
        $member->delete();
        return back()->with('success', 'Team member removed.');
    }

    // ─────────────────────────── Devices ────────────────────────────

    public function devices()
    {
        $this->requireOwner();
        $tenant = tenant();
        $devices = TenantTrustedDevice::activeForTenant($tenant->id)
            ->with(['tenantUser'])
            ->orderBy('last_used_at', 'desc')
            ->get();
        return view('tenant.team.devices', compact('devices'));
    }

    public function revokeDevice(Request $request, string $deviceId)
    {
        $this->requireOwner();
        $tenant = tenant();
        $device = TenantTrustedDevice::where('tenant_id', $tenant->id)
            ->where('id', $deviceId)->first();
        if (! $device) {
            return back()->with('error', 'Device not found.');
        }
        $this->devicesSvc->revoke($device, Auth::guard('tenant')->user());
        return back()->with('success', 'Device revoked.');
    }

    public function revokeAllDevices(Request $request)
    {
        $this->requireOwner();
        $tenant = tenant();
        $count = $this->devicesSvc->revokeAllForTenant($tenant, Auth::guard('tenant')->user());
        return back()->with('success', \"Revoked {$count} trusted device\" . ($count === 1 ? '.' : 's.'));
    }

    // ──────────────────────────── Policy ────────────────────────────

    public function policy()
    {
        $this->requireOwner();
        $tenant = tenant();
        $s = $tenant->settings ?? [];
        $policy = [
            'pin_idle_threshold_sec'       => $s['security']['pin_idle_threshold_sec']       ?? config('intake.auth.pin_idle_threshold_sec', 120),
            'device_trust_expiry_days'     => $s['security']['device_trust_expiry_days']     ?? config('intake.auth.device_trust_expiry_days', 90),
            'switch_location_sticky_sec'   => $s['security']['switch_location_sticky_sec']   ?? (config('intake.auth.pin_action_sticky_sec.switch_location', 0)),
        ];
        return view('tenant.team.policy', compact('policy'));
    }

    public function updatePolicy(Request $request)
    {
        $this->requireOwner();
        $tenant = tenant();
        $data = $request->validate([
            'pin_idle_threshold_sec'     => ['required','integer','min:30','max:3600'],
            'device_trust_expiry_days'   => ['required','integer','min:1','max:365'],
            'switch_location_sticky_sec' => ['required','integer','min:0','max:3600'],
        ]);
        $settings = $tenant->settings ?? [];
        $settings['security'] = $data;
        $tenant->forceFill(['settings' => $settings])->save();
        Log::info('Team.policy.updated', ['tenant_id' => $tenant->id, 'values' => $data]);
        return back()->with('success', 'Sign-in policy saved.');
    }

    // ──────────────────────────── Helpers ───────────────────────────

    protected function requireManager(): void
    {
        $u = Auth::guard('tenant')->user();
        if (! $u || ! $u->isManager()) abort(403, 'Manager or owner access required.');
    }

    protected function requireOwner(): void
    {
        $u = Auth::guard('tenant')->user();
        if (! $u || ! $u->isOwner()) abort(403, 'Owner access required.');
    }

    /**
     * Sync a member's location grants. Removes grants not in $ids,
     * adds the rest. Idempotent. Always preserves at least the
     * default location if $ids is empty.
     */
    protected function syncLocations(TenantUser $user, array $ids): void
    {
        $tenant = tenant();
        $valid = TenantLocation::where('tenant_id', $tenant->id)
            ->whereIn('id', $ids)->pluck('id')->all();

        $current = $user->locations()->pluck('tenant_locations.id')->all();
        $toAdd    = array_diff($valid, $current);
        $toRemove = array_diff($current, $valid);

        foreach ($toAdd as $locId) {
            $user->locations()->attach($locId, [
                'id'        => (string) Str::uuid(),
                'is_active' => true,
                'tenant_id' => $tenant->id,
            ]);
        }
        if (! empty($toRemove)) {
            $user->locations()->detach($toRemove);
        }
    }
}
"""


# --------------------------------------------------------------------
# resources/views/tenant/team/index.blade.php
# Richer list with PIN + devices + last-seen columns
# --------------------------------------------------------------------
TEAM_INDEX_VIEW = """{{-- MARKER-PATCH-129 — team list (replaces old team/index.blade.php) --}}
@extends('layouts.tenant.app')
@php
  $pageTitle = 'Team';
  $me        = Auth::guard('tenant')->user();
  $pinModeOn = $currentTenant->pin_tier_active;
@endphp

@push('styles')
<style>
.tm-invite { background:var(--ia-surface); border:0.5px solid var(--ia-border); border-radius:var(--ia-r-lg); padding:20px 24px; margin-bottom:24px; display:none; }
.tm-invite.open { display:block; }
.tm-name { font-weight:500; font-size:13px; }
.tm-name-sub { font-size:11px; color:var(--ia-text-dim); margin-top:1px; }
.tm-me-tag { display:inline-block; margin-left:6px; font-size:9.5px; font-weight:500; padding:1px 6px; border-radius:8px; background:var(--ia-accent); color:var(--ia-accent-text); }
.tm-avatar { width:30px; height:30px; border-radius:50%; background:var(--ia-accent); color:var(--ia-accent-text); display:inline-flex; align-items:center; justify-content:center; font-size:11px; font-weight:600; flex-shrink:0; }
.tm-row-clickable { cursor:pointer; }
.tm-row-clickable:hover td { background:rgba(255,255,255,.025); }
.tm-row-me td { background:var(--ia-accent-soft); }
</style>
@endpush

@section('content')
<div class=\"ia-page-head\">
  <div class=\"ia-page-head-left\">
    <h1 class=\"ia-page-title\">Team</h1>
    <p class=\"ia-page-subtitle\">{{ $members->count() }} {{ Str::plural('member', $members->count()) }}
      @if($pinModeOn) · PIN mode on @endif
    </p>
  </div>
  <div class=\"ia-page-actions\">
    @if($me->isOwner())
      <a href=\"{{ route('tenant.team.devices') }}\" class=\"ia-btn ia-btn--ghost\">All devices</a>
      <a href=\"{{ route('tenant.team.policy') }}\" class=\"ia-btn ia-btn--ghost\">Sign-in policy</a>
    @endif
    @if($me->isManager())
      <button type=\"button\" class=\"ia-btn ia-btn--primary\" id=\"invite-toggle\">+ Invite member</button>
    @endif
  </div>
</div>

@if($me->isManager())
<div class=\"tm-invite\" id=\"invite-card\">
  <div style=\"font-size:13px;font-weight:500;margin-bottom:16px\">Invite a team member</div>
  <form method=\"POST\" action=\"{{ route('tenant.team.store') }}\">
    @csrf
    <div class=\"ia-input-grid-3\">
      <div class=\"ia-form-group\">
        <label class=\"ia-form-label\">Name <span class=\"ia-required\">*</span></label>
        <input type=\"text\" name=\"name\" class=\"ia-input\" value=\"{{ old('name') }}\" required>
      </div>
      <div class=\"ia-form-group\">
        <label class=\"ia-form-label\">Email <span class=\"ia-required\">*</span></label>
        <input type=\"email\" name=\"email\" class=\"ia-input\" value=\"{{ old('email') }}\" required>
      </div>
      <div class=\"ia-form-group\">
        <label class=\"ia-form-label\">Role <span class=\"ia-required\">*</span></label>
        <select name=\"role\" class=\"ia-input\">
          <option value=\"staff\"   @selected(old('role') === 'staff')>Staff</option>
          <option value=\"manager\" @selected(old('role') === 'manager')>Manager</option>
        </select>
      </div>
    </div>
    <div style=\"display:flex;gap:8px;margin-top:4px\">
      <button type=\"submit\" class=\"ia-btn ia-btn--primary ia-btn--sm\">Send invite</button>
      <button type=\"button\" class=\"ia-btn ia-btn--ghost ia-btn--sm\" id=\"invite-cancel\">Cancel</button>
    </div>
  </form>
</div>
@endif

<div class=\"ia-card ia-card--tight\" style=\"margin-bottom:20px;font-size:12px;color:var(--ia-text-dim)\">
  <strong style=\"color:var(--ia-text)\">Owner</strong> — full access including billing &nbsp;·&nbsp;
  <strong style=\"color:var(--ia-text)\">Manager</strong> — full access except billing &nbsp;·&nbsp;
  <strong style=\"color:var(--ia-text)\">Staff</strong> — appointments and customers only
</div>

<div class=\"ia-table-wrap\">
  <table class=\"ia-table\">
    <thead>
      <tr>
        <th>Name</th>
        <th>Role</th>
        <th>Status</th>
        @if($pinModeOn)<th>PIN</th>@endif
        <th>Devices</th>
        <th>Last seen</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      @foreach($members as $member)
      @php $isMe = $member->id === $me->id; @endphp
      <tr class=\"tm-row-clickable @if($isMe) tm-row-me @endif\"
          onclick=\"window.location.href='{{ $isMe ? route('tenant.account.index') : route('tenant.team.show', $member->id) }}'\">
        <td>
          <div style=\"display:flex;align-items:center;gap:10px\">
            <div class=\"tm-avatar\">{{ strtoupper(substr($member->name, 0, 2)) }}</div>
            <div>
              <div class=\"tm-name\">{{ $member->name }}@if($isMe)<span class=\"tm-me-tag\">you</span>@endif</div>
              <div class=\"tm-name-sub\">{{ $member->email }}</div>
            </div>
          </div>
        </td>
        <td><span class=\"ia-badge\">{{ ucfirst($member->role) }}</span></td>
        <td>
          @if($member->is_active)
            <span class=\"ia-badge ia-badge--completed\">Active</span>
          @else
            <span class=\"ia-badge ia-badge--cancelled\">Inactive</span>
          @endif
        </td>
        @if($pinModeOn)
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
        </td>
        @endif
        <td style=\"font-size:12px;color:var(--ia-text-dim)\">
          @if($member->device_count > 0)
            {{ $member->device_count }} {{ Str::plural('device', $member->device_count) }}
          @else
            —
          @endif
        </td>
        <td style=\"font-size:12px;color:var(--ia-text-dim)\">
          {{ $member->last_login_at?->diffForHumans() ?? 'never' }}
        </td>
        <td style=\"text-align:right;color:var(--ia-text-dim);font-family:var(--ia-font-mono);font-size:14px\">›</td>
      </tr>
      @endforeach
    </tbody>
  </table>
</div>
@endsection

@push('scripts')
<script>
  var t = document.getElementById('invite-toggle');
  var c = document.getElementById('invite-card');
  var x = document.getElementById('invite-cancel');
  if (t) t.addEventListener('click', function(){ c.classList.add('open'); t.style.display='none'; });
  if (x) x.addEventListener('click', function(){ c.classList.remove('open'); t.style.display=''; });
  @if(session('success') && str_contains(session('success'), 'password'))
    c && c.classList.add('open');
  @endif
</script>
@endpush
"""


# --------------------------------------------------------------------
# resources/views/tenant/team/show.blade.php — person detail
# --------------------------------------------------------------------
TEAM_SHOW_VIEW = """{{-- MARKER-PATCH-129 — person detail --}}
@extends('layouts.tenant.app')
@php
  $pageTitle = $member->name;
  $me        = Auth::guard('tenant')->user();
  $pinModeOn = $currentTenant->pin_tier_active;
  $pinLocked = $member->pin_locked_until && $member->pin_locked_until->isFuture();
@endphp

@push('styles')
<style>
.pd-head { display:flex; gap:16px; align-items:center; padding-bottom:18px; border-bottom:0.5px solid var(--ia-border); margin-bottom:20px; }
.pd-avatar { width:56px; height:56px; border-radius:50%; background:var(--ia-accent); color:var(--ia-accent-text); display:inline-flex; align-items:center; justify-content:center; font-size:18px; font-weight:600; flex-shrink:0; }
.pd-h2 { font-size:20px; font-weight:600; margin:0; }
.pd-sub { font-size:12px; color:var(--ia-text-dim); margin-top:3px; }
.pd-actions { margin-left:auto; display:flex; gap:6px; }
.pd-field { display:grid; grid-template-columns:180px 1fr; gap:16px; padding:12px 0; align-items:center; border-top:0.5px solid var(--ia-border); }
.pd-field:first-of-type { border-top:none; padding-top:2px; }
.pd-field-label { font-size:12px; color:var(--ia-text-dim); }
.pd-field-value { font-size:13px; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.pd-field-hint { font-size:11px; color:var(--ia-text-dim); }
.pd-device { display:grid; grid-template-columns:1fr auto; gap:14px; padding:12px 0; border-top:0.5px solid var(--ia-border); align-items:center; }
.pd-device:first-of-type { border-top:none; padding-top:2px; }
.pd-device-label { font-size:13px; font-weight:500; }
.pd-device-meta { font-size:11px; color:var(--ia-text-dim); margin-top:3px; }
.pd-loc-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:8px; }
.pd-loc { display:flex; align-items:center; gap:8px; font-size:13px; padding:8px 10px; border:0.5px solid var(--ia-border); border-radius:var(--ia-r-md); }
.pd-empty { padding:24px; text-align:center; border:0.5px dashed var(--ia-border-strong); border-radius:var(--ia-r-md); font-size:12px; color:var(--ia-text-dim); }
.pd-back { font-size:12px; color:var(--ia-text-dim); display:inline-flex; align-items:center; gap:4px; margin-bottom:12px; text-decoration:none; }
.pd-back:hover { color:var(--ia-text-muted); }
</style>
@endpush

@section('content')
<a href=\"{{ route('tenant.team.index') }}\" class=\"pd-back\">← All team members</a>

<div class=\"pd-head\">
  <div class=\"pd-avatar\">{{ strtoupper(substr($member->name, 0, 2)) }}</div>
  <div>
    <h2 class=\"pd-h2\">{{ $member->name }}</h2>
    <div class=\"pd-sub\">
      {{ ucfirst($member->role) }} ·
      @if($member->is_active) Active @else Inactive @endif ·
      last seen {{ $member->last_login_at?->diffForHumans() ?? 'never' }}
    </div>
  </div>
  <div class=\"pd-actions\">
    <form method=\"POST\" action=\"{{ route('tenant.team.update', $member->id) }}\" style=\"display:inline\">
      @csrf @method('PATCH')
      <input type=\"hidden\" name=\"op\" value=\"toggle_active\">
      <button class=\"ia-btn ia-btn--ghost ia-btn--sm\"
              data-confirm=\"{{ $member->is_active ? 'Deactivate' : 'Reactivate' }} {{ $member->name }}?\">
        {{ $member->is_active ? 'Deactivate' : 'Reactivate' }}
      </button>
    </form>
    <form method=\"POST\" action=\"{{ route('tenant.team.destroy', $member->id) }}\" style=\"display:inline\">
      @csrf @method('DELETE')
      <button class=\"ia-btn ia-btn--ghost ia-btn--sm\" style=\"color:#F87171\"
              data-confirm=\"Remove {{ $member->name }} from the team?\">Remove</button>
    </form>
  </div>
</div>

{{-- Account --}}
<div class=\"ia-card\" style=\"margin-bottom:14px\">
  <div class=\"ia-card-head\"><span class=\"ia-card-title\">Account</span></div>
  <p style=\"font-size:12px;color:var(--ia-text-dim);margin:0 0 14px\">Basic identity. The user cannot change their own email or role.</p>

  <form method=\"POST\" action=\"{{ route('tenant.team.update', $member->id) }}\">
    @csrf @method('PATCH')
    <input type=\"hidden\" name=\"op\" value=\"update_account\">
    <div class=\"pd-field\">
      <div class=\"pd-field-label\">Name</div>
      <div class=\"pd-field-value\"><input class=\"ia-input\" name=\"name\" value=\"{{ $member->name }}\" style=\"min-width:280px\"></div>
    </div>
    <div class=\"pd-field\">
      <div class=\"pd-field-label\">Email</div>
      <div class=\"pd-field-value\"><input class=\"ia-input\" type=\"email\" name=\"email\" value=\"{{ $member->email }}\" style=\"min-width:320px\"></div>
    </div>
    <div style=\"display:flex;justify-content:flex-end;margin-top:8px\">
      <button class=\"ia-btn ia-btn--primary ia-btn--sm\">Save</button>
    </div>
  </form>

  <form method=\"POST\" action=\"{{ route('tenant.team.update', $member->id) }}\">
    @csrf @method('PATCH')
    <input type=\"hidden\" name=\"op\" value=\"change_role\">
    <div class=\"pd-field\">
      <div class=\"pd-field-label\">Role</div>
      <div class=\"pd-field-value\">
        <select name=\"role\" class=\"ia-input\" style=\"width:auto\">
          @foreach(['owner','manager','staff'] as $r)
            <option value=\"{{ $r }}\" @selected($member->role === $r)>{{ ucfirst($r) }}</option>
          @endforeach
        </select>
        <button class=\"ia-btn ia-btn--ghost ia-btn--sm\">Change role</button>
      </div>
    </div>
  </form>
</div>

{{-- Credentials --}}
<div class=\"ia-card\" style=\"margin-bottom:14px\">
  <div class=\"ia-card-head\"><span class=\"ia-card-title\">Credentials</span></div>
  <p style=\"font-size:12px;color:var(--ia-text-dim);margin:0 0 14px\">Reset their password or PIN. The user will be required to set new credentials on next sign-in.</p>

  @if($pinLocked)
    <div class=\"ia-notice ia-notice--error\" style=\"margin-bottom:12px\">
      <strong>PIN is locked.</strong> Too many wrong attempts. Cooldown ends
      {{ $member->pin_locked_until->diffForHumans() }}.
    </div>
  @endif

  <form method=\"POST\" action=\"{{ route('tenant.team.update', $member->id) }}\">
    @csrf @method('PATCH')
    <input type=\"hidden\" name=\"op\" value=\"reset_password\">
    <div class=\"pd-field\">
      <div class=\"pd-field-label\">Password</div>
      <div class=\"pd-field-value\">
        <button class=\"ia-btn ia-btn--ghost ia-btn--sm\"
                data-confirm=\"Reset password for {{ $member->name }}?\">Reset password</button>
        <span class=\"pd-field-hint\">Generates a temporary password. They'll change it on next sign-in.</span>
      </div>
    </div>
  </form>

  @if($pinModeOn)
  <div class=\"pd-field\">
    <div class=\"pd-field-label\">PIN</div>
    <div class=\"pd-field-value\">
      @if($pinLocked)
        <span class=\"ia-badge ia-badge--cancelled\">Locked</span>
        <form method=\"POST\" action=\"{{ route('tenant.team.update', $member->id) }}\" style=\"display:inline\">
          @csrf @method('PATCH')
          <input type=\"hidden\" name=\"op\" value=\"pin_unlock\">
          <button class=\"ia-btn ia-btn--ghost ia-btn--sm\"
                  data-confirm=\"Unlock {{ $member->name }}'s PIN?\">Unlock</button>
        </form>
      @elseif($member->pin_hash)
        <span class=\"ia-badge ia-badge--completed\">Set</span>
      @else
        <span class=\"ia-badge ia-badge--pending\">Not set</span>
      @endif
      @if($member->pin_hash)
        <form method=\"POST\" action=\"{{ route('tenant.team.update', $member->id) }}\" style=\"display:inline\">
          @csrf @method('PATCH')
          <input type=\"hidden\" name=\"op\" value=\"pin_force_reset\">
          <button class=\"ia-btn ia-btn--ghost ia-btn--sm\"
                  data-confirm=\"Force {{ $member->name }} to set a new PIN?\">Force reset</button>
        </form>
      @endif
    </div>
  </div>
  @endif

  <form method=\"POST\" action=\"{{ route('tenant.team.update', $member->id) }}\">
    @csrf @method('PATCH')
    <input type=\"hidden\" name=\"op\" value=\"sign_out_everywhere\">
    <div class=\"pd-field\">
      <div class=\"pd-field-label\">Active sessions</div>
      <div class=\"pd-field-value\">
        <button class=\"ia-btn ia-btn--ghost ia-btn--sm\" style=\"color:#F87171\"
                data-confirm=\"Sign {{ $member->name }} out from every browser?\">Sign out everywhere</button>
        <span class=\"pd-field-hint\">Revokes every trusted device. They will sign in fresh.</span>
      </div>
    </div>
  </form>
</div>

{{-- Locations --}}
<div class=\"ia-card\" style=\"margin-bottom:14px\">
  <div class=\"ia-card-head\"><span class=\"ia-card-title\">Locations</span></div>
  <p style=\"font-size:12px;color:var(--ia-text-dim);margin:0 0 14px\">Which locations this person has access to.</p>

  <form method=\"POST\" action=\"{{ route('tenant.team.update', $member->id) }}\">
    @csrf @method('PATCH')
    <input type=\"hidden\" name=\"op\" value=\"update_locations\">
    <div class=\"pd-loc-grid\">
      @foreach($allLocations as $loc)
        <label class=\"pd-loc\">
          <input type=\"checkbox\" name=\"location_ids[]\" value=\"{{ $loc->id }}\"
                 @checked(in_array($loc->id, $memberLocationIds))>
          <span>{{ $loc->name }}@if($loc->is_default) <span style=\"font-size:11px;color:var(--ia-text-dim)\">(default)</span>@endif</span>
        </label>
      @endforeach
    </div>
    <div style=\"display:flex;justify-content:flex-end;margin-top:14px\">
      <button class=\"ia-btn ia-btn--primary ia-btn--sm\">Save locations</button>
    </div>
  </form>
</div>

{{-- Devices --}}
<div class=\"ia-card\" style=\"margin-bottom:14px\">
  <div class=\"ia-card-head\"><span class=\"ia-card-title\">Trusted devices</span></div>
  <p style=\"font-size:12px;color:var(--ia-text-dim);margin:0 0 14px\">Browsers they signed in from with \"Trust this device\" checked.</p>

  @if($devices->isEmpty())
    <div class=\"pd-empty\">No trusted devices. They sign in with email + password every visit.</div>
  @else
    @foreach($devices as $d)
      <div class=\"pd-device\">
        <div>
          <div class=\"pd-device-label\">{{ $d->label ?: 'Unnamed device' }}</div>
          <div class=\"pd-device-meta\">
            Last used {{ $d->last_used_at?->diffForHumans() ?? '—' }}
            · IP {{ $d->ip_last_seen ?? '—' }}
            @if($d->expires_at) · Expires {{ $d->expires_at->diffForHumans() }} @endif
          </div>
        </div>
      </div>
    @endforeach
  @endif
</div>
@endsection
"""


# --------------------------------------------------------------------
# resources/views/tenant/team/devices.blade.php
# Owner-only all-devices audit page
# --------------------------------------------------------------------
TEAM_DEVICES_VIEW = """{{-- MARKER-PATCH-129 — owner all-devices audit --}}
@extends('layouts.tenant.app')
@php $pageTitle = 'All devices'; @endphp

@push('styles')
<style>
.td-row { display:grid; grid-template-columns:1fr auto; gap:14px; padding:12px 0; border-top:0.5px solid var(--ia-border); align-items:center; }
.td-row:first-of-type { border-top:none; padding-top:2px; }
.td-label { font-size:13px; font-weight:500; display:flex; align-items:center; gap:8px; }
.td-meta { font-size:11px; color:var(--ia-text-dim); margin-top:3px; }
.td-back { font-size:12px; color:var(--ia-text-dim); display:inline-flex; align-items:center; gap:4px; margin-bottom:12px; text-decoration:none; }
.td-back:hover { color:var(--ia-text-muted); }
.td-empty { padding:28px; text-align:center; border:0.5px dashed var(--ia-border-strong); border-radius:var(--ia-r-md); font-size:12px; color:var(--ia-text-dim); }
</style>
@endpush

@section('content')
<a href=\"{{ route('tenant.team.index') }}\" class=\"td-back\">← Team</a>

<div class=\"ia-page-head\">
  <div class=\"ia-page-head-left\">
    <h1 class=\"ia-page-title\">All trusted devices</h1>
    <p class=\"ia-page-subtitle\">{{ $devices->count() }} active {{ Str::plural('device', $devices->count()) }} across the team</p>
  </div>
  @if($devices->isNotEmpty())
  <div class=\"ia-page-actions\">
    <form method=\"POST\" action=\"{{ route('tenant.team.devices.revoke-all') }}\">
      @csrf
      <button class=\"ia-btn ia-btn--ghost ia-btn--sm\" style=\"color:#F87171\"
              data-confirm=\"Revoke ALL trusted devices? Every browser will require email + password on next visit.\">
        Revoke all
      </button>
    </form>
  </div>
  @endif
</div>

<div class=\"ia-card\">
@if($devices->isEmpty())
  <div class=\"td-empty\">No trusted devices anywhere on this shop.</div>
@else
  @foreach($devices as $d)
    <div class=\"td-row\">
      <div>
        <div class=\"td-label\">
          {{ $d->label ?: 'Unnamed device' }}
          @if($d->tenantUser)
            <span class=\"ia-badge\" style=\"font-size:10px\">{{ $d->tenantUser->name }}</span>
          @endif
        </div>
        <div class=\"td-meta\">
          Last used {{ $d->last_used_at?->diffForHumans() ?? '—' }}
          · IP {{ $d->ip_last_seen ?? '—' }}
          @if($d->expires_at) · Expires {{ $d->expires_at->diffForHumans() }} @endif
        </div>
      </div>
      <form method=\"POST\" action=\"{{ route('tenant.team.devices.revoke', $d->id) }}\">
        @csrf
        <button class=\"ia-btn ia-btn--ghost ia-btn--sm\" style=\"color:#F87171\"
                data-confirm=\"Revoke this device?\">Revoke</button>
      </form>
    </div>
  @endforeach
@endif
</div>
@endsection
"""


# --------------------------------------------------------------------
# resources/views/tenant/team/policy.blade.php
# Owner-only sign-in policy page
# --------------------------------------------------------------------
TEAM_POLICY_VIEW = """{{-- MARKER-PATCH-129 — sign-in policy (was tab on /admin/security) --}}
@extends('layouts.tenant.app')
@php $pageTitle = 'Sign-in policy'; @endphp

@push('styles')
<style>
.tp-row { display:grid; grid-template-columns:1fr 200px; gap:20px; padding:16px 0; border-top:0.5px solid var(--ia-border); align-items:start; }
.tp-row:first-of-type { border-top:none; padding-top:2px; }
.tp-row label { font-size:13px; font-weight:500; display:block; margin-bottom:4px; }
.tp-row .hint { font-size:11.5px; color:var(--ia-text-dim); line-height:1.55; }
.tp-row input[type=number] { width:100%; padding:7px 10px; background:var(--ia-input-bg); border:0.5px solid var(--ia-border); border-radius:var(--ia-r-md); color:var(--ia-text); font-family:inherit; font-size:13px; }
.tp-suffix { font-size:11px; color:var(--ia-text-dim); margin-left:6px; }
.tp-back { font-size:12px; color:var(--ia-text-dim); display:inline-flex; align-items:center; gap:4px; margin-bottom:12px; text-decoration:none; }
.tp-back:hover { color:var(--ia-text-muted); }
</style>
@endpush

@section('content')
<a href=\"{{ route('tenant.team.index') }}\" class=\"tp-back\">← Team</a>

<div class=\"ia-page-head\">
  <div class=\"ia-page-head-left\">
    <h1 class=\"ia-page-title\">Sign-in policy</h1>
    <p class=\"ia-page-subtitle\">How strict sign-in is for everyone at {{ $currentTenant->name }}. Owner only.</p>
  </div>
</div>

<div class=\"ia-card\">
  <form method=\"POST\" action=\"{{ route('tenant.team.policy.update') }}\">
    @csrf @method('PATCH')

    <div class=\"tp-row\">
      <div>
        <label>Idle lock threshold</label>
        <div class=\"hint\">After this much inactivity, signed-in staff see a PIN unlock overlay. Their work stays intact underneath.</div>
      </div>
      <div>
        <input type=\"number\" name=\"pin_idle_threshold_sec\" min=\"30\" max=\"3600\" step=\"10\" value=\"{{ old('pin_idle_threshold_sec', $policy['pin_idle_threshold_sec']) }}\" required>
        <span class=\"tp-suffix\">seconds</span>
      </div>
    </div>

    <div class=\"tp-row\">
      <div>
        <label>Device trust duration</label>
        <div class=\"hint\">How long a \"Trust this device\" cookie stays valid before requiring email + password again. Each use slides the window forward.</div>
      </div>
      <div>
        <input type=\"number\" name=\"device_trust_expiry_days\" min=\"1\" max=\"365\" step=\"1\" value=\"{{ old('device_trust_expiry_days', $policy['device_trust_expiry_days']) }}\" required>
        <span class=\"tp-suffix\">days</span>
      </div>
    </div>

    <div class=\"tp-row\">
      <div>
        <label>Switch-location PIN sticky window</label>
        <div class=\"hint\">After a successful PIN re-prompt for location switch, how long before the next switch also re-prompts. Set 0 to always prompt.</div>
      </div>
      <div>
        <input type=\"number\" name=\"switch_location_sticky_sec\" min=\"0\" max=\"3600\" step=\"10\" value=\"{{ old('switch_location_sticky_sec', $policy['switch_location_sticky_sec']) }}\" required>
        <span class=\"tp-suffix\">seconds</span>
      </div>
    </div>

    <div style=\"margin-top:18px;display:flex;justify-content:flex-end\">
      <button class=\"ia-btn ia-btn--primary\">Save policy</button>
    </div>
  </form>
</div>
@endsection
"""


# --------------------------------------------------------------------
# resources/views/tenant/account/index.blade.php — self-service
# --------------------------------------------------------------------
ACCOUNT_INDEX_VIEW = """{{-- MARKER-PATCH-129 — your own account --}}
@extends('layouts.tenant.app')
@php
  $pageTitle = 'Your account';
  $pinModeOn = $currentTenant->pin_tier_active;
@endphp

@push('styles')
<style>
.ac-head { display:flex; gap:16px; align-items:center; padding-bottom:18px; border-bottom:0.5px solid var(--ia-border); margin-bottom:20px; }
.ac-avatar { width:56px; height:56px; border-radius:50%; background:var(--ia-accent); color:var(--ia-accent-text); display:inline-flex; align-items:center; justify-content:center; font-size:18px; font-weight:600; }
.ac-h2 { font-size:20px; font-weight:600; margin:0; }
.ac-sub { font-size:12px; color:var(--ia-text-dim); margin-top:3px; }
.ac-actions { margin-left:auto; }
.ac-field { display:grid; grid-template-columns:180px 1fr; gap:16px; padding:12px 0; align-items:center; border-top:0.5px solid var(--ia-border); }
.ac-field:first-of-type { border-top:none; padding-top:2px; }
.ac-field-label { font-size:12px; color:var(--ia-text-dim); }
.ac-field-value { font-size:13px; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.ac-field-hint { font-size:11px; color:var(--ia-text-dim); }
.ac-device { display:grid; grid-template-columns:1fr auto; gap:14px; padding:12px 0; border-top:0.5px solid var(--ia-border); align-items:center; }
.ac-device:first-of-type { border-top:none; padding-top:2px; }
.ac-device-label { font-size:13px; font-weight:500; }
.ac-device-meta { font-size:11px; color:var(--ia-text-dim); margin-top:3px; }
.ac-pin-pad { display:flex; gap:6px; }
.ac-pin-pad input { width:38px; height:44px; text-align:center; background:var(--ia-input-bg); border:0.5px solid var(--ia-border); color:var(--ia-text); font-family:var(--ia-font-mono); font-size:18px; border-radius:var(--ia-r-md); }
.ac-empty { padding:24px; text-align:center; border:0.5px dashed var(--ia-border-strong); border-radius:var(--ia-r-md); font-size:12px; color:var(--ia-text-dim); }
</style>
@endpush

@section('content')

<div class=\"ac-head\">
  <div class=\"ac-avatar\">{{ strtoupper(substr($me->name, 0, 2)) }}</div>
  <div>
    <h2 class=\"ac-h2\">Your account</h2>
    <div class=\"ac-sub\">{{ $me->name }} · {{ ucfirst($me->role) }} · signed in {{ $me->last_login_at?->diffForHumans() ?? 'just now' }}</div>
  </div>
  <div class=\"ac-actions\">
    <form method=\"POST\" action=\"{{ route('tenant.account.sign-out-everywhere') }}\">
      @csrf
      <button class=\"ia-btn ia-btn--ghost ia-btn--sm\" style=\"color:#F87171\"
              data-confirm=\"Sign you out of every browser including this one?\">Sign out everywhere</button>
    </form>
  </div>
</div>

{{-- Account --}}
<div class=\"ia-card\" style=\"margin-bottom:14px\">
  <div class=\"ia-card-head\"><span class=\"ia-card-title\">Account</span></div>
  <p style=\"font-size:12px;color:var(--ia-text-dim);margin:0 0 14px\">Update your name. Email and role are managed by an owner — ask them if you need a change.</p>

  <form method=\"POST\" action=\"{{ route('tenant.account.name') }}\">
    @csrf @method('PATCH')
    <div class=\"ac-field\">
      <div class=\"ac-field-label\">Name</div>
      <div class=\"ac-field-value\"><input class=\"ia-input\" name=\"name\" value=\"{{ $me->name }}\" style=\"min-width:280px\" required></div>
    </div>
    <div class=\"ac-field\">
      <div class=\"ac-field-label\">Email</div>
      <div class=\"ac-field-value\">
        <span style=\"font-family:var(--ia-font-mono);font-size:12.5px\">{{ $me->email }}</span>
        <span class=\"ac-field-hint\">Ask an owner to change this.</span>
      </div>
    </div>
    <div class=\"ac-field\">
      <div class=\"ac-field-label\">Role</div>
      <div class=\"ac-field-value\"><span class=\"ia-badge\">{{ ucfirst($me->role) }}</span></div>
    </div>
    <div style=\"display:flex;justify-content:flex-end;margin-top:8px\">
      <button class=\"ia-btn ia-btn--primary ia-btn--sm\">Save</button>
    </div>
  </form>
</div>

{{-- Password --}}
<div class=\"ia-card\" style=\"margin-bottom:14px\">
  <div class=\"ia-card-head\"><span class=\"ia-card-title\">Password</span></div>
  <p style=\"font-size:12px;color:var(--ia-text-dim);margin:0 0 14px\">Used for signing in and confirming sensitive changes.</p>

  <form method=\"POST\" action=\"{{ route('tenant.account.password') }}\">
    @csrf @method('PATCH')
    <div class=\"ac-field\">
      <div class=\"ac-field-label\">Current password</div>
      <div class=\"ac-field-value\"><input class=\"ia-input\" type=\"password\" name=\"current_password\" placeholder=\"••••••••\" style=\"min-width:280px\" required></div>
    </div>
    <div class=\"ac-field\">
      <div class=\"ac-field-label\">New password</div>
      <div class=\"ac-field-value\"><input class=\"ia-input\" type=\"password\" name=\"new_password\" placeholder=\"At least 10 characters\" style=\"min-width:280px\" required minlength=\"10\"></div>
    </div>
    <div class=\"ac-field\">
      <div class=\"ac-field-label\">Confirm new password</div>
      <div class=\"ac-field-value\"><input class=\"ia-input\" type=\"password\" name=\"new_password_confirmation\" placeholder=\"Match above\" style=\"min-width:280px\" required></div>
    </div>
    <div style=\"display:flex;justify-content:flex-end;margin-top:8px\">
      <button class=\"ia-btn ia-btn--primary ia-btn--sm\">Update password</button>
    </div>
  </form>
</div>

{{-- PIN --}}
@if($pinModeOn)
<div class=\"ia-card\" style=\"margin-bottom:14px\">
  <div class=\"ia-card-head\"><span class=\"ia-card-title\">PIN</span></div>
  <p style=\"font-size:12px;color:var(--ia-text-dim);margin:0 0 14px\">A 4-digit PIN unlocks the screen after idle timeout. Required while this shop has PIN mode on.</p>

  @if(! $me->pin_hash)
    <div class=\"ia-notice ia-notice--warning\" style=\"margin-bottom:12px\">
      <strong>You haven't set a PIN yet.</strong> You'll be prompted to set one the next time you're idle. You can also set it here now.
    </div>
  @endif

  <form method=\"POST\" action=\"{{ route('tenant.account.pin') }}\">
    @csrf @method('PATCH')
    <div class=\"ac-field\">
      <div class=\"ac-field-label\">{{ $me->pin_hash ? 'New PIN' : 'Set a PIN' }}</div>
      <div class=\"ac-field-value\"><input class=\"ia-input\" name=\"pin\" maxlength=\"4\" pattern=\"\\d{4}\" placeholder=\"4 digits\" style=\"width:120px;font-family:var(--ia-font-mono);font-size:18px;letter-spacing:6px;text-align:center\" required></div>
    </div>
    <div class=\"ac-field\">
      <div class=\"ac-field-label\">Confirm PIN</div>
      <div class=\"ac-field-value\"><input class=\"ia-input\" name=\"pin_confirm\" maxlength=\"4\" pattern=\"\\d{4}\" placeholder=\"4 digits\" style=\"width:120px;font-family:var(--ia-font-mono);font-size:18px;letter-spacing:6px;text-align:center\" required></div>
    </div>
    <div class=\"ac-field\">
      <div class=\"ac-field-label\">Your password</div>
      <div class=\"ac-field-value\">
        <input class=\"ia-input\" type=\"password\" name=\"current_password\" placeholder=\"••••••••\" style=\"min-width:280px\" required>
        <span class=\"ac-field-hint\">Re-enter to confirm.</span>
      </div>
    </div>
    <div style=\"display:flex;justify-content:flex-end;margin-top:8px;gap:8px\">
      @if($me->pin_hash)
        <button type=\"submit\" formaction=\"{{ route('tenant.account.pin.clear') }}\"
                class=\"ia-btn ia-btn--ghost ia-btn--sm\"
                data-confirm=\"Clear your PIN? You'll be asked to set a new one.\">Clear PIN</button>
      @endif
      <button class=\"ia-btn ia-btn--primary ia-btn--sm\">Save PIN</button>
    </div>
  </form>
</div>
@endif

{{-- Your devices --}}
<div class=\"ia-card\" style=\"margin-bottom:14px\">
  <div class=\"ia-card-head\"><span class=\"ia-card-title\">Your devices</span></div>
  <p style=\"font-size:12px;color:var(--ia-text-dim);margin:0 0 14px\">Browsers you've trusted. Revoke any you don't recognise.</p>

  @if($devices->isEmpty())
    <div class=\"ac-empty\">No trusted devices.</div>
  @else
    @foreach($devices as $d)
      <div class=\"ac-device\">
        <div>
          <div class=\"ac-device-label\">{{ $d->label ?: 'Unnamed device' }}</div>
          <div class=\"ac-device-meta\">
            Last used {{ $d->last_used_at?->diffForHumans() ?? '—' }}
            · IP {{ $d->ip_last_seen ?? '—' }}
            @if($d->expires_at) · Expires {{ $d->expires_at->diffForHumans() }} @endif
          </div>
        </div>
        <form method=\"POST\" action=\"{{ route('tenant.account.device.revoke', $d->id) }}\">
          @csrf
          <button class=\"ia-btn ia-btn--ghost ia-btn--sm\" style=\"color:#F87171\"
                  data-confirm=\"Revoke this device?\">Revoke</button>
        </form>
      </div>
    @endforeach
  @endif
</div>

@endsection
"""


# ====================================================================
# Driver
# ====================================================================

NEW_FILES = {
    'app/Http/Controllers/Tenant/TeamController.php':       TEAM_CONTROLLER,
    'app/Http/Controllers/Tenant/AccountController.php':    ACCOUNT_CONTROLLER,
    'resources/views/tenant/team/index.blade.php':          TEAM_INDEX_VIEW,
    'resources/views/tenant/team/show.blade.php':           TEAM_SHOW_VIEW,
    'resources/views/tenant/team/devices.blade.php':        TEAM_DEVICES_VIEW,
    'resources/views/tenant/team/policy.blade.php':         TEAM_POLICY_VIEW,
    'resources/views/tenant/account/index.blade.php':       ACCOUNT_INDEX_VIEW,
}


# ─── Route table swap ────────────────────────────────────────────────

OLD_ROUTES = """            Route::get('/security',                       [TenantControllers\\SecurityController::class, 'index'])->name('security.index');
            Route::patch('/security/settings',            [TenantControllers\\SecurityController::class, 'updateSettings'])->name('security.settings.update');
            Route::post('/security/device/{id}/revoke',   [TenantControllers\\SecurityController::class, 'revokeDevice'])->name('security.device.revoke');
            Route::post('/security/devices/revoke-all',   [TenantControllers\\SecurityController::class, 'revokeAllDevices'])->name('security.devices.revoke-all');"""

NEW_ROUTES_SECURITY_REDIRECTS = """            // MARKER-PATCH-129 — old /admin/security/* URLs redirect to /admin/team/*
            Route::get('/security',                  fn() => redirect()->route('tenant.team.index'))->name('security.index');
            Route::get('/security/devices',          fn() => redirect()->route('tenant.team.devices'));
            Route::get('/security/policy',           fn() => redirect()->route('tenant.team.policy'));"""

OLD_TEAM_ROUTES = """            Route::get('/team',                 [TenantControllers\\TeamController::class, 'index'])->name('team.index');
            Route::post('/team',                [TenantControllers\\TeamController::class, 'store'])->name('team.store');
            Route::patch('/team/{id}',          [TenantControllers\\TeamController::class, 'update'])->name('team.update');
            Route::delete('/team/{id}',         [TenantControllers\\TeamController::class, 'destroy'])->name('team.destroy');"""

NEW_TEAM_ROUTES = """            // MARKER-PATCH-129 — consolidated Team & Access
            Route::get('/team',                            [TenantControllers\\TeamController::class, 'index'])->name('team.index');
            Route::post('/team',                           [TenantControllers\\TeamController::class, 'store'])->name('team.store');
            Route::get('/team/devices',                    [TenantControllers\\TeamController::class, 'devices'])->name('team.devices');
            Route::post('/team/devices/{id}/revoke',       [TenantControllers\\TeamController::class, 'revokeDevice'])->name('team.devices.revoke');
            Route::post('/team/devices/revoke-all',        [TenantControllers\\TeamController::class, 'revokeAllDevices'])->name('team.devices.revoke-all');
            Route::get('/team/policy',                     [TenantControllers\\TeamController::class, 'policy'])->name('team.policy');
            Route::patch('/team/policy',                   [TenantControllers\\TeamController::class, 'updatePolicy'])->name('team.policy.update');
            Route::get('/team/{id}',                       [TenantControllers\\TeamController::class, 'show'])->name('team.show');
            Route::patch('/team/{id}',                     [TenantControllers\\TeamController::class, 'update'])->name('team.update');
            Route::delete('/team/{id}',                    [TenantControllers\\TeamController::class, 'destroy'])->name('team.destroy');

            // Self-service account surfaces (current user only)
            Route::get('/account',                         [TenantControllers\\AccountController::class, 'index'])->name('account.index');
            Route::patch('/account/name',                  [TenantControllers\\AccountController::class, 'updateName'])->name('account.name');
            Route::patch('/account/password',              [TenantControllers\\AccountController::class, 'updatePassword'])->name('account.password');
            Route::patch('/account/pin',                   [TenantControllers\\AccountController::class, 'setPin'])->name('account.pin');
            Route::patch('/account/pin/clear',             [TenantControllers\\AccountController::class, 'clearPin'])->name('account.pin.clear');
            Route::post('/account/device/{id}/revoke',     [TenantControllers\\AccountController::class, 'revokeDevice'])->name('account.device.revoke');
            Route::post('/account/sign-out-everywhere',    [TenantControllers\\AccountController::class, 'signOutEverywhere'])->name('account.sign-out-everywhere');"""


# ─── Nav swap ────────────────────────────────────────────────────────

OLD_NAV_TEAM_ENTRY = """    [
      'route'  => 'tenant.team.index',
      'label'  => 'Team',
      'icon'   => '<svg width=\"14\" height=\"14\" viewBox=\"0 0 14 14\" fill=\"none\"><circle cx=\"5\" cy=\"5\" r=\"2.2\" stroke=\"currentColor\" stroke-width=\"1.2\"/><circle cx=\"10.5\" cy=\"5.5\" r=\"1.6\" stroke=\"currentColor\" stroke-width=\"1.2\"/><path d=\"M1.5 12c0-1.8 1.5-3 3.5-3s3.5 1.2 3.5 3\" stroke=\"currentColor\" stroke-width=\"1.2\" stroke-linecap=\"round\"/><path d=\"M8.5 12c0-1.4 1-2.4 2.5-2.4s2.5 1 2.5 2.4\" stroke=\"currentColor\" stroke-width=\"1.2\" stroke-linecap=\"round\"/></svg>',
      'group'  => 'manage',
      'gate'   => 'additional_users_enabled',
    ],
    [
      'route'  => 'tenant.security.index',
      'label'  => 'Security',
      'icon'   => '<svg width=\"14\" height=\"14\" viewBox=\"0 0 14 14\" fill=\"none\"><path d=\"M7 1L2 3.5v3.5c0 3.3 2.2 6 5 6s5-2.7 5-6V3.5L7 1z\" stroke=\"currentColor\" stroke-width=\"1.2\" stroke-linejoin=\"round\"/></svg>',
      'group'  => 'manage',
      'gate'   => 'additional_users_enabled',
    ],"""

NEW_NAV_TEAM_ENTRY = """    // MARKER-PATCH-129 — Team & Access (consolidated from Team + Security)
    [
      'route'  => 'tenant.team.index',
      'label'  => 'Team & access',
      'icon'   => '<svg width=\"14\" height=\"14\" viewBox=\"0 0 14 14\" fill=\"none\"><circle cx=\"5\" cy=\"5\" r=\"2.2\" stroke=\"currentColor\" stroke-width=\"1.2\"/><circle cx=\"10.5\" cy=\"5.5\" r=\"1.6\" stroke=\"currentColor\" stroke-width=\"1.2\"/><path d=\"M1.5 12c0-1.8 1.5-3 3.5-3s3.5 1.2 3.5 3\" stroke=\"currentColor\" stroke-width=\"1.2\" stroke-linecap=\"round\"/><path d=\"M8.5 12c0-1.4 1-2.4 2.5-2.4s2.5 1 2.5 2.4\" stroke=\"currentColor\" stroke-width=\"1.2\" stroke-linecap=\"round\"/></svg>',
      'group'  => 'manage',
      'gate'   => 'additional_users_enabled',
    ],"""

OLD_MORE_DRAWER = """    ['route' => 'tenant.team.index',               'label' => 'Team',         'gate' => 'additional_users_enabled'],
    ['route' => 'tenant.security.index',           'label' => 'Security',     'gate' => 'additional_users_enabled'],"""

NEW_MORE_DRAWER = """    // MARKER-PATCH-129
    ['route' => 'tenant.team.index',               'label' => 'Team & access', 'gate' => 'additional_users_enabled'],"""


# ====================================================================
# Driver implementation
# ====================================================================

def process(root: pathlib.Path, apply: bool) -> dict:
    summary = {}

    # 1. Write new files (overwrite any existing — TeamController and
    #    team/index.blade.php are intentionally replaced)
    for rel, content in NEW_FILES.items():
        path = root / rel
        if path.exists() and path.read_text() == content:
            summary[f'file:{rel}'] = 'unchanged'
            continue
        if apply:
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(content)
            summary[f'file:{rel}'] = 'written'
        else:
            summary[f'file:{rel}'] = 'would_write'

    # 2. Route table edit
    routes = root / 'routes' / 'web.php'
    routes_text = routes.read_text()
    if MARKER in routes_text and "name('team.policy.update')" in routes_text:
        summary['routes'] = 'already_applied'
    else:
        # Replace security routes block
        if OLD_ROUTES not in routes_text:
            print('ERROR: security routes anchor not found in routes/web.php', file=sys.stderr)
            sys.exit(2)
        routes_text = routes_text.replace(OLD_ROUTES, NEW_ROUTES_SECURITY_REDIRECTS, 1)
        # Replace team routes block
        if OLD_TEAM_ROUTES not in routes_text:
            print('ERROR: team routes anchor not found in routes/web.php', file=sys.stderr)
            sys.exit(2)
        routes_text = routes_text.replace(OLD_TEAM_ROUTES, NEW_TEAM_ROUTES, 1)
        if apply:
            routes.write_text(routes_text)
        summary['routes'] = 'updated'

    # 3. Nav swap (sidebar items)
    nav = root / 'resources' / 'views' / 'layouts' / 'tenant' / '_nav-items.blade.php'
    nav_text = nav.read_text()
    if MARKER in nav_text:
        summary['nav'] = 'already_applied'
    elif OLD_NAV_TEAM_ENTRY in nav_text:
        nav_text = nav_text.replace(OLD_NAV_TEAM_ENTRY, NEW_NAV_TEAM_ENTRY, 1)
        if apply:
            nav.write_text(nav_text)
        summary['nav'] = 'updated'
    else:
        print('ERROR: nav items anchor not found in _nav-items.blade.php', file=sys.stderr)
        sys.exit(2)

    # 4. Mobile more-drawer swap
    more = root / 'resources' / 'views' / 'layouts' / 'tenant' / '_more-drawer.blade.php'
    more_text = more.read_text()
    if MARKER in more_text:
        summary['more_drawer'] = 'already_applied'
    elif OLD_MORE_DRAWER in more_text:
        more_text = more_text.replace(OLD_MORE_DRAWER, NEW_MORE_DRAWER, 1)
        if apply:
            more.write_text(more_text)
        summary['more_drawer'] = 'updated'
    else:
        print('ERROR: more-drawer anchor not found', file=sys.stderr)
        sys.exit(2)

    # 5. DeviceTrustService: add revokeAllForUser if not present
    svc = root / 'app' / 'Services' / 'DeviceTrustService.php'
    svc_text = svc.read_text()
    if 'revokeAllForUser' in svc_text:
        summary['device_service'] = 'already_applied'
    else:
        new_method = """
    /**
     * MARKER-PATCH-129 — revoke every active device belonging to one user.
     * Powers "Sign out everywhere" actions in self-service + admin.
     */
    public function revokeAllForUser(TenantUser $user, ?TenantUser $byUser = null): int
    {
        $count = TenantTrustedDevice::activeForTenant($user->tenant_id)
            ->where('tenant_user_id', $user->id)
            ->update([
                'revoked_at'         => now(),
                'revoked_by_user_id' => $byUser?->id,
                'updated_at'         => now(),
            ]);

        Log::info('DeviceTrust.revokeAllForUser', [
            'tenant_id'  => $user->tenant_id,
            'user_id'    => $user->id,
            'count'      => $count,
            'by_user'    => $byUser?->id,
        ]);

        return $count;
    }
}
"""
        # Replace the final closing brace (and trailing newline) with method + brace
        old_tail = "        return $count;\n    }\n}\n"
        if old_tail not in svc_text:
            print('ERROR: DeviceTrustService tail anchor not found', file=sys.stderr)
            sys.exit(2)
        new_text = svc_text.replace(old_tail, "        return $count;\n    }\n" + new_method, 1)
        if apply:
            svc.write_text(new_text)
        summary['device_service'] = 'method_added'

    return summary


def verify(root: pathlib.Path) -> list[str]:
    failures = []

    for rel in NEW_FILES:
        p = root / rel
        if not p.exists():
            failures.append(f'{rel} missing')
            continue
        if MARKER not in p.read_text():
            failures.append(f'{rel} missing MARKER')

    routes = (root / 'routes' / 'web.php').read_text()
    if "name('team.devices')" not in routes:
        failures.append('team.devices route missing')
    if "name('team.policy')" not in routes:
        failures.append('team.policy route missing')
    if "name('account.index')" not in routes:
        failures.append('account.index route missing')

    nav = (root / 'resources' / 'views' / 'layouts' / 'tenant' / '_nav-items.blade.php').read_text()
    if 'Security' in nav and "tenant.security.index" in nav:
        failures.append('Security nav entry still present')

    return failures


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('root')
    ap.add_argument('--apply', action='store_true')
    args = ap.parse_args()

    root = pathlib.Path(args.root)
    if not (root / 'routes' / 'web.php').exists():
        print(f'ERROR: {root} does not look like an intake repo', file=sys.stderr)
        sys.exit(2)

    mode = 'APPLY' if args.apply else 'DRY-RUN'
    print(f'=== patch-129 [{mode}] target={root} ===\\n')

    summary = process(root, apply=args.apply)
    print('Summary:')
    for k, v in summary.items():
        print(f'  {k}: {v}')

    if args.apply:
        print('\\nVerifying...')
        failures = verify(root)
        if failures:
            print('\\nFAIL:')
            for f in failures:
                print(f'  - {f}')
            sys.exit(1)
        print('  all checks pass')
    else:
        print('\\n(dry-run — no files written.)')


if __name__ == '__main__':
    main()
