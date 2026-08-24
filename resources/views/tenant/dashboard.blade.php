@extends('layouts.tenant.app')
@push('styles')
  <link rel="stylesheet" href="{{ asset('css/tenant/dashboard.css') }}?v={{ filemtime(public_path('css/tenant/dashboard.css')) }}">
  {{-- MARKER-PATCH-110-STEP-10a --}}
  <link rel="stylesheet" href="{{ asset('css/tenant/dashboard-tiles.css') }}?v={{ filemtime(public_path('css/tenant/dashboard-tiles.css')) }}">
@endpush
@section('mobile-fab', 'walk-in')

@section('content')

@php
  $greetingWord = "Good {$greeting['time_of_day']}";
  $greetingLine = $greeting['name'] ? "{$greetingWord}, {$greeting['name']}." : "{$greetingWord}.";
@endphp

{{-- DASH-MOBILE v1 — mobile-only hero + at-a-glance stats. Hidden on desktop. --}}
<div class="ia-dash-mobile-only">
  {{-- 3-stat row --}}
  <div class="ia-dash-m-stats">
    <div class="ia-dash-m-stat">
      <div class="ia-dash-m-stat-num">{{ $today['today_count'] }}</div>
      <div class="ia-dash-m-stat-lbl">Today</div>
    </div>
    <div class="ia-dash-m-stat">
      <div class="ia-dash-m-stat-num">{{ format_money($today['week_revenue_cents']) }}</div>
      <div class="ia-dash-m-stat-lbl">Wk revenue</div>
    </div>
    <div class="ia-dash-m-stat">
      <div class="ia-dash-m-stat-num">{{ $today['week_new_customers'] }}</div>
      <div class="ia-dash-m-stat-lbl">New cust.</div>
    </div>
  </div>

  {{-- Next-up hero card. Renders only when there's a next_up appointment with a time. --}}
  @php
    $nu = $today['next_up'] ?? null;
    $nuTime = ($nu && $nu->appointment_time)
      ? \Carbon\Carbon::parse($nu->appointment_time)
      : null;
    $nuMinutesAway = null;
    if ($nuTime) {
      try {
        // MARKER-PATCH-361 — appointment_time is naive tenant-local wall-clock;
        // parse it in the tenant timezone so the countdown isn't offset by the
        // UTC delta (which made the banner appear/hide at the wrong times).
        $tz = tenant()?->timezone() ?? config('app.timezone', 'UTC');
        $nuStart = \Carbon\Carbon::parse($nu->appointment_date->toDateString() . ' ' . $nu->appointment_time, $tz);
        // CARBON3-DIFF-FIX v1: timestamp math instead of diffInMinutes(false)
        // because Carbon 3 returns negative for "$nuStart is later than now",
        // which broke the "In 24 minutes" branch (always fell through to "Next up").
        $nuMinutesAway = (int) round(($nuStart->getTimestamp() - now()->getTimestamp()) / 60);
      } catch (\Throwable $e) { $nuMinutesAway = null; }
    }
    $nuService = $nu && $nu->items->isNotEmpty() ? $nu->items->first()->item_name_snapshot : null;
  @endphp
  @if($nu && $nuTime)
    <a href="{{ route('tenant.appointments.show', $nu->id) }}" class="ia-dash-m-hero">
      <div class="ia-dash-m-hero-when">
        @if($nuMinutesAway !== null && $nuMinutesAway >= 0 && $nuMinutesAway < 120)
          @if($nuMinutesAway === 0)
            Right now
          @elseif($nuMinutesAway < 60)
            In {{ $nuMinutesAway }} {{ \Illuminate\Support\Str::plural('minute', $nuMinutesAway) }}
          @else
            In {{ floor($nuMinutesAway / 60) }}h {{ $nuMinutesAway % 60 }}m
          @endif
          · {{ $nuTime->format('g:i A') }}
        @else
          Next up · {{ $nuTime->format('g:i A') }}
        @endif
      </div>
      <div class="ia-dash-m-hero-cust">{{ $nu->customerName() }}</div>
      @if($nuService)
        <div class="ia-dash-m-hero-svc">{{ $nuService }}@if($nu->total_duration_minutes) · {{ $nu->total_duration_minutes }} min @endif</div>
      @endif
      <div class="ia-dash-m-hero-cta">View →</div>
    </a>
  @elseif($today['today_count'] === 0)
    <div class="ia-dash-m-empty">
      No appointments today. Open the calendar to book one.
    </div>
  @endif
</div>

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">{{ $greetingLine }}</h1>
    {{-- MARKER-PATCH-110-STEP-10c --}}
    <p class="ia-page-subtitle">
      <strong>{{ $greeting['date_long'] }}</strong>
      @php $attentionCount = $attention['total_items'] ?? 0; @endphp
      @if($attentionCount > 0)
        · <span style="color:#F59E0B;font-weight:600">{{ $attentionCount }} {{ Str::plural('thing', $attentionCount) }} {{ $attentionCount === 1 ? 'needs' : 'need' }} you today</span>
      @else
        · <span style="color:var(--ia-accent);font-weight:600">all caught up · enjoy the calm</span>
      @endif
    </p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.register.index') }}" class="ia-btn ia-btn--primary">
      + New sale
    </a>
    {{-- MARKER-DASH-NEWAPPT — was a bare link to the list, which meant
         hunting for the real button once you got there. --}}
    <a href="{{ route('tenant.appointments.index', ['new' => 1]) }}" class="ia-btn ia-btn--primary">
      + New appointment
    </a>
    {{-- MARKER-DASH-HEAD-MATCH — toggle lives in the page head on both views --}}
    <div class="ia-viewseg" style="display:inline-flex;background:var(--ia-surface);border:1px solid var(--ia-border);border-radius:9px;padding:3px">
      <button type="button" class="on" style="padding:6px 13px;font-size:12px;font-weight:600;border-radius:6px;background:var(--ia-surface-2);color:var(--ia-text);border:none;cursor:pointer;font-family:inherit">Overview</button>
      <form method="POST" action="{{ route('tenant.dashboard.view') }}" style="margin:0;display:inline">
        @csrf<input type="hidden" name="view" value="tiles">
        <button type="submit" style="padding:6px 13px;font-size:12px;font-weight:600;border-radius:6px;background:none;color:var(--ia-text-dim);border:none;cursor:pointer;font-family:inherit">Tiles</button>
      </form>
    </div>
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

{{-- MARKER-PATCH-110-STEP-10b --}}
{{-- MARKER-DASH-HEAD-MATCH — toggle moved into the page head above --}}

@include('tenant.dashboard._zone_triage_tiles')
@include('tenant.dashboard._zone_today_tile')
@include('tenant.dashboard._zone_growth_tiles')
@include('tenant.dashboard._zone_launcher')

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

  // Intercept appointment row clicks in Zone 1 — open drawer instead of navigating
  document.addEventListener('click', function(e){
    var row = e.target.closest('.ia-dash-today-row[data-appt-id]');
    if (!row) return;
    // Let modified clicks (cmd/ctrl/middle-click) open in new tab as normal
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) return;
    e.preventDefault();
    var apptId = row.getAttribute('data-appt-id');
    var fullHref = row.getAttribute('href');
    if (fullLink) fullLink.setAttribute('href', fullHref);
    loadDrawer(apptId);
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
    // Updates the 3-bar load indicator on each day chip. Backend supplies
    // load_level (0-3) computed from appt_count vs. day capacity.
    stripData.forEach(function(d){
      var el = document.querySelector('[data-load-for="' + d.date + '"]');
      if (el && typeof d.load_level !== 'undefined') {
        el.setAttribute('data-level', String(d.load_level));
      }
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
      c.style.borderBottom = '0.5px solid var(--ia-border)';
    });
    var active = strip.querySelector('[data-date="' + dateStr + '"]');
    if (active) {
      active.classList.add('is-target');
      active.style.background = 'var(--ia-surface-2)';
      active.style.borderBottom = '2px solid var(--ia-accent)';
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
