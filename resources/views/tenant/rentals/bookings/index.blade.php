@extends('layouts.tenant.app')
@php $pageTitle = 'Rental Bookings'; @endphp

{{-- MARKER-PATCH-219, rebuilt by MARKER-PATCH-234 — triage-first list:
     search + filters on every tab, "Needs attention" pinned first. --}}

@section('content')

@include('layouts.tenant._rental-nav', ['active' => 'bookings'])

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Rental Bookings</h1>
    <p class="ia-page-subtitle">Every rental, one pipeline. Overdue floats to the top, always.</p>
  </div>
  <div style="display:flex;gap:8px">
    <a href="{{ route('tenant.rentals.bookings.create') }}" class="ia-btn ia-btn--primary">New rental</a>
  </div>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>
@endif

<form method="GET" action="{{ route('tenant.rentals.bookings.index') }}" style="display:flex;gap:10px;align-items:center;margin-bottom:14px;flex-wrap:wrap">
  <input type="hidden" name="tab" value="{{ $tab }}">
  <input type="text" name="q" value="{{ $q }}" placeholder="Search customer, rental #, unit…" class="ia-input" style="flex:1 1 240px;width:auto;min-width:200px">
  <select name="category" class="ia-input" style="width:auto;flex:0 0 auto" onchange="this.form.submit()">
    <option value="">All categories</option>
    @foreach($categories as $cat)
      <option value="{{ $cat->id }}" {{ $category === (string) $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
    @endforeach
  </select>
  <select name="when" class="ia-input" style="width:auto;flex:0 0 auto" onchange="this.form.submit()">
    <option value="">Any date</option>
    <option value="today" {{ $when === 'today' ? 'selected' : '' }}>Today</option>
    <option value="week" {{ $when === 'week' ? 'selected' : '' }}>Next 7 days</option>
  </select>
  <button type="submit" class="ia-btn">Search</button>
  @if($q !== '' || $category !== '' || $when !== '')
    <a href="{{ route('tenant.rentals.bookings.index', ['tab' => $tab]) }}" class="ia-btn" style="opacity:.7">Clear</a>
  @endif
</form>

@php $keep = array_filter(['q' => $q, 'category' => $category, 'when' => $when], fn ($v) => $v !== ''); @endphp
<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
  <a href="{{ route('tenant.rentals.bookings.index', $keep + ['tab' => 'attention']) }}" class="ia-btn {{ $tab === 'attention' ? 'ia-btn--primary' : '' }}">Needs attention ({{ $counts['attention'] }})</a>
  <a href="{{ route('tenant.rentals.bookings.index', $keep + ['tab' => 'out']) }}" class="ia-btn {{ $tab === 'out' ? 'ia-btn--primary' : '' }}">Out ({{ $counts['out'] }})</a>
  <a href="{{ route('tenant.rentals.bookings.index', $keep + ['tab' => 'upcoming']) }}" class="ia-btn {{ $tab === 'upcoming' ? 'ia-btn--primary' : '' }}">Upcoming ({{ $counts['upcoming'] }})</a>
  <a href="{{ route('tenant.rentals.bookings.index', $keep + ['tab' => 'done']) }}" class="ia-btn {{ $tab === 'done' ? 'ia-btn--primary' : '' }}">Done ({{ $counts['done'] }})</a>
  <a href="{{ route('tenant.rentals.bookings.index', $keep + ['tab' => 'all']) }}" class="ia-btn {{ $tab === 'all' ? 'ia-btn--primary' : '' }}">All</a>
</div>

<div class="ia-card" style="padding:0;overflow:hidden">
  @if($rentals->isEmpty())
    <div class="ia-empty" style="padding:40px;text-align:center">
      <div class="ia-empty-title">Nothing here</div>
      <div class="ia-empty-body" style="margin-top:6px">
        @if($q !== '' || $category !== '' || $when !== '') Nothing matches those filters.
        @elseif($tab === 'attention') Nothing needs you — no overdue rentals, no unpaid pickups today.
        @elseif($tab === 'out') Nothing is out right now.
        @elseif($tab === 'upcoming') No upcoming reservations.
        @else No rentals yet.
        @endif
      </div>
    </div>
  @else
    <div style="display:grid;grid-template-columns:100px 1.3fr 1.5fr 1fr 1fr 90px 130px;gap:12px;padding:10px 18px;border-bottom:.5px solid var(--ia-border);font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;opacity:.45">
      <span>Rental</span><span>Customer</span><span>Units</span><span>Out</span><span>Due</span><span style="text-align:right">Balance</span><span>Status</span>
    </div>
    @foreach($rentals as $r)
      @php
        $late = $r->isOverdue();
        $bal  = max(0, (int) $r->total_cents - (int) $r->paid_cents);
        $units = $r->lines->where('kind', 'unit');
      @endphp
      <a href="{{ route('tenant.rentals.bookings.show', $r->id) }}"
         style="display:grid;grid-template-columns:100px 1.3fr 1.5fr 1fr 1fr 90px 130px;gap:12px;align-items:center;padding:12px 18px;border-bottom:0.5px solid var(--ia-border);text-decoration:none;color:inherit">
        <span style="font-size:12px;opacity:.6;font-family:var(--ia-font-mono,monospace)">{{ $r->rental_number }}</span>
        <span style="font-size:13.5px;font-weight:600;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $r->customer?->fullName() }}</span>
        <span style="font-size:12.5px;opacity:.7;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $units->pluck('name_snapshot')->take(2)->implode(', ') }}{{ $units->count() > 2 ? ' +' . ($units->count() - 2) : '' }}</span>
        <span style="font-size:12px;opacity:.65">{{ tlocal_datetime($r->starts_at, 'M j, g:i A') }}</span>
        <span style="font-size:12px;{{ $late ? 'color:#ef4444;font-weight:700' : 'opacity:.65' }}">{{ tlocal_datetime($r->due_at, 'M j, g:i A') }}</span>
        <span style="font-size:12.5px;text-align:right;{{ $bal > 0 ? 'color:#E0A82E;font-weight:600' : 'opacity:.45' }}">{{ $bal > 0 ? format_money($bal) : '—' }}</span>
        <span>@include('tenant.rentals._status-pill', ['rental' => $r])</span>
      </a>
    @endforeach
  @endif
</div>
<p style="font-size:11.5px;opacity:.45;margin-top:12px">"Needs attention" = overdue, or balance due on a pickup starting today. Showing up to 200 rows — narrow with search if you need more history.</p>

@endsection
