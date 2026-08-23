#!/usr/bin/env python3
"""Invite an owner directly.

The data model has always supported several owners — TeamController
already guards "only owners can grant the Owner role" and "cannot remove
the last owner". The invite form just never offered it, so adding a
second owner meant inviting them as a Manager and then promoting them
from the member page. That two-step isn't obvious, which is why it reads
as "you can only have one owner".

Now: an owner (and only an owner) sees Owner in the invite role list,
with the same server-side guard on the store path so the option can't be
forged by a manager posting the form.
Run from repo root: python3 apply-owner-invite.py
"""
import sys

def sub(p, old, new, label):
    s = open(p).read()
    if new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    open(p, 'w').write(s.replace(old, new, 1))
    print(f"OK: {label}")

CTRL = 'app/Http/Controllers/Tenant/TeamController.php'
VIEW = 'resources/views/tenant/team/index.blade.php'

# ---------------------------------------------------------------- controller
sub(CTRL,
    """        $data = $request->validate([
            'name'         => ['required','string','max:255'],
            'email'        => ['required','email','max:255'],
            'role'         => ['required','in:manager,staff'],
            'location_ids' => ['nullable','array'],
            'location_ids.*' => ['uuid'],
        ]);""",
    """        $data = $request->validate([
            'name'         => ['required','string','max:255'],
            'email'        => ['required','email','max:255'],
            'role'         => ['required','in:owner,manager,staff'],
            'location_ids' => ['nullable','array'],
            'location_ids.*' => ['uuid'],
        ]);

        // MARKER-OWNER-INVITE — several owners have always been supported (see
        // the "cannot remove the last owner" guard); the form just never
        // offered it. Granting Owner stays owner-only, matching change_role.
        if ($data['role'] === 'owner' && ! Auth::guard('tenant')->user()->isOwner()) {
            return back()->with('error', 'Only an owner can invite another owner.');
        }""",
    "controller: allow owner role")

# ---------------------------------------------------------------- view
sub(VIEW,
    """        <select name="role" class="ia-input">
          <option value="staff"   @selected(old('role') === 'staff')>Staff</option>
          <option value="manager" @selected(old('role') === 'manager')>Manager</option>
        </select>""",
    """        <select name="role" class="ia-input">
          <option value="staff"   @selected(old('role') === 'staff')>Staff</option>
          <option value="manager" @selected(old('role') === 'manager')>Manager</option>
          {{-- MARKER-OWNER-INVITE — a shop can have several owners; only an
               owner can create one. --}}
          @if($me->isOwner())
            <option value="owner" @selected(old('role') === 'owner')>Owner</option>
          @endif
        </select>
        @if($me->isOwner())
          <div style="font-size:11px;color:var(--ia-text-dim);margin-top:5px">Owners get full access including billing. A shop can have more than one.</div>
        @endif""",
    "view: owner option")

print("Done. No migration needed. view:clear after deploy.")
