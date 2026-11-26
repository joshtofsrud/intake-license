<!DOCTYPE html>
{{-- public reservation checkout. Standalone page in the
     public design language. Card flow: submit → rental+PI created → Payment
     Element → confirm endpoint → confirmation. --}}
@php
  // MARKER-RENTAL-PAGES-THEME: when the renter chooses, "Pay now by card" starts selected, so the wording starts there too
  $rrOnline = ($payChoice ?? false) ? true : (bool) $payOnline;
  $tname  = $currentTenant->name ?? 'Rentals';
@endphp
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
{!! \App\Support\SiteLook::schemeScript($currentTenant ?? tenant()) !!}
@include('partials.mobile-input-zoom')
<title>Reserve {{ $model->name }} — {{ $tname }}</title>
@include('public._theme-fonts')
@include('public._rental-theme')
<style>
  .rr-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 12px; }
  .rr-pay { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 2px; }
  .rr-pay label { flex: 1; min-width: 160px; display: flex; gap: 9px; align-items: center; font-size: 14.5px; padding: 11px 13px; border: 1px solid var(--rt-line); border-radius: calc(var(--rt-r) * .7); background: var(--rt-field); cursor: pointer; }
  .rr-pay label:has(input:checked) { border-color: var(--p-accent); box-shadow: 0 0 0 1px var(--p-accent); }
  .rr-pay input { accent-color: var(--p-accent); }
  #pay-wrap { display: none; }
  #details-card { transition: opacity .2s; }
  @media (max-width: 520px) { .rr-grid2 { grid-template-columns: 1fr; } }
</style>
  @include('public._ga4')
  @include('public._site-scripts', ['ssPart' => 'head']) {{-- MARKER-SITE-SCRIPTS --}}
</head>
<body>
@include('public._launch-ribbon', ['lrModule' => 'rent'])
@include('public._chrome-inline', ['chromePos' => 'top'])
<div class="rt-wrap narrow">
  <a class="rt-back" href="{{ route('tenant.rentals.browse', ['starts' => $starts, 'due' => $due]) }}">← Back to rentals</a>
  <h1 class="rt-h1">Reserve {{ $model->name }}</h1>

  <div class="rt-card" style="margin-top:20px">
    <div class="rt-kv"><span>Category</span><span>{{ $model->category?->name }}</span></div>
    <div class="rt-kv"><span>Pickup</span><span>{{ $startLocal->format('D M j, g:i A') }}</span></div>
    <div class="rt-kv"><span>Return</span><span>{{ $dueLocal->format('D M j, g:i A') }}</span></div>
    <div class="rt-kv"><span>{{ \App\Support\Rentals\RentalPricing::lineLabel($rateMode ?? 'daily', (int) $rateCents, (int) $days, (int) $subtotal, fn ($c) => format_money($c)) }}</span><span>{{ format_money($subtotal) }}</span></div>
    @if($tax > 0)<div class="rt-kv"><span>Tax</span><span>{{ format_money($tax) }}</span></div>@endif
    <div class="rt-kv total"><span id="due-label" style="color:var(--p-text)">{{ $rrOnline ? 'Pay now' : 'Due at pickup' }}</span><span>{{ format_money($total) }}</span></div>
  </div>

  <div class="rt-card" id="details-card" style="margin-top:16px">
    <h2 style="font-size:18px">Your details</h2>
    <form id="reserve-form">
      <input type="hidden" name="model_id" value="{{ $model->id }}">
      <input type="hidden" name="starts" value="{{ $starts }}">
      <input type="hidden" name="due" value="{{ $due }}">
      <div class="rr-grid2">
        <div><label class="rt-label" for="rrFirst">First name</label><input class="rt-input" id="rrFirst" name="first_name" required maxlength="120" autocomplete="given-name"></div>
        <div><label class="rt-label" for="rrLast">Last name</label><input class="rt-input" id="rrLast" name="last_name" maxlength="120" autocomplete="family-name"></div>
      </div>
      <label class="rt-label" for="rrEmail">Email</label><input class="rt-input" id="rrEmail" type="email" name="email" required maxlength="190" autocomplete="email">
      <label class="rt-label" for="rrPhone">Phone</label><input class="rt-input" id="rrPhone" type="tel" name="phone" maxlength="40" autocomplete="tel">
      @if($payChoice ?? false)
        {{-- MARKER-RENTAL-PAY-MODE: the shop lets the renter choose --}}
        <span class="rt-label">How would you like to pay?</span>
        <div class="rr-pay">
          <label><input type="radio" name="pay" value="online" checked> Pay now by card</label>
          <label><input type="radio" name="pay" value="pickup"> Pay at pickup</label>
        </div>
      @endif
      <button type="submit" class="rt-btn block" style="margin-top:20px" id="reserve-btn">{{ $rrOnline ? 'Continue to payment' : 'Reserve — pay at pickup' }}</button>
    </form>
    <div class="rt-err" id="reserve-err"></div>
  </div>

  @if($payOnline || ($payChoice ?? false))
  <div class="rt-card" id="pay-wrap" style="margin-top:16px">
    <h2 style="font-size:18px;margin-bottom:14px">Payment — {{ format_money($total) }}</h2>
    <div id="payment-element"></div>
    <button type="button" class="rt-btn block" style="margin-top:18px" id="pay-btn">Pay {{ format_money($total) }} &amp; reserve</button>
    <div class="rt-err" id="pay-err"></div>
  </div>
  @endif

  <p class="rt-note" style="text-align:center;margin-top:14px" id="pay-hint">Your spot is held the moment you reserve. Bring ID at pickup{{ $rrOnline ? '' : ' — payment is taken at the counter' }}.</p>
</div>

<script src="https://js.stripe.com/v3/"></script>
<script>
(function () {
  var form = document.getElementById('reserve-form');
  var btn = document.getElementById('reserve-btn');
  var errEl = document.getElementById('reserve-err');
  var csrf = '{{ csrf_token() }}';
  var stripe = null, elements = null, piId = null;

  function showErr(el, msg) { el.textContent = msg; el.style.display = 'block'; }

  // MARKER-RENTAL-PAY-MODE: keep the wording in step with the renter's choice
  form.querySelectorAll('input[name="pay"]').forEach(function (r) {
    r.addEventListener('change', function () {
      var now = form.querySelector('input[name="pay"]:checked').value === 'online';
      btn.textContent = now ? 'Continue to payment' : 'Reserve — pay at pickup';
      document.getElementById('due-label').textContent = now ? 'Pay now' : 'Due at pickup';
      document.getElementById('pay-hint').textContent = 'Your spot is held the moment you reserve. Bring ID at pickup' + (now ? '.' : ' — payment is taken at the counter.');
    });
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    btn.disabled = true;
    errEl.style.display = 'none';

    var payload = {};
    new FormData(form).forEach(function (v, k) { payload[k] = v; });

    fetch('{{ route('tenant.rentals.reserve.store') }}', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
      body: JSON.stringify(payload)
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
      .then(function (res) {
        if (!res.ok || !res.json.ok) {
          showErr(errEl, (res.json && res.json.error) || 'Could not reserve — try again.');
          btn.disabled = false;
          return;
        }
        if (res.json.mode === 'done') { window.location = res.json.next; return; }

        // Card flow: mount the Payment Element.
        piId = res.json.payment_intent;
        stripe = Stripe(res.json.publishable_key);
        elements = stripe.elements({ clientSecret: res.json.client_secret, appearance: {!! json_encode(\App\Support\RentalTheme::stripe($currentTenant ?? $tenant ?? tenant()), JSON_HEX_TAG | JSON_HEX_AMP) !!} });
        elements.create('payment').mount('#payment-element');
        document.getElementById('pay-wrap').style.display = 'block';
        document.getElementById('details-card').style.opacity = '.45';
        form.querySelectorAll('input,button').forEach(function (el) { el.disabled = true; });
        document.getElementById('pay-wrap').scrollIntoView({ behavior: 'smooth' });
      })
      .catch(function () { showErr(errEl, 'Could not reserve — try again.'); btn.disabled = false; });
  });

  var payBtn = document.getElementById('pay-btn');
  if (payBtn) {
    payBtn.addEventListener('click', function () {
      var payErr = document.getElementById('pay-err');
      payBtn.disabled = true;
      payErr.style.display = 'none';
      stripe.confirmPayment({ elements: elements, redirect: 'if_required' }).then(function (result) {
        if (result.error) {
          showErr(payErr, result.error.message || 'Card was not accepted.');
          payBtn.disabled = false;
          return;
        }
        fetch('{{ route('tenant.rentals.reserve.confirm') }}', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
          body: JSON.stringify({ payment_intent: piId })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
          .then(function (res) {
            if (res.ok && res.json.ok) { window.location = res.json.next; }
            else {
              showErr(payErr, (res.json && res.json.error) || 'Payment went through but confirmation hiccuped — the shop will reconcile it. You\'re reserved.');
              payBtn.disabled = false;
            }
          });
      });
    });
  }
})();
</script>
@include('public._chrome-inline', ['chromePos' => 'bottom'])
@include('public._site-scripts', ['ssPart' => 'body']) {{-- MARKER-SITE-SCRIPTS --}}
</body>
</html>
