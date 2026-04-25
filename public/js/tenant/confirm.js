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

  window.IntakeConfirm = { show: show };
}());
