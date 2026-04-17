<div id="detail-modal" style="display:none">
<style>
#detail-backdrop {
  position:fixed;inset:0;background:rgba(0,0,0,.6);
  backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);
  z-index:9999;display:flex;align-items:flex-start;justify-content:center;
  padding:40px 20px;overflow-y:auto;animation:dm-fade .2s ease-out;
}
@keyframes dm-fade{from{opacity:0}to{opacity:1}}
#detail-card {
  background:var(--ia-surface,#1a1a1a);color:var(--ia-text,#f0f0f0);
  border:.5px solid var(--ia-border,rgba(255,255,255,.1));
  border-radius:var(--ia-r-lg,16px);width:100%;max-width:720px;
  animation:dm-pop .25s cubic-bezier(.2,1.1,.3,1);
}
@keyframes dm-pop{from{transform:scale(.95) translateY(10px);opacity:0}to{transform:scale(1) translateY(0);opacity:1}}
.dm-head {
  padding:24px 28px 0;display:flex;justify-content:space-between;align-items:flex-start;
}
.dm-title{font-size:20px;font-weight:700;}
.dm-subtitle{font-size:13px;opacity:.5;margin-top:4px;}
.dm-close{background:none;border:none;color:inherit;font-size:24px;cursor:pointer;opacity:.5;padding:4px 8px;line-height:1;}
.dm-close:hover{opacity:1}
.dm-body{padding:20px 28px 24px;}
.dm-section{margin-bottom:20px;}
.dm-section:last-child{margin-bottom:0;}
.dm-section-label{font-size:11px;text-transform:uppercase;letter-spacing:.07em;font-weight:500;opacity:.45;margin-bottom:10px;}
.dm-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.dm-field-label{font-size:11px;opacity:.45;margin-bottom:2px;}
.dm-field-value{font-size:14px;font-weight:500;}
.dm-badge{display:inline-block;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:600;}
.dm-badge--pending{background:rgba(234,179,8,.15);color:#EAB308;}
.dm-badge--confirmed{background:rgba(59,130,246,.15);color:#3B82F6;}
.dm-badge--in-progress{background:rgba(168,85,247,.15);color:#A855F7;}
.dm-badge--completed{background:rgba(34,197,94,.15);color:#22C55E;}
.dm-badge--shipped{background:rgba(6,182,212,.15);color:#06B6D4;}
.dm-badge--closed{background:rgba(107,114,128,.15);color:#6B7280;}
.dm-badge--cancelled{background:rgba(239,68,68,.15);color:#EF4444;}
.dm-badge--refunded{background:rgba(239,68,68,.15);color:#EF4444;}
.dm-badge--unpaid{background:rgba(239,68,68,.15);color:#EF4444;}
.dm-badge--partial{background:rgba(234,179,8,.15);color:#EAB308;}
.dm-badge--paid{background:rgba(34,197,94,.15);color:#22C55E;}
.dm-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;}
.dm-btn{padding:8px 16px;border-radius:var(--ia-r-md,8px);font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;border:none;transition:filter .12s;}
.dm-btn--primary{background:var(--ia-accent,#BEF264);color:#000;}
.dm-btn--secondary{background:rgba(255,255,255,.08);color:var(--ia-text,#f0f0f0);}
.dm-btn--danger{background:rgba(239,68,68,.15);color:#EF4444;}
.dm-btn:hover{filter:brightness(.9);}
.dm-btn:disabled{opacity:.4;cursor:not-allowed;}
.dm-table{width:100%;border-collapse:collapse;font-size:13px;}
.dm-table th{font-size:11px;text-transform:uppercase;letter-spacing:.06em;font-weight:500;opacity:.4;padding:6px 0;text-align:left;border-bottom:.5px solid var(--ia-border);}
.dm-table td{padding:8px 0;border-bottom:.5px solid var(--ia-border);vertical-align:middle;}
.dm-table tr:last-child td{border-bottom:none;}
.dm-table .num{text-align:right;font-variant-numeric:tabular-nums;}
.dm-note{padding:10px 0;border-bottom:.5px solid var(--ia-border);}
.dm-note:last-child{border-bottom:none;}
.dm-note-head{display:flex;gap:8px;align-items:center;margin-bottom:4px;}
.dm-note-author{font-size:12px;font-weight:600;}
.dm-note-time{font-size:11px;opacity:.4;}
.dm-note-body{font-size:13px;opacity:.8;line-height:1.5;}
.dm-note-delete{background:none;border:none;color:#EF4444;cursor:pointer;font-size:12px;opacity:.5;padding:0 4px;}
.dm-note-delete:hover{opacity:1;}
.dm-input{width:100%;padding:8px 12px;background:rgba(255,255,255,.04);border:.5px solid var(--ia-border);border-radius:var(--ia-r-md,8px);color:var(--ia-text);font-size:13px;font-family:inherit;}
.dm-input:focus{outline:none;border-color:var(--ia-accent);}
.dm-row{display:flex;gap:8px;align-items:flex-end;}
.dm-total-row{display:flex;justify-content:space-between;padding:8px 0;font-weight:500;font-size:14px;border-top:.5px solid var(--ia-border);margin-top:4px;}
.dm-stat-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:.5px solid var(--ia-border);font-size:13px;}
.dm-stat-row:last-child{border-bottom:none;}
.dm-stat-label{opacity:.5;}
.dm-stat-value{font-weight:500;}
.dm-loading{text-align:center;padding:60px 0;opacity:.4;font-size:14px;}
.dm-err{background:rgba(226,75,74,.12);color:#f39999;border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:12px;display:none;}
.dm-tabs{display:flex;gap:0;border-bottom:.5px solid var(--ia-border);margin-bottom:16px;}
.dm-tab{padding:10px 16px;font-size:13px;font-weight:600;opacity:.4;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-.5px;transition:all .12s;}
.dm-tab:hover{opacity:.7;}
.dm-tab.active{opacity:1;border-color:var(--ia-accent);}
.dm-tab-content{display:none;}
.dm-tab-content.active{display:block;}
@media(max-width:600px){.dm-grid{grid-template-columns:1fr;}}
</style>

<div id="detail-backdrop" onclick="if(event.target===this)closeDetailModal()">
  <div id="detail-card">
    <div class="dm-head">
      <div>
        <div class="dm-title" id="dm-title">Loading...</div>
        <div class="dm-subtitle" id="dm-subtitle"></div>
      </div>
      <button type="button" class="dm-close" onclick="closeDetailModal()">&times;</button>
    </div>
    <div class="dm-body" id="dm-body">
      <div class="dm-loading">Loading details...</div>
    </div>
  </div>
</div>
</div>

<script>
var DM = {
  currentType: null,
  currentId: null,
  csrf: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',

  open: function(type, id, url) {
    this.currentType = type;
    this.currentId = id;
    document.getElementById('detail-modal').style.display = 'block';
    document.getElementById('dm-title').textContent = 'Loading...';
    document.getElementById('dm-subtitle').textContent = '';
    document.getElementById('dm-body').innerHTML = '<div class="dm-loading">Loading details...</div>';
    document.body.style.overflow = 'hidden';

    fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (!data.ok) { DM.showError('Failed to load.'); return; }
        if (type === 'appointment') DM.renderAppointment(data);
        else if (type === 'customer') DM.renderCustomer(data);
      })
      .catch(function(e) { DM.showError('Network error: ' + e.message); });
  },

  showError: function(msg) {
    document.getElementById('dm-body').innerHTML = '<div class="dm-err" style="display:block">' + this.esc(msg) + '</div>';
  },

  // ================================================================
  // APPOINTMENT
  // ================================================================
  renderAppointment: function(data) {
    var a = data.appointment;
    var tr = data.transitions;

    document.getElementById('dm-title').textContent = a.ra_number;
    document.getElementById('dm-subtitle').textContent = a.customer_name + ' · ' + a.appointment_date;

    var h = '';

    // Status + transitions
    h += '<div class="dm-section">';
    h += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">';
    h += '<span class="dm-badge dm-badge--' + a.status.replace('_','-') + '">' + a.status_label + '</span>';
    h += '<span class="dm-badge dm-badge--' + a.payment_status + '">' + a.payment_label + '</span>';
    h += '</div>';
    if (tr.length > 0) {
      h += '<div class="dm-actions">';
      for (var i = 0; i < tr.length; i++) {
        var t = tr[i];
        h += '<button class="dm-btn ' + (t.destructive ? 'dm-btn--danger' : 'dm-btn--secondary') + '" onclick="DM.updateAppt(\'status\',{status:\'' + t.status + '\'})">' + this.esc(t.label) + '</button>';
      }
      h += '</div>';
    }
    h += '</div>';

    // Tabs
    h += '<div class="dm-tabs">';
    h += '<div class="dm-tab active" onclick="DM.switchTab(this,\'dm-tab-details\')">Details</div>';
    h += '<div class="dm-tab" onclick="DM.switchTab(this,\'dm-tab-charges\')">Charges</div>';
    h += '<div class="dm-tab" onclick="DM.switchTab(this,\'dm-tab-notes\')">Notes (' + a.notes.length + ')</div>';
    h += '</div>';

    // Details tab
    h += '<div class="dm-tab-content active" id="dm-tab-details">';
    h += '<div class="dm-grid">';
    h += this.field('Customer', a.customer_name);
    h += this.field('Email', a.customer_email);
    h += this.field('Phone', a.customer_phone || '—');
    h += this.field('Date', a.appointment_date);
    h += this.field('Created', a.created_at);
    h += this.field('Payment', a.payment_label);
    h += '</div>';

    // Line items
    if (a.items.length > 0) {
      h += '<div style="margin-top:16px"><div class="dm-section-label">Services</div>';
      h += '<table class="dm-table"><thead><tr><th>Item</th><th>Tier</th><th class="num">Price</th></tr></thead><tbody>';
      for (var i = 0; i < a.items.length; i++) {
        h += '<tr><td>' + this.esc(a.items[i].name) + '</td><td style="opacity:.6">' + this.esc(a.items[i].tier) + '</td><td class="num">' + a.items[i].price + '</td></tr>';
      }
      h += '</tbody></table></div>';
    }

    // Payment summary
    h += '<div style="margin-top:16px">';
    h += '<div class="dm-stat-row"><span class="dm-stat-label">Subtotal</span><span class="dm-stat-value">' + a.subtotal_display + '</span></div>';
    h += '<div class="dm-stat-row"><span class="dm-stat-label">Total</span><span class="dm-stat-value" style="font-size:16px">' + a.total_display + '</span></div>';
    if (a.paid_cents > 0) h += '<div class="dm-stat-row"><span class="dm-stat-label">Paid</span><span class="dm-stat-value" style="color:#22C55E">' + a.paid_display + '</span></div>';
    h += '</div>';

    // Payment status update
    h += '<div style="margin-top:12px"><div class="dm-row">';
    h += '<select id="dm-payment-select" class="dm-input" style="max-width:160px">';
    ['unpaid','partial','paid','refunded'].forEach(function(ps) {
      h += '<option value="' + ps + '"' + (ps === a.payment_status ? ' selected' : '') + '>' + ps.charAt(0).toUpperCase() + ps.slice(1) + '</option>';
    });
    h += '</select>';
    h += '<button class="dm-btn dm-btn--secondary" onclick="DM.updateAppt(\'payment\',{payment_status:document.getElementById(\'dm-payment-select\').value})">Update Payment</button>';
    h += '</div></div>';

    if (a.staff_notes) {
      h += '<div style="margin-top:16px"><div class="dm-section-label">Staff Notes</div>';
      h += '<div style="font-size:13px;opacity:.7;white-space:pre-line">' + this.esc(a.staff_notes) + '</div></div>';
    }
    h += '</div>';

    // Charges tab
    h += '<div class="dm-tab-content" id="dm-tab-charges">';
    h += '<div id="dm-charges-list">';
    if (a.charges.length === 0) {
      h += '<p style="font-size:13px;opacity:.4">No additional charges.</p>';
    } else {
      for (var i = 0; i < a.charges.length; i++) {
        var c = a.charges[i];
        h += '<div class="dm-stat-row"><span>' + this.esc(c.description) + ' <span style="font-size:11px;opacity:.4">' + c.date + '</span></span><span class="dm-stat-value">' + c.amount + '</span></div>';
      }
    }
    h += '</div>';
    h += '<div style="margin-top:12px;padding-top:12px;border-top:.5px solid var(--ia-border)">';
    h += '<div class="dm-section-label">Add Charge</div>';
    h += '<div class="dm-row">';
    h += '<input type="text" id="dm-charge-desc" class="dm-input" placeholder="Description" style="flex:2">';
    h += '<input type="number" id="dm-charge-amt" class="dm-input" placeholder="$0.00" step="0.01" min="0.01" style="flex:1">';
    h += '<button class="dm-btn dm-btn--primary" onclick="DM.addCharge()">Add</button>';
    h += '</div></div>';
    h += '</div>';

    // Notes tab
    h += '<div class="dm-tab-content" id="dm-tab-notes">';
    h += '<div style="margin-bottom:12px"><div class="dm-row">';
    h += '<input type="text" id="dm-note-input" class="dm-input" placeholder="Add a note..." style="flex:1" maxlength="500">';
    h += '<button class="dm-btn dm-btn--primary" onclick="DM.addApptNote()">Add</button>';
    h += '</div></div>';
    h += '<div id="dm-notes-list">';
    if (a.notes.length === 0) {
      h += '<p style="font-size:13px;opacity:.4">No notes yet.</p>';
    } else {
      for (var i = 0; i < a.notes.length; i++) {
        var n = a.notes[i];
        h += '<div class="dm-note" data-note-id="' + n.id + '">';
        h += '<div class="dm-note-head"><span class="dm-note-author">' + this.esc(n.author) + '</span><span class="dm-note-time">' + n.created_at + '</span>';
        if (n.type !== 'system') h += '<button class="dm-note-delete" onclick="DM.deleteApptNote(\'' + n.id + '\')">&times;</button>';
        h += '</div>';
        h += '<div class="dm-note-body">' + this.esc(n.note) + '</div></div>';
      }
    }
    h += '</div></div>';

    document.getElementById('dm-body').innerHTML = h;
  },

  updateAppt: function(op, data) {
    var url = this.buildUrl('appointments', this.currentId);
    data.op = op;
    data._method = 'PATCH';
    this.post(url, data, function() { DM.reload(); });
  },

  addCharge: function() {
    var desc = document.getElementById('dm-charge-desc').value.trim();
    var amt = parseFloat(document.getElementById('dm-charge-amt').value || 0);
    if (!desc || amt <= 0) return;
    this.updateAppt('add_charge', { description: desc, amount_cents: Math.round(amt * 100) });
  },

  addApptNote: function() {
    var input = document.getElementById('dm-note-input');
    var note = input.value.trim();
    if (!note) return;
    this.updateAppt('add_note', { note: note });
  },

  deleteApptNote: function(noteId) {
    if (!confirm('Delete this note?')) return;
    this.updateAppt('delete_note', { note_id: noteId });
  },

  // ================================================================
  // CUSTOMER
  // ================================================================
  renderCustomer: function(data) {
    var c = data.customer;
    var appts = data.appointments;
    var notes = data.notes;

    document.getElementById('dm-title').textContent = c.name;
    document.getElementById('dm-subtitle').textContent = c.email;

    var h = '';

    // Stats
    h += '<div class="dm-section"><div class="dm-grid">';
    h += this.field('Total Spend', c.total_spend);
    h += this.field('Appointments', c.total_appts);
    h += this.field('Last Service', c.last_service || 'Never');
    h += this.field('Customer Since', c.created_at);
    h += '</div></div>';

    // Tabs
    h += '<div class="dm-tabs">';
    h += '<div class="dm-tab active" onclick="DM.switchTab(this,\'dm-tab-info\')">Info</div>';
    h += '<div class="dm-tab" onclick="DM.switchTab(this,\'dm-tab-history\')">History (' + appts.length + ')</div>';
    h += '<div class="dm-tab" onclick="DM.switchTab(this,\'dm-tab-custnotes\')">Notes (' + notes.length + ')</div>';
    h += '</div>';

    // Info tab
    h += '<div class="dm-tab-content active" id="dm-tab-info">';
    h += '<div class="dm-grid">';
    h += this.field('Name', c.name);
    h += this.field('Email', c.email);
    h += this.field('Phone', c.phone || '—');
    h += this.field('Address', [c.address_line1, c.city, c.state, c.postcode].filter(Boolean).join(', ') || '—');
    h += '</div></div>';

    // History tab
    h += '<div class="dm-tab-content" id="dm-tab-history">';
    if (appts.length === 0) {
      h += '<p style="font-size:13px;opacity:.4">No appointments yet.</p>';
    } else {
      h += '<table class="dm-table"><thead><tr><th>ITO #</th><th>Date</th><th>Status</th><th class="num">Total</th></tr></thead><tbody>';
      for (var i = 0; i < appts.length; i++) {
        var a = appts[i];
        h += '<tr style="cursor:pointer" onclick="DM.openAppt(\'' + a.id + '\')">';
        h += '<td style="font-weight:500">' + this.esc(a.ito) + '</td>';
        h += '<td style="opacity:.6">' + a.date + '</td>';
        h += '<td><span class="dm-badge dm-badge--' + a.status_key.replace('_','-') + '">' + a.status + '</span></td>';
        h += '<td class="num">' + a.total + '</td>';
        h += '</tr>';
      }
      h += '</tbody></table>';
    }
    h += '</div>';

    // Notes tab
    h += '<div class="dm-tab-content" id="dm-tab-custnotes">';
    h += '<div style="margin-bottom:12px"><div class="dm-row">';
    h += '<input type="text" id="dm-custnote-input" class="dm-input" placeholder="Add a note..." style="flex:1" maxlength="200">';
    h += '<button class="dm-btn dm-btn--primary" onclick="DM.addCustNote()">Add</button>';
    h += '</div></div>';
    h += '<div id="dm-custnotes-list">';
    if (notes.length === 0) {
      h += '<p style="font-size:13px;opacity:.4">No notes yet.</p>';
    } else {
      for (var i = 0; i < notes.length; i++) {
        var n = notes[i];
        h += '<div class="dm-note" data-note-id="' + n.id + '">';
        h += '<div class="dm-note-head"><span class="dm-note-author">' + this.esc(n.author) + '</span><span class="dm-note-time">' + n.created_at + '</span>';
        h += '<button class="dm-note-delete" onclick="DM.deleteCustNote(\'' + n.id + '\')">&times;</button>';
        h += '</div>';
        h += '<div class="dm-note-body">' + this.esc(n.note) + '</div></div>';
      }
    }
    h += '</div></div>';

    document.getElementById('dm-body').innerHTML = h;
  },

  addCustNote: function() {
    var input = document.getElementById('dm-custnote-input');
    var note = input.value.trim();
    if (!note) return;
    var url = this.buildUrl('customers', this.currentId);
    this.post(url, { op: 'add_note', _method: 'PATCH', note: note }, function() { DM.reload(); });
  },

  deleteCustNote: function(noteId) {
    if (!confirm('Delete this note?')) return;
    var url = this.buildUrl('customers', this.currentId);
    this.post(url, { op: 'delete_note', _method: 'PATCH', note_id: noteId }, function() { DM.reload(); });
  },

  openAppt: function(id) {
    var url = this.buildUrl('appointments', id);
    this.open('appointment', id, url);
  },

  // ================================================================
  // HELPERS
  // ================================================================
  switchTab: function(el, tabId) {
    var parent = el.parentElement;
    parent.querySelectorAll('.dm-tab').forEach(function(t) { t.classList.remove('active'); });
    el.classList.add('active');
    var body = parent.parentElement;
    body.querySelectorAll('.dm-tab-content').forEach(function(c) { c.classList.remove('active'); });
    document.getElementById(tabId).classList.add('active');
  },

  field: function(label, value) {
    return '<div><div class="dm-field-label">' + label + '</div><div class="dm-field-value">' + this.esc(value || '—') + '</div></div>';
  },

  buildUrl: function(resource, id) {
    return '/admin/' + resource + '/' + id;
  },

  reload: function() {
    var url = this.buildUrl(this.currentType === 'appointment' ? 'appointments' : 'customers', this.currentId);
    this.open(this.currentType, this.currentId, url);
  },

  post: function(url, data, onSuccess) {
    var fd = new FormData();
    fd.append('_token', this.csrf);
    for (var key in data) fd.append(key, data[key]);
    fetch(url, { method: 'POST', body: fd, headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(resp) {
        if (resp.ok) { if (onSuccess) onSuccess(resp); }
        else { alert(resp.message || 'Error'); }
      })
      .catch(function(e) { alert('Network error: ' + e.message); });
  },

  esc: function(str) {
    return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
};

function openDetailModal(type, id) {
  var url = '/admin/' + (type === 'appointment' ? 'appointments' : 'customers') + '/' + id;
  DM.open(type, id, url);
}

function closeDetailModal() {
  document.getElementById('detail-modal').style.display = 'none';
  document.body.style.overflow = '';
}
</script>
