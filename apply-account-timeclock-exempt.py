#!/usr/bin/env python3
"""Self-serve timeclock exemption. The Team page redirects you to your
own account page when the member is you, so an owner could never flip
their own "never clocks in" flag. Put the toggle where self-service
belongs: /admin/account.
Run from repo root: python3 apply-account-timeclock-exempt.py
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

# 1) Controller op
sub('app/Http/Controllers/Tenant/AccountController.php',
    """    public function updatePassword(Request $request)""",
    """    // MARKER-TIMECLOCK-EXEMPT — self-serve: owners and salaried staff turn
    // off their own clock-in nudge (Team redirects self-edits here).
    public function updateTimeclockExempt(Request $request)
    {
        $me = Auth::guard('tenant')->user();
        $exempt = (bool) $request->boolean('exempt_from_timeclock');
        $me->update(['exempt_from_timeclock' => $exempt]);

        return back()->with('success', $exempt
            ? "You won't be prompted to clock in anymore."
            : 'Clock-in prompts are back on.');
    }

    public function updatePassword(Request $request)""",
    "controller op")

# 2) Route
sub('routes/web.php',
    """            Route::patch('/account/password',              [TenantControllers\\AccountController::class, 'updatePassword'])->name('account.password');""",
    """            Route::patch('/account/password',              [TenantControllers\\AccountController::class, 'updatePassword'])->name('account.password');
            Route::patch('/account/timeclock-exempt',      [TenantControllers\\AccountController::class, 'updateTimeclockExempt'])->name('account.timeclock-exempt'); // MARKER-TIMECLOCK-EXEMPT""",
    "route")

# 3) View card — after the Account card, before Password
sub('resources/views/tenant/account/index.blade.php',
    """<div class="ia-card" style="margin-bottom:14px">
  <div class="ia-card-head"><span class="ia-card-title">Password</span></div>""",
    """{{-- MARKER-TIMECLOCK-EXEMPT — self-serve clock-in nudge opt-out --}}
<div class="ia-card" style="margin-bottom:14px">
  <div class="ia-card-head"><span class="ia-card-title">Time clock</span></div>
  <p style="font-size:12px;color:var(--ia-text-dim);margin:0 0 14px">If you don't punch a clock, turn off the reminder that shows on every page when you're off the clock.</p>

  <form method="POST" action="{{ route('tenant.account.timeclock-exempt') }}">
    @csrf @method('PATCH')
    <div class="ac-field">
      <div class="ac-field-label">Clock-in prompts</div>
      <div class="ac-field-value">
        <label style="display:flex;align-items:center;gap:9px;font-size:13px;cursor:pointer">
          <input type="checkbox" name="exempt_from_timeclock" value="1" {{ $me->exempt_from_timeclock ? 'checked' : '' }}>
          <span>I never clock in — hide the prompt</span>
        </label>
        <span class="ac-field-hint">Your hours still record normally if you do clock in.</span>
      </div>
    </div>
    <div style="display:flex;justify-content:flex-end;margin-top:8px">
      <button class="ia-btn ia-btn--primary ia-btn--sm">Save</button>
    </div>
  </form>
</div>

<div class="ia-card" style="margin-bottom:14px">
  <div class="ia-card-head"><span class="ia-card-title">Password</span></div>""",
    "account view card")

print("Done. No migration needed (column shipped with the earlier patch).")
