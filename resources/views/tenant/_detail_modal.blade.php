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
  border-radius:var(--ia-r-lg,16px);width:100%;max-width:760px;
  animation:dm-pop .25s cubic-bezier(.2,1.1,.3,1);
}
@keyframes dm-pop{from{transform:scale(.95) translateY(10px);opacity:0}to{transform:scale(1) translateY(0);opacity:1}}
@keyframes dm-success{0%{transform:scale(1)}50%{transform:scale(1.05)}100%{transform:scale(1)}}
.dm-head{padding:24px 28px 0;display:flex;justify-content:space-between;align-items:flex-start;}
.dm-head-left{display:flex;gap:14px;align-items:flex-start;}
.dm-avatar{width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700;flex-shrink:0;background:var(--ia-accent-soft);color:var(--ia-accent);}
.dm-head-info{flex:1;}
.dm-title{font-size:20px;font-weight:700;display:flex;align-items:center;gap:10px;flex-wrap:wrap;word-break:break-all;}
@media (max-width:600px){
  .dm-head{padding:18px 18px 0;flex-wrap:wrap;gap:10px;}
  .dm-head-left{gap:10px;flex:1;min-width:0;}
  .dm-avatar{width:36px;height:36px;font-size:13px;}
  .dm-title{font-size:15px;font-weight:600;line-height:1.25;}
  .dm-title-ito{word-break:break-all;}
  .dm-subtitle{font-size:12px;}
  .dm-age{font-size:10px;}
  #dm-full-page-link{font-size:11px !important;padding:5px 10px !important;margin-right:4px !important;}
  .dm-body{padding:14px 18px 20px;}
  .dm-grid{grid-template-columns:1fr;gap:8px;}
}
.dm-title-ito{cursor:pointer;position:relative;}
.dm-title-ito:hover{opacity:.7;}
.dm-copied{position:absolute;top:-24px;left:50%;transform:translateX(-50%);background:#22C55E;color:#fff;font-size:10px;padding:2px 8px;border-radius:4px;white-space:nowrap;animation:dm-copied-fade 1.5s forwards;pointer-events:none;}
@keyframes dm-copied-fade{0%{opacity:1;transform:translateX(-50%) translateY(0)}70%{opacity:1}100%{opacity:0;transform:translateX(-50%) translateY(-8px)}}
.dm-subtitle{font-size:13px;opacity:.5;margin-top:4px;}
.dm-age{font-size:11px;opacity:.35;margin-top:2px;}
.dm-close{background:none;border:none;color:inherit;font-size:24px;cursor:pointer;opacity:.5;padding:4px 8px;line-height:1;}
.dm-close:hover{opacity:1}
.dm-body{padding:20px 28px 24px;}
.dm-section{margin-bottom:20px;}
.dm-section:last-child{margin-bottom:0;}
.dm-section-label{font-size:11px;text-transform:uppercase;letter-spacing:.07em;font-weight:500;opacity:.45;margin-bottom:10px;}
.dm-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.dm-field-label{font-size:11px;opacity:.45;margin-bottom:2px;}
.dm-field-value{font-size:14px;font-weight:500;}
.dm-status-bar{display:flex;align-items:center;gap:10px;margin-bottom:16px;padding:14px 18px;border-radius:var(--ia-r-lg);background:rgba(255,255,255,.03);border:.5px solid var(--ia-border);}
.dm-status-badge{display:inline-flex;align-items:center;gap:6px;padding:5px 14px;border-radius:8px;font-size:13px;font-weight:700;letter-spacing:.02em;}
.dm-status-badge .dm-status-dot{width:8px;height:8px;border-radius:50%;}
.dm-badge--pending .dm-status-dot{background:#EAB308;}
.dm-badge--confirmed .dm-status-dot{background:#3B82F6;}
.dm-badge--in-progress .dm-status-dot{background:#A855F7;}
.dm-badge--completed .dm-status-dot{background:#22C55E;}
.dm-badge--shipped .dm-status-dot{background:#06B6D4;}
.dm-badge--closed .dm-status-dot{background:#6B7280;}
.dm-badge--cancelled .dm-status-dot{background:#EF4444;}
.dm-badge--refunded .dm-status-dot{background:#EF4444;}
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
.dm-progress{display:flex;gap:3px;flex:1;margin-left:12px;}
.dm-progress-step{height:4px;border-radius:2px;flex:1;background:rgba(255,255,255,.08);}
.dm-progress-step.done{background:var(--ia-accent);}
.dm-progress-step.current{background:var(--ia-accent);opacity:.5;}
.dm-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;}
.dm-btn{padding:8px 16px;border-radius:var(--ia-r-md,8px);font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;border:none;transition:all .12s;}
.dm-btn--primary{background:var(--ia-accent,#BEF264);color:#000;}
.dm-btn--secondary{background:rgba(255,255,255,.08);color:var(--ia-text,#f0f0f0);}
.dm-btn--danger{background:rgba(239,68,68,.15);color:#EF4444;}
.dm-btn--ghost{background:none;color:var(--ia-text);opacity:.5;}
.dm-btn--ghost:hover{opacity:1;}
.dm-btn:hover{filter:brightness(.9);}
.dm-btn:disabled{opacity:.4;cursor:not-allowed;}
.dm-btn.dm-success-flash{animation:dm-success .3s;}
.dm-table{width:100%;border-collapse:collapse;font-size:13px;}
.dm-table th{font-size:11px;text-transform:uppercase;letter-spacing:.06em;font-weight:500;opacity:.4;padding:6px 0;text-align:left;border-bottom:.5px solid var(--ia-border);}
.dm-table td{padding:8px 0;border-bottom:.5px solid var(--ia-border);vertical-align:middle;}
.dm-table tr:last-child td{border-bottom:none;}
.dm-table .num{text-align:right;font-variant-numeric:tabular-nums;}
.dm-table tr.dm-clickable{cursor:pointer;}
.dm-table tr.dm-clickable:hover td{opacity:.7;}
.dm-timeline{position:relative;padding-left:24px;}
.dm-timeline::before{content:'';position:absolute;left:7px;top:8px;bottom:8px;width:1.5px;background:var(--ia-border);}
.dm-tl-item{position:relative;padding:8px 0;padding-left:16px;}
.dm-tl-dot{position:absolute;left:-20px;top:12px;width:10px;height:10px;border-radius:50%;border:2px solid var(--ia-border);background:var(--ia-surface);}
.dm-tl-item.system .dm-tl-dot{background:var(--ia-accent);border-color:var(--ia-accent);}
.dm-tl-item.staff .dm-tl-dot{background:#3B82F6;border-color:#3B82F6;}
.dm-tl-head{display:flex;gap:8px;align-items:center;margin-bottom:2px;}
.dm-tl-author{font-size:12px;font-weight:600;}
.dm-tl-time{font-size:11px;opacity:.35;}
.dm-tl-body{font-size:13px;opacity:.75;line-height:1.5;}
.dm-tl-delete{background:none;border:none;color:#EF4444;cursor:pointer;font-size:11px;opacity:.4;padding:0 4px;margin-left:4px;}
.dm-tl-delete:hover{opacity:1;}
.dm-input{width:100%;padding:8px 12px;background:rgba(255,255,255,.04);border:.5px solid var(--ia-border);border-radius:var(--ia-r-md,8px);color:var(--ia-text);font-size:13px;font-family:inherit;}
.dm-input:focus{outline:none;border-color:var(--ia-accent);}
.dm-row{display:flex;gap:8px;align-items:flex-end;}
.dm-stat-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:.5px solid var(--ia-border);font-size:13px;}
.dm-stat-row:last-child{border-bottom:none;}
.dm-stat-label{opacity:.5;}
.dm-stat-value{font-weight:500;}
.dm-revenue-total{display:flex;justify-content:space-between;padding:10px 0;font-size:16px;font-weight:700;border-top:1.5px solid var(--ia-border);margin-top:4px;}
.dm-slot-bar{display:flex;gap:3px;margin-top:6px;}
.dm-slot{width:16px;height:6px;border-radius:3px;background:rgba(255,255,255,.08);}
.dm-slot.filled{background:var(--ia-accent);}
.dm-editable{cursor:pointer;border-bottom:1px dashed rgba(255,255,255,.2);padding-bottom:1px;}
.dm-editable:hover{border-color:var(--ia-accent);}
.dm-edit-inline{display:none;gap:6px;align-items:center;}
.dm-edit-inline.open{display:flex;}
.dm-loading{text-align:center;padding:60px 0;opacity:.4;font-size:14px;}
.dm-err{background:rgba(226,75,74,.12);color:#f39999;border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:12px;display:none;}
.dm-tabs{display:flex;gap:0;border-bottom:.5px solid var(--ia-border);margin-bottom:16px;}
.dm-tab{padding:10px 16px;font-size:13px;font-weight:600;opacity:.4;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-.5px;transition:all .12s;}
.dm-tab:hover{opacity:.7;}
.dm-tab.active{opacity:1;border-color:var(--ia-accent);}
.dm-tab-content{display:none;}
.dm-tab-content.active{display:block;}
.dm-cust-link{color:var(--ia-accent);cursor:pointer;font-size:12px;margin-top:4px;display:inline-block;}
.dm-cust-link:hover{text-decoration:underline;}
@media(max-width:600px){.dm-grid{grid-template-columns:1fr;}}
</style>

<div id="detail-backdrop" onclick="if(event.target===this)closeDetailModal()">
  <div id="detail-card">
    <div class="dm-head">
      <div class="dm-head-left">
        <div class="dm-avatar" id="dm-avatar">??</div>
        <div class="dm-head-info">
          <div class="dm-title" id="dm-title">Loading...</div>
          <div class="dm-subtitle" id="dm-subtitle"></div>
          <div class="dm-age" id="dm-age"></div>
        </div>
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
  activeTab: null,
  csrf: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
  statusOrder: ['pending','confirmed','in_progress','completed','shipped','closed'],

  open: function(type, id) {
    this.currentType = type;
    this.currentId = id;
    document.getElementById('detail-modal').style.display = 'block';
    document.getElementById('dm-title').textContent = 'Loading...';
    document.getElementById('dm-subtitle').textContent = '';
    document.getElementById('dm-age').textContent = '';
    document.getElementById('dm-avatar').textContent = '??';
    document.getElementById('dm-body').innerHTML = '<div class="dm-loading">Loading details...</div>';
    document.body.style.overflow = 'hidden';

    var resource = type === 'appointment' ? 'appointments' : 'customers';
    var url = '/admin/' + resource + '?detail=' + encodeURIComponent(id);

    fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function(r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function(data) {
        if (!data.ok) { DM.showError('Failed to load.'); return; }
        if (type === 'appointment') DM.renderAppointment(data);
        else if (type === 'customer') DM.renderCustomer(data);
      })
      .catch(function(e) { DM.showError('Failed to load: ' + e.message); });
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

    // Avatar
    var initials = (a.customer_name || '??').split(' ').map(function(w){return w[0]}).join('').substring(0,2).toUpperCase();
    document.getElementById('dm-avatar').textContent = initials;

    // Title with copyable ITO
    document.getElementById('dm-title').innerHTML = '<span class="dm-title-ito" onclick="DM.copyITO(\'' + this.esc(a.ra_number) + '\',this)" title="Click to copy">' + this.esc(a.ra_number) + '</span>';
    document.getElementById('dm-subtitle').textContent = a.customer_name + ' \u00b7 ' + a.appointment_date;
    document.getElementById('dm-age').textContent = 'Created ' + a.created_at;

    // "View full page" escape hatch — links to the show page for any
    // editing/details the modal doesn't yet support (work order edit, etc.).
    var headRight = document.querySelector('.dm-head .dm-head-right') || document.querySelector('.dm-head');
    if (headRight) {
      var existing = document.getElementById('dm-full-page-link');
      if (existing) existing.remove();
      var link = document.createElement('a');
      link.id = 'dm-full-page-link';
      link.href = '/admin/appointments/' + a.id;
      link.innerHTML = 'View full page <span style="opacity:.7">\u2192</span>';
      link.style.cssText = [
        'font-size:12px',
        'font-weight:500',
        'padding:6px 12px',
        'margin-right:8px',
        'border-radius:var(--ia-r-md, 6px)',
        'border:.5px solid var(--ia-border, rgba(255,255,255,.12))',
        'background:rgba(255,255,255,.04)',
        'color:inherit',
        'text-decoration:none',
        'align-self:center',
        'transition:background .12s, border-color .12s',
        'white-space:nowrap',
      ].join(';');
      link.onmouseover = function() {
        this.style.background = 'rgba(255,255,255,.08)';
        this.style.borderColor = 'var(--ia-accent, #BEF264)';
      };
      link.onmouseout = function() {
        this.style.background = 'rgba(255,255,255,.04)';
        this.style.borderColor = 'var(--ia-border, rgba(255,255,255,.12))';
      };
      var closeBtn = document.querySelector('.dm-close');
      if (closeBtn) closeBtn.parentNode.insertBefore(link, closeBtn);
    }

    var h = '';

    // Status bar with progress
    var statusIdx = this.statusOrder.indexOf(a.status);
    var isCancelled = a.status === 'cancelled' || a.status === 'refunded';
    h += '<div class="dm-status-bar">';
    h += '<span class="dm-status-badge dm-badge--' + a.status.replace('_','-') + '"><span class="dm-status-dot"></span>' + a.status_label + '</span>';
    h += '<span class="dm-badge dm-badge--' + a.payment_status + '">' + a.payment_label + '</span>';
    if (!isCancelled) {
      h += '<div class="dm-progress">';
      for (var i = 0; i < this.statusOrder.length; i++) {
        var cls = i < statusIdx ? 'done' : (i === statusIdx ? 'current' : '');
        h += '<div class="dm-progress-step ' + cls + '"></div>';
      }
      h += '</div>';
    }
    h += '</div>';

    // Transition buttons
    if (tr.length > 0) {
      h += '<div class="dm-actions" style="margin-bottom:16px">';
      for (var i = 0; i < tr.length; i++) {
        var t = tr[i];
        var confirmAttr = t.destructive ? ' onclick="if(!confirm(\'Are you sure you want to ' + t.label.toLowerCase() + ' this appointment?\'))return;DM.updateAppt(\'status\',{status:\'' + t.status + '\'})"' : ' onclick="DM.updateAppt(\'status\',{status:\'' + t.status + '\'})"';
        h += '<button class="dm-btn ' + (t.destructive ? 'dm-btn--danger' : 'dm-btn--secondary') + '"' + confirmAttr + '>' + this.esc(t.label) + '</button>';
      }
      h += '</div>';
    }

    // Tabs
    var savedTab = this.activeTab || 'dm-tab-details';
    h += '<div class="dm-tabs">';
    h += '<div class="dm-tab ' + (savedTab === 'dm-tab-details' ? 'active' : '') + '" onclick="DM.switchTab(this,\'dm-tab-details\')">Details</div>';
    h += '<div class="dm-tab ' + (savedTab === 'dm-tab-charges' ? 'active' : '') + '" onclick="DM.switchTab(this,\'dm-tab-charges\')">Charges (' + a.charges.length + ')</div>';
    h += '<div class="dm-tab ' + (savedTab === 'dm-tab-notes' ? 'active' : '') + '" onclick="DM.switchTab(this,\'dm-tab-notes\')">Timeline (' + a.notes.length + ')</div>';
    h += '</div>';

    // Details tab
    h += '<div class="dm-tab-content ' + (savedTab === 'dm-tab-details' ? 'active' : '') + '" id="dm-tab-details">';
    h += '<div class="dm-grid">';
    h += this.field('Customer', a.customer_name + (a.customer_id ? ' <span class="dm-cust-link" onclick="DM.openCust(\'' + a.customer_id + '\')">View profile \u2192</span>' : ''));
    h += this.field('Email', a.customer_email);
    h += this.field('Phone', a.customer_phone || '\u2014');

    // Resource field (only when assigned — older drop-off appointments may have no resource)
    if (a.resource) {
      var rDot = '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' + this.esc(a.resource.color_hex || '#888') + ';margin-right:6px;vertical-align:middle"></span>';
      var rText = rDot + this.esc(a.resource.name) + (a.resource.subtitle ? ' <span style="opacity:.5">\u00b7 ' + this.esc(a.resource.subtitle) + '</span>' : '');
      h += this.field('Resource', rText);
    }

    // Time field (only when set — drop-off appointments have date but no time)
    if (a.appointment_time) {
      var timeText = a.appointment_time + (a.appointment_end_time ? ' <span style="opacity:.5">\u00b7 ' + a.appointment_end_time + '</span>' : '');
      if (a.total_duration_minutes) timeText += ' <span style="opacity:.4;font-size:11px">(' + a.total_duration_minutes + ' min)</span>';
      h += this.field('Time', timeText);
    }

    // Editable date
    h += '<div><div class="dm-field-label">Date</div>';
    h += '<div class="dm-field-value"><span class="dm-editable" id="dm-date-display" onclick="DM.toggleDateEdit()">' + a.appointment_date + '</span></div>';
    h += '<div class="dm-edit-inline" id="dm-date-edit"><input type="date" class="dm-input" id="dm-date-input" value="' + a.appointment_date_raw + '" style="width:auto"><button class="dm-btn dm-btn--primary" style="padding:6px 12px" onclick="DM.saveDate()">Save</button><button class="dm-btn dm-btn--ghost" style="padding:6px 8px" onclick="DM.toggleDateEdit()">Cancel</button></div>';
    h += '</div>';

    h += this.field('Created', a.created_at);
    h += this.field('Payment', a.payment_label);
    h += '</div>';

    // Line items
    if (a.items.length > 0) {
      h += '<div style="margin-top:16px"><div class="dm-section-label">Services</div>';
      h += '<table class="dm-table"><thead><tr><th>Item</th><th>Duration</th><th class="num">Price</th></tr></thead><tbody>';
      for (var i = 0; i < a.items.length; i++) {
        var it = a.items[i];
        var durText = it.duration + ' min';
        var bookendText = '';
        if ((it.prep_min || 0) > 0 || (it.cleanup_min || 0) > 0) {
          var parts = [];
          if (it.prep_min > 0) parts.push(it.prep_min + 'm prep');
          if (it.cleanup_min > 0) parts.push(it.cleanup_min + 'm clean');
          bookendText = ' <span style="opacity:.4;font-size:11px">+ ' + parts.join(', ') + '</span>';
        }
        h += '<tr><td>' + this.esc(it.name) + '</td><td style="opacity:.6">' + durText + bookendText + '</td><td class="num">' + it.price + '</td></tr>';
      }
      h += '</tbody></table></div>';
    }

    // Revenue breakdown
    h += '<div style="margin-top:16px">';
    h += '<div class="dm-stat-row"><span class="dm-stat-label">Subtotal</span><span class="dm-stat-value">' + a.subtotal_display + '</span></div>';
    if (a.charges.length > 0) {
      var chargeTotal = a.charges.reduce(function(sum, c) { return sum + (parseInt(c.amount.replace(/[^0-9]/g,'')) || 0); }, 0);
      h += '<div class="dm-stat-row"><span class="dm-stat-label">Charges</span><span class="dm-stat-value">+' + a.charges.length + ' items</span></div>';
    }
    h += '<div class="dm-revenue-total"><span>Total</span><span>' + a.total_display + '</span></div>';
    if (a.paid_cents > 0) {
      h += '<div class="dm-stat-row"><span class="dm-stat-label">Paid</span><span class="dm-stat-value" style="color:#22C55E">' + a.paid_display + '</span></div>';
      if (a.total_cents > a.paid_cents) {
        var remaining = a.total_cents - a.paid_cents;
        h += '<div class="dm-stat-row"><span class="dm-stat-label" style="color:#EF4444">Remaining</span><span class="dm-stat-value" style="color:#EF4444">$' + (remaining/100).toFixed(2) + '</span></div>';
      }
    }
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

    // Slot weight
    h += '<div style="margin-top:16px"><div class="dm-section-label">Capacity</div>';
    h += '<div class="dm-slot-bar">';
    var sw = a.slot_weight || 1;
    for (var i = 0; i < 4; i++) {
      h += '<div class="dm-slot ' + (i < sw ? 'filled' : '') + '"></div>';
    }
    h += '</div>';
    h += '<div style="font-size:11px;opacity:.35;margin-top:4px">' + sw + ' slot' + (sw > 1 ? 's' : '') + ' used</div>';
    h += '</div>';

    // Work order responses — identifier promoted at top, then other fields.
    if (a.work_order_responses && a.work_order_responses.length > 0) {
      var identifier = null;
      var other = [];
      for (var wi = 0; wi < a.work_order_responses.length; wi++) {
        var wr = a.work_order_responses[wi];
        if (wr.is_identifier && !identifier) identifier = wr;
        else other.push(wr);
      }
      h += '<div style="margin-top:16px"><div class="dm-section-label">Work Order</div>';
      if (identifier) {
        h += '<div style="margin-bottom:10px;padding:10px 14px;background:rgba(255,255,255,.03);border:.5px solid var(--ia-border);border-radius:var(--ia-r-md)">';
        h += '<div style="font-size:11px;opacity:.45;margin-bottom:3px">' + this.esc(identifier.field_label) + '</div>';
        h += '<div style="font-size:15px;font-weight:500;font-family:var(--ia-font-mono,monospace)">' + this.esc(identifier.value || '\u2014') + '</div>';
        h += '</div>';
      }
      if (other.length > 0) {
        h += '<div class="dm-grid">';
        for (var oi = 0; oi < other.length; oi++) {
          h += this.field(other[oi].field_label, this.esc(other[oi].value || '\u2014'));
        }
        h += '</div>';
      }
      h += '</div>';
    }

    // Customer-facing form responses (custom intake answers).
    if (a.form_responses && a.form_responses.length > 0) {
      h += '<div style="margin-top:16px"><div class="dm-section-label">Customer Details</div>';
      h += '<div class="dm-grid">';
      for (var fi = 0; fi < a.form_responses.length; fi++) {
        h += this.field(a.form_responses[fi].field_label, this.esc(a.form_responses[fi].value || '\u2014'));
      }
      h += '</div></div>';
    }

    if (a.staff_notes) {
      h += '<div style="margin-top:16px"><div class="dm-section-label">Staff Notes</div>';
      h += '<div style="font-size:13px;opacity:.7;white-space:pre-line">' + this.esc(a.staff_notes) + '</div></div>';
    }
    h += '</div>';

    // Charges tab
    h += '<div class="dm-tab-content ' + (savedTab === 'dm-tab-charges' ? 'active' : '') + '" id="dm-tab-charges">';
    h += '<div id="dm-charges-list">';
    if (a.charges.length === 0) {
      h += '<p style="font-size:13px;opacity:.4">No additional charges.</p>';
    } else {
      for (var i = 0; i < a.charges.length; i++) {
        var c = a.charges[i];
        h += '<div class="dm-stat-row"><span>' + this.esc(c.description) + ' <span style="font-size:11px;opacity:.4">' + c.date + ' \u00b7 ' + (c.is_paid ? 'Paid' : 'Unpaid') + '</span></span><span class="dm-stat-value">' + c.amount + '</span></div>';
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

    // Timeline tab (notes + status changes)
    h += '<div class="dm-tab-content ' + (savedTab === 'dm-tab-notes' ? 'active' : '') + '" id="dm-tab-notes">';
    h += '<div style="margin-bottom:16px"><div class="dm-row">';
    h += '<input type="text" id="dm-note-input" class="dm-input" placeholder="Add a note..." style="flex:1" maxlength="500" onkeydown="if(event.key===\'Enter\'){DM.addApptNote();event.preventDefault()}">';
    h += '<button class="dm-btn dm-btn--primary" onclick="DM.addApptNote()">Add</button>';
    h += '</div></div>';
    h += '<div class="dm-timeline" id="dm-notes-list">';
    if (a.notes.length === 0) {
      h += '<p style="font-size:13px;opacity:.4;padding-left:0">No activity yet.</p>';
    } else {
      for (var i = 0; i < a.notes.length; i++) {
        var n = a.notes[i];
        h += '<div class="dm-tl-item ' + n.type + '" data-note-id="' + n.id + '">';
        h += '<div class="dm-tl-dot"></div>';
        h += '<div class="dm-tl-head"><span class="dm-tl-author">' + this.esc(n.author) + '</span><span class="dm-tl-time">' + n.created_at + '</span>';
        if (n.type !== 'system') h += '<button class="dm-tl-delete" onclick="DM.deleteApptNote(\'' + n.id + '\')" title="Delete">&times;</button>';
        h += '</div>';
        h += '<div class="dm-tl-body">' + this.esc(n.note) + '</div></div>';
      }
    }
    h += '</div></div>';

    document.getElementById('dm-body').innerHTML = h;
  },

  copyITO: function(ito, el) {
    navigator.clipboard.writeText(ito).then(function() {
      var tip = document.createElement('span');
      tip.className = 'dm-copied';
      tip.textContent = 'Copied!';
      el.appendChild(tip);
      setTimeout(function() { tip.remove(); }, 1500);
    });
  },

  toggleDateEdit: function() {
    var display = document.getElementById('dm-date-display');
    var edit = document.getElementById('dm-date-edit');
    if (edit.classList.contains('open')) {
      edit.classList.remove('open');
      display.style.display = '';
    } else {
      edit.classList.add('open');
      display.style.display = 'none';
    }
  },

  saveDate: function() {
    var newDate = document.getElementById('dm-date-input').value;
    if (!newDate) return;
    this.updateAppt('date', { appointment_date: newDate });
  },

  updateAppt: function(op, data) {
    var url = '/admin/appointments?update=' + encodeURIComponent(this.currentId);
    data.op = op;
    this.post(url, data, function() { DM.reload(); });
  },

  addCharge: function() {
    var desc = document.getElementById('dm-charge-desc').value.trim();
    var amt = parseFloat(document.getElementById('dm-charge-amt').value || 0);
    if (!desc || amt <= 0) return;
    this.activeTab = 'dm-tab-charges';
    this.updateAppt('add_charge', { description: desc, amount_cents: Math.round(amt * 100) });
  },

  addApptNote: function() {
    var input = document.getElementById('dm-note-input');
    var note = input.value.trim();
    if (!note) return;
    this.activeTab = 'dm-tab-notes';
    this.updateAppt('add_note', { note: note });
  },

  deleteApptNote: function(noteId) {
    if (!confirm('Delete this note?')) return;
    this.activeTab = 'dm-tab-notes';
    this.updateAppt('delete_note', { note_id: noteId });
  },

  // ================================================================
  // CUSTOMER
  // ================================================================
  renderCustomer: function(data) {
    var c = data.customer;
    var appts = data.appointments;
    var notes = data.notes;

    var initials = ((c.first_name || '?')[0] + (c.last_name || '?')[0]).toUpperCase();
    document.getElementById('dm-avatar').textContent = initials;
    document.getElementById('dm-title').textContent = c.name;
    document.getElementById('dm-subtitle').textContent = c.email;
    document.getElementById('dm-age').textContent = 'Customer since ' + c.created_at;

    var h = '';

    // Stats
    h += '<div class="dm-section"><div class="dm-grid">';
    h += this.field('Total Spend', c.total_spend);
    h += this.field('Appointments', c.total_appts);
    h += this.field('Last Service', c.last_service || 'Never');
    h += this.field('Customer Since', c.created_at);
    h += '</div></div>';

    var savedTab = this.activeTab || 'dm-tab-info';
    h += '<div class="dm-tabs">';
    h += '<div class="dm-tab ' + (savedTab === 'dm-tab-info' ? 'active' : '') + '" onclick="DM.switchTab(this,\'dm-tab-info\')">Info</div>';
    h += '<div class="dm-tab ' + (savedTab === 'dm-tab-history' ? 'active' : '') + '" onclick="DM.switchTab(this,\'dm-tab-history\')">History (' + appts.length + ')</div>';
    h += '<div class="dm-tab ' + (savedTab === 'dm-tab-custnotes' ? 'active' : '') + '" onclick="DM.switchTab(this,\'dm-tab-custnotes\')">Notes (' + notes.length + ')</div>';
    h += '</div>';

    // Info tab
    h += '<div class="dm-tab-content ' + (savedTab === 'dm-tab-info' ? 'active' : '') + '" id="dm-tab-info">';
    h += '<div class="dm-grid">';
    h += this.field('Name', c.name);
    h += this.field('Email', c.email);
    h += this.field('Phone', c.phone || '\u2014');
    h += this.field('Address', [c.address_line1, c.city, c.state, c.postcode].filter(Boolean).join(', ') || '\u2014');
    h += '</div></div>';

    // History tab
    h += '<div class="dm-tab-content ' + (savedTab === 'dm-tab-history' ? 'active' : '') + '" id="dm-tab-history">';
    if (appts.length === 0) {
      h += '<p style="font-size:13px;opacity:.4">No appointments yet.</p>';
    } else {
      h += '<table class="dm-table"><thead><tr><th>ITO #</th><th>Date</th><th>Status</th><th class="num">Total</th></tr></thead><tbody>';
      for (var i = 0; i < appts.length; i++) {
        var a = appts[i];
        h += '<tr class="dm-clickable" onclick="DM.openAppt(\'' + a.id + '\')">';
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
    h += '<div class="dm-tab-content ' + (savedTab === 'dm-tab-custnotes' ? 'active' : '') + '" id="dm-tab-custnotes">';
    h += '<div style="margin-bottom:12px"><div class="dm-row">';
    h += '<input type="text" id="dm-custnote-input" class="dm-input" placeholder="Add a note..." style="flex:1" maxlength="200" onkeydown="if(event.key===\'Enter\'){DM.addCustNote();event.preventDefault()}">';
    h += '<button class="dm-btn dm-btn--primary" onclick="DM.addCustNote()">Add</button>';
    h += '</div></div>';
    h += '<div class="dm-timeline" id="dm-custnotes-list">';
    if (notes.length === 0) {
      h += '<p style="font-size:13px;opacity:.4">No notes yet.</p>';
    } else {
      for (var i = 0; i < notes.length; i++) {
        var n = notes[i];
        h += '<div class="dm-tl-item staff" data-note-id="' + n.id + '">';
        h += '<div class="dm-tl-dot"></div>';
        h += '<div class="dm-tl-head"><span class="dm-tl-author">' + this.esc(n.author) + '</span><span class="dm-tl-time">' + n.created_at + '</span>';
        h += '<button class="dm-tl-delete" onclick="DM.deleteCustNote(\'' + n.id + '\')" title="Delete">&times;</button>';
        h += '</div>';
        h += '<div class="dm-tl-body">' + this.esc(n.note) + '</div></div>';
      }
    }
    h += '</div></div>';

    document.getElementById('dm-body').innerHTML = h;
  },

  addCustNote: function() {
    var input = document.getElementById('dm-custnote-input');
    var note = input.value.trim();
    if (!note) return;
    this.activeTab = 'dm-tab-custnotes';
    var url = '/admin/customers?update=' + encodeURIComponent(this.currentId);
    this.post(url, { op: 'add_note', note: note }, function() { DM.reload(); });
  },

  deleteCustNote: function(noteId) {
    if (!confirm('Delete this note?')) return;
    this.activeTab = 'dm-tab-custnotes';
    var url = '/admin/customers?update=' + encodeURIComponent(this.currentId);
    this.post(url, { op: 'delete_note', note_id: noteId }, function() { DM.reload(); });
  },

  openAppt: function(id) {
    this.activeTab = null;
    this.open('appointment', id);
  },

  openCust: function(id) {
    this.activeTab = null;
    this.open('customer', id);
  },

  // ================================================================
  // HELPERS
  // ================================================================
  switchTab: function(el, tabId) {
    this.activeTab = tabId;
    var parent = el.parentElement;
    parent.querySelectorAll('.dm-tab').forEach(function(t) { t.classList.remove('active'); });
    el.classList.add('active');
    var body = parent.parentElement;
    body.querySelectorAll('.dm-tab-content').forEach(function(c) { c.classList.remove('active'); });
    document.getElementById(tabId).classList.add('active');
  },

  field: function(label, value) {
    return '<div><div class="dm-field-label">' + label + '</div><div class="dm-field-value">' + (value || '\u2014') + '</div></div>';
  },

  reload: function() {
    this.open(this.currentType, this.currentId);
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

function openDetailModal(type, id) { DM.open(type, id); }
function closeDetailModal() {
  document.getElementById('detail-modal').style.display = 'none';
  document.body.style.overflow = '';
  DM.activeTab = null;
}

// Keyboard shortcut: Esc to close
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape' && document.getElementById('detail-modal').style.display !== 'none') {
    closeDetailModal();
  }
});
</script>
