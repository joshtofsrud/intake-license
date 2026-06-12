@extends('layouts.tenant.app')
@php $pageTitle = 'Rental Desk'; @endphp

{{-- MARKER-PATCH-217 / MARKER-PATCH-218 / MARKER-PATCH-219 / MARKER-PATCH-222
     — the live view from the rental mockup (views.rentDash). --}}

@push('styles')
<style>
  .rd-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:22px; }
  .rd-grid-3 { display:grid; grid-template-columns:repeat(3, 1fr); gap:12px; }
  @media (max-width: 980px) { .rd-grid-2 { grid-template-columns:1fr; } .rd-grid-3 { grid-template-columns:1fr 1fr; } }
  .rd-flex-between { display:flex; align-items:center; justify-content:space-between; gap:10px; }
  .ia-badge--out      { background:#FAEEDA; color:#854F0B; }
  .ia-badge--overdue  { background:#FCEBEB; color:#A32D2D; }
  .ia-badge--healthy  { background:#EAF3DE; color:#3B6D11; }
  .ia-badge--tight    { background:#FAEEDA; color:#854F0B; }
  .ia-badge--maint    { background:#FCEBEB; color:#A32D2D; }
  .rd-mini { padding:14px; border-radius:10px; box-shadow:inset 0 0 0 .5px var(--ia-border); cursor:pointer; text-decoration:none; color:inherit; display:block; }
  .rd-mini:hover { background:var(--ia-surface-2, rgba(127,127,127,.06)); }
  .rd-mini-count { font-size:11.5px; opacity:.6; margin-top:8px; }
  .rd-mini-note  { font-size:11.5px; opacity:.45; }
  .rd-stat-link { text-decoration:none; color:inherit; display:block; }
</style>
@endpush

@section('content')

@include('layouts.tenant._rental-nav', ['active' => 'desk'])

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Rental Desk</h1>
    <p class="ia-page-subtitle">Live view of your rental fleet — what's out, what's due, what's free.</p>
  </div>
  <div style="display:flex;gap:8px">
    <a href="{{ route('tenant.rentals.bookings.create') }}" class="ia-btn ia-btn--primary">New rental</a>
  </div>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>
@endif
@if($errors->any())
  <div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>
@endif

<div class="ia-stats-grid" style="margin-bottom:22px">
  <a class="ia-stat rd-stat-link" href="{{ route('tenant.rentals.bookings.index', ['tab' => 'out']) }}">
    <div class="ia-stat-label">Out right now</div>
    <div class="ia-stat-value">{{ $outNow }}</div>
    <div class="ia-stat-delta">of {{ $rentableUnits }} rentable units</div>
  </a>
  <div class="ia-stat">
    <div class="ia-stat-label">Due back today</div>
    <div class="ia-stat-value">{{ $dueTodayCount }}</div>
    @if($overdueCount > 0)
      <div class="ia-stat-delta down">{{ $overdueCount }} already overdue</div>
    @else
      <div class="ia-stat-delta">nothing overdue</div>
    @endif
  </div>
  <div class="ia-stat">
    <div class="ia-stat-label">Pickups today</div>
    <div class="ia-stat-value">{{ $pickupsTodayCount }}</div>
    <div class="ia-stat-delta">{{ $nextPickupAt ? 'next at ' . tlocal($nextPickupAt) : 'none scheduled' }}</div>
  </div>
  <div class="ia-stat">
    <div class="ia-stat-label">Rental revenue (MTD)</div>
    <div class="ia-stat-value">{{ format_money($mtdRevenueCents) }}</div>
    @if($revenueDeltaPct !== null)
      <div class="ia-stat-delta {{ $revenueDeltaPct >= 0 ? 'up' : 'down' }}">{{ $revenueDeltaPct >= 0 ? '▲' : '▼' }} {{ abs($revenueDeltaPct) }}% vs {{ $prevMonthLabel }}</div>
    @else
      <div class="ia-stat-delta">from the payment ledger</div>
    @endif
  </div>
  <div class="ia-stat">
    <div class="ia-stat-label">Utilization (7d)</div>
    <div class="ia-stat-value">{{ $utilizationPct }}%</div>
    <div class="ia-stat-delta">fleet-wide avg</div>
  </div>
  <div class="ia-stat">
    <div class="ia-stat-label">Held deposits</div>
    <div class="ia-stat-value">{{ format_money($heldDepositCents) }}</div>
    <div class="ia-stat-delta">{{ $heldDepositCount }} active hold{{ $heldDepositCount === 1 ? '' : 's' }}</div>
  </div>
</div>

<div class="rd-grid-2">
  <div class="ia-card" style="padding:0;overflow:hidden">
    <div class="rd-flex-between" style="padding:16px 20px 12px;border-bottom:.5px solid var(--ia-border)">
      <span class="ia-card-title">Due back today &amp; overdue</span>
      <a class="ia-card-action" href="{{ route('tenant.rentals.bookings.index', ['tab' => 'out']) }}" style="text-decoration:none">View all bookings →</a>
    </div>
    @if($dueBack->isEmpty())
      <div style="padding:22px 20px;font-size:12.5px;opacity:.55">Nothing due back today — all clear.</div>
    @else
    <table class="ia-table">
      <thead><tr><th>Customer</th><th>Unit</th><th>Due</th><th>Status</th><th></th></tr></thead>
      <tbody>
        @foreach($dueBack as $r)
          @php
            $late = $r->isOverdue();
            $units = $r->lines->where('kind', 'unit');
            $unitLabel = $units->count() > 1
              ? $units->first()->name_snapshot . ' +' . ($units->count() - 1) . ' more'
              : ($units->first()->name_snapshot ?? '—');
            $lateLabel = '';
            if ($late) {
              $mins = $r->due_at->diffInMinutes(now());
              $lateLabel = $mins >= 60 ? floor($mins / 60) . 'h overdue' : $mins . 'm overdue';
            }
          @endphp
          <tr onclick="window.location='{{ route('tenant.rentals.bookings.show', $r->id) }}'">
            <td>{{ $r->customer?->first_name }} {{ $r->customer?->last_name }}</td>
            <td>{{ $unitLabel }}</td>
            <td class="ia-num">{{ tlocal($r->due_at) }}</td>
            <td>
              @if($late)
                <span class="ia-badge ia-badge--overdue">{{ $lateLabel }}</span>
              @else
                <span class="ia-badge ia-badge--out">Out</span>
              @endif
            </td>
            <td style="text-align:right">
              {{-- MARKER-PATCH-233 — desk returns open the guided flow. --}}
              <a href="{{ route('tenant.rentals.bookings.return.flow', $r->id) }}" onclick="event.stopPropagation()" class="ia-btn {{ $late ? 'ia-btn--primary' : '' }}" style="font-size:11.5px;padding:4px 10px;text-decoration:none">Start return</a>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    @endif
  </div>

  <div class="ia-card" style="padding:0;overflow:hidden">
    <div class="rd-flex-between" style="padding:16px 20px 12px;border-bottom:.5px solid var(--ia-border)">
      <span class="ia-card-title">Upcoming pickups</span>
      <a class="ia-card-action" href="{{ route('tenant.rentals.availability.timeline') }}" style="text-decoration:none">Availability →</a>
    </div>
    @if($pickups->isEmpty())
      <div style="padding:22px 20px;font-size:12.5px;opacity:.55">No reservations starting this week.</div>
    @else
    <table class="ia-table">
      <thead><tr><th>Time</th><th>Customer</th><th>Reserved</th><th></th></tr></thead>
      <tbody>
        @foreach($pickups as $r)
          @php
            $units = $r->lines->where('kind', 'unit');
            $first = $units->first();
            $durLabel = '';
            if ($first) {
              $durLabel = match ($first->rate_mode_snapshot) {
                'hourly'  => $first->duration_units . ' hr' . ($first->duration_units == 1 ? '' : 's'),
                'daily'   => $first->duration_units . ' day' . ($first->duration_units == 1 ? '' : 's'),
                'weekend' => 'weekend',
                default   => '',
              };
            }
            $resLabel = trim(($first->name_snapshot ?? '—')
              . ($units->count() > 1 ? ' +' . ($units->count() - 1) : '')
              . ($durLabel !== '' ? ' · ' . $durLabel : ''));
          @endphp
          <tr onclick="window.location='{{ route('tenant.rentals.bookings.show', $r->id) }}'">
            <td class="ia-num">{{ $r->starts_at->copy()->setTimezone(tenant()->timezone())->isToday() ? tlocal($r->starts_at) : tlocal_datetime($r->starts_at, 'M j, g:i A') }}</td>
            <td>{{ $r->customer?->first_name }} {{ $r->customer?->last_name }}</td>
            <td>{{ $resLabel }}</td>
            <td style="text-align:right">
              {{-- MARKER-PATCH-232 — desk pickups open the guided flow. --}}
              <a href="{{ route('tenant.rentals.bookings.checkout.flow', $r->id) }}" onclick="event.stopPropagation()" class="ia-btn ia-btn--primary" style="font-size:11.5px;padding:4px 10px;text-decoration:none">Check out</a>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    @endif
  </div>
</div>

<div class="ia-card">
  <div class="ia-card-head">
    <span class="ia-card-title">Fleet snapshot</span>
    <a class="ia-card-action" href="{{ route('tenant.rentals.fleet') }}" style="text-decoration:none">Manage fleet →</a>
  </div>
  @if($fleetSnapshot->isEmpty())
    <div class="ia-empty" style="padding:36px;text-align:center">
      <div class="ia-empty-title">Your fleet starts here</div>
      <div class="ia-empty-body" style="margin-top:6px">Add rental categories and units, then this desk lights up with live availability.</div>
      <p style="margin-top:14px"><a href="{{ route('tenant.rentals.fleet') }}" class="ia-btn ia-btn--primary">Set up your fleet</a></p>
    </div>
  @else
    <div class="rd-grid-3">
      @foreach($fleetSnapshot as $cat)
        <a class="rd-mini" href="{{ route('tenant.rentals.fleet') }}">
          <div class="rd-flex-between">
            <span style="font-size:13px;font-weight:600">{{ $cat['name'] }}</span>
            <span class="ia-badge ia-badge--{{ $cat['badge'] }}">{{ $cat['label'] }}</span>
          </div>
          <div class="rd-mini-count">{{ $cat['total'] }} unit{{ $cat['total'] === 1 ? '' : 's' }}</div>
          <div class="rd-mini-note">
            @if($cat['maint'] > 0)
              {{ $cat['maint'] }} in maintenance · {{ $cat['avail'] }} available
            @else
              {{ $cat['avail'] }} available now
            @endif
          </div>
        </a>
      @endforeach
    </div>
  @endif
</div>

@endsection
