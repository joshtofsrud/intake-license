{{-- SO CREATE DRAWER · universal creation surface
     Opens via SoDrawer.open(). Hand-rolled JS, no Livewire.
     Reuses tenant.inventory.items.search and tenant.customers.search.
     Posts allocations as nested array — controller creates one SO row
     per allocation, sharing a batch_id when >1 row. --}}

<div id="so-drawer" class="so-drawer-shell" style="display:none">
  <div class="so-drawer-backdrop" onclick="SoDrawer.close()"></div>
  <div class="so-drawer-panel">
    <form id="so-drawer-form" method="POST" action="{{ route('tenant.special-orders.store') }}">
      @csrf

      <div class="so-drawer-head">
        <h3>New special order</h3>
        <button type="button" class="so-drawer-x" onclick="SoDrawer.close()" aria-label="Close">×</button>
      </div>

      <div class="so-drawer-body">

        {{-- ITEM PICKER --}}
        <div class="ia-form-group">
          <label class="ia-form-label">Item <span class="ia-required">*</span></label>
          <div class="so-item-picker">
            <input type="text" id="so-item-search" class="ia-input"
                   placeholder="Search inventory items by name, SKU, or UPC…"
                   autocomplete="off">
            <input type="hidden" name="inventory_item_id" id="so-item-id">
            <input type="hidden" name="item_name" id="so-item-name">
            <div id="so-item-results" class="so-search-results" style="display:none"></div>
            <div id="so-item-selected" class="so-selected-row" style="display:none">
              <div>
                <strong id="so-selected-item-name"></strong>
                <div class="ia-text-muted" style="font-size:11.5px" id="so-selected-item-sku"></div>
              </div>
              <button type="button" class="ia-btn ia-btn--ghost" onclick="SoDrawer.clearItem()">Change</button>
            </div>
          </div>
          <div class="ia-form-help">
            Don't see it in the list? Type the item name freeform and submit — it'll be created as a "not yet catalogued" SO.
          </div>
          <button type="button" id="so-item-freeform-btn" class="ia-btn ia-btn--ghost" style="margin-top:8px;display:none"
                  onclick="SoDrawer.useFreeformItem()">
            Use "<span id="so-item-freeform-label"></span>" as freeform item name
          </button>
        </div>

        {{-- VENDOR + PO + ETA + COST --}}
        <div class="ia-input-grid-2">
          <div class="ia-form-group">
            <label class="ia-form-label">Vendor</label>
            <select name="vendor_id" class="ia-select">
              <option value="">— TBD (will be needed, not ordered) —</option>
              @foreach($vendors as $v)
                <option value="{{ $v->id }}">{{ $v->name }}</option>
              @endforeach
            </select>
            <div class="ia-form-help">If selected with PO + ETA, SO starts as "ordered". Otherwise "needed".</div>
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Our PO #</label>
            <input type="text" name="po_number" class="ia-input" maxlength="64">
          </div>
        </div>

        <div class="ia-input-grid-2">
          <div class="ia-form-group">
            <label class="ia-form-label">Expected arrival</label>
            <input type="date" name="expected_arrival_date" class="ia-input">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Est. unit cost (cents)</label>
            <input type="number" name="unit_cost_cents_estimated" class="ia-input" min="0" placeholder="e.g. 1250 = $12.50">
          </div>
        </div>

        {{-- ALLOCATION TABLE --}}
        <div class="ia-form-group">
          <label class="ia-form-label">Allocate to <span class="ia-required">*</span></label>
          <div id="so-allocations" class="so-allocations">
            {{-- Allocation rows injected via JS --}}
          </div>
          <button type="button" class="ia-btn ia-btn--ghost" onclick="SoDrawer.addAllocation()" style="margin-top:8px">
            + Add allocation row
          </button>
          <div class="ia-form-help">
            Pick where each unit goes. Modes: <strong>Customer</strong> (no specific appointment), <strong>Customer + appt</strong> (linked to a specific job), or <strong>Stock</strong> (shop inventory). Multiple rows create a batch.
          </div>
        </div>

        {{-- DEPOSIT --}}
        <div class="ia-form-group">
          <label class="ia-form-label">Deposit (cents, optional)</label>
          <input type="number" name="deposit_cents" class="ia-input" min="0" placeholder="0">
          <div class="ia-form-help">
            Recorded on the SO row only. Stripe capture wires up in Stage 6.
          </div>
        </div>

        {{-- INITIAL NOTE --}}
        <div class="ia-form-group">
          <label class="ia-form-label">Initial note (optional)</label>
          <textarea name="notes" class="ia-input" rows="2" placeholder="Context staff should know — customer prefs, vendor quirks, etc."></textarea>
        </div>

      </div>

      <div class="so-drawer-foot">
        <button type="button" class="ia-btn ia-btn--ghost" onclick="SoDrawer.close()">Cancel</button>
        <button type="submit" class="ia-btn ia-btn--primary" id="so-drawer-submit">Create special order</button>
      </div>

    </form>
  </div>
</div>

@push('scripts')
<script>
(function () {
  'use strict';

  // ── State ─────────────────────────────────────────────
  var allocationRows = [];
  var nextAllocId = 1;

  // ── Routes (passed from Blade) ────────────────────────
  var ROUTES = {
    itemSearch: '{{ route("tenant.inventory.items.search") }}',
    customerSearch: '{{ route("tenant.customers.search") }}',
    apptsForCustomer: '{{ route("tenant.special-orders.appointments-for-customer") }}',
  };

  // ── Drawer open/close ─────────────────────────────────
  // open() accepts an optional prefill object from entry-point buttons:
  //   { item_id, item_name, customer_id, customer_label, appointment_id, alloc_mode }
  // Stage 5 integration touchpoints (item detail, customer detail,
  // appointment detail) pass context-appropriate prefills.
  window.SoDrawer = {
    open: function (opts) {
      opts = opts || {};
      document.getElementById('so-drawer').style.display = 'flex';
      document.body.style.overflow = 'hidden';

      // Item prefill (skip the search step)
      if (opts.item_id && opts.item_name) {
        SoDrawer.pickItem(opts.item_id, opts.item_name, '');
      } else if (opts.item_name && !opts.item_id) {
        // Freeform item name (no catalog match)
        document.getElementById('so-item-id').value = '';
        document.getElementById('so-item-name').value = opts.item_name;
        document.getElementById('so-selected-item-name').textContent = opts.item_name + ' (not catalogued)';
        document.getElementById('so-selected-item-sku').textContent = '';
        document.getElementById('so-item-selected').style.display = 'flex';
        document.getElementById('so-item-search').style.display = 'none';
      }

      // Ensure at least one allocation row exists
      if (allocationRows.length === 0) {
        SoDrawer.addAllocation();
      }

      // Customer prefill on first allocation row
      if (opts.customer_id && opts.customer_label) {
        var firstAllocId = allocationRows[0];
        var firstRow = document.querySelector('.so-alloc-row[data-alloc-id="' + firstAllocId + '"]');
        if (firstRow) {
          // Set mode first if specified
          if (opts.alloc_mode === 'customer_appt') {
            SoDrawer.setMode(firstAllocId, 'customer_appt');
          }
          // Pre-pick the customer
          firstRow.querySelector('input[name^="allocations"][name$="[customer_id]"]').value = opts.customer_id;
          var sel = firstRow.querySelector('.so-customer-selected');
          sel.innerHTML = '<strong>' + escapeHtml(opts.customer_label) + '</strong>' +
            '<button type="button" class="ia-btn ia-btn--ghost" onclick="SoDrawer._clearCustomer(this)">Change</button>';
          sel.style.display = 'flex';
          firstRow.querySelector('.so-customer-search').style.display = 'none';

          // If appointment was passed too, set it directly (no need for picker)
          if (opts.appointment_id) {
            firstRow.querySelector('input[name^="allocations"][name$="[appointment_id]"]').value = opts.appointment_id;
            // Load + select the matching option in the appt picker so user sees it
            loadApptsForCustomer(firstRow, opts.customer_id);
            // After load completes (async), the prefilled appointment_id is honored
            // by hidden input; UI lag is minor and the form submits correctly.
          } else if (opts.alloc_mode === 'customer_appt') {
            loadApptsForCustomer(firstRow, opts.customer_id);
          }
        }
      }
    },
    close: function () {
      document.getElementById('so-drawer').style.display = 'none';
      document.body.style.overflow = '';
    },

    // ── Item picker ─────────────────────────────────────
    clearItem: function () {
      document.getElementById('so-item-id').value = '';
      document.getElementById('so-item-name').value = '';
      document.getElementById('so-item-selected').style.display = 'none';
      document.getElementById('so-item-search').style.display = 'block';
      document.getElementById('so-item-search').value = '';
      document.getElementById('so-item-search').focus();
    },

    pickItem: function (id, name, sku) {
      document.getElementById('so-item-id').value = id;
      document.getElementById('so-item-name').value = name;
      document.getElementById('so-selected-item-name').textContent = name;
      document.getElementById('so-selected-item-sku').textContent = sku || '';
      document.getElementById('so-item-selected').style.display = 'flex';
      document.getElementById('so-item-search').style.display = 'none';
      document.getElementById('so-item-results').style.display = 'none';
      document.getElementById('so-item-freeform-btn').style.display = 'none';
    },

    useFreeformItem: function () {
      var label = document.getElementById('so-item-freeform-label').textContent;
      if (!label) return;
      document.getElementById('so-item-id').value = '';
      document.getElementById('so-item-name').value = label;
      document.getElementById('so-selected-item-name').textContent = label + ' (not catalogued)';
      document.getElementById('so-selected-item-sku').textContent = '';
      document.getElementById('so-item-selected').style.display = 'flex';
      document.getElementById('so-item-search').style.display = 'none';
      document.getElementById('so-item-results').style.display = 'none';
      document.getElementById('so-item-freeform-btn').style.display = 'none';
    },

    // ── Allocations ─────────────────────────────────────
    addAllocation: function () {
      var idx = nextAllocId++;
      allocationRows.push(idx);
      var row = document.createElement('div');
      row.className = 'so-alloc-row';
      row.dataset.allocId = idx;
      row.innerHTML = ''
        + '<div class="so-alloc-mode">'
        +   '<button type="button" class="so-mode-btn active" data-mode="customer" onclick="SoDrawer.setMode(' + idx + ',\'customer\')">Customer</button>'
        +   '<button type="button" class="so-mode-btn" data-mode="customer_appt" onclick="SoDrawer.setMode(' + idx + ',\'customer_appt\')">Customer + appt</button>'
        +   '<button type="button" class="so-mode-btn" data-mode="stock" onclick="SoDrawer.setMode(' + idx + ',\'stock\')">Stock</button>'
        + '</div>'
        + '<input type="hidden" name="allocations[' + idx + '][mode]" value="customer">'
        + '<div class="so-alloc-body">'
        +   '<div class="so-alloc-customer">'
        +     '<input type="text" class="ia-input so-customer-search" placeholder="Search customers…" autocomplete="off">'
        +     '<input type="hidden" name="allocations[' + idx + '][customer_id]">'
        +     '<div class="so-customer-results so-search-results" style="display:none"></div>'
        +     '<div class="so-customer-selected so-selected-row" style="display:none"></div>'
        +   '</div>'
        +   '<div class="so-alloc-appt" style="display:none">'
        +     '<select class="ia-select so-appt-select">'
        +       '<option value="">— pick an appointment —</option>'
        +     '</select>'
        +     '<input type="hidden" name="allocations[' + idx + '][appointment_id]">'
        +   '</div>'
        +   '<div class="so-alloc-stock-label" style="display:none">→ Shop inventory</div>'
        + '</div>'
        + '<div class="so-alloc-qty">'
        +   '<label class="ia-form-label" style="margin-bottom:2px;font-size:9.5px">Qty</label>'
        +   '<input type="number" name="allocations[' + idx + '][quantity]" class="ia-input" min="1" value="1" required>'
        + '</div>'
        + '<button type="button" class="so-alloc-remove" onclick="SoDrawer.removeAllocation(' + idx + ')" aria-label="Remove">×</button>';

      document.getElementById('so-allocations').appendChild(row);
      bindAllocationRow(row);
    },

    removeAllocation: function (idx) {
      if (allocationRows.length === 1) return; // keep at least one
      var row = document.querySelector('.so-alloc-row[data-alloc-id="' + idx + '"]');
      if (row) row.remove();
      allocationRows = allocationRows.filter(function (x) { return x !== idx; });
    },

    setMode: function (allocId, mode) {
      var row = document.querySelector('.so-alloc-row[data-alloc-id="' + allocId + '"]');
      if (!row) return;
      // Update buttons
      row.querySelectorAll('.so-mode-btn').forEach(function (b) {
        b.classList.toggle('active', b.dataset.mode === mode);
      });
      // Update hidden mode field
      row.querySelector('input[name^="allocations"][name$="[mode]"]').value = mode;
      // Show/hide body sections
      var custEl = row.querySelector('.so-alloc-customer');
      var apptEl = row.querySelector('.so-alloc-appt');
      var stockEl = row.querySelector('.so-alloc-stock-label');
      custEl.style.display = (mode === 'stock') ? 'none' : 'block';
      apptEl.style.display = (mode === 'customer_appt') ? 'block' : 'none';
      stockEl.style.display = (mode === 'stock') ? 'block' : 'none';
      // Clear customer + appt if switched to stock
      if (mode === 'stock') {
        row.querySelector('input[name^="allocations"][name$="[customer_id]"]').value = '';
        row.querySelector('input[name^="allocations"][name$="[appointment_id]"]').value = '';
      }
    },
  };

  // ── Item search debounced ─────────────────────────────
  var itemSearchTimer = null;
  document.addEventListener('DOMContentLoaded', function () {
    var input = document.getElementById('so-item-search');
    if (!input) return;
    input.addEventListener('input', function () {
      clearTimeout(itemSearchTimer);
      var q = this.value.trim();
      if (q.length < 2) {
        document.getElementById('so-item-results').style.display = 'none';
        document.getElementById('so-item-freeform-btn').style.display = 'none';
        return;
      }
      itemSearchTimer = setTimeout(function () { runItemSearch(q); }, 200);
    });
  });

  function runItemSearch(q) {
    fetch(ROUTES.itemSearch + '?q=' + encodeURIComponent(q), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var box = document.getElementById('so-item-results');
        if (!data.ok || !data.results || data.results.length === 0) {
          box.innerHTML = '<div class="so-search-empty">No matches. You can use this name as a freeform item.</div>';
          box.style.display = 'block';
          document.getElementById('so-item-freeform-label').textContent = q;
          document.getElementById('so-item-freeform-btn').style.display = 'block';
          return;
        }
        document.getElementById('so-item-freeform-btn').style.display = 'none';
        box.innerHTML = data.results.map(function (it) {
          return '<div class="so-search-row" onclick="SoDrawer.pickItem(\'' + it.id + '\',' +
            JSON.stringify(it.name) + ',' + JSON.stringify(it.sku || '') + ')">' +
            '<strong>' + escapeHtml(it.name) + '</strong>' +
            (it.sku ? '<div class="ia-text-muted" style="font-size:11px">' + escapeHtml(it.sku) + '</div>' : '') +
            '</div>';
        }).join('');
        box.style.display = 'block';
      })
      .catch(function () { /* silent */ });
  }

  // ── Allocation row bindings ───────────────────────────
  function bindAllocationRow(row) {
    var allocId = row.dataset.allocId;
    var custInput = row.querySelector('.so-customer-search');
    var custTimer = null;

    custInput.addEventListener('input', function () {
      clearTimeout(custTimer);
      var q = this.value.trim();
      if (q.length < 2) {
        row.querySelector('.so-customer-results').style.display = 'none';
        return;
      }
      custTimer = setTimeout(function () { runCustomerSearch(row, q); }, 200);
    });
  }

  function runCustomerSearch(row, q) {
    fetch(ROUTES.customerSearch + '?q=' + encodeURIComponent(q), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var box = row.querySelector('.so-customer-results');
        if (!data.customers || data.customers.length === 0) {
          box.innerHTML = '<div class="so-search-empty">No matches.</div>';
          box.style.display = 'block';
          return;
        }
        box.innerHTML = data.customers.map(function (c) {
          var label = (c.label || '').trim() || '(unnamed)';
          var meta  = [c.email, c.phone].filter(Boolean).join(' · ');
          return '<div class="so-search-row" data-cust-id="' + c.id + '" data-cust-label=' + JSON.stringify(label) + '>' +
            '<strong>' + escapeHtml(label) + '</strong>' +
            (meta ? '<div class="ia-text-muted" style="font-size:11px">' + escapeHtml(meta) + '</div>' : '') +
            '</div>';
        }).join('');
        // Bind clicks
        box.querySelectorAll('.so-search-row').forEach(function (el) {
          el.addEventListener('click', function () {
            pickCustomer(row, el.dataset.custId, el.dataset.custLabel);
          });
        });
        box.style.display = 'block';
      })
      .catch(function () { /* silent */ });
  }

  function pickCustomer(row, id, label) {
    row.querySelector('input[name^="allocations"][name$="[customer_id]"]').value = id;
    var sel = row.querySelector('.so-customer-selected');
    sel.innerHTML = '<strong>' + escapeHtml(label) + '</strong>' +
      '<button type="button" class="ia-btn ia-btn--ghost" onclick="SoDrawer._clearCustomer(this)">Change</button>';
    sel.style.display = 'flex';
    row.querySelector('.so-customer-search').style.display = 'none';
    row.querySelector('.so-customer-results').style.display = 'none';

    // If mode is customer_appt, fetch upcoming appts
    var mode = row.querySelector('input[name^="allocations"][name$="[mode]"]').value;
    if (mode === 'customer_appt') {
      loadApptsForCustomer(row, id);
    }
  }

  window.SoDrawer._clearCustomer = function (btn) {
    var row = btn.closest('.so-alloc-row');
    if (!row) return;
    row.querySelector('input[name^="allocations"][name$="[customer_id]"]').value = '';
    row.querySelector('input[name^="allocations"][name$="[appointment_id]"]').value = '';
    var sel = row.querySelector('.so-customer-selected');
    sel.style.display = 'none';
    var search = row.querySelector('.so-customer-search');
    search.style.display = 'block';
    search.value = '';
    search.focus();
    // Reset appt select
    var apptSelect = row.querySelector('.so-appt-select');
    if (apptSelect) apptSelect.innerHTML = '<option value="">— pick an appointment —</option>';
  };

  function loadApptsForCustomer(row, customerId) {
    fetch(ROUTES.apptsForCustomer + '?customer_id=' + encodeURIComponent(customerId), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var sel = row.querySelector('.so-appt-select');
        if (!sel) return;
        var html = '<option value="">— pick an appointment —</option>';
        if (data.ok && data.appointments) {
          data.appointments.forEach(function (a) {
            html += '<option value="' + a.id + '">' + escapeHtml(a.label) + '</option>';
          });
        }
        sel.innerHTML = html;
        sel.addEventListener('change', function () {
          row.querySelector('input[name^="allocations"][name$="[appointment_id]"]').value = this.value;
        });
      })
      .catch(function () { /* silent */ });
  }

  // ── Util ──────────────────────────────────────────────
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && document.getElementById('so-drawer').style.display === 'flex') {
      SoDrawer.close();
    }
  });
})();
</script>
@endpush

@push('styles')
<style>
/* SO-DRAWER — slide-in right drawer with search rows + allocation table */

.so-drawer-shell {
  position: fixed; inset: 0; z-index: 100;
  align-items: stretch; justify-content: flex-end;
}
.so-drawer-shell[style*="flex"] { display: flex !important; }
.so-drawer-backdrop {
  position: absolute; inset: 0; background: rgba(0,0,0,0.55);
}
.so-drawer-panel {
  position: relative; z-index: 1;
  background: var(--ia-surface);
  border-left: 0.5px solid var(--ia-border);
  width: min(560px, 92vw);
  display: flex; flex-direction: column;
  box-shadow: -8px 0 24px rgba(0,0,0,0.4);
}
.so-drawer-head {
  padding: 16px 20px;
  border-bottom: 0.5px solid var(--ia-border);
  display: flex; align-items: center; justify-content: space-between;
}
.so-drawer-head h3 { margin: 0; font-size: 16px; font-weight: 700; }
.so-drawer-x {
  background: transparent; border: none; color: var(--ia-text-muted);
  font-size: 24px; cursor: pointer; line-height: 1;
  width: 32px; height: 32px; border-radius: 6px;
}
.so-drawer-x:hover { background: var(--ia-surface); color: var(--ia-text); }
.so-drawer-body { padding: 20px; overflow-y: auto; flex: 1; }
.so-drawer-foot {
  padding: 14px 20px; border-top: 0.5px solid var(--ia-border);
  display: flex; gap: 8px; justify-content: flex-end;
}

#so-drawer-form { display: flex; flex-direction: column; height: 100%; }

/* Item picker */
.so-item-picker { position: relative; }
.so-search-results {
  position: absolute; top: 100%; left: 0; right: 0;
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-md);
  margin-top: 2px;
  max-height: 200px; overflow-y: auto;
  z-index: 10;
}
.so-search-row {
  padding: 10px 12px;
  border-bottom: 0.5px solid var(--ia-border);
  cursor: pointer;
  font-size: 13px;
}
.so-search-row:last-child { border-bottom: none; }
.so-search-row:hover { background: var(--ia-bg); }
.so-search-row strong { color: var(--ia-text); font-weight: 600; }
.so-search-empty {
  padding: 12px; font-size: 13px; color: var(--ia-text-muted); text-align: center;
}
.so-selected-row {
  padding: 10px 12px;
  background: var(--ia-bg);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-md);
  display: flex; align-items: center; justify-content: space-between;
  gap: 10px;
}

/* Allocations */
.so-allocations { display: flex; flex-direction: column; gap: 8px; }
.so-alloc-row {
  background: var(--ia-bg);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-md);
  padding: 10px 12px;
  display: grid;
  grid-template-columns: 1fr 80px 32px;
  gap: 10px;
  align-items: start;
}
.so-alloc-mode {
  grid-column: 1 / -1;
  display: flex; gap: 4px;
  margin-bottom: 6px;
}
.so-mode-btn {
  padding: 4px 10px;
  font-size: 11px; font-weight: 500;
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: 99px;
  cursor: pointer;
  color: var(--ia-text-muted);
}
.so-mode-btn.active {
  background: var(--ia-accent); color: #000; border-color: var(--ia-accent);
  font-weight: 600;
}
.so-alloc-body { position: relative; }
.so-alloc-customer { position: relative; }
.so-alloc-stock-label {
  padding: 8px 11px;
  font-size: 13px; color: var(--ia-text-muted); font-style: italic;
}
.so-alloc-appt { margin-top: 6px; }
.so-alloc-qty {
  font-size: 12px;
}
.so-alloc-qty label {
  display: block;
  text-transform: uppercase; letter-spacing: 0.06em; color: var(--ia-text-muted); font-weight: 600;
}
.so-alloc-remove {
  background: transparent; border: none;
  color: var(--ia-text-muted); cursor: pointer;
  font-size: 18px; line-height: 1;
  align-self: center;
}
.so-alloc-remove:hover { color: #F87171; }
</style>
@endpush
