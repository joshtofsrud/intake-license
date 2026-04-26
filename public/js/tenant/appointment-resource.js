(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var card = document.querySelector('[data-appt-resource-card]');
    if (!card) return;

    var apptId  = card.getAttribute('data-appt-id');
    var select  = card.querySelector('[data-appt-resource-select]');
    var saveBtn = card.querySelector('[data-appt-resource-save]');
    if (!apptId || !select || !saveBtn) return;

    var originalValue = select.value;

    function endpoint() {
      return '/admin/appointments/' + encodeURIComponent(apptId);
    }

    function getCsrf() {
      return (window.IntakeAdmin && window.IntakeAdmin.csrfToken) || '';
    }

    function postChange(resourceId, force) {
      var fd = new FormData();
      fd.append('_method', 'PATCH');
      fd.append('_token', getCsrf());
      fd.append('op', 'change_resource');
      fd.append('resource_id', resourceId);
      if (force) fd.append('force', '1');

      return fetch(endpoint(), {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': getCsrf(), 'Accept': 'application/json' },
        body: fd,
        credentials: 'same-origin'
      });
    }

    function resetButton() {
      saveBtn.disabled = false;
      saveBtn.textContent = 'Save resource';
    }

    function readJson(res) {
      return res.json().then(function (body) {
        return { status: res.status, body: body };
      });
    }

    function handleSave() {
      var newValue = select.value;
      if (newValue === originalValue) {
        if (window.IntakeToast) window.IntakeToast.info('No change to save.');
        return;
      }

      saveBtn.disabled = true;
      saveBtn.textContent = 'Saving...';

      postChange(newValue, false).then(readJson).then(function (r) {
        if (r.status === 200 && r.body.ok) {
          if (window.IntakeToast) window.IntakeToast.success('Resource changed.');
          window.location.reload();
          return;
        }

        if (r.status === 409 && r.body.conflict) {
          handleConflict(newValue, r.body);
          return;
        }

        var msg = (r.body && r.body.message) || 'Could not change resource.';
        if (window.IntakeToast) window.IntakeToast.error(msg);
        select.value = originalValue;
        resetButton();
      }).catch(function () {
        if (window.IntakeToast) window.IntakeToast.error('Network error. Try again.');
        select.value = originalValue;
        resetButton();
      });
    }

    function handleConflict(newValue, body) {
      var conflict = body.conflict || {};
      var oldName  = body.old_name || 'current resource';
      var newName  = body.new_name || 'new resource';
      var message;

      if (conflict.kind === 'appointment') {
        var who = conflict.customer_name || ('appointment ' + (conflict.ra_number || ''));
        message = newName + ' already has ' + who +
                  ' booked from ' + conflict.starts_at + ' to ' + conflict.ends_at + '. ' +
                  'Move anyway? This will create a double-booking on ' + newName + '.';
      } else if (conflict.kind === 'break') {
        message = newName + ' has a break from ' + conflict.starts_at +
                  ' to ' + conflict.ends_at + '. Move anyway?';
      } else if (conflict.kind === 'hold') {
        message = newName + ' has a walk-in hold from ' + conflict.starts_at +
                  ' to ' + conflict.ends_at + '. Move anyway?';
      } else {
        message = 'That resource is busy at this time. Move anyway?';
      }

      window.IntakeConfirm.show({
        title:       'Resource is busy',
        message:     message,
        confirmText: 'Move anyway',
        cancelText:  'Keep on ' + oldName,
        danger:      true
      }).then(function (ok) {
        if (!ok) {
          select.value = originalValue;
          resetButton();
          return;
        }

        saveBtn.textContent = 'Saving (override)...';
        postChange(newValue, true).then(readJson).then(function (r) {
          if (r.status === 200 && r.body.ok) {
            if (window.IntakeToast) window.IntakeToast.success('Resource changed (override).');
            window.location.reload();
            return;
          }
          var msg = (r.body && r.body.message) || 'Could not change resource.';
          if (window.IntakeToast) window.IntakeToast.error(msg);
          select.value = originalValue;
          resetButton();
        }).catch(function () {
          if (window.IntakeToast) window.IntakeToast.error('Network error. Try again.');
          select.value = originalValue;
          resetButton();
        });
      });
    }

    saveBtn.addEventListener('click', handleSave);
  });
})();
