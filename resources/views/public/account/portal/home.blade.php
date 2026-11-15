@extends('public.account._shell')
@php
  $pageTitle = 'My Account';
  $sym   = tenant()->currency_symbol ?: '$';
  $money = fn ($c) => $sym . number_format(((int) $c) / 100, 2);
  $rentalTile = $activeRental ?: $upcomingRental;
@endphp
@push('styles')
  @include('public.account.portal._portal-css')
@endpush

@section('content')
@include('public.account.portal._nav', ['active' => 'home'])

{{-- MARKER-PORTAL-RENTALS: Studio-style home — greeting, tiles that open
     what they show, then recent activity. Colours come from the shop's site. --}}
<div class="ac-hi">
  <h1>Hi, {{ $customer->first_name }}</h1>
  <p>{{ $customer->email }}</p>
</div>

@if($activeRental && $activeRental->isOverdue())
  <div class="ac-banner ac-banner--overdue">
    <span><b>Rental overdue</b> — {{ $activeRental->lines->first()?->name_snapshot ?? 'your rental' }} was due {{ tlocal_datetime($activeRental->due_at, 'D, M j \a\t g:i A') }}</span>
    <a href="{{ route('tenant.customer.portal.rental', $activeRental->id) }}">Details</a>
  </div>
@endif

<div class="ac-tiles">
  {{-- next booking --}}
  @if($nextAppointment)
    @php
      /* appointment_date + appointment_time are naive tenant-local — format, never convert (PATCH-361). */
      $naDate = \Carbon\Carbon::parse($nextAppointment->appointment_date)->format('D, M j');
      $naTime = $nextAppointment->appointment_time ? \Carbon\Carbon::parse($nextAppointment->appointment_time)->format('g:i A') : null;
    @endphp
    <a class="ac-tile ac-t1" href="{{ route('tenant.customer.portal.work_order', $nextAppointment->id) }}">
      <span class="ac-tile-k">Next up</span>
      <span class="ac-tile-v">{{ $nextAppointment->portalTitle() }}</span>
      <span class="ac-tile-s">{{ $naDate }}@if($naTime) &middot; {{ $naTime }}@endif &middot; {{ \App\Support\AppointmentStatus::LABELS[$nextAppointment->status] ?? ucfirst($nextAppointment->status) }}</span>
    </a>
  @else
    <a class="ac-tile ac-t1" href="{{ route('tenant.booking') }}">
      <span class="ac-tile-k">Bookings</span>
      <span class="ac-tile-v">Nothing booked</span>
      <span class="ac-tile-s">Book a service &rarr;</span>
    </a>
  @endif

  {{-- messages: preview of the last one --}}
  <a class="ac-tile ac-t3" href="{{ route('tenant.customer.portal.messages') }}">
    <span class="ac-tile-k">Messages @if($lastMessage && $lastMessage->direction !== 'in')<i class="ac-dot" title="{{ tenant()->name }} wrote last"></i>@endif</span>
    @if($lastMessage)
      <span class="ac-tile-p">{{ \Illuminate\Support\Str::limit($lastMessage->body, 110) }}</span>
      <span class="ac-tile-s">{{ $lastMessage->direction === 'in' ? 'You' : tenant()->name }} &middot; {{ tlocal_datetime($lastMessage->created_at, 'M j, g:i A') }}</span>
    @else
      <span class="ac-tile-v">No messages yet</span>
      <span class="ac-tile-s">Ask us anything &rarr;</span>
    @endif
  </a>

  {{-- rental out or coming up --}}
  @if($rentalTile)
    <a class="ac-tile ac-t2" href="{{ route('tenant.customer.portal.rental', $rentalTile->id) }}">
      <span class="ac-tile-k">{{ $activeRental ? 'Rental out' : 'Upcoming rental' }}</span>
      <span class="ac-tile-v">{{ $rentalTile->lines->first()?->name_snapshot ?? 'Rental' }}@if($rentalTile->lines->count() > 1) + {{ $rentalTile->lines->count() - 1 }}@endif</span>
      <span class="ac-tile-s">{{ $activeRental ? 'Due back ' . tlocal_datetime($rentalTile->due_at, 'D, M j · g:i A') : 'Starts ' . tlocal_datetime($rentalTile->starts_at, 'D, M j · g:i A') }}</span>
    </a>
  @endif

  {{-- balance owing on work orders --}}
  @if($openBalance > 0)
    <a class="ac-tile ac-t4" href="{{ $balanceUrl }}">
      <span class="ac-tile-k">Balance due</span>
      <span class="ac-tile-v">{{ $money($openBalance) }}</span>
      <span class="ac-tile-s">On your work orders &rarr;</span>
    </a>
  @endif
</div>

<div class="ac-section-title">Recent activity</div>
<div class="ac-list">
  @if($lastOrder)
    <a class="ac-list-row" href="{{ route('tenant.order.confirmation', $lastOrder->token) }}">
      <div><div class="ac-list-name">Order {{ $lastOrder->order_number }}</div>
        <div class="ac-list-meta">{{ tlocal_date($lastOrder->created_at) }} &middot; {{ $lastOrder->fulfillment_type === 'ship' ? 'shipping' : ($lastOrder->fulfillment_type === 'local_delivery' ? 'delivery' : 'pickup') }}</div></div>
      <div class="ac-list-right"><div style="font-weight:700">{{ $money($lastOrder->total_cents) }}</div>
        <div style="font-size:11px;opacity:.55;text-transform:capitalize">{{ str_replace('_', ' ', $lastOrder->status) }}</div></div>
    </a>
  @endif
  @if($lastSale)
    <a class="ac-list-row" href="{{ route('tenant.customer.portal.sale', $lastSale->id) }}">
      <div><div class="ac-list-name">In-store purchase {{ $lastSale->sale_number ? '#' . $lastSale->sale_number : '' }}</div>
        <div class="ac-list-meta">{{ \Carbon\Carbon::parse($lastSale->sale_date)->format('M j, Y') }}</div></div>
      <div class="ac-list-right"><div style="font-weight:700">{{ $money($lastSale->total_cents) }}</div></div>
    </a>
  @endif
  @if(!$lastOrder && !$lastSale)
    <a class="ac-list-row" href="{{ route('tenant.shop.index') }}">
      <div><div class="ac-list-name">Nothing here yet</div><div class="ac-list-meta">Browse the shop &rarr;</div></div>
    </a>
  @endif
</div>

<div class="ac-quick">
  <a href="{{ route('tenant.booking') }}">Book service</a>
  <a href="{{ route('tenant.shop.index') }}">Shop online</a>
  <a href="{{ route('tenant.customer.portal.messages') }}">Message us</a>
</div>
@endsection
