@extends('layouts.tenant.app')
@php $pageTitle = 'Availability'; @endphp

{{-- MARKER-PATCH-223 — fleet-wide availability timeline (mockup rentAvail). --}}

@push('styles')
<style>
  .avail-legend { display:flex; align-items:center; gap:18px; margin-bottom:16px; flex-wrap:wrap; }
  .avail-legend span { display:inline-flex; align-items:center; gap:6px; font-size:11.5px; opacity:.75; }
  .avail-chip { width:14px; height:10px; border-radius:3px; display:inline-block; }
  .avail-timeline { border-radius:12px; box-shadow:inset 0 0 0 .5px var(--ia-border); overflow:hidden; background:var(--ia-surface); }
  .avail-row { display:grid; grid-template-columns:220px 1fr; border-bottom:.5px solid var(--ia-border); }
  .avail-row:last-child { border-bottom:none; }
  .avail-row-head { font-size:11px; text-transform:uppercase; letter-spacing:.06em; opacity:.6; }
  .avail-unit { padding:10px 16px; display:flex; align-items:center; gap:10px; border-right:.5px solid var(--ia-border); min-width:0; }
  .avail-unit-name { font-size:13px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .avail-unit-sub { font-size:11px; opacity:.5; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .avail-track { position:relative; display:flex; min-height:46px; user-select:none; }
  .avail-day { flex:1; border-right:.5px solid var(--ia-border); }
  .avail-day:last-child { border-right:none; }
  .avail-day.weekend { background:rgba(127,127,127,.05); }
  .avail-day.is-dragover { background:rgba(190,242,100,.18); }
  .avail-bar { position:absolute; top:7px; bottom:7px; border-radius:6px; color:#fff; font-size:11px; font-weight:600;
               display:flex; align-items:center; padding:0 8px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
               cursor:pointer; z-index:2; text-decoration:none; }
  .avail-bar.out      { background:#185FA5; }
  .avail-bar.reserved { background:#B8801A; }
  .avail-bar.overdue  { background:#A32D2D; }
  .avail-bar.maint    { background:#534AB7; }
  .avail-pills { display:flex; gap:6px; flex-wrap:wrap; }
  .avail-pill { font-size:11.5px; padding:4px 10px; border-radius:999px; box-shadow:inset 0 0 0 .5px var(--ia-border);
                cursor:pointer; text-decoration:none; color:inherit; opacity:.7; }
  .avail-pill.is-active { background:var(--ia-text); color:var(--ia-bg, #fff); opacity:1; }
</style>
@endpush

@section('content')

@include('layouts.tenant._rental-nav', ['active' => 'availability'])

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Availability</h1>
    <p class="ia-page-subtitle">Fleet-wide timeline. Drag across empty days to create a reservation, or click a bar to open it.</p>
  </div>
  <div style="display:flex;gap:8px;align-items:center">
    <a href="{{ route('tenant.rentals.availability.timeline', array_filter(['start' => $prevStart, 'category' => $categoryId])) }}" class="ia-btn" title="Previous week">‹</a>
    <a href="{{ route('tenant.rentals.availability.timeline', array_filter(['category' => $categoryId])) }}" class="ia-btn">Today</a>
    <a href="{{ route('tenant.rentals.availability.timeline', array_filter(['start' => $nextStart, 'category' => $categoryId])) }}" class="ia-btn" title="Next week">›</a>
    <span style="font-size:12.5px;opacity:.65;margin:0 6px;white-space:nowrap">{{ $rangeLabel }}</span>
    <a href="{{ route('tenant.rentals.bookings.create') }}" class="ia-btn ia-btn--primary" style="white-space:nowrap">New reservation</a>
  </div>
</div>

<div class="avail-legend">
  <span><span class="avail-chip" style="background:#185FA5"></span> Out / in use</span>
  <span><span class="avail-chip" style="background:#B8801A"></span> Reserved</span>
  <span><span class="avail-chip" style="background:#A32D2D"></span> Overdue</span>
  <span><span class="avail-chip" style="background:#534AB7"></span> Maintenance</span>
  <span style="margin-left:auto" class="avail-pills">
    <a class="avail-pill {{ $categoryId ? '' : 'is-active' }}" href="{{ route('tenant.rentals.availability.timeline', array_filter(['start' => request('start')])) }}">All</a>
    @foreach($categories as $cat)
      <a class="avail-pill {{ $categoryId === $cat->id ? 'is-active' : '' }}"
         href="{{ route('tenant.rentals.availability.timeline', array_filter(['start' => request('start'), 'category' => $cat->id])) }}">{{ $cat->name }}</a>
    @endforeach
  </span>
</div>

<div class="avail-timeline" id="avail-timeline" data-win-start="{{ $winStart->toDateString() }}">
  <div class="avail-row avail-row-head">
    <div class="avail-unit" style="padding:9px 16px">Unit</div>
    <div class="avail-track" style="min-height:auto">
      @foreach($days as $d)
        <div class="avail-day {{ $d['weekend'] ? 'weekend' : '' }}" style="padding:9px 0;text-align:center">{{ $d['label'] }}</div>
      @endforeach
    </div>
  </div>

  @forelse($rows as $row)
    <div class="avail-row">
      <div class="avail-unit">
        <div style="min-width:0">
          <div class="avail-unit-name">{{ $row['name'] }}</div>
          <div class="avail-unit-sub">{{ $row['sub'] }}</div>
        </div>
      </div>
      <div class="avail-track" data-unit="{{ $row['id'] }}">
        @foreach($days as $i => $d)
          <div class="avail-day {{ $d['weekend'] ? 'weekend' : '' }}" data-day="{{ $i }}"></div>
        @endforeach
        @foreach($row['bars'] as $bar)
          <a class="avail-bar {{ $bar['type'] }}" href="{{ $bar['href'] }}"
             style="left:{{ $bar['left'] }}%;width:{{ $bar['width'] }}%"
             title="{{ $bar['label'] }}">{{ $bar['label'] }}</a>
        @endforeach
      </div>
    </div>
  @empty
    <div style="padding:36px;text-align:center;font-size:13px;opacity:.6">No units in this view — add some in <a href="{{ route('tenant.rentals.fleet') }}">Fleet</a>.</div>
  @endforelse
</div>

<p style="font-size:11.5px;opacity:.45;margin-top:14px">
  Availability here is computed the same way booking does it — advisory locks per tenant re-verify every unit inside the
  critical section, so an online reservation and a desk booking can never double-book a unit.
  Showing {{ $unitsShown }} unit{{ $unitsShown === 1 ? '' : 's' }}.
</p>

<script>
(function () {
  // MARKER-PATCH-223 — drag across empty days on a unit row -> New Rental
  // prefilled with the unit + window (pickup 9:00 AM, return 5:00 PM).
  var createUrl = '{{ route('tenant.rentals.bookings.create') }}';
  var winStart  = document.getElementById('avail-timeline').getAttribute('data-win-start');

  var drag = null; // { unit, startDay, endDay, track }

  function dayPlus(base, days) {
    var d = new Date(base + 'T00:00:00');
    d.setDate(d.getDate() + days);
    var p = function (n) { return (n < 10 ? '0' : '') + n; };
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
  }

  function paint() {
    document.querySelectorAll('.avail-day.is-dragover').forEach(function (el) { el.classList.remove('is-dragover'); });
    if (!drag) return;
    var lo = Math.min(drag.startDay, drag.endDay), hi = Math.max(drag.startDay, drag.endDay);
    drag.track.querySelectorAll('.avail-day').forEach(function (el) {
      var d = parseInt(el.getAttribute('data-day'), 10);
      if (d >= lo && d <= hi) el.classList.add('is-dragover');
    });
  }

  document.querySelectorAll('.avail-track[data-unit]').forEach(function (track) {
    track.addEventListener('mousedown', function (e) {
      var cell = e.target.closest('.avail-day');
      if (!cell || e.target.closest('.avail-bar')) return;
      drag = { unit: track.getAttribute('data-unit'), startDay: parseInt(cell.getAttribute('data-day'), 10),
               endDay: parseInt(cell.getAttribute('data-day'), 10), track: track };
      paint();
      e.preventDefault();
    });
    track.addEventListener('mousemove', function (e) {
      if (!drag || drag.track !== track) return;
      var cell = e.target.closest('.avail-day');
      if (!cell) return;
      drag.endDay = parseInt(cell.getAttribute('data-day'), 10);
      paint();
    });
  });

  document.addEventListener('mouseup', function () {
    if (!drag) return;
    var lo = Math.min(drag.startDay, drag.endDay), hi = Math.max(drag.startDay, drag.endDay);
    var starts = dayPlus(winStart, lo) + 'T09:00';
    var due    = dayPlus(winStart, hi) + 'T17:00';
    var unit   = drag.unit;
    drag = null;
    paint();
    window.location = createUrl + '?starts=' + encodeURIComponent(starts) + '&due=' + encodeURIComponent(due) + '&unit=' + encodeURIComponent(unit);
  });
})();
</script>

@endsection
