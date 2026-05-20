#!/usr/bin/env bash
# ============================================================================
# Auth Refactor — Chunk 7
# Sensitive-action gate: location switch requires PIN re-prompt
#
# CONTEXT
#   The original ask that started the auth refactor: "switching locations
#   should require a PIN re-prompt so it's not accidental."
#
#   This chunk lands the *mechanic* (PinGateService::requirePin($action))
#   and wires it for one action: switch_location. Future actions (refund,
#   void, manager override) plug into the same mechanic.
#
#   Policy: Always prompt — no sticky window. Every location switch is
#   intentional; every location switch confirms. (User decision; spec
#   default was 30 min, but stricter is fine.)
#
# WHAT THIS PATCH ADDS
#   1. PinGateService — a tiny service exposing requirePin($action) and
#      confirm($pin, $action). Future actions plug in as new $action keys.
#
#   2. AuthController::selectLocation — gated on pin_tier_active. If
#      pin_tier_active AND no PIN proof in the request, returns 403 JSON
#      { error: 'pin_required', destination: '...' }. The client catches
#      this and shows the modal.
#
#   3. Location-switch confirm modal — overlay-style, names the destination
#      location, 4 PIN boxes, Cancel / Confirm buttons.
#
#   4. location-switcher.js — intercepts the form submit, sends the POST
#      via fetch(), catches the 403, shows the modal, re-submits with PIN.
#
# IDEMPOTENT.
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
echo "Auth Refactor — Chunk 7 (action gate)"
echo "Running in: $(pwd)"
echo "=========================================="

# ----------------------------------------------------------------------------
# STEP 1 — PinGateService
# ----------------------------------------------------------------------------

SERVICE_FILE=app/Services/PinGateService.php

if [ -f "$SERVICE_FILE" ]; then
    echo "STEP 1: SKIP (PinGateService already exists)"
else
    cat > "$SERVICE_FILE" <<'PHP'
<?php

namespace App\Services;

use App\Models\Tenant\TenantUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * PinGateService
 *
 * The mechanic for Layer 4 of the auth refactor (sensitive-action gates).
 * See auth-refactor-spec-v2.md §6.
 *
 * Each gated action is identified by a string key (e.g. 'switch_location',
 * 'refund', 'void'). Calling code asks:
 *
 *   - requirePin($request, $action): does this request need a PIN re-prompt
 *     for this action? Returns false if the tenant is not pin_tier_active,
 *     OR if a recent PIN confirmation for THIS action exists in the session
 *     within the configured sticky window.
 *
 *   - confirm($request, $action, $pin, $user): verify the PIN against the
 *     current user and, on success, record the confirmation for this action
 *     in the session. Returns true on success, false on bad PIN.
 *
 * Sticky window per action lives in config/intake.php auth.pin_action_sticky_sec.
 * A value of 0 means "always prompt" (chunk 7's launch behavior for
 * switch_location).
 */
class PinGateService
{
    public function __construct(protected PinService $pins) {}

    /**
     * Does this request need a PIN re-prompt for $action?
     *
     * Returns true if the gate should fire (UI must show PIN entry).
     */
    public function requirePin(Request $request, string $action): bool
    {
        $tenant = app('tenant') ?? null;
        if (! $tenant || ! $tenant->pin_tier_active) {
            return false;
        }

        $stickyConfig = config('intake.auth.pin_action_sticky_sec', []);
        $stickySec = (int) ($stickyConfig[$action] ?? 0);

        if ($stickySec === 0) {
            // No sticky window — every action requires PIN.
            return true;
        }

        // Sticky window > 0 — check if there's a recent confirmation in session.
        $confirmations = $request->session()->get('pin_confirmed_actions', []);
        $confirmedAtIso = $confirmations[$action] ?? null;

        if (! $confirmedAtIso) {
            return true; // never confirmed → require
        }

        try {
            $confirmedAt = \Illuminate\Support\Carbon::parse($confirmedAtIso);
        } catch (\Throwable $e) {
            return true;
        }

        // If within sticky window, no PIN needed.
        return $confirmedAt->lt(now()->subSeconds($stickySec));
    }

    /**
     * Verify a PIN attempt for an action. On success, record the confirmation
     * timestamp in the session so subsequent sticky-window checks pass.
     *
     * Returns true on success, false on bad PIN.
     */
    public function confirm(Request $request, string $action, string $pin, TenantUser $user): bool
    {
        if (! $user->pin_hash) {
            return false;
        }

        if ($this->pins->isLocked($user)) {
            return false;
        }

        try {
            $ok = $this->pins->verifyPin($user, $pin);
        } catch (\DomainException $e) {
            return false;
        }

        if (! $ok) {
            return false;
        }

        // Success — record confirmation timestamp for this action.
        $confirmations = $request->session()->get('pin_confirmed_actions', []);
        $confirmations[$action] = now()->toIso8601String();
        $request->session()->put('pin_confirmed_actions', $confirmations);

        return true;
    }
}
PHP
    echo "STEP 1: OK (created PinGateService)"
fi

# ----------------------------------------------------------------------------
# STEP 2 — Add config for sticky windows
# ----------------------------------------------------------------------------

python3 <<'PY'
from pathlib import Path
p = Path('config/intake.php')
s = p.read_text()

if "'pin_action_sticky_sec'" in s:
    print("STEP 2: SKIP (sticky config already present)")
else:
    old = "'pin_heartbeat_interval_sec' => 60,\n    ],"
    new = """'pin_heartbeat_interval_sec' => 60,

        // Sensitive-action gates (chunk 7). Keys are action names; values
        // are sticky-window seconds. A value of 0 means "always prompt"
        // — every action requires a fresh PIN.
        //
        // To add a new gated action: add the key here, then have the
        // controller call PinGateService::requirePin($request, $key) and
        // return 403 { error: 'pin_required', action: $key } when true.
        'pin_action_sticky_sec' => [
            'switch_location' => 0,  // always prompt — user choice
            // Future actions go here. Examples:
            //   'refund'           => 0,        // always prompt
            //   'void_sale'        => 0,        // always prompt
            //   'override_oversold' => 300,     // 5 min sticky
            //   'manager_override' => 0,        // always prompt
        ],
    ],"""

    if s.count(old) != 1:
        print(f"STEP 2: ABORT (anchor matches {s.count(old)} times)")
        raise SystemExit(1)
    s = s.replace(old, new)
    p.write_text(s)
    print("STEP 2: OK (added pin_action_sticky_sec config)")
PY

# ----------------------------------------------------------------------------
# STEP 3 — Gate AuthController::selectLocation on switch_location action
# ----------------------------------------------------------------------------

python3 <<'PY'
from pathlib import Path
p = Path('app/Http/Controllers/Tenant/AuthController.php')
s = p.read_text()

if "switch_location action gate" in s:
    print("STEP 3: SKIP (gate already in selectLocation)")
else:
    old = """        if (!$hasAccess) {
            return back()->withErrors(['location_id' => 'You do not have access to that location.']);
        }

        $request->session()->put('current_location_id', $request->input('location_id'));"""

    new = """        if (!$hasAccess) {
            return back()->withErrors(['location_id' => 'You do not have access to that location.']);
        }

        // CHUNK-7 switch_location action gate.
        // If pin_tier_active and no recent PIN confirmation for switch_location,
        // require one. The client-side fetch() handler catches the 403 and
        // re-submits with the pin field after the user enters it.
        $gate = app(\\App\\Services\\PinGateService::class);
        if ($gate->requirePin($request, 'switch_location')) {
            $pin = $request->input('pin');

            if (! $pin) {
                // Client must show the modal and re-submit with the pin field.
                // Use 403 (forbidden) with a JSON body. Always reply in JSON
                // here — the new client flow uses fetch() so it expects JSON
                // either way.
                $location = $user->activeLocations()
                    ->where('tenant_locations.id', $request->input('location_id'))
                    ->first();

                return response()->json([
                    'ok'    => false,
                    'error' => 'pin_required',
                    'action' => 'switch_location',
                    'destination' => $location?->name,
                ], 403);
            }

            // PIN provided — verify.
            $ok = $gate->confirm($request, 'switch_location', $pin, $user);
            if (! $ok) {
                return response()->json([
                    'ok'    => false,
                    'error' => 'pin_mismatch',
                ], 422);
            }
        }

        $request->session()->put('current_location_id', $request->input('location_id'));"""

    if s.count(old) != 1:
        print(f"STEP 3: ABORT (anchor matches {s.count(old)} times)")
        raise SystemExit(1)
    s = s.replace(old, new)
    p.write_text(s)
    print("STEP 3: OK (selectLocation now gates on switch_location action)")
PY

# ----------------------------------------------------------------------------
# STEP 4 — Convert the redirect response to JSON when posting via fetch.
# The selectLocation existing return paths use redirect(); these need to
# also support JSON for the fetch-based flow. Add an $expectsJson branch.
# ----------------------------------------------------------------------------

python3 <<'PY'
from pathlib import Path
p = Path('app/Http/Controllers/Tenant/AuthController.php')
s = p.read_text()

if "CHUNK-7 json-return" in s:
    print("STEP 4: SKIP (JSON return path already present)")
else:
    old = """        $request->session()->put('current_location_id', $request->input('location_id'));

        // PATCH-103 return_url — the header switcher posts the URL the user
        // was on so we can return them there. Only honor same-host URLs to
        // avoid open-redirect risk.
        $returnUrl = $request->input('return_url');
        if ($returnUrl && is_string($returnUrl)) {
            $current = $request->getSchemeAndHttpHost();
            if (str_starts_with($returnUrl, $current . '/')) {
                return redirect($returnUrl);
            }
        }

        return redirect()->intended(route('tenant.dashboard'));
    }"""

    new = """        $request->session()->put('current_location_id', $request->input('location_id'));

        // PATCH-103 return_url — the header switcher posts the URL the user
        // was on so we can return them there. Only honor same-host URLs to
        // avoid open-redirect risk.
        $returnUrl = $request->input('return_url');
        $redirectTarget = route('tenant.dashboard');
        if ($returnUrl && is_string($returnUrl)) {
            $current = $request->getSchemeAndHttpHost();
            if (str_starts_with($returnUrl, $current . '/')) {
                $redirectTarget = $returnUrl;
            }
        }

        // CHUNK-7 json-return — fetch-based clients (the location switcher
        // since chunk 7) expect JSON; window.location.href will handle the
        // redirect on the client side.
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok'       => true,
                'redirect' => $redirectTarget,
            ]);
        }

        return redirect($redirectTarget);
    }"""

    if s.count(old) != 1:
        print(f"STEP 4: ABORT (anchor matches {s.count(old)} times)")
        raise SystemExit(1)
    s = s.replace(old, new)
    p.write_text(s)
    print("STEP 4: OK (selectLocation returns JSON when expectsJson)")
PY

# ----------------------------------------------------------------------------
# STEP 5 — Add the confirm modal partial
# ----------------------------------------------------------------------------

PARTIAL=resources/views/layouts/tenant/_action-gate-modal.blade.php

if [ -f "$PARTIAL" ]; then
    echo "STEP 5: SKIP (action gate modal already exists)"
else
    cat > "$PARTIAL" <<'BLADE'
{{-- ================================================================
     Action-gate modal (chunk 7).
     Always rendered on authenticated pages where pin_tier_active.
     Hidden by default; shown by action-gate.js when an action gate
     requires a PIN re-prompt.

     This is the modal shown when switching locations (and, in the future,
     for refunds, voids, manager overrides, etc.).
     ================================================================ --}}
@if(isset($currentTenant) && $currentTenant->pin_tier_active && isset($authUser))
<div class="ia-action-gate" id="ia-action-gate"
     style="display: none"
     role="dialog"
     aria-modal="true"
     aria-labelledby="ia-action-gate-title">
  <div class="ia-action-gate-card">
    <div class="ia-action-gate-icon" aria-hidden="true">
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
      </svg>
    </div>

    <div class="ia-action-gate-title" id="ia-action-gate-title">
      <span data-gate-action-label>Confirm action</span>
    </div>
    <div class="ia-action-gate-sub">
      Enter your PIN to continue as {{ $authUser->name }}
    </div>

    <div class="ia-action-gate-pin-wrap">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="ia-action-gate-pin" data-gate-pos="0" autocomplete="off">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="ia-action-gate-pin" data-gate-pos="1" autocomplete="off">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="ia-action-gate-pin" data-gate-pos="2" autocomplete="off">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="ia-action-gate-pin" data-gate-pos="3" autocomplete="off">
    </div>

    <div class="ia-action-gate-msg" id="ia-action-gate-msg"></div>

    <div class="ia-action-gate-actions">
      <button type="button" class="ia-action-gate-btn ia-action-gate-btn-ghost" id="ia-action-gate-cancel">Cancel</button>
      <button type="button" class="ia-action-gate-btn ia-action-gate-btn-primary" id="ia-action-gate-confirm">Confirm</button>
    </div>
  </div>
</div>
@endif
BLADE
    echo "STEP 5: OK (created _action-gate-modal.blade.php)"
fi

# ----------------------------------------------------------------------------
# STEP 6 — Append CSS for the action gate modal
# ----------------------------------------------------------------------------

python3 <<'PY'
from pathlib import Path
p = Path('public/css/tenant/base.css')
s = p.read_text()

if "/* === Action gate modal (chunk 7) === */" in s:
    print("STEP 6: SKIP (action gate CSS already present)")
else:
    css = """

/* === Action gate modal (chunk 7) === */
.ia-action-gate {
  position: fixed;
  inset: 0;
  z-index: 10000;
  background: rgba(0, 0, 0, 0.55);
  backdrop-filter: blur(4px);
  -webkit-backdrop-filter: blur(4px);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
}
.ia-action-gate-card {
  background: var(--ia-surface-1, #1a1a1a);
  border: 1px solid var(--ia-border, rgba(255,255,255,.12));
  border-radius: var(--ia-r-lg, 12px);
  padding: 26px 30px;
  width: 100%;
  max-width: 380px;
  text-align: center;
  box-shadow: 0 12px 48px rgba(0, 0, 0, 0.5);
}
.ia-action-gate-icon {
  width: 52px;
  height: 52px;
  border-radius: 50%;
  background: var(--ia-accent-soft, rgba(190, 242, 100, 0.1));
  color: var(--ia-accent, #BEF264);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 12px;
}
.ia-action-gate-title {
  font-size: 16px;
  font-weight: 600;
  color: var(--ia-text, inherit);
  margin-bottom: 4px;
}
.ia-action-gate-sub {
  font-size: 12.5px;
  opacity: 0.6;
  margin-bottom: 20px;
}
.ia-action-gate-pin-wrap {
  display: flex;
  justify-content: center;
  gap: 10px;
  margin-bottom: 14px;
}
.ia-action-gate-pin {
  width: 50px;
  height: 60px;
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid var(--ia-border, rgba(255,255,255,.12));
  border-radius: var(--ia-r-md, 8px);
  color: var(--ia-text, inherit);
  font-size: 22px;
  font-weight: 600;
  text-align: center;
  font-family: inherit;
  transition: border-color 0.12s;
}
.ia-action-gate-pin:focus {
  outline: none;
  border-color: var(--ia-accent, #BEF264);
}
.ia-action-gate-pin.error {
  border-color: #E24B4A;
}
.ia-action-gate-msg {
  font-size: 13px;
  min-height: 18px;
  margin-bottom: 14px;
  color: #F09595;
}
.ia-action-gate-msg.info {
  color: var(--ia-muted, rgba(255,255,255,.55));
}
.ia-action-gate-actions {
  display: flex;
  gap: 10px;
}
.ia-action-gate-btn {
  flex: 1;
  padding: 10px 14px;
  border-radius: var(--ia-r-md, 8px);
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  border: 1px solid var(--ia-border, rgba(255,255,255,.12));
  background: transparent;
  color: var(--ia-text, inherit);
  font-family: inherit;
  transition: background 0.12s, filter 0.12s;
}
.ia-action-gate-btn-ghost:hover {
  background: rgba(255, 255, 255, 0.06);
}
.ia-action-gate-btn-primary {
  background: var(--ia-accent, #BEF264);
  color: var(--ia-accent-text, #000);
  border-color: var(--ia-accent, #BEF264);
}
.ia-action-gate-btn-primary:hover {
  filter: brightness(0.93);
}
.ia-action-gate-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* Light theme polish */
.ia-theme-b .ia-action-gate-card {
  background: #ffffff;
  border-color: rgba(0, 0, 0, 0.10);
  box-shadow: 0 12px 48px rgba(0, 0, 0, 0.18);
}
.ia-theme-b .ia-action-gate-pin {
  background: rgba(0, 0, 0, 0.03);
  border-color: rgba(0, 0, 0, 0.10);
}
.ia-theme-b .ia-action-gate-btn {
  border-color: rgba(0, 0, 0, 0.12);
}
.ia-theme-b .ia-action-gate-btn-ghost:hover {
  background: rgba(0, 0, 0, 0.05);
}
"""
    s = s + css
    p.write_text(s)
    print("STEP 6: OK (appended action gate CSS)")
PY

# ----------------------------------------------------------------------------
# STEP 7 — Replace location-switcher.js with the fetch-based version that
# catches the 403 and shows the action gate modal.
# ----------------------------------------------------------------------------

python3 <<'PY'
from pathlib import Path
p = Path('public/js/tenant/location-switcher.js')
s = p.read_text()

if "CHUNK-7 action gate" in s:
    print("STEP 7: SKIP (location-switcher.js already chunk-7 aware)")
else:
    new = """/* PATCH-103-LOCATION-SWITCHER + CHUNK-7 action gate */
(function () {
  'use strict';

  const csrf = (window.IntakeAdmin && window.IntakeAdmin.csrfToken) || (() => {
    const el = document.querySelector('meta[name=csrf-token]');
    return el ? el.content : '';
  })();

  function closeAllDetails(except) {
    document.querySelectorAll('[data-loc-switcher="root"] details[open]').forEach(function (d) {
      if (d !== except) d.removeAttribute('open');
    });
  }

  // Outside-click + Escape close behavior (unchanged from patch 103).
  document.addEventListener('click', function (e) {
    var root = e.target.closest('[data-loc-switcher="root"]');
    if (!root) { closeAllDetails(null); }
    else {
      var openHere = root.querySelector('details[open]');
      closeAllDetails(openHere);
    }
  }, true);

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' || e.key === 'Esc') closeAllDetails(null);
  });

  // Click on the current location is a no-op (unchanged from patch 103).
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.ia-loc-switcher-item.is-current');
    if (btn) {
      e.preventDefault();
      e.stopPropagation();
      var d = btn.closest('details');
      if (d) d.removeAttribute('open');
    }
  }, true);

  // ===========================================================
  // CHUNK-7 — intercept form submit, POST via fetch, catch 403
  // pin_required, show modal, re-submit with PIN.
  // ===========================================================

  const gateEl       = document.getElementById('ia-action-gate');
  const gateLabel    = gateEl?.querySelector('[data-gate-action-label]');
  const gateInputs   = gateEl ? Array.from(gateEl.querySelectorAll('.ia-action-gate-pin')) : [];
  const gateMsg      = document.getElementById('ia-action-gate-msg');
  const gateConfirm  = document.getElementById('ia-action-gate-confirm');
  const gateCancel   = document.getElementById('ia-action-gate-cancel');

  // Pending request state — what to POST when the user confirms.
  let pendingForm   = null;
  let pendingFields = null;

  function showGate(title) {
    if (!gateEl) return;
    if (gateLabel && title) gateLabel.textContent = title;
    gateEl.style.display = 'flex';
    gateInputs.forEach(i => { i.value = ''; i.classList.remove('error'); });
    if (gateMsg) { gateMsg.textContent = ''; gateMsg.className = 'ia-action-gate-msg'; }
    setTimeout(() => gateInputs[0]?.focus(), 50);
  }

  function hideGate() {
    if (!gateEl) return;
    gateEl.style.display = 'none';
    pendingForm = null;
    pendingFields = null;
  }

  function gateError(text) {
    if (gateMsg) { gateMsg.textContent = text; gateMsg.className = 'ia-action-gate-msg'; }
    gateInputs.forEach(i => i.classList.add('error'));
    setTimeout(() => gateInputs.forEach(i => i.classList.remove('error')), 600);
  }

  // Hook every form inside any [data-loc-switcher="root"].
  document.querySelectorAll('[data-loc-switcher="root"] form[data-loc-switcher="form"]').forEach(form => {
    form.addEventListener('submit', async function (e) {
      // Only intercept when there's a clicked submitter (we need its name+value).
      // Native form submission gives us the submitter via e.submitter on modern browsers.
      const submitter = e.submitter;
      if (!submitter || submitter.tagName !== 'BUTTON') return;
      // The submitter is one of the location buttons; its value is the location id.
      // Don't intercept if it's the current location (CSS pointer-events should
      // catch this anyway).
      if (submitter.classList.contains('is-current')) {
        e.preventDefault();
        return;
      }

      e.preventDefault();

      const fd = new FormData(form);
      fd.set('location_id', submitter.value);

      // Convert FormData to plain object for the fetch payload.
      const fields = {};
      fd.forEach((v, k) => { fields[k] = v; });

      pendingForm = form;
      pendingFields = fields;

      await submitLocationChange(fields);
    });
  });

  async function submitLocationChange(fields, pin) {
    const payload = Object.assign({}, fields);
    if (pin) payload.pin = pin;

    const action = pendingForm?.action || window.location.href;

    try {
      const res = await fetch(action, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'Accept':       'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(payload),
      });

      const body = await res.json().catch(() => ({}));

      if (res.ok && body.ok && body.redirect) {
        window.location.href = body.redirect;
        return;
      }

      if (res.status === 403 && body.error === 'pin_required') {
        const dest = body.destination || 'this location';
        showGate('Switch to ' + dest + '?');
        return;
      }

      if (res.status === 422 && body.error === 'pin_mismatch') {
        gateError("That PIN didn't match. Try again.");
        return;
      }

      // Unknown failure — fall back to a normal submit so the user
      // sees the standard error path.
      gateError('Could not switch location. Try again.');
    } catch (err) {
      gateError('Network error. Try again.');
    }
  }

  // PIN input wiring for the gate modal.
  gateInputs.forEach((inp, idx) => {
    inp.addEventListener('input', () => {
      inp.value = inp.value.replace(/\\D/g, '').slice(0, 1);
      if (inp.value && idx < gateInputs.length - 1) {
        gateInputs[idx + 1].focus();
      }
      if (gateInputs.every(i => i.value)) {
        submitWithPin();
      }
    });
    inp.addEventListener('keydown', (e) => {
      if (e.key === 'Backspace' && !inp.value && idx > 0) {
        gateInputs[idx - 1].focus();
      }
      if (e.key === 'Enter') submitWithPin();
    });
  });

  async function submitWithPin() {
    if (!pendingFields) return;
    const pin = gateInputs.map(i => i.value).join('');
    if (pin.length !== 4) return;
    if (gateMsg) { gateMsg.textContent = 'Confirming…'; gateMsg.className = 'ia-action-gate-msg info'; }
    await submitLocationChange(pendingFields, pin);
  }

  if (gateConfirm) gateConfirm.addEventListener('click', submitWithPin);
  if (gateCancel)  gateCancel.addEventListener('click', hideGate);
})();
"""
    p.write_text(new)
    print("STEP 7: OK (rewrote location-switcher.js with chunk-7 fetch + modal flow)")
PY

# ----------------------------------------------------------------------------
# STEP 8 — Include the action gate modal partial in app.blade.php.
# ----------------------------------------------------------------------------

python3 <<'PY'
from pathlib import Path
p = Path('resources/views/layouts/tenant/app.blade.php')
s = p.read_text()

if "_action-gate-modal" in s:
    print("STEP 8: SKIP (action gate modal already included)")
else:
    old = "@include('layouts.tenant._lock-overlay')"
    new = "@include('layouts.tenant._lock-overlay')\n@include('layouts.tenant._action-gate-modal')"
    if s.count(old) != 1:
        print(f"STEP 8: ABORT (anchor matches {s.count(old)} times)")
        raise SystemExit(1)
    s = s.replace(old, new)
    p.write_text(s)
    print("STEP 8: OK (action gate modal included in app.blade.php)")
PY

# ----------------------------------------------------------------------------
# Verification
# ----------------------------------------------------------------------------

echo ""
echo "----------------------------------------"
echo "VERIFY: new files"
echo "----------------------------------------"
for f in app/Services/PinGateService.php \
         resources/views/layouts/tenant/_action-gate-modal.blade.php; do
    if [ -f "$f" ]; then
        echo "  ✓ $f ($(wc -l < $f) lines)"
    else
        echo "  ✗ $f MISSING"
    fi
done

echo ""
echo "----------------------------------------"
echo "VERIFY: AuthController gate + JSON return"
echo "----------------------------------------"
grep -n "switch_location action gate\|CHUNK-7 json-return\|PinGateService" app/Http/Controllers/Tenant/AuthController.php | head -5

echo ""
echo "----------------------------------------"
echo "VERIFY: location-switcher.js chunk-7 aware"
echo "----------------------------------------"
grep -n "CHUNK-7\|submitLocationChange\|showGate" public/js/tenant/location-switcher.js | head -5

echo ""
echo "----------------------------------------"
echo "VERIFY: modal mounted in layout"
echo "----------------------------------------"
grep -n "_action-gate-modal\|_lock-overlay" resources/views/layouts/tenant/app.blade.php | head -3

echo ""
echo "=========================================="
echo "Chunk 7 application complete."
echo ""
echo "Server steps:"
echo "  git pull && composer install --no-interaction --no-scripts && \\"
echo "  php artisan view:clear && php artisan optimize:clear && \\"
echo "  systemctl stop php8.3-fpm && sleep 2 && systemctl start php8.3-fpm"
echo "  (no migrations)"
echo ""
echo "Verify on thebikehub.intake.works (with 2 users + pin_tier_active + you signed in via PIN):"
echo "  1. You need 2+ locations on your user to even see the switcher."
echo "     If you don't have 2 locations, add one via the existing"
echo "     Locations admin and grant your user access."
echo "  2. Click the location row in the sidebar identity block."
echo "  3. Pick another location → modal drops asking for PIN with the"
echo "     destination location named."
echo "  4. Enter your PIN → page reloads at the same URL with the new"
echo "     location active."
echo "  5. Click the location row again, pick another location → modal"
echo "     re-appears (no sticky window, by your call)."
echo "=========================================="
