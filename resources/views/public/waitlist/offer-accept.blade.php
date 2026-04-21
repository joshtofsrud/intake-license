@php
  $headerTitle    = 'A spot opened up!';
  $headerSubtitle = 'Confirm to book this slot. This offer has also been sent to other customers on the waitlist — first to confirm gets it.';
@endphp
@include('public.waitlist._shell', ['slot' => $__env->yieldContent('waitlist_slot')])
@section('waitlist_slot')
<div class="w-card">
  <div class="w-offer-slot">
    <div class="w-offer-slot-label">Opening available</div>
    <div class="w-offer-slot-time">{{ $offer->slot_datetime->format('l, F j') }}</div>
    <div class="w-offer-slot-time" style="font-size:18px;font-weight:500;margin-top:2px">{{ $offer->slot_datetime->format('g:i A') }}</div>
    <div class="w-offer-service">{{ $service?->name ?? 'Service' }}</div>
  </div>

  <form method="POST" action="{{ route('tenant.waitlist.offer.accept', ['token' => $offer->offer_token]) }}">
    @csrf
    <button type="submit" class="w-btn w-btn--full">Confirm this booking</button>
  </form>

  <div style="margin-top:16px;font-size:13px;color:var(--p-muted);text-align:center;line-height:1.5">
    Can't make this time? No action needed — you'll stay on the waitlist for future openings.
  </div>
</div>
@endsection
