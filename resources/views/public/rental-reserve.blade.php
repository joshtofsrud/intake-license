<!DOCTYPE html>
{{-- MARKER-PATCH-240 — public reservation checkout. Standalone page in the
     public design language. Card flow: submit → rental+PI created → Payment
     Element → confirm endpoint → confirmation. --}}
@php
  $accent = $currentTenant->accent_color ?? '#BEF264';
  $tname  = $currentTenant->name ?? 'Rentals';
@endphp
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reserve {{ $model->name }} — {{ $tname }}</title>
<style>
  :root { --acc: {{ $accent }}; }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Inter', sans-serif; color: #161616; background: #fafafa; line-height: 1.6; -webkit-font-smoothing: antialiased; }
  a { color: inherit; text-decoration: none; }
  .wrap { max-width: 560px; margin: 0 auto; padding: 28px 20px 80px; }
  .top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 26px; }
  .top a.home { font-weight: 700; font-size: 16px; }
  h1 { font-size: 22px; font-weight: 650; letter-spacing: -.02em; }
  .card { background: #fff; border: 1.5px solid rgba(0,0,0,.09); border-radius: 14px; padding: 20px 22px; margin-top: 16px; }
  .kv { display: flex; justify-content: space-between; font-size: 14px; padding: 4px 0; }
  .kv span:first-child { opacity: .55; }
  .kv.total { font-weight: 700; border-top: 1.5px solid rgba(0,0,0,.08); margin-top: 6px; padding-top: 10px; }
  label { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; opacity: .5; display: block; margin: 12px 0 5px; font-weight: 600; }
  input { font: inherit; font-size: 14px; padding: 10px 12px; border: 1.5px solid rgba(0,0,0,.12); border-radius: 9px; width: 100%; background: #fff; }
  .btn { font: inherit; font-size: 15px; font-weight: 650; padding: 13px 0; border: none; border-radius: 10px; background: var(--acc); color: #111; cursor: pointer; width: 100%; margin-top: 18px; }
  .btn:disabled { opacity: .5; cursor: default; }
  .err { background: #fef2f2; border: 1px solid #ef4444; color: #7f1d1d; border-radius: 9px; padding: 10px 14px; font-size: 13px; margin-top: 14px; display: none; }
  .hint { font-size: 12px; opacity: .45; margin-top: 10px; text-align: center; }
  #pay-wrap { display: none; margin-top: 16px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <a class="home" href="/">{{ $tname }}</a>
    <a href="{{ route('tenant.rentals.browse', ['starts' => $starts, 'due' => $due]) }}" style="font-size:13.5px;opacity:.6">← Back to rentals</a>
  </div>

  <h1>Reserve — {{ $model->name }}</h1>

  <div class="card">
    <div class="kv"><span>Category</span><span>{{ $model->category?->name }}</span></div>
    <div class="kv"><span>Pickup</span><span>{{ $startLocal->format('D M j, g:i A') }}</span></div>
    <div class="kv"><span>Return</span><span>{{ $dueLocal->format('D M j, g:i A') }}</span></div>
    <div class="kv"><span>{{ $days }} day{{ $days === 1 ? '' : 's' }} × {{ format_money($rateCents) }}</span><span>{{ format_money($subtotal) }}</span></div>
    @if($tax > 0)<div class="kv"><span>Tax</span><span>{{ format_money($tax) }}</span></div>@endif
    <div class="kv total"><span style="opacity:1">{{ $payOnline ? 'Pay now' : 'Due at pickup' }}</span><span>{{ format_money($total) }}</span></div>
  </div>

  <div class="card" id="details-card">
    <div style="font-size:13px;font-weight:650">Your details</div>
    <form id="reserve-form">
      <input type="hidden" name="model_id" value="{{ $model->id }}">
      <input type="hidden" name="starts" value="{{ $starts }}">
      <input type="hidden" name="due" value="{{ $due }}">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 10px">
        <div><label>First name</label><input name="first_name" required maxlength="120"></div>
        <div><label>Last name</label><input name="last_name" maxlength="120"></div>
      </div>
      <label>Email</label><input type="email" name="email" required maxlength="190">
      <label>Phone</label><input type="tel" name="phone" maxlength="40">
      <button type="submit" class="btn" id="reserve-btn">{{ $payOnline ? 'Continue to payment' : 'Reserve — pay at pickup' }}</button>
    </form>
    <div class="err" id="reserve-err"></div>
  </div>

  @if($payOnline)
  <div class="card" id="pay-wrap">
    <div style="font-size:13px;font-weight:650;margin-bottom:12px">Payment — {{ format_money($total) }}</div>
    <div id="payment-element"></div>
    <button type="button" class="btn" id="pay-btn">Pay {{ format_money($total) }} &amp; reserve</button>
    <div class="err" id="pay-err"></div>
  </div>
  @endif

  <p class="hint">Your spot is held the moment you reserve. Bring ID at pickup{{ $payOnline ? '' : ' — payment is taken at the counter' }}.</p>
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
        elements = stripe.elements({ clientSecret: res.json.client_secret });
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
</body>
</html>
