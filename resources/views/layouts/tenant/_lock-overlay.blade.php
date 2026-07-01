{{-- ================================================================
     Idle-lock overlay (chunk 6).
     Always present in the DOM on authenticated pages where pin_tier_active.
     Hidden by default (display:none); shown when:
       1. Server flagged this page render with pinLockPending=true
       2. Client JS detected idle locally
       3. An AJAX fetch returned 423 Locked (caught by global handler in idle-lock.js)
     ================================================================ --}}
@if(isset($currentTenant) && $currentTenant->pin_tier_active && isset($authUser))
<div class="ia-lock-overlay" id="ia-lock-overlay"
     style="display: {{ ($pinLockPending ?? false) ? 'flex' : 'none' }}"
     data-initially-locked="{{ ($pinLockPending ?? false) ? '1' : '0' }}"
     data-lock-mode="{{ $authUser->pin_hash ? 'enter' : 'setup' }}"
     role="dialog"
     aria-modal="true"
     aria-labelledby="ia-lock-title">
  <div class="ia-lock-card">
    <div class="ia-lock-icon" aria-hidden="true">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
      </svg>
    </div>
    <div class="ia-lock-title" id="ia-lock-title">Signed in as {{ $authUser->name }}</div>
    <div class="ia-lock-sub" id="ia-lock-sub">{{ $authUser->pin_hash ? 'Enter your PIN to continue' : 'Create a 4-digit PIN' }}</div>

    <div class="ia-lock-pin-wrap">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="ia-lock-pin-input" data-lock-pos="0" autocomplete="off">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="ia-lock-pin-input" data-lock-pos="1" autocomplete="off">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="ia-lock-pin-input" data-lock-pos="2" autocomplete="off">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="ia-lock-pin-input" data-lock-pos="3" autocomplete="off">
    </div>

    <div class="ia-lock-msg" id="ia-lock-msg"></div>

    <div class="ia-lock-actions">
      <a href="{{ route('tenant.switch') }}" class="ia-lock-btn ia-lock-btn-ghost">← Not you?</a>
      <button type="button" class="ia-lock-btn ia-lock-btn-primary" id="ia-lock-submit">{{ $authUser->pin_hash ? 'Unlock' : 'Set PIN' }}</button>
    </div>

    <div class="ia-lock-footer">Your work is preserved beneath this screen.</div>
  </div>
</div>
@endif
