@php
  $headerTitle    = 'This spot was taken';
  $headerSubtitle = "Another customer confirmed this opening first. Don't worry — you're still on the waitlist.";
@endphp
@include('public.waitlist._shell', ['slot' => $__env->yieldContent('waitlist_slot')])
@section('waitlist_slot')
<div class="w-card" style="text-align:center">
  <p style="color:var(--p-muted);margin-bottom:20px;line-height:1.6">
    The opening on <b>{{ $offer->slot_datetime->format('l, F j \a\t g:i A') }}</b> was just booked by someone else.
  </p>
  <p style="color:var(--p-muted);line-height:1.6">
    You'll be notified again when another spot matching your preferences opens up.
  </p>
</div>

<div class="w-footer-note">
  <a href="{{ route('tenant.booking') }}">Check current availability</a>
</div>
@endsection
