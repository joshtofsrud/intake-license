/* PATCH-103-LOCATION-SWITCHER + CHUNK-7 action gate */
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
      inp.value = inp.value.replace(/\D/g, '').slice(0, 1);
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
