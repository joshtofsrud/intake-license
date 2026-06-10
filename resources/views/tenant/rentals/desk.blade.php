@extends('layouts.tenant.app')
@section('title', 'Rental Desk')

{{-- MARKER-PATCH-217 — desk stub: live stat row + empty state. --}}

@push('styles')
<style>
  .rd-h1 { font-size: 28px; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 4px; }
  .rd-sub { color: var(--ia-text-3, #888); font-size: 13.5px; margin-bottom: 24px; }
  .rd-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 24px; }
  .rd-stat { background: var(--ia-surface, #1c1c1c); border: 1px solid var(--ia-border, #2a2a2a); border-radius: 12px; padding: 16px; }
  .rd-stat-label { font-size: 12px; color: var(--ia-text-3, #888); margin-bottom: 6px; }
  .rd-stat-value { font-size: 24px; font-weight: 800; letter-spacing: -0.02em; }
  .rd-stat-note { font-size: 11.5px; color: var(--ia-text-3, #888); margin-top: 4px; }
  .rd-empty { background: var(--ia-surface, #1c1c1c); border: 1px dashed var(--ia-border, #2a2a2a); border-radius: 12px; padding: 40px 24px; text-align: center; }
  .rd-empty h2 { font-size: 16px; font-weight: 700; margin-bottom: 6px; }
  .rd-empty p { font-size: 13px; color: var(--ia-text-3, #888); max-width: 460px; margin: 0 auto; }
</style>
@endpush

@section('content')
{{-- MARKER-PATCH-218 — fleet link in the page head --}}
<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px">
  <div>
    <div class="rd-h1">Rental Desk</div>
    <div class="rd-sub">Live view of your rental fleet — what's out, what's due, what's free.</div>
  </div>
  <div style="display:flex;gap:8px">
    <a href="{{ route('tenant.rentals.bookings.index') }}" class="ia-btn" style="white-space:nowrap">Bookings</a>
    <a href="{{ route('tenant.rentals.fleet') }}" class="ia-btn" style="white-space:nowrap">Manage fleet</a>
    <a href="{{ route('tenant.rentals.bookings.create') }}" class="ia-btn ia-btn--primary" style="white-space:nowrap">New rental</a>
  </div>
</div>

<div class="rd-stats">
  <div class="rd-stat">
    <div class="rd-stat-label">Out right now</div>
    <div class="rd-stat-value">{{ $outNow }}</div>
    <div class="rd-stat-note">of {{ $unitsTotal }} rentable units</div>
  </div>
  <div class="rd-stat">
    <div class="rd-stat-label">Overdue</div>
    <div class="rd-stat-value">{{ $overdue }}</div>
    <div class="rd-stat-note">past their return time</div>
  </div>
  <div class="rd-stat">
    <div class="rd-stat-label">Reserved upcoming</div>
    <div class="rd-stat-value">{{ $reserved }}</div>
    <div class="rd-stat-note">future pickups on the books</div>
  </div>
  <div class="rd-stat">
    <div class="rd-stat-label">Rental revenue (MTD)</div>
    <div class="rd-stat-value">{{ format_money($mtdRevenueCents) }}</div>
    <div class="rd-stat-note">from the payment ledger</div>
  </div>
</div>

{{-- MARKER-PATCH-219 — live desk tables --}}
@if($unitsTotal > 0)
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px">
  <div class="ia-card" style="padding:0;overflow:hidden">
    <div style="padding:12px 16px;border-bottom:0.5px solid var(--ia-border)"><span class="ia-label">Due back</span></div>
    @if($dueBack->isEmpty())
      <div style="padding:22px 16px;font-size:12.5px;opacity:.55">Nothing out right now.</div>
    @else
      @foreach($dueBack as $r)
        @php $late = $r->isOverdue(); @endphp
        <a href="{{ route('tenant.rentals.bookings.show', $r->id) }}" style="display:flex;justify-content:space-between;gap:10px;padding:10px 16px;border-bottom:0.5px solid var(--ia-border);text-decoration:none;color:inherit">
          <span style="font-size:13px;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            {{ $r->customer?->first_name }} {{ $r->customer?->last_name }}
            <span style="opacity:.55">· {{ $r->lines->where('kind','unit')->pluck('name_snapshot')->take(2)->implode(', ') }}</span>
          </span>
          <span style="font-size:12px;white-space:nowrap;{{ $late ? 'color:#ef4444;font-weight:700' : 'opacity:.65' }}">
            {{ $late ? 'Overdue · ' : '' }}{{ tlocal_datetime($r->due_at, 'M j, g:i A') }}
          </span>
        </a>
      @endforeach
    @endif
  </div>
  <div class="ia-card" style="padding:0;overflow:hidden">
    <div style="padding:12px 16px;border-bottom:0.5px solid var(--ia-border)"><span class="ia-label">Upcoming pickups (7 days)</span></div>
    @if($upcoming->isEmpty())
      <div style="padding:22px 16px;font-size:12.5px;opacity:.55">No reservations starting this week.</div>
    @else
      @foreach($upcoming as $r)
        <a href="{{ route('tenant.rentals.bookings.show', $r->id) }}" style="display:flex;justify-content:space-between;gap:10px;padding:10px 16px;border-bottom:0.5px solid var(--ia-border);text-decoration:none;color:inherit">
          <span style="font-size:13px;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            {{ $r->customer?->first_name }} {{ $r->customer?->last_name }}
            <span style="opacity:.55">· {{ $r->lines->where('kind','unit')->pluck('name_snapshot')->take(2)->implode(', ') }}</span>
          </span>
          <span style="font-size:12px;white-space:nowrap;opacity:.65">{{ tlocal_datetime($r->starts_at, 'M j, g:i A') }}</span>
        </a>
      @endforeach
    @endif
  </div>
</div>
@endif

@if($unitsTotal === 0)
<div class="rd-empty">
  <h2>Your fleet starts here</h2>
  <p>Add rental categories with rate cards, then add your units. Reservations from the desk and your public rental site will land on this screen.</p>
  <p style="margin-top:14px"><a href="{{ route('tenant.rentals.fleet') }}" class="ia-btn ia-btn--primary">Set up your fleet</a></p>
</div>
@endif
@endsection
