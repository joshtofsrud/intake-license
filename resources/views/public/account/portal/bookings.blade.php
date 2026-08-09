@extends('public.account._shell')
@php $pageTitle = 'Bookings'; @endphp
{{-- MARKER-PORTAL-V2 --}}
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
    <div class="ac-list-row">
      <div><div class="ac-list-name">{{ $appt->label ?? 'Appointment' }}</div>
        <div class="ac-list-meta">{{ $adDate }}@if($adTime) &middot; {{ $adTime }}@endif</div></div>
      <div class="ac-list-right"><span class="ac-pill ac-pill--{{ $appt->status }}">{{ ucfirst(str_replace('_', ' ', $appt->status)) }}</span></div>
    </div>
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
    <div class="ac-list-row">
      <div><div class="ac-list-name">{{ $appt->label ?? 'Appointment' }}</div>
        <div class="ac-list-meta">{{ $pdDate }}@if($pdTime) &middot; {{ $pdTime }}@endif</div></div>
      <div class="ac-list-right"><span class="ac-pill ac-pill--{{ $pdPill }}">{{ $pdLabel }}</span></div>
    </div>
  @empty
    <div class="ac-empty">No past appointments</div>
  @endforelse
</div>

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
