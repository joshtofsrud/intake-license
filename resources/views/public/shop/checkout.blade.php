<!DOCTYPE html>
{{-- MARKER-PATCH-566 — Online Retail Wave 4: staged checkout. Contact ->
     fulfillment -> Stripe Payment Element. Totals re-quote client-side from
     server-provided numbers; the server is the only price authority. --}}
@php
  $accent = $tenant->accent_color ?? '#BEF264';
  $tname  = $tenant->name ?? 'Shop';
  $money  = fn ($c) => '$' . number_format(($c ?? 0) / 100, 2);
@endphp
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Checkout — {{ $tname }}</title>
<script src="https://js.stripe.com/v3/"></script>
<style>
  :root { --acc: {{ $accent }}; }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Inter', sans-serif; color: #161616; background: #fafafa; line-height: 1.55; -webkit-font-smoothing: antialiased; }
  a { color: inherit; text-decoration: none; }
  .wrap { max-width: 920px; margin: 0 auto; padding: 28px 20px 80px; }
  .top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
  .top a.home { font-weight: 700; font-size: 16px; }
  h1 { font-size: 24px; font-weight: 650; letter-spacing: -.02em; margin-bottom: 22px; }
  .cols { display: grid; grid-template-columns: 1fr 340px; gap: 26px; align-items: start; }
  @media (max-width: 820px) { .cols { grid-template-columns: 1fr; } }
  .panel { background: #fff; border: 1.5px solid rgba(0,0,0,.09); border-radius: 16px; padding: 20px; margin-bottom: 16px; }
  .panel h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .07em; opacity: .5; font-weight: 700; margin-bottom: 14px; }
  .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  input[type=text], input[type=email], input[type=tel], textarea { width: 100%; font: inherit; font-size: 14px; padding: 11px 13px; border: 1.5px solid rgba(0,0,0,.13); border-radius: 10px; background: #fff; margin-bottom: 10px; }
  textarea { resize: vertical; min-height: 60px; }
  .ful { display: flex; gap: 10px; margin-bottom: 12px; }
  .ful label { flex: 1; border: 1.5px solid rgba(0,0,0,.13); border-radius: 12px; padding: 13px 15px; cursor: pointer; font-size: 13.5px; }
  .ful label.on { border-color: #161616; background: rgba(0,0,0,.025); }
  .ful b { display: block; font-size: 14px; }
  .ful .fee { font-size: 12px; opacity: .55; }
  .ful input { display: none; }
  .chk { display: flex; gap: 9px; align-items: flex-start; font-size: 13.5px; padding: 4px 2px; cursor: pointer; }
  .chk input { margin-top: 3px; accent-color: #161616; }
  #addr-wrap { display: none; }
  .sum-line { display: flex; justify-content: space-between; font-size: 13.5px; padding: 5px 0; }
  .sum-line.total { font-size: 16px; font-weight: 800; border-top: 1.5px solid rgba(0,0,0,.09); margin-top: 8px; padding-top: 12px; }
  .mini { display: flex; gap: 10px; padding: 8px 0; border-bottom: 1px solid rgba(0,0,0,.05); font-size: 13px; align-items: center; }
  .mini:last-of-type { border-bottom: 0; margin-bottom: 8px; }
  .mini img { width: 36px; height: 36px; object-fit: contain; border: 1px solid rgba(0,0,0,.07); border-radius: 7px; }
  .mini .q { opacity: .5; }
  .mini .p { margin-left: auto; font-weight: 650; }
  .pay { display: block; width: 100%; text-align: center; font: inherit; font-size: 15px; font-weight: 700; padding: 15px 0; border: 0; border-radius: 12px; background: var(--acc); cursor: pointer; margin-top: 16px; }
  .pay:disabled { opacity: .5; cursor: wait; }
  .err { color: #b3261e; font-size: 13px; margin-top: 10px; display: none; }
  #payment-element { margin-top: 4px; }
  #pay-panel { display: none; }
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <a class="home" href="/">{{ $tname }}</a>
    <a href="/cart" style="font-size:13.5px;opacity:.6">← Back to cart</a>
  </div>

  <h1>Checkout</h1>

  <div class="cols">
    <div>
      <div class="panel">
        <h2>Your info</h2>
        <div class="grid2">
          <input type="text" id="f-first" placeholder="First name" autocomplete="given-name">
          <input type="text" id="f-last" placeholder="Last name" autocomplete="family-name">
        </div>
        <input type="email" id="f-email" placeholder="Email" autocomplete="email">
        <input type="tel" id="f-phone" placeholder="Phone (for pickup texts)" autocomplete="tel">
      </div>

      <div class="panel">
        <h2>How you'll get it</h2>
        <div class="ful">
          <label class="on" id="ful-pickup">
            <input type="radio" name="ful" value="pickup" checked>
            <b>Pickup</b><span class="fee">Free — we'll text you when it's ready</span>
          </label>
          @if($config['local_delivery'])
            <label id="ful-delivery">
              <input type="radio" name="ful" value="local_delivery">
              <b>Local delivery</b><span class="fee">{{ $config['delivery_fee_cents'] > 0 ? $money($config['delivery_fee_cents']) : 'Free' }} — our service area</span>
            </label>
          @endif
        </div>
        <div id="addr-wrap">
          <input type="text" id="f-addr" placeholder="Delivery address" autocomplete="street-address">
        </div>
        <textarea id="f-notes" placeholder="Anything we should know? (optional)"></textarea>
        <label class="chk">
          <input type="checkbox" id="f-install">
          <span>I'd like this installed — we'll reach out to get it scheduled.</span>
        </label>
      </div>

      <div class="panel" id="pay-panel">
        <h2>Payment</h2>
        <div id="payment-element"></div>
      </div>

      <button class="pay" id="go">Continue to payment</button>
      <div class="err" id="err"></div>
    </div>

    <div class="panel">
      <h2>Order summary</h2>
      @foreach($cart->items as $l)
        <div class="mini">
          @if($l->image_snapshot)<img src="{{ $l->image_snapshot }}" alt="">@endif
          <span>{{ \Illuminate\Support\Str::limit($l->name_snapshot, 34) }} <span class="q">×{{ (int) $l->quantity }}</span></span>
          <span class="p">{{ $money($l->line_total_cents) }}</span>
        </div>
      @endforeach
      <div class="sum-line"><span>Subtotal</span><span id="s-sub">{{ $money($quotePickup['subtotal_cents']) }}</span></div>
      <div class="sum-line"><span>Tax</span><span id="s-tax">{{ $money($quotePickup['tax_cents']) }}</span></div>
      <div class="sum-line" id="s-fee-row" style="display:none"><span>Delivery</span><span id="s-fee">{{ $money($quoteDelivery['shipping_cents']) }}</span></div>
      <div class="sum-line total"><span>Total</span><span id="s-total">{{ $money($quotePickup['total_cents']) }}</span></div>
    </div>
  </div>
</div>

<script>
(function () {
  var PK = @json($stripePk);
  var QUOTES = { pickup: @json($quotePickup), local_delivery: @json($quoteDelivery) };
  var money = function (c) { return '$' + (c / 100).toFixed(2); };
  var ful = 'pickup', stripe = null, elements = null, placed = false;

  function pickFul(kind) {
    ful = kind;
    document.querySelectorAll('.ful label').forEach(function (l) { l.classList.remove('on'); });
    document.getElementById(kind === 'pickup' ? 'ful-pickup' : 'ful-delivery').classList.add('on');
    document.getElementById('addr-wrap').style.display = kind === 'local_delivery' ? 'block' : 'none';
    document.getElementById('s-fee-row').style.display = kind === 'local_delivery' ? 'flex' : 'none';
    var q = QUOTES[kind];
    document.getElementById('s-tax').textContent = money(q.tax_cents);
    document.getElementById('s-total').textContent = money(q.total_cents);
  }
  document.querySelectorAll('.ful input').forEach(function (r) {
    r.addEventListener('change', function () { pickFul(r.value); });
  });

  function fail(msg) {
    var e = document.getElementById('err');
    e.textContent = msg; e.style.display = 'block';
    var b = document.getElementById('go'); b.disabled = false;
  }

  document.getElementById('go').addEventListener('click', async function () {
    var b = this; b.disabled = true;
    document.getElementById('err').style.display = 'none';

    if (!placed) {
      var body = {
        first_name: document.getElementById('f-first').value.trim(),
        last_name:  document.getElementById('f-last').value.trim(),
        email:      document.getElementById('f-email').value.trim(),
        phone:      document.getElementById('f-phone').value.trim(),
        fulfillment_type: ful,
        address:    document.getElementById('f-addr').value.trim(),
        notes:      document.getElementById('f-notes').value.trim(),
        wants_install: document.getElementById('f-install').checked ? 1 : 0
      };
      if (!body.first_name || !body.last_name || !body.email) return fail('Name and email are required.');
      if (ful === 'local_delivery' && !body.address) return fail('Delivery needs an address.');
      if (!PK) return fail('Online payments are not enabled yet — call us and we\'ll take care of it.');

      var res, data;
      try {
        res = await fetch('/checkout/place', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                     'X-CSRF-TOKEN': @json(csrf_token()) },
          body: JSON.stringify(body)
        });
        data = await res.json();
      } catch (e) { return fail('Network hiccup — try again.'); }
      if (!data || !data.ok) return fail((data && data.message) || 'Could not start payment.');

      stripe = Stripe(PK);
      elements = stripe.elements({ clientSecret: data.client_secret });
      elements.create('payment').mount('#payment-element');
      document.getElementById('pay-panel').style.display = 'block';
      window.__orderToken = data.order_token;
      placed = true;
      b.textContent = 'Pay ' + money(data.total_cents);
      b.disabled = false;
      document.getElementById('pay-panel').scrollIntoView({ behavior: 'smooth' });
      return;
    }

    var result = await stripe.confirmPayment({
      elements: elements,
      confirmParams: {
        return_url: window.location.origin + '/checkout/return?order=' + encodeURIComponent(window.__orderToken)
      }
    });
    if (result.error) fail(result.error.message || 'Payment failed — try another card.');
  });
})();
</script>
</body>
</html>
