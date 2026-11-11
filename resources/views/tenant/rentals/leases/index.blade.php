@extends('layouts.tenant.app')
@php $pageTitle = 'Leases'; @endphp

{{-- MARKER-RENTAL-LEASES-STUDIO: the lease book in the Studio design. --}}

@section('mobile-fab', 'new-rental')
@include('layouts.tenant._rental-styles')

@section('content')
@php
  $cur   = $status ?? '';
  $tabs  = ['active' => 'Active', 'overdue' => 'Overdue', 'returned' => 'Returned', '' => 'All'];
  $tones = ['--sd-t1', '--sd-t2', '--sd-t3', '--sd-t4'];
  $ini   = fn ($n) => mb_strtoupper(mb_substr(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), preg_split('/\s+/', trim((string) $n)))), 0, 2)) ?: '?';
@endphp

<div class="sd rf" id="sd">
  <header class="sd-head" id="sdHead">
    <div class="sd-head-l">
      <h1 class="sd-hi">Leases</h1>
      <p class="sd-sub">Season-long leases — what's out, with whom, until when.</p>
    </div>
    <div class="sd-head-r">
      <a href="{{ route('tenant.rentals.leases.packages') }}" class="ia-btn ia-btn--sm">Packages</a>
      <a href="{{ route('tenant.rentals.leases.create') }}" class="ia-btn ia-btn--sm ia-btn--primary">New lease</a>
    </div>
  </header>

  @include('layouts.tenant._rental-nav', ['active' => 'leases'])

  @if(session('flash'))<div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>@endif

  <div class="rf-ctl sd-in">
    <div class="sd-seg" role="tablist" aria-label="Show">
      @foreach($tabs as $k => $label)
        <a href="{{ route('tenant.rentals.leases.index', $k === '' ? [] : ['status' => $k]) }}" class="{{ $cur === $k ? 'on' : '' }}" role="tab">{{ $label }} <em>{{ $counts[$k] ?? 0 }}</em></a>
      @endforeach
    </div>
  </div>

  <div class="sd-list sd-in">
    @if($leases->isEmpty())
      <div class="sd-empty">
        @if($cur === 'overdue') Nothing overdue — every leased unit is back on time.
        @elseif($cur === 'returned') No returned leases yet.
        @elseif($cur === 'active') No active leases.
        @else No leases yet. Create one from a package to get started.
        @endif
        <div class="sd-empty-acts">
          <a href="{{ route('tenant.rentals.leases.create') }}" class="ia-btn ia-btn--sm ia-btn--primary">New lease</a>
          <a href="{{ route('tenant.rentals.leases.packages') }}" class="ia-btn ia-btn--sm">Packages</a>
          @if($cur !== '')<a href="{{ route('tenant.rentals.leases.index') }}" class="ia-btn ia-btn--sm">See all leases</a>@endif
        </div>
      </div>
    @else
      <div class="ls-h"><span></span><span>Customer</span><span>Package</span><span>Units</span><span>Season</span><span>Status</span><span style="text-align:right">Total</span></div>
      @foreach($leases as $lease)
        @php
          $who  = trim(($lease->customer->first_name ?? '') . ' ' . ($lease->customer->last_name ?? '')) ?: '—';
          $late = $lease->isOverdue();
          [$pc, $pl] = $late ? ['late', 'Overdue'] : match ($lease->status) {
              'returned'  => ['done', 'Returned'],
              'cancelled' => ['off', 'Cancelled'],
              default     => ['out', ucfirst($lease->status)],
          };
          $url = route('tenant.rentals.leases.show', $lease->id);
        @endphp
        <div class="ls-r" data-href="{{ $url }}">
          <div class="sd-av" style="--c:var({{ $tones[crc32((string) ($lease->customer_id ?? $lease->id)) % 4] }})">{{ $ini($who) }}</div>
          <div class="ls-who"><a href="{{ $url }}" class="rf-brow-link"><b>{{ $who }}</b></a><small>{{ $lease->lease_number }}</small></div>
          <div class="ls-pk"><b>{{ $lease->package_name_snapshot }}</b></div>
          <span class="ls-count">{{ (int) $lease->assignments_count }} {{ $lease->assignments_count == 1 ? 'unit' : 'units' }}</span>
          <span class="ls-end {{ $late ? 'late' : '' }}">Ends {{ tlocal_date($lease->season_end) }}</span>
          <span class="ls-st"><span class="rf-st {{ $pc }}">{{ $pl }}</span></span>
          <span class="amt">{{ format_money($lease->total_cents) }}</span>
        </div>
      @endforeach
      <div class="sd-end in"><i></i><span>{{ $leases->hasMorePages() ? 'More on the next page' : 'End of the list' }}</span><i></i></div>
    @endif
  </div>
  @if($leases->hasPages())<div style="margin-top:16px">{{ $leases->links() }}</div>@endif

  <p class="sd-legend"><b>Overdue</b> means the season ended and units are still out. A lease holds its units for the whole season, so they show as lease bars on Availability and can't be booked as rentals. Total is the season price; payments are taken in the register.</p>
</div>
@endsection

@push('scripts')
@include('layouts.tenant._rental-boot')
<script>
document.addEventListener('click', function (e) {
  var row = e.target.closest('.ls-r[data-href]');
  if (row && !e.target.closest('a, button')) location.href = row.getAttribute('data-href');
});
</script>
@endpush
