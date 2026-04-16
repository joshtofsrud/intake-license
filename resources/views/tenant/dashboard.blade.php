@extends('layouts.tenant.app')
@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Dashboard</h1>
    <p class="ia-page-subtitle">{{ $currentTenant->name }} · {{ now()->format('l, F j') }}</p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route(.tenant.appointments.index.) }}" class="ia-btn ia-btn--primary">
      + New appointment
    </a>
  </div>
</div>

{{-- Stats --}}
<div class="ia-stats-grid">
  <div class="ia-stat">
    <div class="ia-stat-label">Today's jobs</div>
    <div class="ia-stat-value">{{ $stats['today'] }}</div>
    @if($stats['today_open'] > 0)
      <div class="ia-stat-delta">{{ $stats['today_open'] }} still open</div>
    @endif
  </div>
  <div class="ia-stat">
    <div class="ia-stat-label">This week</div>
    <div class="ia-stat-value">{{ $stats['week'] }}</div>
    @if($stats['week_delta'] !== null)
      <div class="ia-stat-delta {{ $stats['week_delta'] >= 0 ? 'up' : 'down' }}">
        {{ $stats['week_delta'] >= 0 ? '+' : '' }}{{ $stats['week_delta'] }} vs last week
      </div>
    @endif
  </div>
  <div class="ia-stat">
    <div class="ia-stat-label">Revenue (MTD)</div>
    <div class="ia-stat-value">{{ format_money($stats['revenue_mtd']) }}</div>
    @if($stats['revenue_delta'] !== null)
      <div class="ia-stat-delta {{ $stats['revenue_delta'] >= 0 ? 'up' : 'down' }}">
        {{ $stats['revenue_delta'] >= 0 ? '+' : '' }}{{ $stats['revenue_delta'] }}% vs last month
      </div>
    @endif
  </div>
  <div class="ia-stat">
    <div class="ia-stat-label">Open jobs</div>
    <div class="ia-stat-value">{{ $stats['open'] }}</div>
    @if($stats['ready_pickup'] > 0)
      <div class="ia-stat-delta up">{{ $stats['ready_pickup'] }} ready for pickup</div>
    @endif
  </div>
</div>

{{-- Recent appointments --}}
<div class="ia-card">
  <div class="ia-card-head">
    <span class="ia-card-title">Recent appointments</span>
    <a href="{{ route('tenant.appointments.index') }}" class="ia-card-action">View all →</a>
  </div>

  @if($recentAppointments->isEmpty())
    <div class="ia-empty">
      <div class="ia-empty-title">No appointments yet</div>
      <div class="ia-empty-desc">When customers book, they'll appear here.</div>
      <a href="{{ route(.tenant.appointments.index.) }}" class="ia-btn ia-btn--primary">
        + New appointment
      </a>
    </div>
  @else
    <div class="ia-table-wrap" style="margin: 0 -24px -20px; border-radius: 0 0 var(--ia-r-lg) var(--ia-r-lg); overflow: hidden;">
      <table class="ia-table">
        <thead>
          <tr>
            <th>Customer</th>
            <th>Date</th>
            <th>Status</th>
            <th>Payment</th>
            <th class="ia-num">Total</th>
          </tr>
        </thead>
        <tbody>
          @foreach($recentAppointments as $appt)
            <tr onclick="window.location='{{ route('tenant.appointments.show', $appt->id) }}'">
              <td>
                <div style="font-weight:500">{{ $appt->customerName() }}</div>
                <div style="font-size:12px;opacity:.5">{{ $appt->customer_email }}</div>
              </td>
              <td class="ia-muted-cell">{{ $appt->appointment_date->format('M j') }}</td>
              <td>
                <span class="ia-badge ia-badge--{{ str_replace('_', '-', $appt->status) }}">
                  {{ ucwords(str_replace('_', ' ', $appt->status)) }}
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
  @endif
</div>

@endsection
