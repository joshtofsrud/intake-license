@extends('layouts.tenant.app')
@php
  $pageTitle = 'Work Order Fields';
@endphp

<style>
.wof-layout { display: flex; flex-direction: column; gap: 16px; }
.wof-info-banner {
  background: #E6F1FB;
  color: #0C447C;
  border-left: 2px solid #378ADD;
  padding: 10px 14px;
  border-radius: 6px;
  font-size: 12px;
  line-height: 1.5;
}
.wof-info-banner strong { font-weight: 600; }

.wof-table {
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-lg);
  overflow: hidden;
}
.wof-row {
  display: grid;
  grid-template-columns: 26px 1fr 110px 90px 28px;
  gap: 12px;
  align-items: center;
  padding: 14px 16px;
  border-bottom: 0.5px solid var(--ia-border);
}
.wof-row:last-child { border-bottom: none; }
.wof-row--header {
  padding: 10px 16px;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .07em;
  font-weight: 500;
  color: var(--ia-text-muted);
}
.wof-row--identifier {
  background: var(--ia-accent-soft);
}
.wof-drag {
  color: var(--ia-text-dim);
  font-size: 14px;
  cursor: grab;
  user-select: none;
  text-align: center;
}
.wof-drag:active { cursor: grabbing; }
.wof-label-line {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.wof-label {
  font-size: 14px;
  font-weight: 500;
}
.wof-identifier-pill {
  background: var(--ia-accent);
  color: var(--ia-accent-text);
  font-size: 10px;
  font-weight: 500;
  padding: 2px 8px;
  border-radius: 20px;
  text-transform: uppercase;
  letter-spacing: .05em;
}
.wof-help {
  font-size: 12px;
  opacity: .55;
  margin-top: 3px;
}
.wof-options-preview {
  font-size: 11px;
  opacity: .55;
  margin-top: 3px;
}
.wof-type {
  font-size: 12px;
  color: var(--ia-text-muted);
}
.wof-required {
  text-align: center;
  font-size: 12px;
}
.wof-required-yes { color: #3B6D11; font-weight: 500; }
.wof-required-no  { color: var(--ia-text-dim); }
.wof-menu {
  color: var(--ia-text-muted);
  font-size: 14px;
  cursor: pointer;
  text-align: center;
  user-select: none;
  line-height: 1;
  padding: 4px;
  border-radius: 4px;
}
.wof-menu:hover { background: var(--ia-hover); }

.wof-add-card {
  border: 0.5px dashed var(--ia-border-strong);
  border-radius: var(--ia-r-md);
  padding: 18px;
  text-align: center;
  color: var(--ia-text-muted);
  font-size: 13px;
  cursor: pointer;
  transition: all var(--ia-t);
}
.wof-add-card:hover {
  border-color: var(--ia-text);
  color: var(--ia-text);
}

.wof-empty {
  padding: 40px 20px;
  text-align: center;
}
.wof-empty-text {
  font-size: 14px;
  opacity: .6;
  margin-bottom: 12px;
}

.wof-modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.5);
  display: none;
  align-items: center;
  justify-content: center;
  z-index: 50;
  padding: 20px;
}
.wof-modal-backdrop.open { display: flex; }
.wof-modal {
  background: var(--ia-surface);
  border-radius: var(--ia-r-lg);
  padding: 24px;
  width: 100%;
  max-width: 520px;
  max-height: 90vh;
  overflow-y: auto;
}
.wof-modal-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
  padding-bottom: 12px;
  border-bottom: 0.5px solid var(--ia-border);
}
.wof-modal-title {
  font-size: 16px;
  font-weight: 500;
}
.wof-modal-close {
  color: var(--ia-text-muted);
  cursor: pointer;
  font-size: 20px;
  line-height: 1;
  padding: 4px;
}

.wof-form-row { margin-bottom: 16px; }
.wof-form-row label {
  display: block;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .07em;
  font-weight: 500;
  color: var(--ia-text-muted);
  margin-bottom: 6px;
}
.wof-form-row input[type="text"],
.wof-form-row textarea,
.wof-form-row select {
  width: 100%;
  padding: 8px 10px;
  border-radius: var(--ia-r-md);
  border: 0.5px solid var(--ia-border);
  background: var(--ia-input-bg);
  color: var(--ia-text);
  font-size: 14px;
  font-family: inherit;
}
.wof-form-row textarea { resize: vertical; min-height: 72px; }
.wof-form-row .wof-hint {
  font-size: 11px;
  opacity: .55;
  margin-top: 4px;
}

.wof-checkboxes {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.wof-checkbox-row {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 10px;
  border-radius: var(--ia-r-md);
  border: 0.5px solid var(--ia-border);
  cursor: pointer;
}
.wof-checkbox-row input { margin-top: 2px; flex-shrink: 0; }
.wof-checkbox-body {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.wof-checkbox-label {
  font-size: 13px;
  font-weight: 500;
}
.wof-checkbox-help {
  font-size: 11px;
  opacity: .55;
  line-height: 1.4;
}

.wof-options-section {
  display: none;
  padding: 12px;
  background: var(--ia-surface-2);
  border-radius: var(--ia-r-md);
}
.wof-options-section.open { display: block; }

.wof-modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 24px;
  padding-top: 16px;
  border-top: 0.5px solid var(--ia-border);
}

.wof-error {
  background: #FCEBEB;
  color: #A32D2D;
  padding: 10px 12px;
  border-radius: var(--ia-r-md);
  font-size: 12px;
  margin-bottom: 12px;
  display: none;
}
.wof-error.open { display: block; }
</style>

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Work order fields</h1>
    <p class="ia-page-subtitle">Fields your team fills in when receiving a bike.</p>
  </div>
  <div class="ia-page-actions">
    <button type="button" class="ia-btn ia-btn--primary" id="wof-add-btn">+ Add field</button>
  </div>
</div>

<div class="wof-layout">

  <div class="wof-info-banner">
    One field can be marked as a <strong>searchable identifier</strong> — used to look up work orders across appointments. Usually the bike's serial number.
  </div>

  <div class="wof-table" id="wof-list">
    <div class="wof-row wof-row--header">
      <div></div>
      <div>Label</div>
      <div>Type</div>
      <div style="text-align:center">Required</div>
      <div></div>
    </div>

    <div id="wof-rows"></div>
  </div>

  <div class="wof-add-card" id="wof-add-card-btn">+ Add another field</div>

</div>

<div class="wof-modal-backdrop" id="wof-modal-backdrop">
  <div class="wof-modal">
    <div class="wof-modal-head">
      <div class="wof-modal-title" id="wof-modal-title">Add field</div>
      <div class="wof-modal-close" id="wof-modal-close">&times;</div>
    </div>

    <div class="wof-error" id="wof-error"></div>

    <form id="wof-form">
      <input type="hidden" id="wof-field-id" value="">

      <div class="wof-form-row">
        <label>Label</label>
        <input type="text" id="wof-label" maxlength="100" placeholder="e.g. Serial Number" required>
      </div>

      <div class="wof-form-row">
        <label>Field type</label>
        <select id="wof-field-type">
          @foreach($fieldTypes as $value => $label)
            <option value="{{ $value }}">{{ $label }}</option>
          @endforeach
        </select>
      </div>

      <div class="wof-options-section" id="wof-options-section">
        <div class="wof-form-row" style="margin-bottom:8px">
          <label>Dropdown options</label>
          <textarea id="wof-options" rows="5" placeholder="One option per line"></textarea>
          <div class="wof-hint">One option per line. At least 2, maximum 50.</div>
        </div>
      </div>

      <div class="wof-form-row">
        <label>Help text (optional)</label>
        <input type="text" id="wof-help-text" maxlength="255" placeholder="Shown under the field as a hint">
      </div>

      <div class="wof-form-row">
        <label>Settings</label>
        <div class="wof-checkboxes">
          <label class="wof-checkbox-row">
            <input type="checkbox" id="wof-is-required">
            <div class="wof-checkbox-body">
              <span class="wof-checkbox-label">Required</span>
              <span class="wof-checkbox-help">Must be filled before work order can be closed.</span>
            </div>
          </label>
          <label class="wof-checkbox-row">
            <input type="checkbox" id="wof-is-identifier">
            <div class="wof-checkbox-body">
              <span class="wof-checkbox-label">Searchable identifier</span>
              <span class="wof-checkbox-help">Only one field can be the identifier. Setting this will unset any other identifier.</span>
            </div>
          </label>
          <label class="wof-checkbox-row">
            <input type="checkbox" id="wof-is-customer-visible" checked>
            <div class="wof-checkbox-body">
              <span class="wof-checkbox-label">Show to customer</span>
              <span class="wof-checkbox-help">Displayed on booking confirmation and appointment page.</span>
            </div>
          </label>
        </div>
      </div>

      <div class="wof-modal-actions">
        <button type="button" class="ia-btn ia-btn--secondary" id="wof-modal-cancel">Cancel</button>
        <button type="submit" class="ia-btn ia-btn--primary" id="wof-modal-save">Save field</button>
      </div>
    </form>
  </div>
</div>

@endsection

@push('scripts')
<script>
(function () {
  'use strict';

  var csrf = window.IntakeAdmin.csrfToken;
  var baseUrl = '{{ route("tenant.work-order-fields.index") }}';

  var fields = @json($jsFields);

  var rowsEl = document.getElementById('wof-rows');
  var addBtn = document.getElementById('wof-add-btn');
  var addCardBtn = document.getElementById('wof-add-card-btn');
  var backdrop = document.getElementById('wof-modal-backdrop');
  var closeBtn = document.getElementById('wof-modal-close');
  var cancelBtn = document.getElementById('wof-modal-cancel');
  var form = document.getElementById('wof-form');
  var modalTitle = document.getElementById('wof-modal-title');
  var errorEl = document.getElementById('wof-error');
  var typeSelect = document.getElementById('wof-field-type');
  var optionsSection = document.getElementById('wof-options-section');

  function render() {
    rowsEl.innerHTML = '';
    if (fields.length === 0) {
      var empty = document.createElement('div');
      empty.className = 'wof-empty';
      empty.innerHTML = '<div class="wof-empty-text">No work order fields yet.</div>';
      rowsEl.appendChild(empty);
      return;
    }

    fields.forEach(function (f) {
      var row = document.createElement('div');
      row.className = 'wof-row' + (f.is_identifier ? ' wof-row--identifier' : '');
      row.setAttribute('data-field-id', f.id);
      row.draggable = true;

      var typeLabel = typeLabelFor(f.field_type);
      var optionsPreview = '';
      if (f.field_type === 'select' && f.options && f.options.length) {
        optionsPreview = '<div class="wof-options-preview">' + f.options.length + ' options: ' + escapeHtml(f.options.slice(0,5).join(', ')) + (f.options.length > 5 ? '…' : '') + '</div>';
      }

      row.innerHTML =
        '<div class="wof-drag">⋮⋮</div>' +
        '<div>' +
          '<div class="wof-label-line">' +
            '<span class="wof-label">' + escapeHtml(f.label) + '</span>' +
            (f.is_identifier ? '<span class="wof-identifier-pill">Identifier</span>' : '') +
          '</div>' +
          (f.help_text ? '<div class="wof-help">' + escapeHtml(f.help_text) + '</div>' : '') +
          optionsPreview +
        '</div>' +
        '<div class="wof-type">' + typeLabel + '</div>' +
        '<div class="wof-required">' +
          (f.is_required ? '<span class="wof-required-yes">Yes</span>' : '<span class="wof-required-no">—</span>') +
        '</div>' +
        '<div class="wof-menu" data-action="menu">⋯</div>';

      rowsEl.appendChild(row);
    });

    bindRowHandlers();
  }

  function typeLabelFor(t) {
    var m = { 'text':'Text', 'textarea':'Long text', 'number':'Number', 'select':'Dropdown' };
    return m[t] || t;
  }

  function escapeHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function openModal(field) {
    errorEl.classList.remove('open');
    form.reset();

    if (field) {
      modalTitle.textContent = 'Edit field';
      document.getElementById('wof-field-id').value = field.id;
      document.getElementById('wof-label').value = field.label;
      document.getElementById('wof-field-type').value = field.field_type;
      document.getElementById('wof-help-text').value = field.help_text || '';
      document.getElementById('wof-is-required').checked = !!field.is_required;
      document.getElementById('wof-is-identifier').checked = !!field.is_identifier;
      document.getElementById('wof-is-customer-visible').checked = !!field.is_customer_visible;
      if (field.field_type === 'select' && field.options) {
        document.getElementById('wof-options').value = field.options.join('\n');
      }
    } else {
      modalTitle.textContent = 'Add field';
      document.getElementById('wof-field-id').value = '';
      document.getElementById('wof-is-customer-visible').checked = true;
    }

    toggleOptionsSection();
    backdrop.classList.add('open');
    setTimeout(function(){ document.getElementById('wof-label').focus(); }, 50);
  }

  function closeModal() { backdrop.classList.remove('open'); }

  function toggleOptionsSection() {
    if (typeSelect.value === 'select') optionsSection.classList.add('open');
    else optionsSection.classList.remove('open');
  }

  function submitForm(e) {
    e.preventDefault();
    errorEl.classList.remove('open');

    var id = document.getElementById('wof-field-id').value;
    var fd = new FormData();
    fd.append('_token', csrf);
    if (id) fd.append('_method', 'PATCH');
    fd.append('label', document.getElementById('wof-label').value);
    fd.append('field_type', document.getElementById('wof-field-type').value);
    fd.append('help_text', document.getElementById('wof-help-text').value);
    fd.append('is_required', document.getElementById('wof-is-required').checked ? '1' : '0');
    fd.append('is_identifier', document.getElementById('wof-is-identifier').checked ? '1' : '0');
    fd.append('is_customer_visible', document.getElementById('wof-is-customer-visible').checked ? '1' : '0');

    if (document.getElementById('wof-field-type').value === 'select') {
      var opts = document.getElementById('wof-options').value.split('\n').map(function(s){ return s.trim(); }).filter(Boolean);
      opts.forEach(function(o){ fd.append('options[]', o); });
    }

    var url = id ? baseUrl + '/' + id : baseUrl;

    fetch(url, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function(r){ return r.json(); })
      .then(function(resp){
        if (!resp.ok) { showError(resp.error || 'Save failed.'); return; }
        var updated = resp.data;
        if (id) {
          var i = fields.findIndex(function(f){ return f.id === id; });
          if (i >= 0) fields[i] = updated;
          if (updated.is_identifier) {
            fields.forEach(function(f){ if (f.id !== updated.id) f.is_identifier = false; });
          }
        } else {
          if (updated.is_identifier) {
            fields.forEach(function(f){ f.is_identifier = false; });
          }
          fields.push(updated);
        }
        closeModal();
        render();
      })
      .catch(function(){ showError('Network error.'); });
  }

  function deleteField(id) {
    if (!confirm('Delete this field? Existing work-order values will remain on their appointments but can no longer be edited.')) return;
    var fd = new FormData();
    fd.append('_token', csrf);
    fd.append('_method', 'DELETE');
    fetch(baseUrl + '/' + id, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function(r){ return r.json(); })
      .then(function(resp){
        if (resp.ok) {
          fields = fields.filter(function(f){ return f.id !== id; });
          render();
        }
      });
  }

  function bindRowHandlers() {
    rowsEl.querySelectorAll('.wof-row').forEach(function(row) {
      var id = row.getAttribute('data-field-id');
      var menuBtn = row.querySelector('[data-action="menu"]');
      if (menuBtn) {
        menuBtn.addEventListener('click', function(e) {
          e.stopPropagation();
          var choice = prompt('Type "edit" to edit, or "delete" to remove this field:', 'edit');
          if (choice === 'edit') {
            var f = fields.find(function(x){ return x.id === id; });
            if (f) openModal(f);
          } else if (choice === 'delete') {
            deleteField(id);
          }
        });
      }

      row.addEventListener('dragstart', function(e) {
        row.classList.add('wof-dragging');
        e.dataTransfer.setData('text/plain', id);
        e.dataTransfer.effectAllowed = 'move';
      });
      row.addEventListener('dragend', function() { row.classList.remove('wof-dragging'); });
      row.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
      });
      row.addEventListener('drop', function(e) {
        e.preventDefault();
        var draggedId = e.dataTransfer.getData('text/plain');
        if (draggedId === id) return;
        var from = fields.findIndex(function(f){ return f.id === draggedId; });
        var to = fields.findIndex(function(f){ return f.id === id; });
        if (from < 0 || to < 0) return;
        var moved = fields.splice(from, 1)[0];
        fields.splice(to, 0, moved);
        render();
        saveOrder();
      });
    });
  }

  function saveOrder() {
    var firstId = fields[0] && fields[0].id;
    if (!firstId) return;

    var fd = new FormData();
    fd.append('_token', csrf);
    fd.append('_method', 'PATCH');
    fd.append('op', 'reorder');
    fields.forEach(function(f){ fd.append('order[]', f.id); });

    fetch(baseUrl + '/' + firstId, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
  }

  function showError(msg) {
    errorEl.textContent = msg;
    errorEl.classList.add('open');
  }

  addBtn.addEventListener('click', function(){ openModal(null); });
  addCardBtn.addEventListener('click', function(){ openModal(null); });
  closeBtn.addEventListener('click', closeModal);
  cancelBtn.addEventListener('click', closeModal);
  backdrop.addEventListener('click', function(e){ if (e.target === backdrop) closeModal(); });
  typeSelect.addEventListener('change', toggleOptionsSection);
  form.addEventListener('submit', submitForm);

  render();
})();
</script>
@endpush
