@extends('layouts.tenant.app')
@php $pageTitle = 'Lease ' . $lease->lease_number; @endphp

{{-- MARKER-RENTAL-LEASE-SHOW-STUDIO: lease detail in the Studio design.
     Returns/condition checks for leased units still aren't part of this page. --}}

@section('mobile-fab', 'new-rental')
@include('layouts.tenant._rental-styles')

@section('content')
@php
  $who   = trim(($lease->customer->first_name ?? '') . ' ' . ($lease->customer->last_name ?? '')) ?: '—';
  $late  = $lease->isOverdue();
  [$pc, $pl] = $late ? ['late', 'Overdue'] : match ($lease->status) {
      'returned'  => ['done', 'Returned'],
      'cancelled' => ['off', 'Cancelled'],
      default     => ['out', ucfirst($lease->status)],
  };
  $units = $lease->assignments;
  $outN  = $units->whereNull('returned_at')->count();
  $bal   = $lease->balanceDueCents();
  $tones = [1, 2];
  $ini   = fn ($n) => mb_strtoupper(mb_substr(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), preg_split('/\s+/', trim((string) $n)))), 0, 2)) ?: '?';
  $tn    = ['--sd-t1', '--sd-t2', '--sd-t3', '--sd-t4'];
@endphp

<div class="sd rf" id="sd">
  <header class="sd-head" id="sdHead">
    <div class="sd-head-l">
      <div class="rf-crumb"><a href="{{ route('tenant.rentals.leases.index') }}">Leases</a> / {{ $lease->lease_number }}</div>
      <h1 class="sd-hi">{{ $lease->lease_number }}<span class="rf-hpill"><span class="rf-st {{ $pc }}">{{ $pl }}</span></span></h1>
      <p class="sd-sub">{{ $lease->package_name_snapshot }} · {{ $who }}</p>
    </div>
    <div class="sd-head-r"><a href="{{ route('tenant.rentals.leases.index') }}" class="ia-btn ia-btn--sm">All leases</a></div>
  </header>

  @include('layouts.tenant._rental-nav', ['active' => 'leases'])

  @if(session('flash'))<div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>@endif

  <div class="dash-row">
    <div class="dash-col" style="grid-column:span 8">
      <section class="rf-blk sd-in">
        <div class="sd-sec-h"><div><h2>Assigned units</h2><span>{{ $outN }} of {{ $units->count() }} still out</span></div></div>
        @forelse($units as $a)
          <div class="ls-line">
            <div class="rf-thumb" style="background:linear-gradient(135deg,color-mix(in srgb,var({{ $tn[crc32((string) $a->category_name_snapshot) % 4] }}) 70%,var(--ia-surface)),color-mix(in srgb,var({{ $tn[(crc32((string) $a->category_name_snapshot) + 1) % 4] }}) 35%,var(--ia-surface)));color:var(--sd-ink)">{{ $ini($a->unit_name_snapshot) }}</div>
            <div><b>{{ $a->unit_name_snapshot }}</b><small>{{ $a->category_name_snapshot }}@if($a->unit_serial_snapshot) · <span class="rf-mono">{{ $a->unit_serial_snapshot }}</span>@endif</small></div>
            <span class="rf-st {{ $a->returned_at ? 'done' : 'out' }}">{{ $a->returned_at ? 'Returned' : 'Out' }}</span>
          </div>
        @empty
          <div class="sd-empty">No units assigned.</div>
        @endforelse
      </section>
    </div>
    <div class="dash-col" style="grid-column:span 4">
      <aside class="sd-rail rf-rail sd-in" style="--d:100ms">
        <div class="rf-rh"><b>Details</b></div>
        <dl class="rf-kv">
          <dt>Customer</dt><dd>{{ $who }}</dd>
          <dt>Season</dt><dd>{{ tlocal_date($lease->season_start) }} → {{ tlocal_date($lease->season_end) }}</dd>
          <dt>Status</dt><dd>{{ $pl }}</dd>
        </dl>
        <div class="rf-rh"><b>Money</b></div>
        <dl class="rf-tot">
          <dt class="big">Season total</dt><dd>{{ format_money($lease->total_cents) }}</dd>
          <dt>Paid</dt><dd>{{ format_money($lease->paid_cents) }}</dd>
          <dt class="{{ $bal > 0 ? 'owe' : '' }}">Balance</dt><dd>{{ format_money($bal) }}</dd>
          <dt>Deposit hold</dt><dd>{{ format_money($lease->deposit_hold_cents) }} · {{ $lease->deposit_status }}</dd>
        </dl>
      </aside>
    </div>
  </div>

  <p class="sd-legend">A lease keeps its units out for the whole season. Returns and condition checks for leased units aren't part of this page yet, so a unit comes back by being cleared in Fleet.</p>
</div>
@endsection

@push('scripts')
@include('layouts.tenant._rental-boot')
@endpush
