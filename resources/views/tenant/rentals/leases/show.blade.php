@extends('layouts.tenant.app')
@php $pageTitle = 'Lease ' . $lease->lease_number; @endphp

{{-- MARKER-PATCH-230 — lease detail. Returns/condition checks land in 231. --}}

@section('content')

@include('layouts.tenant._rental-nav', ['active' => 'leases'])

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">{{ $lease->lease_number }}</h1>
    <p class="ia-page-subtitle">{{ $lease->package_name_snapshot }}</p>
  </div>
  <a href="{{ route('tenant.rentals.leases.index') }}" class="ia-btn">All leases</a>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>
@endif

<div style="display:grid;grid-template-columns:1.4fr 1fr;gap:16px">
  <div class="ia-card" style="padding:18px 20px">
    <h2 class="ia-h3" style="margin-bottom:12px">Assigned units</h2>
    @forelse($lease->assignments as $a)
      <div style="display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:.5px solid var(--ia-border)">
        <div>
          <div style="font-size:13.5px;font-weight:550">{{ $a->unit_name_snapshot }}</div>
          <div style="font-size:11.5px;opacity:.5">{{ $a->category_name_snapshot }}{{ $a->unit_serial_snapshot ? ' · ' . $a->unit_serial_snapshot : '' }}</div>
        </div>
        <span class="ia-badge {{ $a->returned_at ? 'ia-badge--paid' : 'ia-badge--confirmed' }}">{{ $a->returned_at ? 'Returned' : 'Out' }}</span>
      </div>
    @empty
      <p style="font-size:13px;opacity:.5">No units assigned.</p>
    @endforelse
  </div>

  <div class="ia-card" style="padding:18px 20px">
    <h2 class="ia-h3" style="margin-bottom:12px">Details</h2>
    <div style="display:flex;flex-direction:column;gap:8px;font-size:13px">
      <div style="display:flex;justify-content:space-between"><span style="opacity:.55">Customer</span><span>{{ trim(($lease->customer->first_name ?? '') . ' ' . ($lease->customer->last_name ?? '')) ?: '—' }}</span></div>
      <div style="display:flex;justify-content:space-between"><span style="opacity:.55">Season</span><span>{{ tlocal_date($lease->season_start) }} → {{ tlocal_date($lease->season_end) }}</span></div>
      <div style="display:flex;justify-content:space-between"><span style="opacity:.55">Status</span><span>{{ $lease->isOverdue() ? 'Overdue' : ucfirst($lease->status) }}</span></div>
      <div style="border-top:.5px solid var(--ia-border);margin-top:6px;padding-top:10px;display:flex;justify-content:space-between"><span style="opacity:.55">Season total</span><span style="font-weight:600">{{ format_money($lease->total_cents) }}</span></div>
      <div style="display:flex;justify-content:space-between"><span style="opacity:.55">Paid</span><span>{{ format_money($lease->paid_cents) }}</span></div>
      <div style="display:flex;justify-content:space-between"><span style="opacity:.55">Balance</span><span>{{ format_money($lease->balanceDueCents()) }}</span></div>
      <div style="display:flex;justify-content:space-between"><span style="opacity:.55">Deposit hold</span><span>{{ format_money($lease->deposit_hold_cents) }} · {{ $lease->deposit_status }}</span></div>
    </div>
  </div>
</div>

@endsection
