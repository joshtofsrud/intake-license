@extends('public.account._shell')
@php $pageTitle = 'My Account'; @endphp
{{-- MARKER-PORTAL-V2 --}}
@push('styles')
  @include('public.account.portal._portal-css')
@endpush

@section('content')
@include('public.account.portal._nav', ['active' => 'home'])

<div style="margin-bottom:16px">
  <div style="font-size:22px;font-weight:700;font-family:var(--p-font-heading)">Hi, {{ $customer->first_name }}</div>
  <div style="font-size:14px;opacity:.45;margin-top:2px">{{ $customer->email }}</div>
</div>

@if($nextAppointment)
  @php
    /* appointment_date + appointment_time are naive tenant-local — format,
       never convert (PATCH-361). */
    $naDate = \Carbon\Carbon::parse($nextAppointment->appointment_date)->format('D, M j');
    $naTime = $nextAppointment->appointment_time
        ? \Carbon\Carbon::parse($nextAppointment->appointment_time)->format('g:i A')
        : null;
  @endphp
  <div class="ac-hero">
    <div class="ac-hero-k">Next up</div>
    <div class="ac-hero-t">{{ $nextAppointment->label ?? 'Appointment' }}</div>
    <div class="ac-hero-m">{{ $naDate }}@if($naTime) &middot; {{ $naTime }}@endif</div>
    <div class="ac-hero-actions">
      <a href="{{ route('tenant.customer.portal.messages') }}">Message the shop</a>
      <a href="{{ route('tenant.customer.portal.bookings') }}">All bookings</a>
    </div>
  </div>
@endif

{{-- MARKER-PORTAL-V2 — rental banners --}}
@if($activeRental)
  @php $overdue = $activeRental->due_at && $activeRental->due_at->isPast(); @endphp
  <div class="ac-banner {{ $overdue ? 'ac-banner--overdue' : 'ac-banner--due' }}">
    <span>
      @if($overdue)
        <b>Rental overdue</b> — {{ $activeRental->lines->first()?->name_snapshot ?? 'your rental' }} was due {{ tlocal_datetime($activeRental->due_at, 'D, M j \a\t g:i A') }}
      @else
        <b>Rental due</b> {{ tlocal_datetime($activeRental->due_at, 'D, M j \a\t g:i A') }} — {{ $activeRental->lines->first()?->name_snapshot ?? 'your rental' }}
      @endif
    </span>
    <a href="{{ route('tenant.customer.portal.rentals') }}">Details</a>
  </div>
@endif

@if($upcomingRental)
  <div class="ac-banner ac-banner--soon">
    <span><b>Upcoming rental</b> — {{ $upcomingRental->lines->first()?->name_snapshot ?? 'reserved' }} starts {{ tlocal_datetime($upcomingRental->starts_at, 'D, M j \a\t g:i A') }}</span>
    <a href="{{ route('tenant.customer.portal.rentals') }}">Details</a>
  </div>
@endif

<div class="ac-strip">
  @if($activeRental)
    <div class="ac-chip-card"><div class="ac-chip-k">Active rental</div>
      <div class="ac-chip-v">{{ $activeRental->lines->count() }} {{ \Illuminate\Support\Str::plural('item', $activeRental->lines->count()) }}</div>
      <div class="ac-chip-s">due {{ tlocal($activeRental->due_at, 'D, M j') }}</div></div>
  @endif
  @if($lastMessage)
    <div class="ac-chip-card"><div class="ac-chip-k">Messages</div>
      <div class="ac-chip-v">{{ $lastMessage->direction === 'in' ? 'You wrote' : tenant()->name }}</div>
      <div class="ac-chip-s">{{ tlocal_datetime($lastMessage->created_at, 'M j, g:i A') }}</div></div>
  @endif
</div>

<div class="ac-section-title">Recent activity</div>
<div class="ac-list">
  @if($lastOrder)
    <div class="ac-list-row">
      <div><div class="ac-list-name">Order {{ $lastOrder->order_number }}</div>
        <div class="ac-list-meta">{{ tlocal_date($lastOrder->created_at) }} &middot; {{ $lastOrder->fulfillment_type === 'local_delivery' ? 'delivery' : 'pickup' }}</div></div>
      <div class="ac-list-right"><div style="font-weight:700">${{ number_format($lastOrder->total_cents / 100, 2) }}</div>
        <div style="font-size:11px;opacity:.55;text-transform:capitalize">{{ str_replace('_', ' ', $lastOrder->status) }}</div></div>
    </div>
  @endif
  @if($lastSale)
    <div class="ac-list-row">
      <div><div class="ac-list-name">In-store purchase {{ $lastSale->sale_number ? '#' . $lastSale->sale_number : '' }}</div>
        <div class="ac-list-meta">{{ \Carbon\Carbon::parse($lastSale->sale_date)->format('M j, Y') }}</div></div>
      <div class="ac-list-right"><div style="font-weight:700">${{ number_format($lastSale->total_cents / 100, 2) }}</div></div>
    </div>
  @endif
  @if(!$lastOrder && !$lastSale)
    <div class="ac-empty">Nothing here yet.</div>
  @endif
</div>

<div class="ac-quick">
  <a href="{{ route('tenant.booking') }}">Book service</a>
  <a href="{{ route('tenant.shop.index') }}">Shop online</a>
  <a href="{{ route('tenant.customer.portal.messages') }}">Message us</a>
</div>
@endsection
