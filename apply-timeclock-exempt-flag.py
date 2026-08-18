#!/usr/bin/env python3
"""Timeclock exemption: per-user 'Never clocks in' flag.
Adds tenant_users.exempt_from_timeclock, hides the clock-in nudge for
exempt users, and adds an owner/manager toggle on the team member page.
Run from repo root: python3 apply-timeclock-exempt-flag.py
"""
import os, sys

ROOT = os.getcwd()
def path(p): return os.path.join(ROOT, p)
def read(p):
    with open(path(p)) as f: return f.read()
def write(p, s):
    with open(path(p), 'w') as f: f.write(s)
def sub(p, old, new, label):
    s = read(p)
    if new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    write(p, s.replace(old, new, 1))
    print(f"OK: {label}")

# 1) Migration
mig = 'database/migrations/2026_08_16_100000_add_timeclock_exempt_to_tenant_users.php'
if os.path.exists(path(mig)):
    print("SKIP (exists): migration")
else:
    write(mig, """<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

// MARKER-TIMECLOCK-EXEMPT — owners/salaried staff who never clock in
// shouldn't see the persistent clock-in nudge on every page load.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->boolean('exempt_from_timeclock')->default(false)->after('is_active');
        });
    }
    public function down(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->dropColumn('exempt_from_timeclock');
        });
    }
};
""")
    print("OK: migration")

# 2) Model — fillable + cast
sub('app/Models/Tenant/TenantUser.php',
    "'role','role_id','admin_theme','is_active'",
    "'role','role_id','admin_theme','is_active','exempt_from_timeclock'",
    "TenantUser fillable")
sub('app/Models/Tenant/TenantUser.php',
    "'is_active' => 'boolean',",
    "'is_active' => 'boolean', 'exempt_from_timeclock' => 'boolean',",
    "TenantUser cast")

# 3) Layout — guard the nudge
sub('resources/views/layouts/tenant/app.blade.php',
    "@if(!empty($authUser) && empty($pinLockPending))",
    "@if(!empty($authUser) && empty($pinLockPending) && !$authUser->exempt_from_timeclock)",
    "layout nudge guard")

# 4) Controller — toggle op (mirrors toggle_active)
sub('app/Http/Controllers/Tenant/TeamController.php',
    """            case 'toggle_active': {""",
    """            case 'toggle_timeclock_exempt': {
                // MARKER-TIMECLOCK-EXEMPT
                $member->update(['exempt_from_timeclock' => ! $member->exempt_from_timeclock]);
                return back()->with('success', $member->exempt_from_timeclock
                    ? $member->name . ' will no longer be prompted to clock in.'
                    : $member->name . ' will be prompted to clock in again.');
            }

            case 'toggle_active': {""",
    "TeamController toggle op")

# 5) Team member page — toggle UI after the role form
sub('resources/views/tenant/team/show.blade.php',
    """        <button class="ia-btn ia-btn--ghost ia-btn--sm">Change role</button>
      </div>
    </div>
  </form>
</div>""",
    """        <button class="ia-btn ia-btn--ghost ia-btn--sm">Change role</button>
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
    "team show toggle UI")

print("\nDone. Run migration after deploy.")
