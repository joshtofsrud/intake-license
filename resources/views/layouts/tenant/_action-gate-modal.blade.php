{{-- ================================================================
     Action-gate modal (chunk 7).
     Always rendered on authenticated pages where pin_tier_active.
     Hidden by default; shown by action-gate.js when an action gate
     requires a PIN re-prompt.

     This is the modal shown when switching locations (and, in the future,
     for refunds, voids, manager overrides, etc.).
     ================================================================ --}}
{{-- MARKER-DEMO-TIMELINE — a demo visitor has no PIN and cannot set one, so
     the gate would strand them exactly like switch-user did. --}}
@if(isset($currentTenant) && $currentTenant->pin_tier_active && isset($authUser) && ! $currentTenant->is_demo)
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
