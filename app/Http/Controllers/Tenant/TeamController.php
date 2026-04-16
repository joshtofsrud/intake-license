<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TeamController extends Controller
{
    public function index()
    {
        $members = TenantUser::where('tenant_id', tenant()->id)
            ->orderByRaw("FIELD(role,'owner','manager','staff')")
            ->orderBy('name')
            ->get();
        return view('tenant.team.index', compact('members'));
    }

    public function store(Request $request)
    {
        $this->requireManager();
        $tenant = tenant();

        $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role'  => ['required', 'in:manager,staff'],
        ]);

        $existing = TenantUser::where('tenant_id', $tenant->id)
            ->where('email', $request->input('email'))
            ->exists();

        if ($existing) {
            return back()->with('error', 'A team member with that email already exists.');
        }

        $tempPassword = Str::random(12);

        TenantUser::create([
            'tenant_id' => $tenant->id,
            'name'      => $request->input('name'),
            'email'     => $request->input('email'),
            'password'  => Hash::make($tempPassword),
            'role'      => $request->input('role'),
            'is_active' => true,
        ]);

        // In production, email the temp password to the new member.
        // For now, flash it once so the admin can share it manually.
        return back()->with('success', "Team member added. Temporary password: {$tempPassword}");
    }

    public function update(Request $request, string $id)
    {
        $this->requireManager();
        $tenant = tenant();
        $member = TenantUser::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
        $me     = Auth::guard('tenant')->user();
        $op     = $request->input('op');

        // Can't edit yourself through this flow
        if ($member->id === $me->id) {
            return back()->with('error', 'Use your profile settings to update your own account.');
        }

        if ($op === 'change_role') {
            // Can't change an owner's role unless you're also an owner
            if ($member->role === 'owner' && $me->role !== 'owner') {
                return back()->with('error', 'Only owners can change another owner\'s role.');
            }
            $request->validate(['role' => ['required', 'in:owner,manager,staff']]);
            $member->update(['role' => $request->input('role')]);
            return back()->with('success', 'Role updated.');
        }

        if ($op === 'reset_password') {
            $newPassword = Str::random(12);
            $member->update(['password' => Hash::make($newPassword)]);
            return back()->with('success', "Password reset. New temporary password: {$newPassword}");
        }

        if ($op === 'toggle_active') {
            $member->update(['is_active' => ! $member->is_active]);
            return back()->with('success', $member->is_active ? 'Member reactivated.' : 'Member deactivated.');
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

        // Ensure at least one owner remains
        if ($member->role === 'owner') {
            $ownerCount = TenantUser::where('tenant_id', $tenant->id)->where('role', 'owner')->count();
            if ($ownerCount <= 1) {
                return back()->with('error', 'Cannot remove the last owner.');
            }
        }

        $member->delete();
        return back()->with('success', 'Team member removed.');
    }

    private function requireManager(): void
    {
        $user = Auth::guard('tenant')->user();
        if (! $user || ! $user->isManager()) {
            abort(403, 'Manager or owner access required.');
        }
    }
}
