@extends('layouts.tenant.app')
@php $pageTitle = 'Rental Bookings'; @endphp

{{-- MARKER-PATCH-219 --}}

@section('content')

@include('layouts.tenant._rental-nav', ['active' => 'bookings'])

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Rental Bookings</h1>
    <p class="ia-page-subtitle">Reservations, what's out the door, and what's come back.</p>
  </div>
  <div style="display:flex;gap:8px">
    <a href="{{ route('tenant.rentals.bookings.create') }}" class="ia-btn ia-btn--primary">New rental</a>
  </div>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>
@endif

<div style="display:flex;gap:8px;margin-bottom:16px">
  <a href="{{ route('tenant.rentals.bookings.index', ['tab' => 'out']) }}" class="ia-btn {{ $tab === 'out' ? 'ia-btn--primary' : '' }}">Out now ({{ $counts['out'] }})</a>
  <a href="{{ route('tenant.rentals.bookings.index', ['tab' => 'upcoming']) }}" class="ia-btn {{ $tab === 'upcoming' ? 'ia-btn--primary' : '' }}">Upcoming ({{ $counts['upcoming'] }})</a>
  <a href="{{ route('tenant.rentals.bookings.index', ['tab' => 'past']) }}" class="ia-btn {{ $tab === 'past' ? 'ia-btn--primary' : '' }}">Past ({{ $counts['past'] }})</a>
</div>

<div class="ia-card" style="padding:0;overflow:hidden">
  @if($rentals->isEmpty())
    <div class="ia-empty" style="padding:40px;text-align:center">
      <div class="ia-empty-title">Nothing here</div>
      <div class="ia-empty-body" style="margin-top:6px">
        @if($tab === 'out') Nothing is out right now.
        @elseif($tab === 'upcoming') No upcoming reservations.
        @else No returned or cancelled rentals yet.
        @endif
      </div>
    </div>
  @else
    @foreach($rentals as $r)
      @php $late = $r->isOverdue(); @endphp
      <a href="{{ route('tenant.rentals.bookings.show', $r->id) }}"
         style="display:grid;grid-template-columns:110px 1.4fr 1.6fr 1fr 1fr 90px;gap:12px;align-items:center;padding:12px 18px;border-bottom:0.5px solid var(--ia-border);text-decoration:none;color:inherit">
        <span style="font-size:12px;opacity:.6">{{ $r->rental_number }}</span>
        <span style="font-size:13.5px;font-weight:600;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $r->customer?->first_name }} {{ $r->customer?->last_name }}</span>
        <span style="font-size:12.5px;opacity:.7;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $r->lines->where('kind','unit')->pluck('name_snapshot')->take(3)->implode(', ') }}</span>
        <span style="font-size:12px;opacity:.65">{{ tlocal_datetime($r->starts_at, 'M j, g:i A') }}</span>
        <span style="font-size:12px;{{ $late ? 'color:#ef4444;font-weight:700' : 'opacity:.65' }}">{{ tlocal_datetime($r->due_at, 'M j, g:i A') }}</span>
        <span style="font-size:11.5px;font-weight:700;text-align:right;{{ $late ? 'color:#ef4444' : ($r->status === 'out' ? 'color:#f59e0b' : ($r->status === 'returned' ? 'color:#34d399' : ($r->status === 'cancelled' ? 'color:#ef4444' : 'opacity:.6'))) }}">
          {{ $late ? 'OVERDUE' : strtoupper($r->status) }}
        </span>
      </a>
    @endforeach
  @endif
</div>

@endsection
