@extends('layouts.tenant.app')
@php $pageTitle = 'Rental Desk'; @endphp

{{-- MARKER-RENTAL-DESK-V2: the rental desk in the Studio design — four tiles,
     the month shaded by how much of the fleet is booked, today's handovers,
     the bookings list with each one's details, and the fleet by category.
     Shares dashboard-studio.css/.js with the Studio dashboard. --}}

@section('mobile-fab', 'new-rental')
@push('styles')
  <link rel="stylesheet" href="{{ asset('css/tenant/dashboard-simple.css') }}?v={{ filemtime(public_path('css/tenant/dashboard-simple.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/tenant/dashboard-studio.css') }}?v={{ filemtime(public_path('css/tenant/dashboard-studio.css')) }}">
@endpush

@section('content')
@php
  $studio = ['tiles' => $desk['tiles']];
  $cal = $desk['calendar']; $ho = $desk['handovers']; $bk = $desk['bookings'];
@endphp
<div class="sd" id="sd" data-sd-root>
  <header class="sd-head" id="sdHead">
    <div class="sd-head-l">
      <h1 class="sd-hi">Rental desk</h1>
      <p class="sd-sub" data-sd-live="summary">{{ $desk['summary'] }}</p>
    </div>
    <div class="sd-head-r">
      <button type="button" class="sd-search" data-ar-search aria-label="Search">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <span>Search customers, bookings, units</span><kbd>⌘K</kbd>
      </button>
      <a href="{{ route('tenant.rentals.bookings.index', ['tab' => 'out']) }}" class="ia-btn ia-btn--sm sd-hide-m">Return a unit</a>
      <a href="{{ route('tenant.rentals.bookings.create') }}" class="ia-btn ia-btn--sm ia-btn--primary sd-hide-m">New rental</a>
    </div>
  </header>

  @include('layouts.tenant._rental-nav', ['active' => 'desk'])

  @if(session('flash'))
    <div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>
  @endif
  @if($errors->any())
    <div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>
  @endif

  @if (! $desk['rentable'] && ! $desk['fleet'])
    <div class="sd-empty" style="padding:40px 0;text-align:center">
      <b style="display:block;font-size:15px;color:var(--ia-text);margin-bottom:6px">Your fleet starts here</b>
      Add rental categories and units, then this desk lights up with what's out, what's due and what's free.
      <div class="sd-empty-acts" style="justify-content:center"><a href="{{ route('tenant.rentals.fleet') }}" class="ia-btn ia-btn--primary">Set up your fleet</a></div>
    </div>
  @else
  <div class="dash-row">
    <div class="dash-col" style="grid-column:span 8">
      @include('tenant.dashboard.blocks.studio-tiles')

      <section class="sd-queue sd-in" style="--d:220ms" data-sd-live="queue">
        <div class="sd-sec-h">
          <div><h2>Bookings</h2><span>Overdue first, then by time</span></div>
          <div class="sd-seg" role="tablist" aria-label="Show">
            <button type="button" class="on" data-f="all">All {{ count($bk['rows']) + $bk['more'] }}</button>
            <button type="button" data-f="due">Due back {{ $bk['counts']['due'] }}</button>
            <button type="button" data-f="late">Overdue {{ $bk['counts']['late'] }}</button>
            <button type="button" data-f="going">Going out {{ $bk['counts']['going'] }}</button>
            <button type="button" data-f="out">Out {{ $bk['counts']['out'] }}</button>
          </div>
        </div>
        @if (! $bk['rows'])
          <div class="sd-empty">Nothing out and nothing booked for the next week.
            <div class="sd-empty-acts"><a class="ia-btn ia-btn--sm ia-btn--primary" href="{{ route('tenant.rentals.bookings.create') }}">New rental</a><a class="ia-btn ia-btn--sm" href="{{ route('tenant.rentals.availability.timeline') }}">See availability</a></div>
          </div>
        @else
          <div class="sd-list" id="sdList">
            @foreach ($bk['rows'] as $i => $r)
              <div class="sd-row rd-row rd-g-{{ $r['group'] }}{{ $i === 0 ? ' sel' : '' }}" data-group="{{ $r['group'] }}{{ in_array($r['group'], ['due', 'late'], true) ? ' out' : '' }}" data-i="{{ $i }}" tabindex="0" role="button" aria-label="{{ $r['who'] }}, {{ $r['title'] }}, {{ $r['status'] }}">
                <div class="sd-av">{{ $r['initials'] }}</div>
                <div class="sd-who"><b>{{ $r['who'] }}</b><small>{{ $r['win'] }}</small></div>
                <div class="sd-job"><span class="rd-first">{{ $r['first'] }}</span>@if (count($r['assets']) > 1) <button type="button" class="sd-multi" data-assets='@json($r['assets'])'>{{ count($r['assets']) }} units</button>@endif</div>
                <div class="rd-when">{{ $r['win'] }}</div>
                <div class="sd-st{{ $r['late'] ? ' late' : '' }}">{{ $r['status'] }}</div>
                <a class="ia-btn ia-btn--sm{{ $r['action']['primary'] ? ' ia-btn--primary' : '' }}" href="{{ $r['action']['url'] }}">{{ $r['action']['label'] }}</a>
              </div>
            @endforeach
            <div class="sd-none" hidden>None here right now.</div>
      <div class="sd-end" data-word="bookings"><i></i><span>End of the list</span><i></i></div>
          </div>
          @if ($bk['more'])
            <a class="sd-link sd-more" href="{{ route('tenant.rentals.bookings.index') }}">{{ $bk['more'] }} more in Bookings →</a>
          @endif
          @php $rdJson = array_map(fn ($r) => \Illuminate\Support\Arr::only($r, ['who', 'number', 'title', 'group', 'tags', 'kv', 'actions', 'units']), $bk['rows']); @endphp
          <script type="application/json" id="sdQueue">@json($rdJson)</script>
          <section class="sd-detail" id="sdDetail" aria-live="polite"></section>
        @endif
      </section>
    </div>

    <div class="dash-col" style="grid-column:span 4">
      <aside class="sd-rail sd-in" style="--d:140ms" data-sd-live="rail">
        <div class="sd-cal">
          <div class="sd-cal-h"><b>{{ $cal['month'] }}</b><a href="{{ route('tenant.rentals.availability.timeline') }}" class="sd-link">Availability →</a></div>
          <div class="sd-cal-g">
            @foreach (['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'] as $w)<div class="w">{{ $w }}</div>@endforeach
            @for ($i = 0; $i < $cal['lead']; $i++)<span></span>@endfor
            @foreach ($cal['days'] as $d)
              <a href="{{ $d['url'] }}" class="{{ $d['today'] ? 't' : '' }}" style="--f:{{ $d['fill'] }}" title="{{ \Illuminate\Support\Carbon::parse($d['date'])->format('l, M j') }}: {{ $d['n'] }} of {{ $desk['rentable'] }} units booked">{{ $d['d'] }}</a>
            @endforeach
          </div>
          <div class="sd-cal-key"><span><i style="--f:.25"></i>Quiet</span><span><i style="--f:.6"></i>Busy</span><span><i style="--f:1"></i>Fully booked</span></div>
        </div>
        <div class="sd-tl-h"><b>Today's handovers</b><span>{{ $ho['left'] ? $ho['left'] . ' still to go' : ($ho['rows'] ? 'all done' : '') }}</span></div>
        @if (! $ho['rows'])
          <div class="sd-empty">No pickups or returns today.</div>
        @else
          <div class="sd-tl">
            @foreach ($ho['rows'] as $h)
              <a class="sd-tl-r {{ $h['state'] }}{{ $h['late'] ? ' late' : '' }}" href="{{ $h['url'] }}">
                <time>{{ $h['time'] }}</time>
                <div>
                  <b>@if ($h['state'] === 'next')<em>Next up</em>@endif<i class="dir {{ $h['dir'] }}" aria-hidden="true">{{ $h['dir'] === 'out' ? '↑' : '↓' }}</i>{{ $h['dir'] === 'out' ? 'Out' : 'Back' }} · {{ $h['who'] }}</b>
                  <span>{{ $h['what'] }}</span>
                </div>
              </a>
            @endforeach
          </div>
        @endif
      </aside>
    </div>
  </div>

  <section class="rd-fleet sd-in" style="--d:300ms" data-sd-live="fleet">
    <div class="sd-sec-h"><div><h2>Fleet</h2><span>What's free right now, by category</span></div><a class="sd-link" href="{{ route('tenant.rentals.fleet') }}">Manage fleet →</a></div>
    @foreach ($desk['fleet'] as $f)
      <a class="rd-f" href="{{ route('tenant.rentals.fleet') }}">
        <div><b>{{ $f['name'] }}</b><small>{{ $f['total'] }} {{ \Illuminate\Support\Str::plural('unit', $f['total']) }}@if ($f['maint']) · {{ $f['maint'] }} in maintenance @endif</small></div>
        <div class="rd-bar" aria-hidden="true"><i class="fr" style="width:{{ round($f['free'] / max(1, $f['total']) * 100, 1) }}%"></i><i class="ou" style="width:{{ round($f['out'] / max(1, $f['total']) * 100, 1) }}%"></i><i class="mt" style="width:{{ round($f['maint'] / max(1, $f['total']) * 100, 1) }}%"></i></div>
        <div class="nums">{{ $f['free'] }} free · {{ $f['out'] }} out</div>
        <div class="rd-tag {{ $f['tag'][0] }}">{{ $f['tag'][1] }}</div>
      </a>
    @endforeach
    <div class="rd-key"><span><i class="fr"></i>Free</span><span><i class="ou"></i>Out</span><span><i class="mt"></i>Maintenance</span></div>
  </section>
  @endif

  <p class="sd-legend">Figures are for today in your shop's time zone and refresh every few minutes while this page is open. Revenue is rental payments taken this month, with refunds taken off.</p>
</div>
<div class="sd-pop" id="sdPop" role="tooltip" hidden></div>
@endsection

@push('scripts')
  <script src="{{ asset('js/tenant/dashboard-studio.js') }}?v={{ filemtime(public_path('js/tenant/dashboard-studio.js')) }}" defer></script>
@endpush
