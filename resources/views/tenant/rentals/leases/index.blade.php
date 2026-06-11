@extends('layouts.tenant.app')
@php $pageTitle = 'Leases'; @endphp

{{-- MARKER-PATCH-230 — the lease book. --}}

@section('content')

@include('layouts.tenant._rental-nav', ['active' => 'leases'])

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Leases</h1>
    <p class="ia-page-subtitle">Season-long leases — what's out, with whom, until when.</p>
  </div>
  <div style="display:flex;gap:8px">
    <a href="{{ route('tenant.rentals.leases.packages') }}" class="ia-btn">Packages</a>
    <a href="{{ route('tenant.rentals.leases.create') }}" class="ia-btn ia-btn--primary">+ New lease</a>
  </div>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>
@endif

@if($leases->isEmpty())
  <div class="ia-card" style="padding:40px;text-align:center">
    <p style="font-size:14px;opacity:.6;margin-bottom:16px">No leases yet. Create one from a package to get started.</p>
    <a href="{{ route('tenant.rentals.leases.create') }}" class="ia-btn ia-btn--primary">+ New lease</a>
  </div>
@else
  <div class="ia-card" style="padding:0;overflow:hidden">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
      <thead>
        <tr style="text-align:left;border-bottom:.5px solid var(--ia-border);font-size:11px;text-transform:uppercase;letter-spacing:.05em;opacity:.55">
          <th style="padding:10px 16px">Lease</th>
          <th style="padding:10px 16px">Customer</th>
          <th style="padding:10px 16px">Package</th>
          <th style="padding:10px 16px">Season ends</th>
          <th style="padding:10px 16px">Status</th>
          <th style="padding:10px 16px;text-align:right">Total</th>
        </tr>
      </thead>
      <tbody>
        @foreach($leases as $lease)
          <tr style="border-bottom:.5px solid var(--ia-border);cursor:pointer" onclick="window.location='{{ route('tenant.rentals.leases.show', $lease->id) }}'">
            <td style="padding:11px 16px;font-family:var(--ia-font-mono);font-size:12px">{{ $lease->lease_number }}</td>
            <td style="padding:11px 16px">{{ trim(($lease->customer->first_name ?? '') . ' ' . ($lease->customer->last_name ?? '')) ?: '—' }}</td>
            <td style="padding:11px 16px">{{ $lease->package_name_snapshot }}</td>
            <td style="padding:11px 16px">{{ tlocal_date($lease->season_end) }}</td>
            <td style="padding:11px 16px">
              @php
                $overdue = $lease->isOverdue();
                $cls = $overdue ? 'ia-badge--unpaid' : ($lease->status === 'returned' ? 'ia-badge--paid' : ($lease->status === 'cancelled' ? 'ia-badge--unpaid' : 'ia-badge--confirmed'));
              @endphp
              <span class="ia-badge {{ $cls }}">{{ $overdue ? 'Overdue' : ucfirst($lease->status) }}</span>
            </td>
            <td style="padding:11px 16px;text-align:right;font-variant-numeric:tabular-nums">{{ format_money($lease->total_cents) }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  <div style="margin-top:16px">{{ $leases->links() }}</div>
@endif

@endsection
