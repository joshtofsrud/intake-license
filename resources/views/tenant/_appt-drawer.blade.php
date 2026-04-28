{{--
  Shared appointment drawer.
  Public API: window.ApptDrawer.open(apptId, fullUrl)
              window.ApptDrawer.close()
  Used by: dashboard, drop-off calendar (day + week), and any future surface
  that needs a quick-look at an appointment without leaving the page.
--}}

@once
@push('styles')
<style>
  .appt-drawer-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.4);
    z-index: 90;
    opacity: 0;
    pointer-events: none;
    transition: opacity .18s ease;
  }
  .appt-drawer-backdrop.open { opacity: 1; pointer-events: auto; }

  .appt-drawer {
    position: fixed;
    top: 0; right: 0; bottom: 0;
    width: min(480px, 92vw);
    background: var(--ia-surface);
    border-left: 0.5px solid var(--ia-border);
    z-index: 100;
    transform: translateX(100%);
    transition: transform .22s ease;
    display: flex;
    flex-direction: column;
    box-shadow: -8px 0 24px rgba(0,0,0,0.08);
  }
  .appt-drawer.open { transform: translateX(0); }

  .appt-drawer-head {
    padding: 18px 20px;
    border-bottom: 0.5px solid var(--ia-border);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-shrink: 0;
  }
  .appt-drawer-ra {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: var(--ia-text-muted);
    margin-bottom: 2px;
    font-weight: 500;
  }
  .appt-drawer-title {
    font-size: 18px;
    font-weight: 500;
    letter-spacing: -.01em;
  }
  .appt-drawer-close {
    background: none;
    border: none;
    font-size: 22px;
    line-height: 1;
    color: var(--ia-text-muted);
    cursor: pointer;
    padding: 2px 6px;
    border-radius: 4px;
  }
  .appt-drawer-close:hover { background: var(--ia-hover); }

  .appt-drawer-body {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
  }
  .appt-drawer-section {
    margin-bottom: 18px;
    padding-bottom: 14px;
    border-bottom: 0.5px solid var(--ia-border);
  }
  .appt-drawer-section:last-child { border-bottom: none; }
  .appt-drawer-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: var(--ia-text-muted);
    font-weight: 500;
    margin-bottom: 6px;
  }
  .appt-drawer-badges {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-bottom: 14px;
  }
  .appt-drawer-row {
    display: flex;
    justify-content: space-between;
    padding: 5px 0;
    font-size: 13px;
  }
  .appt-drawer-row-label { color: var(--ia-text-muted); }
  .appt-drawer-identifier {
    font-family: var(--ia-font-mono);
    font-size: 15px;
    font-weight: 500;
    letter-spacing: .02em;
  }
  .appt-drawer-foot {
    padding: 14px 20px;
    border-top: 0.5px solid var(--ia-border);
    display: flex;
    gap: 8px;
    flex-shrink: 0;
  }
  .appt-drawer-foot a, .appt-drawer-foot button { flex: 1; justify-content: center; }

  .appt-drawer-loading {
    padding: 40px 20px;
    text-align: center;
    font-size: 13px;
    color: var(--ia-text-muted);
  }

  /* On mobile + tablet, the bottom nav sits over the drawer foot.
     Mirror the nav's 72px height + safe-area padding so buttons clear it. */
  @media (max-width: 1023px) {
    .appt-drawer-foot {
      padding-bottom: calc(14px + 72px + env(safe-area-inset-bottom, 0px));
    }
  }
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
  var raEl     = document.getElementById('drawer-ra');
  var titleEl  = document.getElementById('drawer-title');
  var bodyEl   = document.getElementById('drawer-body');

  if (!backdrop || !drawer) return;

  function openDrawer() {
    backdrop.classList.add('open');
    drawer.classList.add('open');
    document.body.style.overflow = 'hidden';
  }
  function closeDrawer() {
    backdrop.classList.remove('open');
    drawer.classList.remove('open');
    document.body.style.overflow = '';
  }

  backdrop.addEventListener('click', closeDrawer);
  closeBtn.addEventListener('click', closeDrawer);
  closeBtn2.addEventListener('click', closeDrawer);
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && drawer.classList.contains('open')) closeDrawer();
  });

  function escHtml(s) {
    return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function loadDrawer(apptId, fullUrlOverride) {
    openDrawer();
    raEl.textContent = 'Loading…';
    titleEl.textContent = '';
    bodyEl.innerHTML = '<div class="appt-drawer-loading">Loading…</div>';

    var url = window.location.origin + '/admin/appointments/' + apptId + '/drawer';
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }})
      .then(function(r){ return r.json(); })
      .then(function(resp){
        if (!resp.ok) { bodyEl.innerHTML = '<div class="appt-drawer-loading">Could not load appointment.</div>'; return; }
        var a = resp.appointment;

        raEl.textContent = a.ra_number;
        var headline = a.items && a.items.length ? a.items[0].name : 'Appointment';
        titleEl.textContent = headline;
        fullLink.href = fullUrlOverride || a.full_url;

        var html = '';

        html += '<div class="appt-drawer-badges">';
        html += '<span class="ia-badge ia-badge--' + escHtml(a.status.replace(/_/g, '-')) + '">' + escHtml(a.status_label) + '</span>';
        html += '<span class="ia-badge ia-badge--' + escHtml(a.payment_status) + '">' + escHtml(a.payment_status_label) + '</span>';
        html += '</div>';

        html += '<div class="appt-drawer-section">';
        html += '<div class="appt-drawer-label">When</div>';
        html += '<div style="font-size:14px">' + escHtml(a.appointment_date_long || '');
        if (a.appointment_time) {
          var timeStr = a.appointment_time.substring(0,5);
          html += ' &middot; ' + escHtml(timeStr);
        }
        if (a.duration_minutes) html += ' &middot; ' + a.duration_minutes + ' min';
        html += '</div></div>';

        html += '<div class="appt-drawer-section">';
        html += '<div class="appt-drawer-label">Customer</div>';
        html += '<div style="font-size:14px;font-weight:500">' + escHtml(a.customer_name) + '</div>';
        if (a.customer_email) html += '<div style="font-size:12px;color:var(--ia-text-muted);margin-top:2px">' + escHtml(a.customer_email) + '</div>';
        if (a.customer_phone) html += '<div style="font-size:12px;color:var(--ia-text-muted);margin-top:2px">' + escHtml(a.customer_phone) + '</div>';
        html += '</div>';

        if (a.identifier_value && a.identifier_label) {
          html += '<div class="appt-drawer-section">';
          html += '<div class="appt-drawer-label">' + escHtml(a.identifier_label) + '</div>';
          html += '<div class="appt-drawer-identifier">' + escHtml(a.identifier_value) + '</div>';
          html += '</div>';
        }

        if (a.items && a.items.length) {
          html += '<div class="appt-drawer-section">';
          html += '<div class="appt-drawer-label">Services</div>';
          a.items.forEach(function(it){
            html += '<div class="appt-drawer-row"><span>' + escHtml(it.name) + '</span><span>' + escHtml(it.price) + '</span></div>';
          });
          if (a.addons && a.addons.length) {
            a.addons.forEach(function(ad){
              html += '<div class="appt-drawer-row"><span class="appt-drawer-row-label">+ ' + escHtml(ad.name) + '</span><span>' + escHtml(ad.price) + '</span></div>';
            });
          }
          html += '</div>';
        }

        html += '<div class="appt-drawer-section">';
        html += '<div class="appt-drawer-row" style="font-weight:500;padding-top:4px"><span>Total</span><span>' + escHtml(a.total_formatted) + '</span></div>';
        html += '</div>';

        bodyEl.innerHTML = html;
      })
      .catch(function(){
        bodyEl.innerHTML = '<div class="appt-drawer-loading">Network error.</div>';
      });
  }

  // Public API
  window.ApptDrawer = {
    open:  loadDrawer,
    close: closeDrawer,
  };
})();
</script>
@endpush
@endonce
