@extends('layouts.tenant.app')
@push('styles')
  <link rel="stylesheet" href="{{ asset('css/tenant/dashboard.css') }}">
@endpush
@section('content')

@php
  $greetingWord = "Good {$greeting['time_of_day']}";
  $greetingLine = $greeting['name'] ? "{$greetingWord}, {$greeting['name']}." : "{$greetingWord}.";
@endphp

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">{{ $greetingLine }}</h1>
    <p class="ia-page-subtitle">{{ $greeting['date_long'] }}</p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.appointments.index') }}" class="ia-btn ia-btn--primary">
      + New appointment
    </a>
  </div>
</div>

@if(!empty($workOrderBanner))
<div class="wof-dashboard-banner" id="wof-banner" style="background:var(--ia-accent-soft);border-left:2px solid var(--ia-accent);border-radius:var(--ia-r-md);padding:16px 20px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:flex-start;gap:16px">
  <div style="flex:1">
    <div style="font-size:14px;font-weight:500;margin-bottom:4px">{{ $workOrderBanner['title'] }}</div>
    <div style="font-size:13px;opacity:.75;line-height:1.5">{{ $workOrderBanner['body'] }}</div>
  </div>
  <div style="display:flex;gap:8px;flex-shrink:0">
    <a href="{{ $workOrderBanner['cta_url'] }}" class="ia-btn ia-btn--primary ia-btn--sm">{{ $workOrderBanner['cta_label'] }}</a>
    <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="wof-banner-dismiss">Dismiss</button>
  </div>
</div>

@push('scripts')
<script>
(function(){
  var btn = document.getElementById('wof-banner-dismiss');
  var banner = document.getElementById('wof-banner');
  if (!btn || !banner) return;
  btn.addEventListener('click', function(){
    var fd = new FormData();
    fd.append('_token', window.IntakeAdmin.csrfToken);
    fetch('{{ route("tenant.dashboard.wof-banner.dismiss") }}', {
      method: 'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(){ banner.style.display = 'none'; });
  });
})();
</script>
@endpush
@endif

@include('tenant.dashboard._zone_today')
@include('tenant.dashboard._zone_attention')
@include('tenant.dashboard._zone_growth')

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
  var drawer = document.getElementById('appt-drawer');
  var closeBtn = document.getElementById('drawer-close');
  var closeBtn2 = document.getElementById('drawer-close-2');
  var fullLink = document.getElementById('drawer-fullview');
  var raEl = document.getElementById('drawer-ra');
  var titleEl = document.getElementById('drawer-title');
  var bodyEl = document.getElementById('drawer-body');

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

  function loadDrawer(apptId) {
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
        fullLink.href = a.full_url;

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

  // Intercept appointment row clicks in Zone 1
  document.addEventListener('click', function(e){
    var row = e.target.closest('.ia-dash-today-row');
    if (!row) return;
    e.preventDefault();
    var href = row.getAttribute('href');
    if (!href) return;
    var match = href.match(/\/appointments\/([a-f0-9-]+)/i);
    if (!match) { window.location.href = href; return; }
    loadDrawer(match[1]);
  });
})();
</script>
@endpush


@endsection

@push('scripts')
<script>
(function(){
  'use strict';

  var strip = document.getElementById('ia-date-strip');
  var panel = document.getElementById('ia-day-panel');
  if (!strip || !panel) return;

  var dayUrl = '{{ route("tenant.dashboard.day") }}';

  function escHtml(s) {
    return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function updateStripCounts(stripData) {
    stripData.forEach(function(d){
      var el = document.querySelector('[data-count-for="' + d.date + '"]');
      if (el) el.textContent = d.count > 0 ? d.count : '·';
    });
  }

  function renderAppointments(data) {
    if (data.appointment_count === 0) {
      panel.innerHTML = '<div class="ia-card" style="margin-top:0"><div class="ia-card-head"><span class="ia-card-title">' + escHtml(data.target_date_long) + '</span></div><p style="font-size:13px;opacity:.4;padding:8px 0">No appointments on this day.</p></div>';
      return;
    }

    var html = '<div class="ia-card" style="margin-top:0">';
    html += '<div class="ia-card-head">';
    html += '<span class="ia-card-title">' + escHtml(data.target_date_long) + ' · ' + data.appointment_count + ' ' + (data.appointment_count === 1 ? 'appointment' : 'appointments') + '</span>';
    html += '<a href="{{ route("tenant.appointments.index") }}" class="ia-card-action">Open calendar →</a>';
    html += '</div>';
    html += '<div class="ia-dash-today-list">';

    data.appointments.forEach(function(a){
      html += '<a href="' + escHtml(a.url) + '" class="ia-dash-today-row">';
      html += '<div class="ia-dash-today-time">';
      if (a.time_hm) {
        html += '<div class="ia-dash-today-time-hm">' + escHtml(a.time_hm) + '</div>';
        html += '<div class="ia-dash-today-time-ap">' + escHtml(a.time_ap);
        if (a.duration) html += ' · ' + a.duration + ' min';
        html += '</div>';
      } else {
        html += '<div class="ia-dash-today-time-hm">Drop-off</div>';
        html += '<div class="ia-dash-today-time-ap">' + escHtml(a.receiving) + '</div>';
      }
      html += '</div>';
      html += '<div class="ia-dash-today-main">';
      html += '<div class="ia-dash-today-service">' + escHtml(a.first_item) + '</div>';
      html += '<div class="ia-dash-today-customer">' + escHtml(a.customer_name) + ' · ' + escHtml(a.total_formatted) + '</div>';
      html += '</div>';
      html += '<div class="ia-dash-today-status">';
      html += '<span class="ia-badge ia-badge--' + escHtml(a.status_class) + '">' + escHtml(a.status_label) + '</span>';
      if (a.payment_status !== 'unpaid') {
        html += '<span class="ia-badge ia-badge--' + escHtml(a.payment_status) + '" style="margin-left:4px">' + escHtml(a.payment_status_label) + '</span>';
      }
      html += '</div>';
      html += '</a>';
    });

    html += '</div></div>';
    panel.innerHTML = html;
  }

  function selectDate(dateStr) {
    strip.querySelectorAll('.ia-dash-date-chip').forEach(function(c){
      c.classList.remove('is-target');
      c.style.background = 'transparent';
    });
    var active = strip.querySelector('[data-date="' + dateStr + '"]');
    if (active) {
      active.classList.add('is-target');
      active.style.background = 'var(--ia-accent-soft)';
    }

    panel.innerHTML = '<div style="padding:24px;text-align:center;opacity:.5;font-size:13px">Loading…</div>';

    fetch(dayUrl + '?date=' + encodeURIComponent(dateStr), {
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function(r){ return r.json(); })
      .then(function(resp){
        if (!resp.ok) { panel.innerHTML = '<div style="padding:24px;text-align:center;opacity:.5">Could not load day.</div>'; return; }
        updateStripCounts(resp.strip);
        renderAppointments(resp);
      })
      .catch(function(){
        panel.innerHTML = '<div style="padding:24px;text-align:center;opacity:.5">Network error.</div>';
      });
  }

  // Load initial strip counts for all 7 days
  (function loadInitialCounts(){
    var today = new Date().toISOString().slice(0,10);
    fetch(dayUrl + '?date=' + today, {
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function(r){ return r.json(); })
      .then(function(resp){ if (resp.ok) updateStripCounts(resp.strip); });
  })();

  strip.addEventListener('click', function(e){
    var chip = e.target.closest('.ia-dash-date-chip');
    if (!chip) return;
    var date = chip.getAttribute('data-date');
    if (date) selectDate(date);
  });
})();
</script>
@endpush
