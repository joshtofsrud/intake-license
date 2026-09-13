(function () {
  'use strict';

  function show(opts) {
    opts = opts || {};
    var title       = opts.title       || 'Are you sure?';
    var message     = opts.message     || '';
    var confirmText = opts.confirmText || 'Confirm';
    var cancelText  = opts.cancelText  || 'Cancel';
    var danger      = !!opts.danger;

    return new Promise(function (resolve) {
      var backdrop = document.createElement('div');
      backdrop.className = 'ia-confirm-backdrop';

      var card = document.createElement('div');
      card.className = 'ia-confirm-card';
      card.setAttribute('role', 'dialog');
      card.setAttribute('aria-modal', 'true');

      var titleEl = document.createElement('div');
      titleEl.className = 'ia-confirm-title';
      titleEl.textContent = title;
      card.appendChild(titleEl);

      if (message) {
        var msgEl = document.createElement('div');
        msgEl.className = 'ia-confirm-message';
        msgEl.textContent = message;
        card.appendChild(msgEl);
      }

      var actions = document.createElement('div');
      actions.className = 'ia-confirm-actions';

      var cancelBtn = document.createElement('button');
      cancelBtn.type = 'button';
      cancelBtn.className = 'ia-confirm-btn';
      cancelBtn.textContent = cancelText;

      var confirmBtn = document.createElement('button');
      confirmBtn.type = 'button';
      confirmBtn.className = 'ia-confirm-btn ' + (danger ? 'ia-confirm-btn--danger' : 'ia-confirm-btn--primary');
      confirmBtn.textContent = confirmText;

      actions.appendChild(cancelBtn);
      actions.appendChild(confirmBtn);
      card.appendChild(actions);
      backdrop.appendChild(card);
      document.body.appendChild(backdrop);

      void backdrop.offsetWidth;
      backdrop.classList.add('is-shown');
      setTimeout(function () { confirmBtn.focus(); }, 50);

      function cleanup(result) {
        backdrop.classList.remove('is-shown');
        setTimeout(function () {
          if (backdrop.parentNode) backdrop.parentNode.removeChild(backdrop);
          document.removeEventListener('keydown', onKey);
        }, 160);
        resolve(result);
      }

      function onKey(e) {
        if (e.key === 'Escape') cleanup(false);
        else if (e.key === 'Enter') cleanup(true);
      }

      cancelBtn.addEventListener('click', function () { cleanup(false); });
      confirmBtn.addEventListener('click', function () { cleanup(true); });
      backdrop.addEventListener('click', function (e) { if (e.target === backdrop) cleanup(false); });
      document.addEventListener('keydown', onKey);
    });
  }

  /**
   * Single-button alert variant. Same modal as show() but with one OK button
   * and no resolution semantics (resolves true when dismissed). Use for flash
   * errors and other "you need to know about this" messages where there's
   * nothing to confirm — just acknowledgment. Mobile-safe out of the box
   * because it reuses the same backdrop/card CSS.
   */
  function alert(opts) {
    opts = opts || {};
    var title   = opts.title   || 'Heads up';
    var message = opts.message || '';
    var okText  = opts.okText  || 'Got it';

    return new Promise(function (resolve) {
      var backdrop = document.createElement('div');
      backdrop.className = 'ia-confirm-backdrop';

      var card = document.createElement('div');
      card.className = 'ia-confirm-card';
      card.setAttribute('role', 'dialog');
      card.setAttribute('aria-modal', 'true');

      var titleEl = document.createElement('div');
      titleEl.className = 'ia-confirm-title';
      titleEl.textContent = title;
      card.appendChild(titleEl);

      if (message) {
        var msgEl = document.createElement('div');
        msgEl.className = 'ia-confirm-message';
        msgEl.textContent = message;
        card.appendChild(msgEl);
      }

      var actions = document.createElement('div');
      actions.className = 'ia-confirm-actions';

      var okBtn = document.createElement('button');
      okBtn.type = 'button';
      okBtn.className = 'ia-confirm-btn ia-confirm-btn--primary';
      okBtn.textContent = okText;

      actions.appendChild(okBtn);
      card.appendChild(actions);
      backdrop.appendChild(card);
      document.body.appendChild(backdrop);

      void backdrop.offsetWidth;
      backdrop.classList.add('is-shown');
      setTimeout(function () { okBtn.focus(); }, 50);

      function cleanup() {
        backdrop.classList.remove('is-shown');
        setTimeout(function () {
          if (backdrop.parentNode) backdrop.parentNode.removeChild(backdrop);
          document.removeEventListener('keydown', onKey);
        }, 160);
        resolve(true);
      }

      function onKey(e) {
        if (e.key === 'Escape' || e.key === 'Enter') cleanup();
      }

      okBtn.addEventListener('click', cleanup);
      backdrop.addEventListener('click', function (e) { if (e.target === backdrop) cleanup(); });
      document.addEventListener('keydown', onKey);
    });
  }

  /**
   * MARKER-AUDIENCE-POLISH — a text prompt in the app's own dialog, because
   * every caller that wanted one was falling back to window.prompt.
   * Resolves with the trimmed string, or null if cancelled — same contract
   * callers were already coding against.
   */
  function prompt(opts) {
    opts = opts || {};
    var title       = opts.title       || 'Enter a value';
    var message     = opts.message     || '';
    var value       = opts.value       || '';
    var placeholder = opts.placeholder || '';
    var confirmText = opts.confirmText || 'Save';
    var cancelText  = opts.cancelText  || 'Cancel';

    return new Promise(function (resolve) {
      var backdrop = document.createElement('div');
      backdrop.className = 'ia-confirm-backdrop';

      var card = document.createElement('div');
      card.className = 'ia-confirm-card';

      var h = document.createElement('div');
      h.className = 'ia-confirm-title';
      h.textContent = title;
      card.appendChild(h);

      if (message) {
        var m = document.createElement('div');
        m.className = 'ia-confirm-msg';
        m.textContent = message;
        card.appendChild(m);
      }

      var input = document.createElement('input');
      input.type = 'text';
      input.className = 'ia-confirm-input';
      input.value = value;
      input.placeholder = placeholder;
      card.appendChild(input);

      var row = document.createElement('div');
      row.className = 'ia-confirm-actions';

      var cancel = document.createElement('button');
      cancel.type = 'button';
      cancel.className = 'ia-confirm-btn';
      cancel.textContent = cancelText;

      var ok = document.createElement('button');
      ok.type = 'button';
      ok.className = 'ia-confirm-btn ia-confirm-btn--primary';
      ok.textContent = confirmText;

      row.appendChild(cancel);
      row.appendChild(ok);
      card.appendChild(row);
      backdrop.appendChild(card);
      document.body.appendChild(backdrop);

      function close(result) {
        document.removeEventListener('keydown', onKey);
        if (backdrop.parentNode) backdrop.parentNode.removeChild(backdrop);
        resolve(result);
      }
      function submit() {
        var v = (input.value || '').trim();
        close(v === '' ? null : v);
      }
      function onKey(e) {
        if (e.key === 'Escape') close(null);
        if (e.key === 'Enter' && document.activeElement === input) { e.preventDefault(); submit(); }
      }

      cancel.addEventListener('click', function () { close(null); });
      ok.addEventListener('click', submit);
      backdrop.addEventListener('click', function (e) { if (e.target === backdrop) close(null); });
      document.addEventListener('keydown', onKey);

      setTimeout(function () { input.focus(); input.select(); }, 30);
    });
  }

  window.IntakeConfirm = { show: show, alert: alert, prompt: prompt };
}());

// MARKER-CAMPAIGN-V2F — a one-field prompt in the app's own dialog styling,
// so nothing here has to fall back to window.prompt().
window.IntakeConfirm = window.IntakeConfirm || {};
window.IntakeConfirm.prompt = function (opts) {
  opts = opts || {};
  return new Promise(function (resolve) {
    var back = document.createElement('div');
    back.className = 'ia-confirm-backdrop';
    back.innerHTML =
      '<div class="ia-confirm-card" role="dialog" aria-modal="true">' +
        '<div class="ia-confirm-title"></div>' +
        '<div class="ia-confirm-message"></div>' +
        '<input type="text" class="ia-confirm-input" style="width:100%;margin:0 0 20px;padding:8px 10px;border-radius:var(--ia-r-md);border:0.5px solid var(--ia-border);background:rgba(255,255,255,.06);color:var(--ia-text);font:inherit;font-size:13px">' +
        '<div class="ia-confirm-actions">' +
          '<button type="button" class="ia-confirm-btn ia-confirm-cancel"></button>' +
          '<button type="button" class="ia-confirm-btn ia-confirm-btn--primary ia-confirm-ok"></button>' +
        '</div>' +
      '</div>';
    back.querySelector('.ia-confirm-title').textContent   = opts.title || 'Enter a value';
    back.querySelector('.ia-confirm-message').textContent = opts.message || '';
    back.querySelector('.ia-confirm-cancel').textContent  = opts.cancelText || 'Cancel';
    back.querySelector('.ia-confirm-ok').textContent      = opts.confirmText || 'OK';

    var input = back.querySelector('.ia-confirm-input');
    input.value = opts.value || '';
    if (opts.placeholder) input.placeholder = opts.placeholder;

    function close(val) {
      back.classList.remove('is-shown');
      document.removeEventListener('keydown', onKey);
      setTimeout(function () { back.remove(); }, 150);
      resolve(val);
    }
    function onKey(e) {
      if (e.key === 'Escape') close(null);
      if (e.key === 'Enter' && document.activeElement === input) close(input.value);
    }

    back.querySelector('.ia-confirm-cancel').addEventListener('click', function () { close(null); });
    back.querySelector('.ia-confirm-ok').addEventListener('click', function () { close(input.value); });
    back.addEventListener('click', function (e) { if (e.target === back) close(null); });
    document.addEventListener('keydown', onKey);

    document.body.appendChild(back);
    requestAnimationFrame(function () { back.classList.add('is-shown'); });
    input.focus();
    input.select();
  });
};

// MARKER-TENANT-CONFIRM — one interceptor for every attribute-based native
// confirm in the tenant app.
//
//   onsubmit="return confirm('…')" and onclick="return confirm('…')" are
//   inline handlers, which fire in the TARGET phase. This listener runs in the
//   CAPTURE phase, so it gets there first: it stops the event, asks in-app,
//   and on Yes re-fires with the inline attribute removed for the duration so
//   the native dialog never runs. Nothing in any view has to change, and a
//   form written that way tomorrow gets the same treatment.
//
//   Why not just override window.confirm: it is synchronous, and the inline
//   handler needs its return value in the same tick. A modal cannot answer in
//   the same tick, so the event has to be replayed instead.
(function () {
  'use strict';

  var RE = /\bconfirm\s*\(\s*(['"])((?:\\.|(?!\1).)*)\1/;

  function messageFrom(attr) {
    var m = RE.exec(attr || '');
    if (!m) { return 'Are you sure?'; }
    return m[2].replace(/\\(['"\\])/g, '$1').replace(/\\n/g, '\n');
  }

  function looksDestructive(msg) {
    return /\b(delete|remove|erase|void|cancel|archive|revert|discard|lock|regenerate|disconnect|tear down)\b/i.test(msg);
  }

  function ask(msg) {
    if (!window.IntakeConfirm || typeof window.IntakeConfirm.show !== 'function') {
      // The helper failed to load: fall back to the native dialog rather than
      // silently allowing the action. Failing closed is the whole point.
      return Promise.resolve(window.confirm(msg));
    }
    return window.IntakeConfirm.show({
      title: 'Please confirm',
      message: msg,
      confirmText: 'Yes, go ahead',
      danger: looksDestructive(msg)
    });
  }

  // Forms with onsubmit="return confirm(...)"
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.tagName !== 'FORM') { return; }

    if (form.dataset.iaConfirmed === '1') {
      delete form.dataset.iaConfirmed;
      return; // replay after Yes — let it through
    }

    var attr = form.getAttribute('onsubmit');
    if (!attr || !RE.test(attr)) { return; }

    e.preventDefault();
    e.stopImmediatePropagation();

    var submitter = e.submitter || null;

    ask(messageFrom(attr)).then(function (ok) {
      if (!ok) { return; }
      form.dataset.iaConfirmed = '1';
      form.removeAttribute('onsubmit');
      try {
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit(submitter || undefined);
        } else {
          form.submit();
        }
      } finally {
        form.setAttribute('onsubmit', attr);
      }
    });
  }, true);

  // Buttons / links with onclick="return confirm(...)"
  document.addEventListener('click', function (e) {
    var el = e.target && e.target.closest ? e.target.closest('[onclick]') : null;
    if (!el) { return; }

    if (el.dataset.iaConfirmed === '1') {
      delete el.dataset.iaConfirmed;
      return;
    }

    var attr = el.getAttribute('onclick');
    if (!attr || !RE.test(attr)) { return; }

    e.preventDefault();
    e.stopImmediatePropagation();

    ask(messageFrom(attr)).then(function (ok) {
      if (!ok) { return; }
      el.dataset.iaConfirmed = '1';
      el.removeAttribute('onclick');
      try {
        el.click();
      } finally {
        el.setAttribute('onclick', attr);
      }
    });
  }, true);
})();
