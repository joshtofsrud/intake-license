/* CHUNK-6 idle lock — client-side idle detector + overlay control */
(function () {
  'use strict';

  const overlay = document.getElementById('ia-lock-overlay');
  if (!overlay) {
    // No overlay in DOM means tenant is not pin_tier_active or user
    // is not authenticated. Nothing to do.
    return;
  }

  const admin = window.IntakeAdmin || {};
  let csrf = admin.csrfToken; // MARKER-PATCH-545 — mutable: refreshed after long idle

  // Configurable from window.IntakeAdmin if needed; defaults here match
  // the config/intake.php server-side values.
  const IDLE_THRESHOLD_MS    = (admin.pinIdleThresholdSec || 120) * 1000;
  const HEARTBEAT_INTERVAL_MS = (admin.pinHeartbeatIntervalSec || 60) * 1000;

  let lastActivityAt = Date.now();
  let isLocked       = overlay.dataset.initiallyLocked === '1';
  let heartbeatTimer = null;
  let idleCheckTimer = null;

  const inputs = Array.from(overlay.querySelectorAll('.ia-lock-pin-input'));
  const msgEl  = overlay.querySelector('#ia-lock-msg');
  const submitBtn = overlay.querySelector('#ia-lock-submit');
  const subEl     = overlay.querySelector('#ia-lock-sub');
  // MARKER-PATCH-480 — first-time PIN setup mode.
  const isSetup   = overlay.dataset.lockMode === 'setup';
  let pendingPin  = null;

  function showOverlay() {
    if (overlay.style.display === 'flex') return;
    overlay.style.display = 'flex';
    isLocked = true;
    inputs.forEach(i => { i.value = ''; i.classList.remove('error'); });
    setTimeout(() => { inputs[0]?.focus(); }, 50);
    stopHeartbeat();
    msg('', '');
  }

  function hideOverlay() {
    overlay.style.display = 'none';
    isLocked = false;
    lastActivityAt = Date.now();
    inputs.forEach(i => i.value = '');
    msg('', '');
    startHeartbeat();
  }

  function msg(text, kind) {
    if (!msgEl) return;
    msgEl.textContent = text || '';
    msgEl.className = 'ia-lock-msg' + (kind ? ' ' + kind : '');
  }

  function recordActivity() {
    if (isLocked) return;
    lastActivityAt = Date.now();
  }

  function checkIdle() {
    if (isLocked) return;
    if (Date.now() - lastActivityAt >= IDLE_THRESHOLD_MS) {
      showOverlay();
    }
  }

  async function heartbeat() {
    if (isLocked) return;
    try {
      const res = await fetch('/admin/pin/heartbeat', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
      });
      if (res.status === 423) {
        showOverlay();
      } else if (res.status === 401) {
        // User signed out elsewhere. Send them to the login page.
        window.location.href = '/admin/login';
      }
    } catch (err) {
      // Network issue — silent. If it persists, next idle check or
      // user activity will retry.
    }
  }

  // MARKER-PATCH-480 — enter -> confirm -> save a brand-new PIN.
  async function submitSetup() {
    const pin = inputs.map(i => i.value).join('');
    if (pin.length !== 4) return;

    if (pendingPin === null) {
      pendingPin = pin;
      inputs.forEach(i => { i.value = ''; });
      if (subEl) subEl.textContent = 'Re-enter to confirm';
      msg('', '');
      inputs[0] && inputs[0].focus();
      return;
    }

    if (pin !== pendingPin) {
      pendingPin = null;
      inputs.forEach(i => { i.value = ''; i.classList.add('error'); });
      if (subEl) subEl.textContent = 'Create a 4-digit PIN';
      msg("PINs didn't match. Start over.", '');
      setTimeout(() => inputs.forEach(i => i.classList.remove('error')), 600);
      inputs[0] && inputs[0].focus();
      return;
    }

    if (submitBtn) submitBtn.disabled = true;
    msg('Saving…', 'info');
    try {
      const res = await fetch('/admin/pin/setup', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: JSON.stringify({ pin })
      });
      const body = await res.json().catch(() => ({}));
      if (res.ok && body.ok) { window.location.reload(); return; }
      msg('Could not save PIN. Try again.', '');
      pendingPin = null;
      inputs.forEach(i => { i.value = ''; });
      if (subEl) subEl.textContent = 'Create a 4-digit PIN';
    } catch (e) {
      msg('Network error. Try again.', '');
    } finally {
      if (submitBtn) submitBtn.disabled = false;
    }
  }

  // MARKER-PATCH-545 — after long idle the page's CSRF token (and sometimes
  // the whole session) is stale. Refresh the token and report auth state so
  // the unlock can retry silently instead of erroring forever.
  async function refreshContext() {
    try {
      const r = await fetch('/admin/pin/context', { headers: { 'Accept': 'application/json' } });
      const b = await r.json();
      if (b && b.csrf) csrf = b.csrf;
      return !!(b && b.authed);
    } catch (e) { return false; }
  }

  async function submitPin(isRetry) {
    if (isSetup) return submitSetup();
    const pin = inputs.map(i => i.value).join('');
    if (pin.length !== 4) return;
    if (submitBtn) submitBtn.disabled = true;
    msg('Checking…', 'info');

    try {
      const res = await fetch('/admin/pin/unlock', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: JSON.stringify({ pin })
      });

      // MARKER-PATCH-545 — stale token or dead session
      if ((res.status === 419 || res.status === 401) && !isRetry) {
        const authed = await refreshContext();
        if (authed) {
          if (submitBtn) submitBtn.disabled = false;
          return submitPin(true); // fresh token, same PIN — silent retry
        }
        // Session is gone: the switcher is the only path that works.
        msg('Session expired — taking you to sign in…', 'info');
        const notYou = document.querySelector('#ia-lock-overlay a[href]');
        setTimeout(() => { window.location.href = notYou ? notYou.href : '/admin'; }, 900);
        return;
      }

      const body = await res.json().catch(() => ({}));

      if (res.ok && body.ok) {
        hideOverlay();
        return;
      }

      if (body.error === 'pin_locked') {
        msg('Too many wrong attempts. Ask an owner to unlock.', '');
        inputs.forEach(i => i.classList.add('error'));
        return;
      }

      if (body.error === 'pin_mismatch') {
        msg("That PIN didn't match. Try again.", '');
        inputs.forEach(i => { i.value = ''; i.classList.add('error'); });
        inputs[0]?.focus();
        setTimeout(() => inputs.forEach(i => i.classList.remove('error')), 600);
        return;
      }

      msg('Something went wrong. Try again.', '');
    } catch (err) {
      msg('Network error. Try again.', '');
    } finally {
      if (submitBtn) submitBtn.disabled = false;
    }
  }

  // Activity listeners — any of these resets idle.
  ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'click'].forEach(ev => {
    document.addEventListener(ev, recordActivity, { passive: true, capture: true });
  });

  // PIN inputs — auto-advance + submit-on-fill.
  inputs.forEach((inp, idx) => {
    inp.addEventListener('input', () => {
      inp.value = inp.value.replace(/\D/g, '').slice(0, 1);
      // MARKER-PATCH-466 — reveal the digit for a beat, then mask it via type
      // swap to 'password' (native masking, repaints reliably across browsers).
      clearTimeout(inp._maskTimer);
      if (inp.value) {
        inp.type = 'text';
        inp._maskTimer = setTimeout(() => { inp.type = 'password'; }, 400);
      } else {
        inp.type = 'text';
      }
      if (inp.value && idx < inputs.length - 1) {
        inputs[idx + 1].focus();
      }
      if (inputs.every(i => i.value)) {
        submitPin();
      }
    });
    inp.addEventListener('keydown', (e) => {
      if (e.key === 'Backspace' && !inp.value && idx > 0) {
        inputs[idx - 1].focus();
      }
      if (e.key === 'Enter') {
        submitPin();
      }
    });
  });

  if (submitBtn) {
    submitBtn.addEventListener('click', submitPin);
  }

  // Global 423 catcher — wraps fetch() so any auth'd AJAX call that
  // returns 423 opens the overlay automatically.
  const _fetch = window.fetch;
  window.fetch = async function (...args) {
    const res = await _fetch.apply(this, args);
    if (res.status === 423) {
      // Clone before reading so the original caller can still use it.
      try {
        const clone = res.clone();
        const body = await clone.json();
        if (body && body.locked) {
          showOverlay();
        }
      } catch (e) {
        // Body wasn't JSON or didn't have locked:true. Don't intervene.
      }
    }
    return res;
  };

  // Timers
  function startHeartbeat() {
    stopHeartbeat();
    heartbeatTimer = setInterval(heartbeat, HEARTBEAT_INTERVAL_MS);
  }
  function stopHeartbeat() {
    if (heartbeatTimer) clearInterval(heartbeatTimer);
    heartbeatTimer = null;
  }

  idleCheckTimer = setInterval(checkIdle, 5000);

  if (isLocked) {
    // Server-flagged stale render — focus the PIN field, don't start heartbeat.
    setTimeout(() => { inputs[0]?.focus(); }, 100);
  } else {
    startHeartbeat();
  }
})();
