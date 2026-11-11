@extends('layouts.tenant.app')
@php $pageTitle = 'Availability'; @endphp

{{-- MARKER-RENTAL-AVAILABILITY-STUDIO: fleet-wide timeline in the Studio design.
     Same window, category filter and drag-to-reserve as before; bars now open a
     detail card and the bottom row counts what is free each day. --}}

@section('mobile-fab', 'new-rental')
@include('layouts.tenant._rental-styles')

@section('content')
@php
  $nUnits   = count($rows);
  $nowAt    = $nowPct;
  $isOut    = fn ($r) => $nowAt !== null && collect($r['bars'])->contains(fn ($b) => in_array($b['type'], ['out', 'overdue'], true) && $b['left'] <= $nowAt && ($b['left'] + $b['width']) >= $nowAt);
  $outNow   = $rows->filter($isOut)->count();
  $lateN    = $rows->filter(fn ($r) => collect($r['bars'])->contains(fn ($b) => $b['type'] === 'overdue'))->count();
  $maintN   = $rows->filter(fn ($r) => collect($r['bars'])->contains(fn ($b) => $b['type'] === 'maint'))->count();
  $sub      = $nUnits . ' ' . ($nUnits === 1 ? 'unit' : 'units') . ' · ' . $outNow . ' out now'
            . ($lateN ? ' · ' . $lateN . ' overdue' : '') . ($maintN ? ' · ' . $maintN . ' in maintenance' : '');
  $qs       = fn (array $x) => array_filter($x, fn ($v) => $v !== null && $v !== '');
  $dayCount = count($days);
  // free per day: a unit counts when nothing at all sits on it that day
  $free = [];
  foreach ($days as $i => $d) {
      $lo = $i / $dayCount * 100; $hi = ($i + 1) / $dayCount * 100;
      $free[$i] = $rows->filter(fn ($r) => ! collect($r['bars'])->contains(fn ($b) => $b['left'] < $hi && ($b['left'] + $b['width']) > $lo))->count();
  }
  $lastCat = null;
@endphp

<div class="sd rf" id="sd">
  <header class="sd-head" id="sdHead">
    <div class="sd-head-l">
      <h1 class="sd-hi">Availability</h1>
      <p class="sd-sub">{{ $sub }}</p>
    </div>
    <div class="sd-head-r">
      <div class="av-nav">
        <a href="{{ route('tenant.rentals.availability.timeline', $qs(['start' => $prevStart, 'category' => $categoryId])) }}" class="ia-btn ia-btn--sm" aria-label="Previous week">‹</a>
        <a href="{{ route('tenant.rentals.availability.timeline', $qs(['category' => $categoryId])) }}" class="ia-btn ia-btn--sm">Today</a>
        <a href="{{ route('tenant.rentals.availability.timeline', $qs(['start' => $nextStart, 'category' => $categoryId])) }}" class="ia-btn ia-btn--sm" aria-label="Next week">›</a>
        <span class="av-range">{{ $rangeLabel }}</span>
      </div>
      <a href="{{ route('tenant.rentals.bookings.create') }}" class="ia-btn ia-btn--sm ia-btn--primary">New reservation</a>
    </div>
  </header>

  @include('layouts.tenant._rental-nav', ['active' => 'availability'])

  <div class="rf-ctl sd-in">
    <div class="rf-pills" role="group" aria-label="Category">
      <a class="rf-pill {{ $categoryId ? '' : 'on' }}" href="{{ route('tenant.rentals.availability.timeline', $qs(['start' => request('start')])) }}">All</a>
      @foreach($categories as $cat)
        <a class="rf-pill {{ $categoryId === $cat->id ? 'on' : '' }}" href="{{ route('tenant.rentals.availability.timeline', $qs(['start' => request('start'), 'category' => $cat->id])) }}">{{ $cat->name }}</a>
      @endforeach
    </div>
    <div class="av-key">
      <span><i style="background:var(--sd-t3)"></i>Out</span>
      <span><i style="background:var(--sd-t4)"></i>Reserved</span>
      <span><i style="background:var(--sd-bad)"></i>Overdue</span>
      <span><i style="background:repeating-linear-gradient(135deg,var(--ia-text-dim) 0 3px,transparent 3px 6px)"></i>Maintenance</span>
      @if(tenant()->leases_enabled)<span><i style="background:var(--sd-t1)"></i>Lease</span>@endif
    </div>
  </div>

  <div class="av-scroll sd-in">
    <div class="av" id="avail-timeline" data-win-start="{{ $winStart->toDateString() }}">
      <div class="av-row av-h">
        <div class="av-u">Unit</div>
        <div class="av-days">
          @foreach($days as $d)
            <div class="av-d {{ $d['weekend'] ? 'we' : '' }} {{ $d['today'] ? 'today' : '' }}"><small>{{ $d['dow'] }}</small>{!! $d['today'] ? '<b>' . $d['num'] . '</b>' : e($d['num']) !!}</div>
          @endforeach
        </div>
      </div>

      @forelse($rows as $row)
        @if($row['cat'] !== $lastCat)
          <div class="av-row"><div class="av-cat">{{ $row['cat'] ?: 'Uncategorized' }}</div></div>
          @php $lastCat = $row['cat']; @endphp
        @endif
        <div class="av-row">
          <a class="av-u" href="{{ $row['url'] }}"><b>{{ $row['name'] }}</b><small>{{ $row['sub'] }}</small></a>
          <div class="av-track" data-unit="{{ $row['id'] }}">
            <div class="av-days">
              @foreach($days as $i => $d)<div class="av-d {{ $d['weekend'] ? 'we' : '' }}" data-day="{{ $i }}"></div>@endforeach
            </div>
            @foreach($row['bars'] as $bar)
              <a class="av-bar {{ $bar['type'] }}" href="{{ $bar['href'] }}"
                 style="left:{{ $bar['left'] }}%;width:{{ $bar['width'] }}%"
                 data-who="{{ $bar['who'] ?? $bar['label'] }}" data-ref="{{ $bar['ref'] ?? '' }}" data-when="{{ $bar['when'] ?? '' }}" data-status="{{ $bar['status'] ?? '' }}" data-unit-name="{{ $row['name'] }} {{ $row['sub'] }}"
                 title="{{ $bar['label'] }}">{{ $bar['label'] }}</a>
            @endforeach
            @if($nowPct !== null)<i class="av-now" style="left:{{ $nowPct }}%"></i>@endif
          </div>
        </div>
      @empty
        <div class="sd-empty">
          No units in this view.
          <div class="sd-empty-acts">
            @if($categoryId)<a href="{{ route('tenant.rentals.availability.timeline', $qs(['start' => request('start')])) }}" class="ia-btn ia-btn--sm">Show every category</a>@endif
            <a href="{{ route('tenant.rentals.fleet') }}" class="ia-btn ia-btn--sm ia-btn--primary">Add units in Fleet</a>
          </div>
        </div>
      @endforelse

      @if($nUnits)
        <div class="av-row av-foot">
          <div class="av-u">Free that day</div>
          <div class="av-days">
            @foreach($days as $i => $d)<div class="av-d {{ $free[$i] <= 1 ? 'low' : '' }}">{{ $free[$i] }}</div>@endforeach
          </div>
        </div>
      @endif
    </div>
  </div>

  <p class="av-hint"><span class="mouse">Drag across empty days on a unit to start a reservation for that window, or click a bar to open it.</span><span class="touch">Tap a start day, then an end day, to start a reservation. Tap a bar to open it.</span></p>

  <p class="sd-legend">Bars come from reservations, rentals that are out, @if(tenant()->leases_enabled)season leases @endif and units in maintenance. An overdue rental runs to now, in red, until it comes back. The red line is the current time. “Free that day” counts units with nothing on them at any point that day. Availability is worked out the same way booking does it, and a unit is checked again when the reservation is created, so a desk booking and an online booking can never take the same unit. Showing {{ $unitsShown }} unit{{ $unitsShown === 1 ? '' : 's' }}, up to 60.</p>
</div>

<div class="sd-pop" id="sdPop" role="tooltip" hidden></div>
<div class="rf-toast" id="avToast" hidden></div>
@endsection

@push('scripts')
@include('layouts.tenant._rental-boot')
<script>
(function () {
  'use strict';
  function $$(s, e) { return Array.prototype.slice.call((e || document).querySelectorAll(s)); }
  var createUrl = '{{ route('tenant.rentals.bookings.create') }}';
  var grid = document.getElementById('avail-timeline');
  if (!grid) return;
  var winStart = grid.getAttribute('data-win-start');

  function dayPlus(base, days) {
    var d = new Date(base + 'T00:00:00'); d.setDate(d.getDate() + days);
    var p = function (n) { return (n < 10 ? '0' : '') + n; };
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
  }
  var sel = null, dragging = false; // sel = { unit, a, b, track }
  function paint() {
    $$('.av-d.sel').forEach(function (e) { e.classList.remove('sel'); });
    if (!sel) return;
    var lo = Math.min(sel.a, sel.b), hi = Math.max(sel.a, sel.b);
    $$('.av-d', sel.track).forEach(function (c) { var i = parseInt(c.getAttribute('data-day'), 10); if (i >= lo && i <= hi) c.classList.add('sel'); });
  }
  function finish() {
    if (!sel) return;
    var lo = Math.min(sel.a, sel.b), hi = Math.max(sel.a, sel.b), unit = sel.unit;
    sel = null; paint();
    window.location = createUrl + '?starts=' + encodeURIComponent(dayPlus(winStart, lo) + 'T09:00') + '&due=' + encodeURIComponent(dayPlus(winStart, hi) + 'T17:00') + '&unit=' + encodeURIComponent(unit);
  }
  var toastEl = document.getElementById('avToast'), tt;
  function toast(m) { toastEl.textContent = m; toastEl.hidden = false; clearTimeout(tt); tt = setTimeout(function () { toastEl.hidden = true; }, 2600); }

  $$('.av-track[data-unit]').forEach(function (track) {
    track.addEventListener('pointerdown', function (e) {
      var cell = e.target.closest('.av-d'); if (!cell || e.target.closest('.av-bar')) return;
      var i = parseInt(cell.getAttribute('data-day'), 10), unit = track.getAttribute('data-unit');
      if (e.pointerType === 'touch') {            // phones: tap a start day, then an end day
        if (sel && sel.track === track) { sel.b = i; paint(); finish(); }
        else { sel = { unit: unit, a: i, b: i, track: track }; paint(); toast('Now tap the last day'); }
        return;
      }
      dragging = true; sel = { unit: unit, a: i, b: i, track: track }; paint(); e.preventDefault();
    });
  });
  document.addEventListener('pointermove', function (e) {
    if (!dragging || !sel) return;
    var el = document.elementFromPoint(e.clientX, e.clientY), c = el && el.closest('.av-d');
    if (c && sel.track.contains(c)) { sel.b = parseInt(c.getAttribute('data-day'), 10); paint(); }
  });
  document.addEventListener('pointerup', function () { if (dragging) { dragging = false; finish(); } });

  // hover/focus card on a bar
  var pop = document.getElementById('sdPop');
  function show(b) {
    pop.textContent = '';
    var h = document.createElement('h4'); h.textContent = (b.dataset.ref ? b.dataset.ref + ' · ' : '') + b.dataset.who; pop.appendChild(h);
    [b.dataset.unitName, b.dataset.when, b.dataset.status].forEach(function (t) { if (!t) return; var d = document.createElement('div'); d.textContent = t; pop.appendChild(d); });
    pop.hidden = false;
    var r = b.getBoundingClientRect(), w = pop.offsetWidth;
    pop.style.left = Math.max(12, Math.min(innerWidth - w - 12, r.left)) + 'px'; pop.style.top = (r.bottom + 8) + 'px';
  }
  document.addEventListener('mouseover', function (e) { var b = e.target.closest('.av-bar'); if (b && matchMedia('(hover: hover)').matches) show(b); });
  document.addEventListener('mouseout', function (e) { if (e.target.closest('.av-bar')) pop.hidden = true; });
  document.addEventListener('focusin', function (e) { var b = e.target.closest('.av-bar'); if (b) show(b); });
  document.addEventListener('focusout', function (e) { if (e.target.closest('.av-bar')) pop.hidden = true; });
  addEventListener('scroll', function () { pop.hidden = true; }, { passive: true });
})();
</script>
@endpush
