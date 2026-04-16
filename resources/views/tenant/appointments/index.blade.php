@extends('layouts.tenant.app')
@php
  $pageTitle = 'Appointments';
  $statusLabels = [
    'pending'     => 'Pending',
    'confirmed'   => 'Confirmed',
    'in_progress' => 'In progress',
    'completed'   => 'Completed',
    'shipped'     => 'Shipped',
    'closed'      => 'Closed',
    'cancelled'   => 'Cancelled',
    'refunded'    => 'Refunded',
  ];
@endphp

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Appointments</h1>
    <p class="ia-page-subtitle">Every booking, every status.</p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.appointments.index') }}?view=new" class="ia-btn ia-btn--primary">
      + New appointment
    </a>
  </div>
</div>

{{-- Filter toolbar --}}
<form method="get" action="{{ route('tenant.appointments.index') }}" class="ia-toolbar">
  <input type="search" name="s" class="ia-input" value="{{ $search }}"
    placeholder="Search RA#, name, email…" style="max-width:260px">

  <select name="status" class="ia-input" style="width:auto">
    <option value="">All statuses</option>
    @foreach($statusLabels as $val => $label)
      <option value="{{ $val }}" @selected($status === $val)>{{ $label }}</option>
    @endforeach
  </select>

  <select name="payment" class="ia-input" style="width:auto">
    <option value="">All payments</option>
    <option value="unpaid"  @selected($payment === 'unpaid')>Unpaid</option>
    <option value="partial" @selected($payment === 'partial')>Partial</option>
    <option value="paid"    @selected($payment === 'paid')>Paid</option>
  </select>

  <input type="date" name="date_from" class="ia-input" value="{{ $dateFrom }}"
    style="width:auto" title="From date">
  <input type="date" name="date_to" class="ia-input" value="{{ $dateTo }}"
    style="width:auto" title="To date">

  <button type="submit" class="ia-btn ia-btn--secondary">Filter</button>
  @if($search || $status || $payment || $dateFrom || $dateTo)
    <a href="{{ route('tenant.appointments.index') }}" class="ia-btn ia-btn--ghost">Reset</a>
  @endif
</form>

<p class="ia-result-count">
  <strong>{{ number_format($total) }}</strong> {{ Str::plural('appointment', $total) }}
</p>

@if($appointments->isEmpty())
  <div class="ia-empty">
    <div class="ia-empty-icon">
      <svg width="20" height="20" viewBox="0 0 20 20" fill="none" style="opacity:.4">
        <rect x="2" y="4" width="16" height="13" rx="2" stroke="currentColor" stroke-width="1.4"/>
        <path d="M7 4V2M13 4V2M2 8h16" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
      </svg>
    </div>
    <div class="ia-empty-title">No appointments found</div>
    <div class="ia-empty-desc">
      @if($search || $status || $payment)
        Try adjusting your filters.
      @else
        When customers book, they'll appear here.
      @endif
    </div>
    @if(!$search && !$status && !$payment)
      <a href="{{ route('tenant.appointments.index') }}?view=new" class="ia-btn ia-btn--primary">
        + New appointment
      </a>
    @endif
  </div>

@else

  <div class="ia-table-wrap">
    <table class="ia-table">
      <thead>
        <tr>
          <th>RA #</th>
          <th>Customer</th>
          <th>Date</th>
          <th>Status</th>
          <th>Payment</th>
          <th class="ia-num">Total</th>
        </tr>
      </thead>
      <tbody>
        @foreach($appointments as $appt)
          @php $url = route('tenant.appointments.show', $appt->id); @endphp
          <tr onclick="window.location='{{ $url }}'">
            <td>
              <a href="{{ $url }}" style="font-weight:500;color:inherit">
                {{ $appt->ra_number }}
              </a>
            </td>
            <td>
              <div style="font-weight:500">{{ $appt->customerName() }}</div>
              <div class="ia-muted-cell" style="font-size:12px">{{ $appt->customer_email }}</div>
            </td>
            <td class="ia-muted-cell">
              {{ $appt->appointment_date->format('M j, Y') }}
            </td>
            <td>
              <span class="ia-badge ia-badge--{{ str_replace('_','-',$appt->status) }}">
                {{ $statusLabels[$appt->status] ?? $appt->status }}
              </span>
            </td>
            <td>
              <span class="ia-badge ia-badge--{{ $appt->payment_status }}">
                {{ ucfirst($appt->payment_status) }}
              </span>
            </td>
            <td class="ia-num">{{ format_money($appt->total_cents) }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  {{-- Pagination --}}
  @if($totalPages > 1)
    <div class="ia-pagination">
      @for($p = 1; $p <= $totalPages; $p++)
        <a href="{{ route('tenant.appointments.index', array_merge(request()->query(), ['page' => $p])) }}"
           class="ia-page-btn {{ $p === $page ? 'active' : '' }}">
          {{ $p }}
        </a>
      @endfor
    </div>
  @endif

@endif

@endsection
