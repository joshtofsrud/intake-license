/**
 * Intake SaaS — Tenant Admin JS
 * Lightweight shared utilities for all admin pages.
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    bindRowClicks();
    bindFlashDismiss();
    bindCharCounters();
    bindConfirmButtons();
  });

  // Row click navigation
  function bindRowClicks() {
    document.querySelectorAll('tr[onclick]').forEach(function (row) {
      row.style.cursor = 'pointer';
    });
  }

  // Auto-dismiss flash messages after 4s
  function bindFlashDismiss() {
    document.querySelectorAll('.ia-flash').forEach(function (el) {
      setTimeout(function () {
        el.style.transition = 'opacity .4s';
        el.style.opacity = '0';
        setTimeout(function () { el.remove(); }, 400);
      }, 4000);
    });
  }

  // Character counters on textareas with data-maxlength
  function bindCharCounters() {
    document.querySelectorAll('textarea[data-maxlength]').forEach(function (ta) {
      var max     = parseInt(ta.getAttribute('data-maxlength'), 10);
      var counter = document.getElementById(ta.getAttribute('data-counter'));
      if (!counter) return;

      function update() {
        var remaining = max - ta.value.length;
        counter.textContent = remaining;
        counter.classList.toggle('warn', remaining <= 20);
      }

      ta.addEventListener('input', update);
      update();
    });
  }

  // Confirm dialogs on buttons with data-confirm
  function bindConfirmButtons() {
    document.querySelectorAll('[data-confirm]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        var msg = btn.getAttribute('data-confirm') || 'Are you sure?';
        if (!confirm(msg)) {
          e.preventDefault();
          e.stopPropagation();
        }
      });
    });
  }

  // Expose a simple AJAX helper for page-specific JS
  window.IntakeAjax = {
    post: function (url, data, callback) {
      var formData = new FormData();
      formData.append('_token', window.IntakeAdmin.csrfToken);
      Object.keys(data).forEach(function (k) { formData.append(k, data[k]); });

      fetch(url, { method: 'POST', body: formData })
        .then(function (r) { return r.json(); })
        .then(callback)
        .catch(function (err) { console.error('IntakeAjax error:', err); });
    },
  };

}());
