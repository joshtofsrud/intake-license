@extends('layouts.tenant.app')
@php $pageTitle = 'Rental Bookings'; @endphp

{{-- MARKER-RENTAL-BOOKINGS-STUDIO: every rental in one list, in the Studio
     design. Same tabs, filters and search as before; overdue floats to the top. --}}

@section('mobile-fab', 'new-rental')
@include('layouts.tenant._rental-styles')

@section('content')
@php
  $keep = array_filter(['q' => $q, 'category' => $category, 'when' => $when], fn ($v) => $v !== '');
  $tabs = ['attention' => 'Needs you', 'out' => 'Out', 'upcoming' => 'Upcoming', 'done' => 'Done', 'all' => 'All'];
  $tones = ['--sd-t1', '--sd-t2', '--sd-t3', '--sd-t4'];
  $ini = fn ($n) => mb_strtoupper(mb_substr(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), preg_split('/\s+/', trim((string) $n)))), 0, 2)) ?: '?';
  $catName = $category !== '' ? ($categories->firstWhere('id', $category)?->name ?? 'Category') : null;
  $filtered = $q !== '' || $category !== '' || $when !== '';
@endphp

<div class="sd rf" id="sd">
  <header class="sd-head" id="sdHead">
    <div class="sd-head-l">
      <h1 class="sd-hi">Bookings</h1>
      <p class="sd-sub">Every rental in one list. Overdue always comes first.</p>
    </div>
    <div class="sd-head-r">
      <form method="GET" action="{{ route('tenant.rentals.bookings.index') }}" class="rf-sform" role="search">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="search" name="q" value="{{ $q }}" placeholder="Search customer, rental #, unit" aria-label="Search bookings">
        <input type="hidden" name="tab" value="{{ $tab }}">
        @if($category !== '')<input type="hidden" name="category" value="{{ $category }}">@endif
        @if($when !== '')<input type="hidden" name="when" value="{{ $when }}">@endif
      </form>
      <a href="{{ route('tenant.rentals.bookings.create') }}" class="ia-btn ia-btn--sm ia-btn--primary">New rental</a>
    </div>
  </header>

  @include('layouts.tenant._rental-nav', ['active' => 'bookings'])

  @if(session('flash'))<div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>@endif
  @if($errors->any())<div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>@endif

  <div class="rf-ctl sd-in">
    <div class="sd-seg" role="tablist" aria-label="Show">
      @foreach($tabs as $tk => $tl)
        <a href="{{ route('tenant.rentals.bookings.index', $keep + ['tab' => $tk]) }}" class="{{ $tab === $tk ? 'on' : '' }}" role="tab">{{ $tl }} <em>{{ $counts[$tk] ?? 0 }}</em></a>
      @endforeach
    </div>
    {{-- the two filters are native selects dressed as pills, so they work with a thumb --}}
    <form method="GET" action="{{ route('tenant.rentals.bookings.index') }}" class="rf-pills">
      <input type="hidden" name="tab" value="{{ $tab }}">
      @if($q !== '')<input type="hidden" name="q" value="{{ $q }}">@endif
      <label class="rf-pill rf-pillsel {{ $category !== '' ? 'on' : '' }}">{{ $catName ?? 'All categories' }} <i>▾</i>
        <select name="category" onchange="this.form.submit()" aria-label="Category">
          <option value="">All categories</option>
          @foreach($categories as $cat)<option value="{{ $cat->id }}" {{ $category === (string) $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>@endforeach
        </select>
      </label>
      <label class="rf-pill rf-pillsel {{ $when !== '' ? 'on' : '' }}">{{ ['today' => 'Today', 'week' => 'Next 7 days'][$when] ?? 'Any date' }} <i>▾</i>
        <select name="when" onchange="this.form.submit()" aria-label="Date">
          <option value="">Any date</option>
          <option value="today" {{ $when === 'today' ? 'selected' : '' }}>Today</option>
          <option value="week" {{ $when === 'week' ? 'selected' : '' }}>Next 7 days</option>
        </select>
      </label>
      @if($filtered)<a href="{{ route('tenant.rentals.bookings.index', ['tab' => $tab]) }}" class="rf-pill">Clear</a>@endif
      <noscript><button type="submit" class="rf-pill">Apply</button></noscript>
    </form>
  </div>

  <div class="sd-list rf-blist sd-in">
    @if($rentals->isEmpty())
      <div class="sd-empty">
        @if($filtered) Nothing matches those filters.
        @elseif($tab === 'attention') Nothing needs you — nothing overdue and no unpaid pickups today.
        @elseif($tab === 'out') Nothing is out right now.
        @elseif($tab === 'upcoming') No upcoming reservations.
        @elseif($tab === 'done') Nothing finished yet.
        @else No rentals yet.
        @endif
        <div class="sd-empty-acts">
          @if($filtered)<a href="{{ route('tenant.rentals.bookings.index', ['tab' => $tab]) }}" class="ia-btn ia-btn--sm">Clear filters</a>@endif
          <a href="{{ route('tenant.rentals.bookings.create') }}" class="ia-btn ia-btn--sm ia-btn--primary">New rental</a>
          @if($tab !== 'all')<a href="{{ route('tenant.rentals.bookings.index', $keep + ['tab' => 'all']) }}" class="ia-btn ia-btn--sm">See all bookings</a>@endif
        </div>
      </div>
    @else
      <div class="rf-bh"><span></span><span>Customer</span><span>Units</span><span>Window</span><span>Balance</span><span>Status</span><span></span></div>
      @foreach($rentals as $r)
        @php
          $late  = $r->isOverdue();
          $bal   = max(0, (int) $r->total_cents - (int) $r->paid_cents);
          $units = $r->lines->where('kind', 'unit')->values();
          $who   = $r->customer?->fullName() ?: 'Walk-in';
          $tone  = $tones[crc32((string) ($r->customer_id ?? $r->id)) % 4];
          $dueToday = $r->status === 'out' && ! $late && $r->due_at && tlocal($r->due_at, 'Y-m-d') === tnow()->format('Y-m-d');
          if ($r->status === 'reserved')      $act = ['Check out', route('tenant.rentals.bookings.checkout.flow', $r->id), true];
          elseif ($r->status === 'out')       $act = ['Return', route('tenant.rentals.bookings.return.flow', $r->id), $late || $dueToday];
          else                                $act = ['Open', route('tenant.rentals.bookings.show', $r->id), false];
          $showUrl = route('tenant.rentals.bookings.show', $r->id);
        @endphp
        <div class="rf-brow" data-href="{{ $showUrl }}">
          <div class="sd-av" style="--c:var({{ $tone }})">{{ $ini($who) }}</div>
          <div class="sd-who"><a href="{{ $showUrl }}" class="rf-brow-link"><b>{{ $who }}</b></a><small>{{ $r->rental_number }}</small></div>
          <div class="sd-job">
            <span class="rd-first">{{ $units->first()?->name_snapshot ?: '—' }}</span>
            @if($units->count() > 1)
              <button type="button" class="sd-multi" data-multi="{{ json_encode($units->pluck('name_snapshot')->all()) }}">{{ $units->count() }} units</button>
            @endif
          </div>
          <div class="rf-win-t {{ $late ? 'late' : '' }}">{{ tlocal_datetime($r->starts_at, 'M j, g:i A') }} → <b>{{ tlocal_datetime($r->due_at, 'M j, g:i A') }}</b></div>
          <div class="rf-bal {{ $bal > 0 ? '' : 'zero' }}">{{ $bal > 0 ? format_money($bal) : '—' }}</div>
          <div>@include('tenant.rentals._status-pill', ['rental' => $r])</div>
          <a href="{{ $act[1] }}" class="ia-btn ia-btn--sm {{ $act[2] ? 'ia-btn--primary' : '' }}">{{ $act[0] }}</a>
        </div>
      @endforeach
      <div class="sd-end in"><i></i><span>{{ $rentals->count() >= 200 ? 'Showing the latest 200' : 'End of the list' }}</span><i></i></div>
    @endif
  </div>

  <p class="sd-legend"><b>Needs you</b> is anything overdue, plus pickups today that still have a balance due. Showing up to 200 bookings; search to reach older history. Balance is what's left after payments taken in the register.</p>
</div>

<div class="sd-pop" id="sdPop" role="tooltip" hidden></div>
@endsection

@push('scripts')
<script>
(function () {
  'use strict';
  function $$(s, e) { return Array.prototype.slice.call((e || document).querySelectorAll(s)); }
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!reduce) document.documentElement.classList.add('sd-motion');
  function reveal() { var l = innerHeight * 0.92; $$('.sd-in:not(.is-in)').forEach(function (el) { if (el.getBoundingClientRect().top < l) el.classList.add('is-in'); }); }
  reveal(); addEventListener('scroll', reveal, { passive: true }); addEventListener('resize', reveal);
  setTimeout(function () { $$('.sd-in').forEach(function (el) { el.classList.add('is-in'); }); }, 2500);
  var head = document.getElementById('sdHead');
  function frost() { if (head) head.classList.toggle('frost', scrollY > 24 && innerWidth > 1023); }
  addEventListener('scroll', frost, { passive: true }); frost();

  // a row opens the booking; its own links and buttons do their own thing
  document.addEventListener('click', function (e) {
    var row = e.target.closest('.rf-brow');
    if (!row || e.target.closest('a, button, select, input')) return;
    location.href = row.getAttribute('data-href');
  });

  // the "N units" chip lists every unit on the booking
  var pop = document.getElementById('sdPop'), popFor = null;
  function show(btn) {
    var names = []; try { names = JSON.parse(btn.getAttribute('data-multi') || '[]'); } catch (e) {}
    pop.textContent = '';
    var h = document.createElement('h4'); h.textContent = names.length + ' units on this booking'; pop.appendChild(h);
    names.forEach(function (n) { var d = document.createElement('div'); var b = document.createElement('b'); b.textContent = n; d.appendChild(b); pop.appendChild(d); });
    pop.hidden = false; popFor = btn;
    var r = btn.getBoundingClientRect(), w = pop.offsetWidth;
    pop.style.left = Math.max(12, Math.min(innerWidth - w - 12, r.left)) + 'px';
    pop.style.top = (r.bottom + 8) + 'px';
  }
  function hide() { pop.hidden = true; popFor = null; }
  document.addEventListener('click', function (e) {
    var m = e.target.closest('.sd-multi');
    if (m) { e.stopPropagation(); if (popFor === m) hide(); else show(m); return; }
    if (popFor) hide();
  });
  document.addEventListener('mouseover', function (e) { var m = e.target.closest('.sd-multi'); if (m && matchMedia('(hover: hover)').matches) show(m); });
  document.addEventListener('mouseout', function (e) { if (e.target.closest('.sd-multi') && matchMedia('(hover: hover)').matches) hide(); });
  document.addEventListener('focusin', function (e) { var m = e.target.closest('.sd-multi'); if (m) show(m); });
  document.addEventListener('focusout', function (e) { if (e.target.closest('.sd-multi')) hide(); });
  addEventListener('scroll', function () { if (popFor) hide(); }, { passive: true });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hide(); });
})();
</script>
@endpush
