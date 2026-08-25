{{-- MARKER-TENANT-STANDING — escalating past-due notice. Deliberately quiet
     on day one: a card fails for boring reasons most of the time, and a shop
     that gets shouted at immediately stops reading the banner by day four. --}}
@php
  $st = \App\Support\TenantStanding::of($currentTenant ?? null);
  $urgent = $st['state'] === 'grace' && ($st['days_left'] ?? 99) <= 3;
@endphp
@if(($st['state'] ?? '') === 'grace')
  <div class="ia-standing {{ $urgent ? 'is-urgent' : '' }}">
    <span class="ia-standing-dot"></span>
    <div class="ia-standing-txt">
      @if($urgent)
        <b>Payment still hasn't gone through — {{ $st['days_left'] }} {{ Str::plural('day', $st['days_left']) }} left.</b>
        If it isn't sorted by <b>{{ $st['ends_at']->format('M j') }}</b>, staff will be locked out of Intake until it is.
        Your booking page and customer accounts keep working either way.
      @else
        <b>Your last payment didn't go through.</b>
        We'll try the card again automatically — nothing about your account changes in the meantime.
      @endif
      <a class="ia-standing-cta" href="{{ route('tenant.billing.portal') }}">Update payment method</a>
    </div>
  </div>
@endif
