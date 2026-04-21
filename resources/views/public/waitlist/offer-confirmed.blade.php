@php
  $headerTitle    = 'Booking confirmed!';
  $headerSubtitle = 'See you on ' . $offer->slot_datetime->format('l, F j');
@endphp
@include('public.waitlist._shell', ['slot' => $__env->yieldContent('waitlist_slot')])
@section('waitlist_slot')
<div class="w-card" style="text-align:center">
  <div style="font-size:48px;margin-bottom:12px">✓</div>
  <div style="font-size:20px;font-weight:600;margin-bottom:8px">You're all set</div>
  <p style="color:var(--p-muted);line-height:1.6">
    We've booked <b>{{ $offer->entry?->serviceItem?->name ?? 'your service' }}</b><br>
    on <b>{{ $offer->slot_datetime->format('l, F j \a\t g:i A') }}</b>.
  </p>
  @if($offer->resultingAppointment)
    <p style="color:var(--p-muted);margin-top:16px;font-size:13px">
      Confirmation: {{ $offer->resultingAppointment->ra_number ?? '' }}
    </p>
  @endif
</div>

<div class="w-footer-note">
  You'll get a confirmation email shortly.
</div>
@endsection
