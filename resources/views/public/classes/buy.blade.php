@extends('public.account._shell')
@php
  // MARKER-CLASSES-P3
  $pageTitle = $product->name;
  $isMember  = $kind === 'membership';
  $renewDay  = $renewsOn->format('jS');
@endphp

@push('styles')
  @include('public.classes._online-css')
@endpush

@section('content')
<a href="{{ route('tenant.customer.classes.passes') }}" class="cl-back" style="display:inline-flex;gap:6px;font-size:13px;opacity:.55;margin-bottom:20px">‹ Passes &amp; pricing</a>

@if($introUsed)
  <div class="ac-title">The intro offer is for first visits</div>
  <div class="ac-subtitle">You've had {{ $product->name }} before.@if($alt) Here's what fits instead.@endif</div>
  @if($alt)
    <a class="ac-btn ac-btn--primary" style="display:block;text-align:center" href="{{ route('tenant.customer.classes.buy', ['kind' => 'pack', 'productId' => $alt->id]) }}">{{ $alt->name }} · {{ format_money($alt->price_cents) }}</a>
  @endif
  <a class="ac-btn ac-btn--ghost" style="display:block;text-align:center;margin-top:8px" href="{{ route('tenant.customer.classes.passes') }}">See all passes</a>

@elseif(! $cardsOn)
  <div class="ac-title">{{ $product->name }}</div>
  <div class="ac-subtitle">{{ $currentTenant->name }}</div>
  <div class="ac-flash" style="background:#FAEEDA;color:#633806">{{ $currentTenant->name }} sells passes at the studio. Ask at the desk; you can book your class now and pay there.</div>
  <a class="ac-btn ac-btn--primary" style="display:block;text-align:center" href="{{ route('tenant.customer.classes') }}">Pick a class</a>

@else
  <div class="ac-title">{{ $isMember ? 'Start your membership' : 'Buy ' . $product->name }}</div>
  <div class="ac-subtitle">{{ $currentTenant->name }}</div>

  <div class="co-sum">
    <div class="co-sum-n">{{ $product->name }}</div>
    <div class="co-sum-s">
      @if($isMember)
        {{ $product->isUnlimited() ? 'Unlimited classes' : $product->monthly_limit . ' classes a month' }}
      @else
        {{ $product->credit_count }} {{ $product->credit_count === 1 ? 'class' : 'classes' }} · use within {{ $product->expiry_days }} days{{ $product->is_intro ? ' · first visit only' : '' }}
      @endif
      @if($session) · books you into {{ $session->template->name }}, {{ tlocal($session->starts_at, 'D M j, g:i A') }}@endif
    </div>
    <div class="co-sum-row"><span>{{ $isMember ? 'First month' : 'Price' }}</span><span>{{ format_money($quote['price']) }}</span></div>
    @if($quote['tax'] > 0)<div class="co-sum-row dim"><span>Tax</span><span>{{ format_money($quote['tax']) }}</span></div>@endif
    <div class="co-sum-row t"><span>Today</span><span>{{ format_money($quote['total']) }}</span></div>
    @if($isMember)
      <div class="co-sum-row dim" style="font-size:13px"><span>Then on {{ $renewsOn->format('M j') }} and monthly</span><span>{{ format_money($quote['total']) }}</span></div>
    @endif
  </div>

  @if($customer)
    <div class="ac-field">
      <label class="ac-label">Buying as</label>
      <div style="font-size:14.5px">{{ trim($customer->first_name . ' ' . $customer->last_name) }} · {{ $customer->email }}</div>
    </div>
  @else
    <div class="cl-guest-fields" style="border:1.5px solid var(--p-border);border-radius:var(--p-r-lg);padding:16px;margin-bottom:12px">
      <div style="font-size:13px;font-weight:600;margin-bottom:12px">Your details</div>
      <div class="ac-row">
        <div class="ac-field"><label class="ac-label" for="co-first">First name</label><input id="co-first" class="ac-input" autocomplete="given-name" maxlength="80"></div>
        <div class="ac-field"><label class="ac-label" for="co-last">Last name</label><input id="co-last" class="ac-input" autocomplete="family-name" maxlength="80"></div>
      </div>
      <div class="ac-field" style="margin-bottom:0">
        <label class="ac-label" for="co-email">Email</label>
        <input id="co-email" type="email" class="ac-input" autocomplete="email" maxlength="180">
        <div style="font-size:12px;opacity:.5;margin-top:5px">Your receipt goes here.</div>
      </div>
    </div>
    <div style="font-size:12.5px;opacity:.55;margin-bottom:18px">Been here before? <a class="ac-link" href="{{ route('tenant.customer.login') }}">Sign in</a></div>
  @endif

  <div class="ps-label">Card</div>
  <div class="co-pe" id="co-card"></div>

  @if($isMember)
    <div class="co-terms">{{ format_money($quote['total']) }} today. Then {{ format_money($quote['total']) }} is charged to this card on the {{ $renewDay }} of each month until you stop it. To pause or stop, contact {{ $currentTenant->name }} before your renewal date.</div>
    <label class="co-check"><input type="checkbox" id="co-save"><span>Save this card and charge it each month for {{ $product->name }}</span></label>
  @endif

  <div class="ac-flash ac-flash--error co-err" id="co-err"></div>
  <button type="button" class="ac-btn ac-btn--primary" id="co-pay">Pay {{ format_money($quote['total']) }}</button>
  <div class="co-fine">Secure payment by Stripe, paid to {{ $currentTenant->name }}. Your card number never reaches us.</div>
@endif
@endsection

@if(! $introUsed && $cardsOn)
@push('scripts')
@include('public.classes._pay-js')
<script>
(function () {
  ClassPay.mount({ pk: @json($stripePk), el: '#co-card', amount: {{ (int) $quote['total'] }}, saveCard: {{ $isMember ? 'true' : 'false' }} });
  var btn = document.getElementById('co-pay'), err = document.getElementById('co-err');
  btn.addEventListener('click', function () {
    var payload = { kind: @json($kind), product_id: @json($product->id), session_id: @json($session?->id) };
    @if($isMember)
      if (!document.getElementById('co-save').checked) { err.textContent = 'A membership renews on this card. Tick the box to save it.'; err.style.display = 'block'; return; }
      payload.saves_card = true;
    @endif
    @unless($customer)
      payload.first_name = document.getElementById('co-first').value.trim();
      payload.last_name  = document.getElementById('co-last').value.trim();
      payload.email      = document.getElementById('co-email').value.trim();
      if (!payload.first_name || !payload.email) { err.textContent = 'Enter your name and email.'; err.style.display = 'block'; return; }
    @endunless
    ClassPay.pay(@json(route('tenant.customer.classes.checkout')), payload, err, btn);
  });
})();
</script>
@endpush
@endif
