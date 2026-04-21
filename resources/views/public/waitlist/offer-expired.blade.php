@php
  $headerTitle    = 'This offer has expired';
  $headerSubtitle = "The slot time has passed. You're still on the waitlist for future openings.";
@endphp
@include('public.waitlist._shell', ['slot' => $__env->yieldContent('waitlist_slot')])
@section('waitlist_slot')
<div class="w-card" style="text-align:center">
  <p style="color:var(--p-muted);line-height:1.6">
    You'll receive a new offer the next time a matching spot opens up.
  </p>
</div>

<div class="w-footer-note">
  <a href="{{ route('tenant.booking') }}">Check current availability</a>
</div>
@endsection
