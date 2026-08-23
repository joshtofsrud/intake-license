#!/usr/bin/env python3
"""Team member page: stop the account Save from swallowing a role change.

Three forms sit stacked inside one Account card with no visual break, and
the account form's "Save" button renders immediately ABOVE the Role row.
Change the role dropdown, hit the nearest Save, and you post
op=update_account — the role_id never leaves the page, the account saves,
the modal says "Account updated." and the select snaps back. Nothing is
rejected and nothing warns you; it just looks like roles don't persist.
(Cost a live debugging session on Aug 23 chasing a phantom write bug.)

Fix: each block gets its own bordered section with its label and its own
action, so a button visibly belongs to the fields above it. The role row
puts its button beside the select. change_role also stops claiming a
write when the role didn't change.
Run from repo root: python3 apply-team-role-form-separation.py
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

VIEW = 'resources/views/tenant/team/show.blade.php'
CTRL = 'app/Http/Controllers/Tenant/TeamController.php'

# ---------------------------------------------------------------- view
sub(VIEW,
    """  <form method="POST" action="{{ route('tenant.team.update', $member->id) }}">
    @csrf @method('PATCH')
    <input type="hidden" name="op" value="update_account">
    <div class="pd-field">
      <div class="pd-field-label">Name</div>
      <div class="pd-field-value"><input class="ia-input" name="name" value="{{ $member->name }}" style="min-width:280px"></div>
    </div>
    <div class="pd-field">
      <div class="pd-field-label">Email</div>
      <div class="pd-field-value"><input class="ia-input" type="email" name="email" value="{{ $member->email }}" style="min-width:320px"></div>
    </div>
    <div style="display:flex;justify-content:flex-end;margin-top:8px">
      <button class="ia-btn ia-btn--primary ia-btn--sm">Save</button>
    </div>
  </form>

  <form method="POST" action="{{ route('tenant.team.update', $member->id) }}">
    @csrf @method('PATCH')
    <input type="hidden" name="op" value="change_role">
    <div class="pd-field">
      <div class="pd-field-label">Role</div>
      <div class="pd-field-value">
        {{-- MARKER-PATCH-494 — named roles --}}
        <select name="role_id" class="ia-input" style="width:auto">
          @foreach($allRoles as $r)
            <option value="{{ $r->id }}" @selected($member->role_id === $r->id)>{{ $r->name }}</option>
          @endforeach
        </select>
        <button class="ia-btn ia-btn--ghost ia-btn--sm">Change role</button>
      </div>
    </div>
  </form>

  {{-- MARKER-TIMECLOCK-EXEMPT — owners/salaried staff opt-out of the clock-in nudge --}}
  <form method="POST" action="{{ route('tenant.team.update', $member->id) }}" style="margin-top:12px">
    @csrf @method('PATCH')
    <input type="hidden" name="op" value="toggle_timeclock_exempt">
    <div class="pd-field">
      <div class="pd-field-label">Time clock</div>
      <div class="pd-field-value" style="display:flex;align-items:center;gap:10px">
        <span style="font-size:12.5px;color:var(--ia-text-dim)">
          {{ $member->exempt_from_timeclock ? 'Never clocks in — no clock-in prompts.' : 'Clocks in — sees the clock-in prompt when off the clock.' }}
        </span>
        <button class="ia-btn ia-btn--ghost ia-btn--sm">
          {{ $member->exempt_from_timeclock ? 'Require clock-in' : 'Mark as never clocks in' }}
        </button>
      </div>
    </div>
  </form>
</div>""",
    """  {{-- MARKER-TEAM-FORM-SEP — these three forms used to sit stacked with no
       break, and the identity Save button rendered directly above the Role
       row. Changing the role and pressing that Save posted the ACCOUNT form:
       role_id was never sent, the modal said "Account updated." and the
       select snapped back — indistinguishable from a broken write. Each
       block is now its own bordered section owning its own action. --}}
  <div class="tm-block">
    <form method="POST" action="{{ route('tenant.team.update', $member->id) }}">
      @csrf @method('PATCH')
      <input type="hidden" name="op" value="update_account">
      <div class="tm-block-label">Name &amp; email</div>
      <div class="pd-field">
        <div class="pd-field-label">Name</div>
        <div class="pd-field-value"><input class="ia-input" name="name" value="{{ $member->name }}" style="min-width:280px"></div>
      </div>
      <div class="pd-field">
        <div class="pd-field-label">Email</div>
        <div class="pd-field-value"><input class="ia-input" type="email" name="email" value="{{ $member->email }}" style="min-width:320px"></div>
      </div>
      <div style="display:flex;justify-content:flex-end;margin-top:8px">
        <button class="ia-btn ia-btn--primary ia-btn--sm">Save name &amp; email</button>
      </div>
    </form>
  </div>

  <div class="tm-block">
    <form method="POST" action="{{ route('tenant.team.update', $member->id) }}">
      @csrf @method('PATCH')
      <input type="hidden" name="op" value="change_role">
      <div class="tm-block-label">Role</div>
      <div class="pd-field">
        <div class="pd-field-label">Access level</div>
        <div class="pd-field-value" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          {{-- MARKER-PATCH-494 — named roles --}}
          <select name="role_id" class="ia-input" style="width:auto">
            @foreach($allRoles as $r)
              <option value="{{ $r->id }}" @selected($member->role_id === $r->id)>{{ $r->name }}</option>
            @endforeach
          </select>
          <button class="ia-btn ia-btn--primary ia-btn--sm">Change role</button>
          <span style="font-size:11.5px;color:var(--ia-text-dim)">Takes effect on their next page load.</span>
        </div>
      </div>
    </form>
  </div>

  {{-- MARKER-TIMECLOCK-EXEMPT — owners/salaried staff opt-out of the clock-in nudge --}}
  <div class="tm-block tm-block--last">
    <form method="POST" action="{{ route('tenant.team.update', $member->id) }}">
      @csrf @method('PATCH')
      <input type="hidden" name="op" value="toggle_timeclock_exempt">
      <div class="tm-block-label">Time clock</div>
      <div class="pd-field">
        <div class="pd-field-label">Clock-in prompts</div>
        <div class="pd-field-value" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <span style="font-size:12.5px;color:var(--ia-text-dim)">
            {{ $member->exempt_from_timeclock ? 'Never clocks in — no clock-in prompts.' : 'Clocks in — sees the clock-in prompt when off the clock.' }}
          </span>
          <button class="ia-btn ia-btn--ghost ia-btn--sm">
            {{ $member->exempt_from_timeclock ? 'Require clock-in' : 'Mark as never clocks in' }}
          </button>
        </div>
      </div>
    </form>
  </div>
</div>""",
    "view: separate blocks")

# Styles go in the view's existing @push('styles') block.
sub(VIEW,
    "@push('styles')",
    """@push('styles')
<style>
  /* MARKER-TEAM-FORM-SEP — one visible block per action, so a button is
     never adjacent to fields it doesn't submit. */
  .tm-block { padding:14px 0; border-bottom:.5px solid var(--ia-border); }
  .tm-block:first-of-type { padding-top:0; }
  .tm-block--last { border-bottom:none; padding-bottom:0; }
  .tm-block-label { font-size:10.5px; text-transform:uppercase; letter-spacing:.07em;
                    color:var(--ia-text-dim); font-weight:700; margin-bottom:10px; }
</style>""",
    "view: block styles")

# ---------------------------------------------------------------- controller
sub(CTRL,
    """                $enum = 'staff';
                if ($newRole->is_system && $newRole->name === 'Owner')   $enum = 'owner';
                if ($newRole->is_system && $newRole->name === 'Manager') $enum = 'manager';
                $member->update(['role' => $enum, 'role_id' => $newRole->id]);
                return back()->with('success', "Role updated to {$newRole->name}.");""",
    """                // MARKER-TEAM-FORM-SEP — don't claim a write that didn't happen.
                if ($member->role_id === $newRole->id) {
                    return back()->with('success', $member->name . ' already has the ' . $newRole->name . ' role — nothing changed.');
                }
                $enum = 'staff';
                if ($newRole->is_system && $newRole->name === 'Owner')   $enum = 'owner';
                if ($newRole->is_system && $newRole->name === 'Manager') $enum = 'manager';
                $member->update(['role' => $enum, 'role_id' => $newRole->id]);
                return back()->with('success', $member->name . " is now {$newRole->name}.");""",
    "controller: honest role message")

print("Done. No migration needed. view:clear after deploy.")
