(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var card = document.querySelector('[data-appt-slot-weight-card]');
    if (!card) return;

    var select  = card.querySelector('[data-appt-slot-weight-select]');
    var saveBtn = card.querySelector('[data-appt-slot-weight-save]');
    var apptId  = saveBtn ? saveBtn.getAttribute('data-appt-id') : null;
    if (!select || !saveBtn || !apptId) return;

    var originalValue = select.value;

    function getCsrf() {
      return (window.IntakeAdmin && window.IntakeAdmin.csrfToken) || '';
    }

    function reset() {
      saveBtn.disabled = false;
      saveBtn.textContent = 'Save slot weight';
    }

    saveBtn.addEventListener('click', function () {
      var newValue = select.value;
      if (newValue === originalValue) {
        if (window.IntakeToast) window.IntakeToast.info('No change to save.');
        return;
      }

      saveBtn.disabled = true;
      saveBtn.textContent = 'Saving...';

      var fd = new FormData();
      fd.append('_method', 'PATCH');
      fd.append('_token', getCsrf());
      fd.append('op', 'slot_weight');
      fd.append('slot_weight', newValue);

      fetch('/admin/appointments/' + encodeURIComponent(apptId), {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': getCsrf(), 'Accept': 'application/json' },
        body: fd,
        credentials: 'same-origin'
      })
        .then(function (res) { return res.json().then(function (b) { return { status: res.status, body: b }; }); })
        .then(function (r) {
          if (r.status === 200 && r.body.ok) {
            if (window.IntakeToast) window.IntakeToast.success('Slot weight updated.');
            window.location.reload();
            return;
          }
          var msg = (r.body && r.body.message) || 'Could not update slot weight.';
          if (window.IntakeToast) window.IntakeToast.error(msg);
          select.value = originalValue;
          reset();
        })
        .catch(function () {
          if (window.IntakeToast) window.IntakeToast.error('Network error. Try again.');
          select.value = originalValue;
          reset();
        });
    });
  });
})();
