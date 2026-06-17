{{--
  Shared appointment drawer. (MARKER-PATCH-212 — enriched)
  Public API: window.ApptDrawer.open(apptId, fullUrl) / .close()
--}}

@once
@push('styles')
<style>
  .appt-drawer-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:90;opacity:0;pointer-events:none;transition:opacity .18s ease}
  .appt-drawer-backdrop.open{opacity:1;pointer-events:auto}
  .appt-drawer{position:fixed;top:0;right:0;bottom:0;width:min(520px,94vw);background:var(--ia-surface);border-left:0.5px solid var(--ia-border);z-index:100;transform:translateX(100%);transition:transform .22s ease;display:flex;flex-direction:column;box-shadow:-8px 0 24px rgba(0,0,0,0.18)}
  .appt-drawer.open{transform:translateX(0)}
  .appt-drawer-head{padding:18px 20px;border-bottom:0.5px solid var(--ia-border);display:flex;justify-content:space-between;align-items:flex-start;flex-shrink:0}
  .appt-drawer-ra{font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--ia-text-muted);margin-bottom:2px;font-weight:500}
  .appt-drawer-title{font-size:18px;font-weight:500;letter-spacing:-.01em}
  .appt-drawer-close{background:none;border:none;font-size:22px;line-height:1;color:var(--ia-text-muted);cursor:pointer;padding:2px 6px;border-radius:4px}
  .appt-drawer-close:hover{background:var(--ia-hover)}
  .appt-drawer-body{flex:1;overflow-y:auto;padding:20px}
  .appt-drawer-section{margin-bottom:20px;padding-bottom:18px;border-bottom:0.5px solid var(--ia-border)}
  .appt-drawer-section:last-child{border-bottom:none;margin-bottom:0}
  .appt-drawer-label{font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--ia-text-muted);font-weight:600;margin-bottom:10px}
  .appt-drawer-badges{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px}
  .appt-drawer-row{display:flex;justify-content:space-between;padding:5px 0;font-size:13px}
  .appt-drawer-row-label{color:var(--ia-text-muted)}
  .appt-drawer-identifier{font-family:var(--ia-font-mono);font-size:15px;font-weight:500;letter-spacing:.02em}
  .appt-drawer-foot{padding:14px 20px;border-top:0.5px solid var(--ia-border);display:flex;gap:8px;flex-shrink:0}
  .appt-drawer-foot a,.appt-drawer-foot button{flex:1;justify-content:center}
  .appt-drawer-loading{padding:40px 20px;text-align:center;font-size:13px;color:var(--ia-text-muted)}

  /* ── enriched blocks ── */
  .adw-stats{display:flex;gap:8px;margin-top:12px}
  .adw-stat{flex:1;border:0.5px solid var(--ia-border);border-radius:9px;padding:9px 10px;text-align:center}
  .adw-stat .v{font-size:15px;font-weight:600}
  .adw-stat .k{font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-muted);margin-top:2px}
  .adw-asset{border:0.5px solid var(--ia-border);border-radius:10px;padding:12px 14px;margin-bottom:10px;background:var(--ia-surface-3,rgba(255,255,255,0.03))}
  .adw-asset:last-child{margin-bottom:0}
  .adw-asset-h{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px}
  .adw-asset-n{font-size:14px;font-weight:600}
  .adw-asset-s{font-size:13px;font-weight:600;white-space:nowrap}
  .adw-line{display:flex;justify-content:space-between;font-size:13px;padding:4px 0;color:var(--ia-text)}
  .adw-line.addon{padding-left:12px;color:var(--ia-text-muted);font-size:12.5px}
  .adw-pay-row{display:flex;justify-content:space-between;font-size:13px;padding:5px 0;color:var(--ia-text-muted)}
  .adw-pay-row .v{color:var(--ia-text)}
  .adw-pay-total{display:flex;justify-content:space-between;font-weight:600;font-size:14px;padding-top:9px;margin-top:4px;border-top:0.5px solid var(--ia-border)}
  .adw-balance{display:flex;justify-content:space-between;align-items:center;margin-top:11px;border:0.5px solid var(--ia-border);border-radius:9px;padding:10px 12px;font-size:13px}
  .adw-balance .v{font-weight:600;font-size:15px}
  .adw-balance.clear{color:#86efac}
  .adw-kv{display:flex;flex-direction:column;gap:11px}
  .adw-kv .k{font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--ia-text-muted)}
  .adw-kv .v{font-size:13.5px;margin-top:2px;line-height:1.5}
  .adw-note{background:var(--ia-surface-3,rgba(255,255,255,0.03));border:0.5px solid var(--ia-border);border-radius:9px;padding:11px 13px;font-size:13px;color:var(--ia-text);line-height:1.5;margin-bottom:8px}
  .adw-note:last-child{margin-bottom:0}
  .adw-note .by{font-size:11px;color:var(--ia-text-muted);margin-top:7px}
  .adw-act{position:relative;padding-left:18px}
  .adw-act::before{content:'';position:absolute;left:3px;top:5px;bottom:5px;width:1.5px;background:var(--ia-border)}
  .adw-act-item{position:relative;padding-bottom:13px}
  .adw-act-item:last-child{padding-bottom:0}
  .adw-act-item::before{content:'';position:absolute;left:-18px;top:3px;width:8px;height:8px;border-radius:50%;background:var(--ia-border);box-shadow:0 0 0 2px var(--ia-surface)}
  .adw-act-item.sent::before{background:#86efac}
  .adw-act-item.failed::before{background:#f87171}
  .adw-act-item .at{font-size:13px}
  .adw-act-item .ad{font-size:11px;color:var(--ia-text-muted);margin-top:1px}

  @media (max-width:1023px){.appt-drawer-foot{padding-bottom:calc(14px + 72px + env(safe-area-inset-bottom, 0px))}}
</style>
@endpush

<div class="appt-drawer-backdrop" id="appt-drawer-backdrop"></div>
<aside class="appt-drawer" id="appt-drawer" role="dialog" aria-label="Appointment details">
  <div class="appt-drawer-head">
    <div>
      <div class="appt-drawer-ra" id="drawer-ra">Loading…</div>
      <div class="appt-drawer-title" id="drawer-title"></div>
    </div>
    <button type="button" class="appt-drawer-close" id="drawer-close" aria-label="Close">&times;</button>
  </div>
  <div class="appt-drawer-body" id="drawer-body">
    <div class="appt-drawer-loading">Loading…</div>
  </div>
  <div class="appt-drawer-foot">
    <a href="#" class="ia-btn ia-btn--primary" id="drawer-fullview">Open full view</a>
    {{-- MARKER-PATCH-328 --}}
    @if(data_get(tenant()->settings, 'work_order_tag.enabled', true))
    <button type="button" class="ia-btn ia-btn--ghost" id="drawer-printtag">&#9113; Print tag</button>
    @endif
    <button type="button" class="ia-btn ia-btn--ghost" id="drawer-close-2">Close</button>
  </div>
</aside>

@push('scripts')
<script>
(function(){
  'use strict';
  var backdrop = document.getElementById('appt-drawer-backdrop');
  var drawer   = document.getElementById('appt-drawer');
  var closeBtn = document.getElementById('drawer-close');
  var closeBtn2= document.getElementById('drawer-close-2');
  var fullLink = document.getElementById('drawer-fullview');
  var printTagBtn = document.getElementById('drawer-printtag'); // MARKER-PATCH-328
  var currentApptId = null; // MARKER-PATCH-328
  var raEl     = document.getElementById('drawer-ra');
  var titleEl  = document.getElementById('drawer-title');
  var bodyEl   = document.getElementById('drawer-body');
  if (!backdrop || !drawer) return;

  function openDrawer(){ backdrop.classList.add('open'); drawer.classList.add('open'); document.body.style.overflow='hidden'; }
  function closeDrawer(){ backdrop.classList.remove('open'); drawer.classList.remove('open'); document.body.style.overflow=''; }
  backdrop.addEventListener('click', closeDrawer);
  closeBtn.addEventListener('click', closeDrawer);
  closeBtn2.addEventListener('click', closeDrawer);
  // MARKER-PATCH-328 — print the current job's tag via a hidden iframe.
  if (printTagBtn) printTagBtn.addEventListener('click', function(){
    if (!currentApptId) return;
    if (window.openPrintComposer) { window.openPrintComposer('appointment', currentApptId, { type: 'tag', format: 't80' }); return; } // MARKER-PATCH-338
    var url = window.location.origin + '/admin/appointments/' + currentApptId + '/tag?embed=1';
    var f = document.createElement('iframe');
    f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
    f.src = url;
    f.onload = function(){
      try { f.contentWindow.focus(); f.contentWindow.print(); }
      catch (e) { window.open(url.replace('?embed=1',''), '_blank'); }
      setTimeout(function(){ f.remove(); }, 2000);
    };
    document.body.appendChild(f);
  });
  document.addEventListener('keydown', function(e){ if (e.key==='Escape' && drawer.classList.contains('open')) closeDrawer(); });

  function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
  function sec(label, inner){ return '<div class="appt-drawer-section"><div class="appt-drawer-label">'+label+'</div>'+inner+'</div>'; }

  function loadDrawer(apptId, fullUrlOverride){
    openDrawer();
    currentApptId = apptId; // MARKER-PATCH-328
    raEl.textContent = 'Loading…'; titleEl.textContent = '';
    bodyEl.innerHTML = '<div class="appt-drawer-loading">Loading…</div>';

    fetch(window.location.origin + '/admin/appointments/' + apptId + '/drawer', { headers:{ 'X-Requested-With':'XMLHttpRequest', 'Accept':'application/json' }})
      .then(function(r){ return r.json(); })
      .then(function(resp){
        if (!resp.ok){ bodyEl.innerHTML = '<div class="appt-drawer-loading">Could not load appointment.</div>'; return; }
        var a = resp.appointment;

        raEl.textContent = a.ra_number;
        var headline = (a.assets && a.assets.length) ? (a.assets.length + ' assets') : (a.items && a.items.length ? a.items[0].name : 'Appointment');
        titleEl.textContent = headline;
        fullLink.href = fullUrlOverride || a.full_url;

        var html = '';

        // badges
        html += '<div class="appt-drawer-badges">';
        html += '<span class="ia-badge ia-badge--' + esc(a.status.replace(/_/g,'-')) + '">' + esc(a.status_label) + '</span>';
        html += '<span class="ia-badge ia-badge--' + esc(a.payment_status) + '">' + esc(a.payment_status_label) + '</span>';
        html += '</div>';

        // When
        var whenStr = esc(a.appointment_date_long || '');
        if (a.appointment_time) whenStr += ' &middot; ' + esc(a.appointment_time.substring(0,5));
        if (a.duration_minutes) whenStr += ' &middot; ' + a.duration_minutes + ' min';
        html += sec('When', '<div style="font-size:14px">' + whenStr + '</div>');

        // Customer (+ stats)
        var cust = '<div style="font-size:14px;font-weight:500">' + esc(a.customer_name) + '</div>';
        if (a.customer_email) cust += '<div style="font-size:12px;color:var(--ia-text-muted);margin-top:2px">' + esc(a.customer_email) + '</div>';
        if (a.customer_phone) cust += '<div style="font-size:12px;color:var(--ia-text-muted);margin-top:2px">' + esc(a.customer_phone) + '</div>';
        if (a.customer_visits != null || a.customer_since){
          cust += '<div class="adw-stats">';
          if (a.customer_visits != null) cust += '<div class="adw-stat"><div class="v">' + a.customer_visits + '</div><div class="k">Visits</div></div>';
          if (a.customer_since)          cust += '<div class="adw-stat"><div class="v">' + esc(a.customer_since) + '</div><div class="k">Since</div></div>';
          cust += '</div>';
        }
        html += sec('Customer', cust);

        // Identifier (bike serial / plate etc.)
        if (a.identifier_value && a.identifier_label){
          html += sec(esc(a.identifier_label), '<div class="appt-drawer-identifier">' + esc(a.identifier_value) + '</div>');
        }

        // Assets (multi) OR Services (single)
        if (a.assets && a.assets.length){
          var ah = '';
          a.assets.forEach(function(as){
            ah += '<div class="adw-asset"><div class="adw-asset-h"><span class="adw-asset-n">' + esc(as.name) + '</span><span class="adw-asset-s">' + esc(as.subtotal) + '</span></div>';
            as.lines.forEach(function(l){
              ah += '<div class="adw-line' + (l.addon ? ' addon' : '') + '"><span>' + (l.addon ? '+ ' : '') + esc(l.name) + '</span><span>' + esc(l.price) + '</span></div>';
            });
            ah += '</div>';
          });
          html += sec('Bikes / assets', ah);
        } else if (a.items && a.items.length){
          var sh = '';
          a.items.forEach(function(it){ sh += '<div class="appt-drawer-row"><span>' + esc(it.name) + '</span><span>' + esc(it.price) + '</span></div>'; });
          if (a.addons) a.addons.forEach(function(ad){ sh += '<div class="appt-drawer-row"><span class="appt-drawer-row-label">+ ' + esc(ad.name) + '</span><span>' + esc(ad.price) + '</span></div>'; });
          if (a.parts) a.parts.forEach(function(pt){ sh += '<div class="appt-drawer-row"><span class="appt-drawer-row-label">' + esc(pt.name) + (pt.quantity > 1 ? ' \u00d7' + pt.quantity : '') + '</span><span>' + esc(pt.price) + '</span></div>'; });
          html += sec('Services', sh);
        }

        // Payment breakdown
        if (a.payment){
          var p = a.payment, pay = '';
          pay += '<div class="adw-pay-row"><span>Subtotal</span><span class="v">' + esc(p.subtotal) + '</span></div>';
          pay += '<div class="adw-pay-row"><span>Tax</span><span class="v">' + esc(p.tax) + '</span></div>';
          pay += '<div class="adw-pay-total"><span>Total</span><span>' + esc(p.total) + '</span></div>';
          if (a.paid_cents > 0) pay += '<div class="adw-pay-row" style="margin-top:6px"><span>Paid</span><span class="v">' + esc(p.paid) + '</span></div>';
          var clear = (p.balance_cents === 0);
          pay += '<div class="adw-balance' + (clear ? ' clear' : '') + '"><span>' + (clear ? 'Balance' : 'Balance due') + '</span><span class="v">' + esc(p.balance) + '</span></div>';
          html += sec('Payment', pay);
        }

        // Intake details
        if (a.work_order && a.work_order.length){
          var kv = '<div class="adw-kv">';
          a.work_order.forEach(function(w){ kv += '<div><div class="k">' + esc(w.label) + '</div><div class="v">' + esc(w.value) + '</div></div>'; });
          kv += '</div>';
          html += sec('Intake details', kv);
        }

        // Shop note
        if (a.notes && a.notes.length){
          var nh = '';
          a.notes.forEach(function(n){ nh += '<div class="adw-note">' + esc(n.content) + '<div class="by">' + esc(n.type) + (n.date ? ' &middot; ' + esc(n.date) : '') + '</div></div>'; });
          html += sec('Shop note', nh);
        }

        // Activity
        if (a.activity && a.activity.length){
          var act = '<div class="adw-act">';
          a.activity.forEach(function(ev){
            var cls = ev.status === 'sent' ? ' sent' : ((/fail|bounce|error|complaint/i).test(ev.status||'') ? ' failed' : '');
            var chan = ev.channel ? ' &middot; ' + esc(ev.channel) : '';
            act += '<div class="adw-act-item' + cls + '"><div class="at">' + esc(ev.event) + '</div><div class="ad">' + esc(ev.date || '') + chan + (ev.status ? ' &middot; ' + esc(ev.status) : '') + '</div></div>';
          });
          act += '</div>';
          html += sec('Activity', act);
        }

        bodyEl.innerHTML = html;
      })
      .catch(function(){ bodyEl.innerHTML = '<div class="appt-drawer-loading">Network error.</div>'; });
  }

  window.ApptDrawer = { open: loadDrawer, close: closeDrawer };
})();
</script>
@endpush
@endonce
