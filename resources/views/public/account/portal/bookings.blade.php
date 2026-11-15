@extends('public.account._shell')
@php $pageTitle = 'Bookings'; @endphp
@push('styles')
  @include('public.account.portal._portal-css')
@endpush

@section('content')
@include('public.account.portal._nav', ['active' => 'bookings'])

<div class="ac-section-title">Upcoming</div>
<div class="ac-list">
  @forelse($upcomingAppointments as $appt)
    @php
      $adDate = \Carbon\Carbon::parse($appt->appointment_date)->format('D, M j');
      $adTime = $appt->appointment_time ? \Carbon\Carbon::parse($appt->appointment_time)->format('g:i A') : null;
    @endphp
    {{-- MARKER-PORTAL-DETAIL: rows open the work order --}}
    <a class="ac-list-row" href="{{ route('tenant.customer.portal.work_order', $appt->id) }}">
      <div><div class="ac-list-name">{{ $appt->portalTitle() }}</div>
        <div class="ac-list-meta">{{ $adDate }}@if($adTime) &middot; {{ $adTime }}@endif @if($appt->ra_number) &middot; {{ $appt->ra_number }}@endif</div></div>
      <div class="ac-list-right"><span class="ac-pill ac-pill--{{ $appt->status }}">{{ ucfirst(str_replace('_', ' ', $appt->status)) }}</span><span class="ac-go">&rsaquo;</span></div>
    </a>
  @empty
    <div class="ac-empty">No upcoming appointments</div>
  @endforelse
</div>

@if($upcomingClasses->isNotEmpty())
  <div class="ac-section-title">Upcoming classes</div>
  <div class="ac-list">
    @foreach($upcomingClasses as $reg)
      <div class="ac-list-row">
        <div><div class="ac-list-name">{{ $reg->session->template->name }}</div>
          <div class="ac-list-meta">{{ tlocal($reg->session->starts_at, 'D, M j · g:i A') }}</div></div>
        <div class="ac-list-right"><span class="ac-pill ac-pill--{{ $reg->status }}">{{ ucfirst($reg->status) }}</span>
          @if($reg->status === 'waitlisted')<div style="font-size:11px;opacity:.4;margin-top:3px">#{{ $reg->waitlist_position }} in queue</div>@endif</div>
      </div>
    @endforeach
  </div>
@endif

<div class="ac-section-title">History</div>
<div class="ac-list">
  @forelse($pastAppointments as $appt)
    @php
      $pdDate = \Carbon\Carbon::parse($appt->appointment_date)->format('D, M j, Y');
      $pdTime = $appt->appointment_time ? \Carbon\Carbon::parse($appt->appointment_time)->format('g:i A') : null;
      $pdLabel = in_array($appt->status, ['completed', 'cancelled'], true)
          ? ucfirst($appt->status) : 'Past';
      $pdPill = in_array($appt->status, ['completed', 'cancelled'], true) ? $appt->status : 'completed';
    @endphp
    <a class="ac-list-row" href="{{ route('tenant.customer.portal.work_order', $appt->id) }}">
      <div><div class="ac-list-name">{{ $appt->portalTitle() }}</div>
        <div class="ac-list-meta">{{ $pdDate }}@if($pdTime) &middot; {{ $pdTime }}@endif @if($appt->ra_number) &middot; {{ $appt->ra_number }}@endif</div></div>
      <div class="ac-list-right"><span class="ac-pill ac-pill--{{ $pdPill }}">{{ $pdLabel }}</span><span class="ac-go">&rsaquo;</span></div>
    </a>
  @empty
    <div class="ac-empty">No past appointments</div>
  @endforelse
</div>
@if($pastAppointments->hasPages())
  <div class="ac-pager">
    <span>@if($pastAppointments->previousPageUrl())<a href="{{ $pastAppointments->previousPageUrl() }}">&larr; Newer</a>@endif</span>
    <span>@if($pastAppointments->nextPageUrl())<a href="{{ $pastAppointments->nextPageUrl() }}">Older &rarr;</a>@endif</span>
  </div>
@endif

@if($pastClasses->isNotEmpty())
  <div class="ac-section-title">Past classes</div>
  <div class="ac-list">
    @foreach($pastClasses as $reg)
      <div class="ac-list-row">
        <div><div class="ac-list-name">{{ $reg->session->template->name }}</div>
          <div class="ac-list-meta">{{ tlocal($reg->session->starts_at, 'D, M j, Y · g:i A') }}</div></div>
        <div class="ac-list-right"><span class="ac-pill ac-pill--{{ $reg->status === 'registered' ? 'completed' : $reg->status }}">{{ $reg->status === 'checked_in' ? 'Attended' : ucfirst(str_replace('_', ' ', $reg->status)) }}</span></div>
      </div>
    @endforeach
  </div>
@endif

<a href="{{ route('tenant.booking') }}" class="ac-btn ac-btn--primary" style="text-decoration:none">Book an appointment</a>
@endsection
