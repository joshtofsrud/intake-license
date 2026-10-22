@extends('public.account._shell')
@php
  // MARKER-CLASSES-P3
  $pageTitle = 'Thank you';
  $booked    = $reg && in_array($reg->status, ['registered', 'checked_in'], true);
  $waitlist  = $reg && $reg->status === 'waitlisted';
  $paid      = (int) $checkout->amount_cents + (int) $checkout->tax_cents;
@endphp

@push('styles')
  @include('public.classes._online-css')
  @if(in_array($checkout->status, ['pending', 'settling'], true))<meta http-equiv="refresh" content="4">@endif
@endpush

@section('content')

@if($checkout->status === 'failed')
  <div class="ac-title">That didn't go through</div>
  <div class="ac-flash ac-flash--error">{{ $checkout->note ?: 'We couldn\'t complete your purchase.' }}</div>
  <a class="ac-btn ac-btn--primary" style="display:block;text-align:center" href="{{ route('tenant.customer.classes') }}">Back to the schedule</a>

@elseif($checkout->status !== 'paid')
  <div style="text-align:center">
    <div class="ac-title">Confirming your payment…</div>
    <div class="ac-subtitle">This takes a few seconds. The page refreshes on its own.</div>
  </div>

@else
  <div style="text-align:center;margin-bottom:6px">
    <div class="ok-mark"><svg width="22" height="22" viewBox="0 0 22 22" fill="none"><path d="M5 11.5l4 4 8-9" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
    <div class="ac-title">{{ $booked ? "You're booked" : ($waitlist ? "You're on the waitlist" : "You're all set" . ($buyer?->first_name ? ', ' . $buyer->first_name : '')) }}</div>
    <div class="ac-subtitle">
      @if($session && ($booked || $waitlist))
        {{ $session->template->name }} · {{ tlocal($session->starts_at, 'D M j, g:i A') }}
      @else
        Receipt sent to {{ $buyer?->email }}
      @endif
    </div>
  </div>

  <div class="ok-list">
    @if($checkout->kind === 'dropin')
      <div><span>Class</span><b>{{ $session?->template->name }}</b></div>
      <div><span>When</span><b>{{ $session ? tlocal($session->starts_at, 'D M j, g:i A') : '' }}</b></div>
    @else
      <div><span>Pass</span><b>{{ $product?->name }}</b></div>
      @if($member)
        <div><span>Renews</span><b>{{ $member->current_period_end?->copy()->addDay()->format('M j') }} · {{ format_money((int) $member->renewal_price_cents) }}</b></div>
        @if($member->card_last4)<div><span>Card</span><b>{{ ucfirst((string) $member->card_brand) }} ending {{ $member->card_last4 }}</b></div>@endif
      @elseif($pack)
        <div><span>Classes left</span><b>{{ $pack->fresh()->credits_remaining }}</b></div>
        <div><span>Use by</span><b>{{ $pack->expires_at?->format('M j, Y') }}</b></div>
      @endif
      @if($waitlist)<div><span>Class</span><b>Full, you're #{{ $reg->waitlist_position }} on the waitlist</b></div>@endif
    @endif
    <div><span>Paid</span><b>{{ format_money($paid) }}@if($sale?->card_last4) · {{ ucfirst((string) $sale->card_brand) }} {{ $sale->card_last4 }}@endif</b></div>
  </div>

  @if($checkout->note && $checkout->kind !== 'dropin' && ! $reg)
    <div class="ac-flash" style="background:#FAEEDA;color:#633806">Your pass is ready, but we couldn't book that class. Pick a time on the schedule.</div>
  @endif

  @if($booked || $waitlist)
    <a class="ac-btn ac-btn--ghost" style="display:block;text-align:center" href="{{ route('tenant.customer.classes') }}">Book another class</a>
  @else
    <a class="ac-btn ac-btn--primary" style="display:block;text-align:center" href="{{ route('tenant.customer.classes') }}">Book your first class</a>
  @endif

  @if(! $customer && $buyer && ! $buyer->password)
    <div class="co-fine" style="margin-top:14px">To see your passes later, <a class="ac-link" href="{{ route('tenant.customer.register') }}">create an account</a> with {{ $buyer->email }}.</div>
  @endif
@endif
@endsection
