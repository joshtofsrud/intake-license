{{-- MARKER-RENTAL-EXT — one screen, one tap. States: open / paid / dead. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Extend your rental — {{ $tenant->name }}</title>
<style>
  body { font-family:-apple-system,'Inter',sans-serif; background:#f5f5f4; color:#141414; margin:0; -webkit-font-smoothing:antialiased; }
  .wrap { max-width:420px; margin:0 auto; padding:28px 18px 60px; }
  .shop { font-size:13px; font-weight:700; letter-spacing:.02em; margin-bottom:22px; opacity:.7 }
  .card { background:#fff; border:1px solid rgba(0,0,0,.09); border-radius:16px; padding:22px 20px; box-shadow:0 1px 4px rgba(0,0,0,.04); }
  h1 { font-size:21px; letter-spacing:-.01em; margin:0 0 6px; line-height:1.25 }
  .sub { font-size:14px; opacity:.6; line-height:1.55; margin:0 0 18px }
  .row { display:flex; justify-content:space-between; font-size:13.5px; padding:9px 0; border-bottom:1px dashed rgba(0,0,0,.09) }
  .row:last-of-type { border-bottom:none }
  .row b { font-variant-numeric:tabular-nums }
  .strike { text-decoration:line-through; opacity:.4; margin-right:6px }
  .btn { display:block; width:100%; border:none; border-radius:11px; padding:14px; font-size:15px; font-weight:700; cursor:pointer; text-align:center; box-sizing:border-box }
  .btn-pay { background:#141414; color:#fff; margin-top:16px }
  .btn-no { background:none; color:rgba(0,0,0,.45); font-weight:500; font-size:13px; margin-top:6px }
  .badge { display:inline-block; font-size:11px; font-weight:700; padding:4px 10px; border-radius:99px; margin-bottom:12px }
  .badge.ok { background:rgba(34,160,84,.12); color:#1c7a43 }
  .badge.dead { background:rgba(0,0,0,.07); color:rgba(0,0,0,.5) }
  .err { display:none; background:rgba(220,60,60,.08); color:#b23434; border-radius:9px; padding:10px 12px; font-size:13px; margin-top:12px }
  #payment-element { margin-top:16px }
  #pay-wrap { display:none }
</style>
</head>
<body>
<div class="wrap">
  <div class="shop">{{ $tenant->name }}</div>

  @if($offer->status === 'paid')
    <div class="card">
      <span class="badge ok">Extended</span>
      <h1>You're all set.</h1>
      <p class="sub">Your rental is extended — enjoy the extra time.</p>
      <div class="row"><span>Return by</span><b>{{ tlocal_datetime($offer->extend_to, 'g:i A, D M j') }}</b></div>
      @if($unit)<div class="row"><span>Unit</span><b>{{ $unit->name }}@if($unit->identifier) · {{ $unit->identifier }}@endif</b></div>@endif
      <div class="row"><span>Paid</span><b>{{ format_money($offer->total_cents) }}</b></div>
      <p class="sub" style="margin-top:16px;margin-bottom:0">Any deposit hold stays in place until you return the unit — no new charge unless something's damaged or lost.</p>
    </div>

  @elseif(!$offer->isOpen() || $rental->status !== 'out' || $rental->returned_at)
    <div class="card">
      <span class="badge dead">{{ $offer->status === 'declined' ? 'Declined' : 'Offer expired' }}</span>
      <h1>This offer isn't available anymore.</h1>
      <p class="sub" style="margin-bottom:0">
        @if($offer->status === 'declined') No worries — see you at {{ tlocal_datetime($rental->due_at, 'g:i A') }}.
        @else The extension window has passed. If you'd like more time, give the shop a call.
        @endif
      </p>
    </div>

  @else
    <div class="card">
      <h1>Keep it longer?</h1>
      <p class="sub">Nobody has {{ $unit?->name ?? 'your rental' }} booked after you — extend to <b>{{ tlocal_datetime($offer->extend_to, 'g:i A') }}</b> for {{ $offer->discount_pct }}% off.</p>
      <div class="row"><span>Current return</span><b>{{ tlocal_datetime($offer->offer_from, 'g:i A') }}</b></div>
      <div class="row"><span>Extended return</span><b>{{ tlocal_datetime($offer->extend_to, 'g:i A') }}</b></div>
      <div class="row"><span>Price</span><b><span class="strike">{{ format_money((int) round($offer->subtotal_cents * 100 / max(1, 100 - $offer->discount_pct))) }}</span>{{ format_money($offer->subtotal_cents) }}</b></div>
      @if($offer->tax_cents > 0)<div class="row"><span>Tax</span><b>{{ format_money($offer->tax_cents) }}</b></div>@endif
      <div class="row"><span>Total now</span><b>{{ format_money($offer->total_cents) }}</b></div>

      <button class="btn btn-pay" id="ext-pay-start">Extend & pay {{ format_money($offer->total_cents) }}</button>
      <div id="pay-wrap">
        <div id="payment-element"></div>
        <button class="btn btn-pay" id="ext-pay-confirm">Pay {{ format_money($offer->total_cents) }}</button>
      </div>
      <div class="err" id="ext-err"></div>
      <form method="POST" action="{{ route('tenant.rentals.extension.decline', $offer->token) }}">
        @csrf
        <button class="btn btn-no">No thanks — I'll return it on time</button>
      </form>
    </div>

    <script src="https://js.stripe.com/v3/"></script>
    <script>
    (function () {
      var csrf = '{{ csrf_token() }}';
      var errEl = document.getElementById('ext-err');
      var start = document.getElementById('ext-pay-start');
      var confirmBtn = document.getElementById('ext-pay-confirm');
      var stripe = null, elements = null, piId = null;
      function showErr(msg) { errEl.textContent = msg; errEl.style.display = 'block'; }

      start.addEventListener('click', function () {
        start.disabled = true; errEl.style.display = 'none';
        fetch('{{ route('tenant.rentals.extension.pay', $offer->token) }}', {
          method: 'POST',
          headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
          .then(function (res) {
            if (!res.ok || !res.json.ok) { showErr((res.json && res.json.error) || 'Could not start the payment.'); start.disabled = false; return; }
            piId = res.json.payment_intent;
            stripe = Stripe(res.json.publishable_key);
            elements = stripe.elements({ clientSecret: res.json.client_secret });
            elements.create('payment').mount('#payment-element');
            start.style.display = 'none';
            document.getElementById('pay-wrap').style.display = 'block';
          })
          .catch(function () { showErr('Could not start the payment.'); start.disabled = false; });
      });

      confirmBtn.addEventListener('click', function () {
        confirmBtn.disabled = true; errEl.style.display = 'none';
        stripe.confirmPayment({ elements: elements, redirect: 'if_required' }).then(function (result) {
          if (result.error) { showErr(result.error.message || 'Card was not accepted.'); confirmBtn.disabled = false; return; }
          fetch('{{ route('tenant.rentals.extension.confirm', $offer->token) }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ payment_intent: piId })
          }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
            .then(function (res) {
              if (res.ok && res.json.ok) { window.location = res.json.next; }
              else { showErr((res.json && res.json.error) || 'Payment went through but confirmation hiccuped — the shop will sort it.'); confirmBtn.disabled = false; }
            });
        });
      });
    })();
    </script>
  @endif
</div>
</body>
</html>
