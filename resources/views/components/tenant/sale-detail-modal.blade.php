{{--
  Sale detail modal. Read-only view of a committed sale, refund, draft, or
  quote. Triggered globally via window.openSaleModal(saleId).

  Renders an empty backdrop + card on the page. JS fetches sale JSON from
  tenant.register.sales.show and populates the card body. Refund-of links
  re-load the modal with the original sale (no page navigation).

  Refund button (visible on committed, non-refund sales) closes the modal
  and navigates to the register page with ?refund=SALE_NUMBER, which the
  register page picks up to auto-open the refund picker.

  Usage:
    <x-tenant.sale-detail-modal />

  Once per page is enough — the include exposes a singleton modal and a
  global openSaleModal(saleId) function.
--}}
<style>
  .sd-backdrop{
    position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9990;
    display:flex;align-items:center;justify-content:center;padding:24px;
    opacity:0;transition:opacity .15s;pointer-events:none
  }
  .sd-backdrop.is-shown{opacity:1;pointer-events:auto}

  .sd-card{
    background:var(--ia-surface);border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-lg);width:100%;max-width:640px;
    max-height:calc(100vh - 48px);display:flex;flex-direction:column;
    transform:translateY(8px) scale(.98);transition:transform .15s
  }
  .sd-backdrop.is-shown .sd-card{transform:translateY(0) scale(1)}

  .sd-head{
    padding:18px 20px 16px;border-bottom:0.5px solid var(--ia-border);
    display:flex;align-items:flex-start;justify-content:space-between;gap:12px
  }
  .sd-head-main{min-width:0;flex:1}
  .sd-title{
    font-size:16px;font-weight:500;color:var(--ia-text);
    display:flex;align-items:center;gap:10px;flex-wrap:wrap
  }
  .sd-sale-num{font-family:'SF Mono',Menlo,monospace;font-size:14px}
  .sd-status{
    display:inline-block;padding:2px 8px;border-radius:99px;
    font-size:10px;text-transform:uppercase;letter-spacing:.06em;font-weight:600
  }
  .sd-status.draft{background:rgba(255,255,255,.06);color:var(--ia-text-dim)}
  .sd-status.quote{background:rgba(190,242,100,.15);color:var(--ia-accent)}
  .sd-status.unpaid{background:rgba(255,180,80,.15);color:#FFB450}
  .sd-status.paid{background:rgba(120,200,120,.15);color:#78c878}
  .sd-status.partial{background:rgba(255,180,80,.15);color:#FFB450}
  .sd-status.refunded{background:rgba(240,149,149,.15);color:#F09595}
  .sd-status.refund{background:rgba(240,149,149,.15);color:#F09595}

  .sd-meta{
    margin-top:6px;font-size:12px;color:var(--ia-text-dim);
    display:flex;flex-wrap:wrap;gap:6px 14px
  }
  .sd-meta b{color:var(--ia-text);font-weight:500}

  .sd-close{
    background:transparent;border:none;color:var(--ia-text-dim);
    font-size:20px;cursor:pointer;padding:4px 8px;line-height:1;
    border-radius:var(--ia-r-md);transition:background var(--ia-t),color var(--ia-t)
  }
  .sd-close:hover{background:var(--ia-hover);color:var(--ia-text)}

  .sd-body{padding:16px 20px;overflow-y:auto;flex:1}

  .sd-section{margin-bottom:18px}
  .sd-section:last-child{margin-bottom:0}
  .sd-section-h{
    font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;
    color:var(--ia-text-dim);margin-bottom:8px
  }

  .sd-items{width:100%;border-collapse:collapse;font-size:13px}
  .sd-items th{
    text-align:left;padding:8px 10px;font-size:11px;font-weight:600;
    color:var(--ia-text-dim);text-transform:uppercase;letter-spacing:.06em;
    border-bottom:0.5px solid var(--ia-border)
  }
  .sd-items th.num,.sd-items td.num{text-align:right;font-variant-numeric:tabular-nums}
  .sd-items td{
    padding:10px;border-bottom:0.5px solid var(--ia-border);
    color:var(--ia-text);vertical-align:top
  }
  .sd-items tr:last-child td{border-bottom:none}
  .sd-item-name{font-weight:500}
  .sd-item-desc{font-size:12px;color:var(--ia-text-dim);margin-top:2px}
  .sd-item-type{
    display:inline-block;font-size:10px;text-transform:uppercase;
    letter-spacing:.04em;color:var(--ia-text-dim);margin-left:6px;
    padding:1px 6px;border:0.5px solid var(--ia-border);border-radius:99px
  }

  .sd-totals{
    margin-top:14px;padding-top:12px;border-top:0.5px solid var(--ia-border);
    font-size:13px
  }
  .sd-totals-row{
    display:flex;justify-content:space-between;padding:3px 10px;
    color:var(--ia-text-dim)
  }
  .sd-totals-row b{color:var(--ia-text);font-weight:500}
  .sd-totals-row.total{
    margin-top:6px;padding-top:8px;border-top:0.5px solid var(--ia-border);
    font-size:14px;font-weight:600;color:var(--ia-text)
  }
  .sd-totals-row.total span{color:var(--ia-text)}
  .sd-totals-row .num{font-variant-numeric:tabular-nums}

  .sd-refund-info{
    background:var(--ia-surface-2);border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-md);padding:10px 12px;font-size:12px;
    color:var(--ia-text-dim)
  }
  .sd-refund-info a{color:var(--ia-accent);text-decoration:none;cursor:pointer}
  .sd-refund-info a:hover{text-decoration:underline}
  .sd-refund-list{margin:6px 0 0;padding:0;list-style:none}
  .sd-refund-list li{padding:3px 0;font-size:12px}

  .sd-notes{
    background:var(--ia-surface-2);border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-md);padding:10px 12px;font-size:13px;
    color:var(--ia-text);white-space:pre-wrap
  }

  .sd-actions{
    padding:14px 20px;border-top:0.5px solid var(--ia-border);
    display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap
  }
  .sd-btn{
    font-size:13px;padding:8px 14px;border-radius:var(--ia-r-md);
    border:0.5px solid var(--ia-border);background:transparent;
    color:var(--ia-text);cursor:pointer;font-family:inherit;
    transition:background var(--ia-t)
  }
  .sd-btn:hover{background:var(--ia-hover)}
  .sd-btn--primary{background:var(--ia-accent);color:var(--ia-accent-text);border-color:var(--ia-accent)}
  .sd-btn--primary:hover{filter:brightness(1.1);background:var(--ia-accent)}

  .sd-loading,.sd-error{
    padding:32px 20px;text-align:center;color:var(--ia-text-dim);font-size:13px
  }
  .sd-error{color:#F09595}

  /* Mobile: full-screen takeover, same pattern as IntakeConfirm in spirit. */
  @media (max-width: 600px){
    .sd-backdrop{padding:0;align-items:stretch}
    .sd-card{max-width:none;max-height:none;border-radius:0;border:none}
  }
</style>

<div class="sd-backdrop" id="sdBackdrop" role="dialog" aria-modal="true" aria-labelledby="sdTitle" hidden>
  <div class="sd-card">
    <div class="sd-head">
      <div class="sd-head-main">
        <div class="sd-title" id="sdTitle">Loading…</div>
        <div class="sd-meta" id="sdMeta"></div>
      </div>
      <button type="button" class="sd-close" id="sdClose" aria-label="Close">×</button>
    </div>
    <div class="sd-body" id="sdBody">
      <div class="sd-loading">Loading sale details…</div>
    </div>
    <div class="sd-actions" id="sdActions" style="display:none">
      <button type="button" class="sd-btn" id="sdCloseBtn">Close</button>
      <button type="button" class="sd-btn sd-btn--primary" id="sdRefundBtn" style="display:none">
        Refund this sale
      </button>
    </div>
  </div>
</div>

<script>
(function(){
  'use strict';

  var backdrop  = document.getElementById('sdBackdrop');
  var titleEl   = document.getElementById('sdTitle');
  var metaEl    = document.getElementById('sdMeta');
  var bodyEl    = document.getElementById('sdBody');
  var actionsEl = document.getElementById('sdActions');
  var closeBtn  = document.getElementById('sdClose');
  var closeBtn2 = document.getElementById('sdCloseBtn');
  var refundBtn = document.getElementById('sdRefundBtn');

  var SHOW_URL_TEMPLATE = @json(route('tenant.register.sales.show', ['id' => '__ID__']));
  var REGISTER_URL      = @json(route('tenant.register.index', []));

  function fmtMoney(cents) {
    var sign = cents < 0 ? '-' : '';
    var abs  = Math.abs(cents);
    return sign + '$' + (abs / 100).toFixed(2);
  }

  function fmtDate(iso) {
    if (!iso) return '—';
    var d = new Date(iso);
    if (isNaN(d.getTime())) return iso;
    return d.toLocaleString(undefined, {
      year: 'numeric', month: 'short', day: 'numeric',
      hour: 'numeric', minute: '2-digit'
    });
  }

  function escapeHtml(s) {
    if (s == null) return '';
    return String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function open() {
    backdrop.hidden = false;
    // next frame so transition fires
    requestAnimationFrame(function(){ backdrop.classList.add('is-shown'); });
    document.body.style.overflow = 'hidden';
  }

  function close() {
    backdrop.classList.remove('is-shown');
    document.body.style.overflow = '';
    setTimeout(function(){ backdrop.hidden = true; }, 150);
  }

  function showLoading() {
    titleEl.textContent = 'Loading…';
    metaEl.innerHTML = '';
    bodyEl.innerHTML = '<div class="sd-loading">Loading sale details…</div>';
    actionsEl.style.display = 'none';
    refundBtn.style.display = 'none';
    refundBtn.dataset.saleNumber = '';
  }

  function showError(msg) {
    bodyEl.innerHTML = '<div class="sd-error">' + escapeHtml(msg) + '</div>';
    actionsEl.style.display = 'flex';
    refundBtn.style.display = 'none';
  }

  function render(sale) {
    // Title row
    var statusClass = sale.is_refund ? 'refund' : (sale.payment_status || 'draft');
    var statusLabel = sale.is_refund
      ? 'Refund'
      : (sale.payment_status ? sale.payment_status.charAt(0).toUpperCase() + sale.payment_status.slice(1) : '—');
    var titleHtml = (sale.is_refund ? 'POS refund ' : 'Sale ');
    if (sale.sale_number) {
      titleHtml += '<span class="sd-sale-num">#' + escapeHtml(sale.sale_number) + '</span>';
    } else {
      titleHtml += '<span class="sd-sale-num" style="color:var(--ia-text-dim)">—</span>';
    }
    titleHtml += '<span class="sd-status ' + escapeHtml(statusClass) + '">' + escapeHtml(statusLabel) + '</span>';
    titleEl.innerHTML = titleHtml;

    // Meta line
    var metaParts = [];
    var dateIso = sale.paid_at || sale.created_at || sale.updated_at;
    if (dateIso) metaParts.push('<b>' + escapeHtml(fmtDate(dateIso)) + '</b>');
    if (sale.customer && sale.customer.name) {
      metaParts.push('<b>' + escapeHtml(sale.customer.name) + '</b>');
    }
    if (sale.payment_method) metaParts.push('via ' + escapeHtml(sale.payment_method));
    if (sale.rang_up_by) metaParts.push('by ' + escapeHtml(sale.rang_up_by));
    if (sale.location_name) metaParts.push(escapeHtml(sale.location_name));
    metaEl.innerHTML = metaParts.join(' · ');

    // Body
    var html = '';

    // Refund-of banner (this sale is a refund of another)
    if (sale.refund_of) {
      html += '<div class="sd-section"><div class="sd-refund-info">'
        + 'Refund of original sale '
        + '<a data-open-sale="' + escapeHtml(sale.refund_of.id) + '">'
        + '#' + escapeHtml(sale.refund_of.sale_number || '—')
        + '</a>'
        + '</div></div>';
    }

    // Refunds banner (this sale has been (partially) refunded)
    if (sale.refunds && sale.refunds.length > 0) {
      html += '<div class="sd-section"><div class="sd-refund-info">'
        + 'Refunded ' + sale.refunds.length + ' time' + (sale.refunds.length === 1 ? '' : 's') + ':'
        + '<ul class="sd-refund-list">';
      sale.refunds.forEach(function(r){
        html += '<li>'
          + '<a data-open-sale="' + escapeHtml(r.id) + '">#' + escapeHtml(r.sale_number || '—') + '</a>'
          + ' · ' + escapeHtml(fmtMoney(r.total_cents))
          + ' · ' + escapeHtml(fmtDate(r.paid_at))
          + '</li>';
      });
      html += '</ul></div></div>';
    }

    // Items
    if (sale.items && sale.items.length > 0) {
      html += '<div class="sd-section">'
        + '<div class="sd-section-h">Line items</div>'
        + '<table class="sd-items">'
        + '<thead><tr>'
        + '<th>Item</th>'
        + '<th class="num">Qty</th>'
        + '<th class="num">Price</th>'
        + '<th class="num">Total</th>'
        + '</tr></thead><tbody>';
      sale.items.forEach(function(it){
        var typeBadge = '';
        if (it.type && it.type !== 'service') {
          typeBadge = '<span class="sd-item-type">' + escapeHtml(it.type.replace('_', ' ')) + '</span>';
        }
        html += '<tr>'
          + '<td>'
          + '<div class="sd-item-name">' + escapeHtml(it.name || '—') + typeBadge + '</div>'
          + (it.description ? '<div class="sd-item-desc">' + escapeHtml(it.description) + '</div>' : '')
          + '</td>'
          + '<td class="num">' + escapeHtml(String(it.quantity)) + '</td>'
          + '<td class="num">' + escapeHtml(fmtMoney(it.unit_price_cents)) + '</td>'
          + '<td class="num">' + escapeHtml(fmtMoney(it.line_total_cents)) + '</td>'
          + '</tr>';
      });
      html += '</tbody></table>';
    } else {
      html += '<div class="sd-section"><div class="sd-loading">No line items.</div></div>';
    }

    // Totals
    html += '<div class="sd-totals">';
    html += '<div class="sd-totals-row"><span>Subtotal</span><span class="num">' + escapeHtml(fmtMoney(sale.subtotal_cents)) + '</span></div>';
    if (sale.discount_cents) {
      html += '<div class="sd-totals-row"><span>Discount</span><span class="num">-' + escapeHtml(fmtMoney(sale.discount_cents)) + '</span></div>';
    }
    if (sale.tax_cents) {
      html += '<div class="sd-totals-row"><span>Tax</span><span class="num">' + escapeHtml(fmtMoney(sale.tax_cents)) + '</span></div>';
    }
    if (sale.surcharge_cents) {
      html += '<div class="sd-totals-row"><span>Surcharge</span><span class="num">' + escapeHtml(fmtMoney(sale.surcharge_cents)) + '</span></div>';
    }
    if (sale.tip_cents) {
      html += '<div class="sd-totals-row"><span>Tip</span><span class="num">' + escapeHtml(fmtMoney(sale.tip_cents)) + '</span></div>';
    }
    html += '<div class="sd-totals-row total"><span>Total</span><span class="num">' + escapeHtml(fmtMoney(sale.total_cents)) + '</span></div>';
    html += '</div>';

    // MARKER-PATCH-191 — Payments ledger: show every payment against this sale
    // (deposits, balance, refunds) with method, kind, date and amount, plus a
    // paid/balance summary. This is the detail that was missing.
    (function(){
      var pays = sale.payments || [];
      var paid = (typeof sale.paid_cents === 'number') ? sale.paid_cents : null;
      html += '<div class="sd-section" style="margin-top:18px">'
        + '<div class="sd-section-h">Payments</div>';
      if (pays.length > 0) {
        html += '<table class="sd-items"><tbody>';
        pays.forEach(function(p){
          var when = p.recorded_at ? fmtDate(p.recorded_at) : '';
          var kind = (p.kind || '').replace('_',' ');
          var label = p.method_label || p.method || '';
          var meta = [label, kind].filter(Boolean).join(' · ');
          var amtClass = p.is_refund ? ' style="color:#F87171"' : '';
          var amtTxt = (p.is_refund ? '' : '') + fmtMoney(p.amount_cents);
          html += '<tr>'
            + '<td><div class="sd-item-name">' + escapeHtml(meta || 'Payment') + '</div>'
            + (when ? '<div class="sd-item-desc">' + escapeHtml(when) + '</div>' : '')
            + (p.notes ? '<div class="sd-item-desc">' + escapeHtml(p.notes) + '</div>' : '')
            + '</td>'
            + '<td class="num"' + amtClass + '>' + escapeHtml(amtTxt) + '</td>'
            + '</tr>';
        });
        html += '</tbody></table>';
      } else {
        html += '<div class="sd-loading">No payments recorded against this sale.</div>';
      }
      // Paid / balance summary
      if (paid !== null) {
        var bal = (sale.total_cents || 0) - paid;
        html += '<div class="sd-totals" style="margin-top:6px">';
        html += '<div class="sd-totals-row"><span>Paid</span><span class="num">' + escapeHtml(fmtMoney(paid)) + '</span></div>';
        if (bal > 0) {
          html += '<div class="sd-totals-row total"><span>Balance due</span><span class="num" style="color:#F59E0B">' + escapeHtml(fmtMoney(bal)) + '</span></div>';
        } else if (bal < 0) {
          html += '<div class="sd-totals-row"><span>Overpaid</span><span class="num" style="color:#F87171">' + escapeHtml(fmtMoney(-bal)) + '</span></div>';
        }
        html += '</div>';
      }
      html += '</div>';
    })();

    // Notes
    if (sale.notes && sale.notes.trim()) {
      html += '<div class="sd-section" style="margin-top:18px">'
        + '<div class="sd-section-h">Notes</div>'
        + '<div class="sd-notes">' + escapeHtml(sale.notes) + '</div>'
        + '</div>';
    }

    // MARKER-PATCH-161 — Email send log + re-send actions (only on committed, non-draft, non-quote sales)
    if (sale.sale_number && !sale.is_draft && !sale.is_quote) {
      var log = sale.send_log || [];
      var defaultEmail = (sale.customer && sale.customer.email) ? sale.customer.email : '';

      html += '<div class="sd-section" style="margin-top:24px">'
        + '<div class="sd-section-h" style="display:flex;justify-content:space-between;align-items:center">'
        +   '<span>Email receipts</span>'
        +   '<span style="font-size:11px;opacity:.5;font-weight:400">' + log.length + ' send' + (log.length === 1 ? '' : 's') + '</span>'
        + '</div>';

      if (log.length > 0) {
        html += '<div style="background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:6px;padding:10px 12px;margin-bottom:10px">';
        log.slice(0, 5).forEach(function(entry, idx) {
          var statusBadge = '';
          if (entry.status === 'sent')    statusBadge = '<span style="font-size:10px;padding:1px 6px;border-radius:3px;background:rgba(34,139,34,.15);color:#3fb04a;text-transform:uppercase;letter-spacing:.05em;font-weight:600">Sent</span>';
          else if (entry.status === 'failed')  statusBadge = '<span style="font-size:10px;padding:1px 6px;border-radius:3px;background:rgba(248,113,113,.15);color:#f87171;text-transform:uppercase;letter-spacing:.05em;font-weight:600">Failed</span>';
          else if (entry.status === 'skipped') statusBadge = '<span style="font-size:10px;padding:1px 6px;border-radius:3px;background:rgba(150,150,150,.15);color:var(--ia-text-dim);text-transform:uppercase;letter-spacing:.05em;font-weight:600">Skipped</span>';

          var border = idx < Math.min(log.length, 5) - 1 ? 'border-bottom:0.5px solid var(--ia-border);' : '';
          html += '<div style="display:flex;justify-content:space-between;align-items:center;padding:7px 0;font-size:12.5px;' + border + '">'
            +   '<div style="min-width:0;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'
            +     '<span style="color:var(--ia-text)">' + escapeHtml(entry.recipient || '(none)') + '</span>'
            +     '<span style="color:var(--ia-text-dim);margin-left:8px;font-size:11px">— ' + escapeHtml(fmtDate(entry.created_at)) + '</span>'
            +     (entry.error ? '<div style="font-size:10.5px;color:#f87171;margin-top:2px">' + escapeHtml(entry.error) + '</div>' : '')
            +   '</div>'
            +   statusBadge
            + '</div>';
        });
        html += '</div>';
      } else {
        html += '<div style="font-size:12.5px;color:var(--ia-text-dim);padding:8px 0;margin-bottom:10px">No receipts have been sent yet.</div>';
      }

      // Action buttons
      var resendDisabled = defaultEmail ? '' : 'disabled';
      var resendLabel = defaultEmail
        ? 'Re-send to ' + escapeHtml(defaultEmail)
        : 'Re-send (no email on file)';

      html += '<div style="display:flex;gap:8px;flex-wrap:wrap">'
        +   '<button type="button" class="ia-btn ia-btn--secondary ia-btn--sm" data-action="resend-default" ' + resendDisabled + '>'
        +     resendLabel
        +   '</button>'
        +   '<button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" data-action="resend-other">'
        +     '+ Send to another email'
        +   '</button>'
        + '</div>';

      // Inline form for "send to another"
      html += '<div data-resend-form style="display:none;margin-top:10px;padding:10px;background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:6px">'
        +   '<input type="email" data-resend-input class="ia-input" placeholder="email@example.com" style="margin-bottom:8px;font-size:13px">'
        +   '<div style="display:flex;gap:6px">'
        +     '<button type="button" class="ia-btn ia-btn--primary ia-btn--sm" data-action="resend-submit">Send</button>'
        +     '<button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" data-action="resend-cancel">Cancel</button>'
        +   '</div>'
        + '</div>';

      html += '<div data-resend-toast style="display:none;margin-top:10px;padding:8px 12px;background:rgba(190,242,100,.08);border:0.5px solid rgba(190,242,100,.3);border-radius:6px;font-size:12.5px;color:var(--ia-text)"></div>';

      html += '</div>';
    }

    bodyEl.innerHTML = html;

    // Wire any inline open-sale links (refund-of / refunds list)
    bodyEl.querySelectorAll('[data-open-sale]').forEach(function(a){
      a.addEventListener('click', function(e){
        e.preventDefault();
        window.openSaleModal(a.dataset.openSale);
      });
    });

    // MARKER-PATCH-161 — Re-send wiring
    var resendUrl = @json(route('tenant.sales.resend_receipt', ['id' => '__ID__']));
    var saleId    = sale.id;
    var defaultEmail = (sale.customer && sale.customer.email) ? sale.customer.email : '';

    function showResendToast(msg, isError) {
      var toast = bodyEl.querySelector('[data-resend-toast]');
      if (!toast) return;
      toast.textContent = msg;
      toast.style.display = '';
      toast.style.background = isError ? 'rgba(248,113,113,.08)' : 'rgba(190,242,100,.08)';
      toast.style.borderColor = isError ? 'rgba(248,113,113,.3)' : 'rgba(190,242,100,.3)';
      setTimeout(function(){ toast.style.display = 'none'; }, 4000);
    }

    function postResend(email, btn) {
      if (btn) { btn.disabled = true; btn.textContent = 'Sending…'; }
      var url = resendUrl.replace('__ID__', encodeURIComponent(saleId));
      var headers = {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      };
      var csrf = document.querySelector('meta[name="csrf-token"]');
      if (csrf) headers['X-CSRF-TOKEN'] = csrf.getAttribute('content');

      return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: headers,
        body: JSON.stringify(email ? { email: email } : {}),
      })
      .then(function(r){ return r.json().then(function(d){ return { ok: r.ok && d.ok, body: d }; }); })
      .then(function(res){
        if (res.ok) {
          showResendToast('Re-send queued. It will arrive in a few seconds.', false);
          // Refresh the modal to show the new log entry after a short delay.
          setTimeout(function(){ window.openSaleModal(saleId); }, 1500);
        } else {
          showResendToast((res.body && res.body.error) || 'Could not re-send.', true);
        }
      })
      .catch(function(){ showResendToast('Network error.', true); });
    }

    var resendDefault = bodyEl.querySelector('[data-action="resend-default"]');
    if (resendDefault) {
      resendDefault.addEventListener('click', function(){
        if (!defaultEmail) return;
        postResend(null, resendDefault);
      });
    }

    var resendOther = bodyEl.querySelector('[data-action="resend-other"]');
    var resendForm  = bodyEl.querySelector('[data-resend-form]');
    var resendInput = bodyEl.querySelector('[data-resend-input]');
    var resendSubmit= bodyEl.querySelector('[data-action="resend-submit"]');
    var resendCancel= bodyEl.querySelector('[data-action="resend-cancel"]');

    if (resendOther && resendForm) {
      resendOther.addEventListener('click', function(){
        resendForm.style.display = '';
        if (resendInput) resendInput.focus();
      });
    }
    if (resendCancel && resendForm) {
      resendCancel.addEventListener('click', function(){
        resendForm.style.display = 'none';
        if (resendInput) resendInput.value = '';
      });
    }
    if (resendSubmit && resendInput) {
      resendSubmit.addEventListener('click', function(){
        var email = (resendInput.value || '').trim();
        if (!email) { resendInput.focus(); return; }
        postResend(email, resendSubmit);
      });
      resendInput.addEventListener('keydown', function(e){
        if (e.key === 'Enter') { e.preventDefault(); resendSubmit.click(); }
      });
    }

    // Actions: refund button only on committed, non-refund, non-draft, non-quote sales
    actionsEl.style.display = 'flex';
    var canRefund = !sale.is_refund
      && !sale.is_draft
      && !sale.is_quote
      && sale.payment_status !== 'refunded'
      && sale.sale_number;
    if (canRefund) {
      refundBtn.style.display = '';
      refundBtn.dataset.saleNumber = sale.sale_number;
    } else {
      refundBtn.style.display = 'none';
      refundBtn.dataset.saleNumber = '';
    }
  }

  // Public API
  window.openSaleModal = function(saleId) {
    if (!saleId) return;
    open();
    showLoading();
    var url = SHOW_URL_TEMPLATE.replace('__ID__', encodeURIComponent(saleId));
    fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, body: d }; }); })
      .then(function(res){
        if (!res.ok || !res.body || !res.body.ok) {
          showError((res.body && res.body.error) || 'Could not load sale.');
          return;
        }
        render(res.body.sale);
      })
      .catch(function(){
        showError('Network error loading sale.');
      });
  };

  // Close handlers
  closeBtn.addEventListener('click', close);
  closeBtn2.addEventListener('click', close);
  backdrop.addEventListener('click', function(e){
    if (e.target === backdrop) close();
  });
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && !backdrop.hidden) close();
  });

  // Refund handoff: close modal, redirect to register with ?refund=NUM
  refundBtn.addEventListener('click', function(){
    var num = refundBtn.dataset.saleNumber;
    if (!num) return;
    close();
    var sep = REGISTER_URL.indexOf('?') >= 0 ? '&' : '?';
    window.location.href = REGISTER_URL + sep + 'refund=' + encodeURIComponent(num);
  });
})();
</script>
