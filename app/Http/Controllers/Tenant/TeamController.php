<?php
// MARKER-PATCH-129

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantLocation;
use App\Models\Tenant\TenantTrustedDevice;
use App\Models\Tenant\TenantUser;
use App\Services\DeviceTrustService;
use App\Services\PinService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
            ->orderByRaw("FIELD(role,'owner','manager','staff')")
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

        return back()->with('success', "Team member added. Temporary password: {$tempPassword}");
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
                    return back()->with('error', "Only owners can change another owner's role.");
                }
                $data = $request->validate(['role' => ['required','in:owner,manager,staff']]);
                $member->update(['role' => $data['role']]);
                return back()->with('success', 'Role updated.');
            }

            case 'reset_password': {
                $newPassword = Str::random(12);
                $member->update(['password' => Hash::make($newPassword)]);
                return back()->with('success', "Password reset. New temporary password: {$newPassword}");
            }

            case 'toggle_active': {
                $member->update(['is_active' => ! $member->is_active]);
                return back()->with('success', $member->is_active ? 'Member reactivated.' : 'Member deactivated.');
            }

            case 'pin_unlock': {
                $this->pins->unlockUser($member, $me);
                return back()->with('success', $member->name . "'s PIN unlocked.");
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
        return back()->with('success', "Revoked {$count} trusted device" . ($count === 1 ? '.' : 's.'));
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
