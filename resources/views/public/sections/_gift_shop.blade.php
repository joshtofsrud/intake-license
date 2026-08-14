{{-- MARKER-GIFTCARDS-PUBLIC — chrome-wrapped gift card buy page, per the
     approved mockup. Same scoped-style pattern as _shop_checkout. --}}
@php
  $accent = $tenant->accent_color ?? '#BEF264';
@endphp
<script src="https://js.stripe.com/v3/"></script>
<style>
  :root { --acc: {{ $accent }}; }
  .spg-gift .wrap { max-width: 1080px; margin: 0 auto; padding: 28px 20px 80px; }
  .spg-gift h1 { font-size: 26px; font-weight: 650; letter-spacing: -.02em; }
  .spg-gift .sub { font-size: 14px; opacity: .55; margin-top: 4px; }
  .spg-gift .cols { display: grid; grid-template-columns: 1fr 340px; gap: 26px; align-items: start; margin-top: 24px; }
  @media (max-width: 820px) { .spg-gift .cols { grid-template-columns: 1fr; } }
  .spg-gift .panel { background: #fff; border: 1.5px solid rgba(0,0,0,.09); border-radius: 16px; padding: 20px; margin-bottom: 16px; }
  .spg-gift .panel h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .07em; opacity: .5; font-weight: 700; margin-bottom: 14px; }
  .spg-gift .ful { display: flex; gap: 10px; margin-bottom: 4px; }
  .spg-gift .ful label { flex: 1; border: 1.5px solid rgba(0,0,0,.13); border-radius: 12px; padding: 13px 15px; cursor: pointer; font-size: 13.5px; }
  .spg-gift .ful label.on { border-color: #161616; background: rgba(0,0,0,.025); }
  .spg-gift .ful b { display: block; font-size: 14px; }
  .spg-gift .ful .fee { font-size: 12px; opacity: .55; }
  .spg-gift .ful input { display: none; }
  .spg-gift .amounts { display: flex; gap: 8px; flex-wrap: wrap; }
  .spg-gift .amt { font-size: 14px; font-weight: 650; padding: 11px 20px; border-radius: 12px; border: 1.5px solid rgba(0,0,0,.13); background: #fff; cursor: pointer; font-family: inherit; }
  .spg-gift .amt.on { border-color: #161616; background: #161616; color: #fff; }
  .spg-gift input[type=text], .spg-gift input[type=email], .spg-gift input[type=date], .spg-gift select, .spg-gift textarea { width: 100%; font: inherit; font-size: 14px; padding: 11px 13px; border: 1.5px solid rgba(0,0,0,.13); border-radius: 10px; background: #fff; margin-bottom: 10px; }
  .spg-gift textarea { resize: vertical; min-height: 70px; }
  .spg-gift .lbl { display: block; font-size: 12.5px; font-weight: 650; margin-bottom: 5px; }
  .spg-gift .hint { font-size: 12px; opacity: .5; margin: -4px 0 10px; }
  .spg-gift .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  .spg-gift .char { font-size: 11.5px; opacity: .45; text-align: right; margin: -6px 0 8px; }
  .spg-gift .gcv { border-radius: 16px; padding: 22px 24px; background: #161616; color: #fff; position: relative; overflow: hidden; margin-bottom: 16px; }
  .spg-gift .gcv::after { content: ''; position: absolute; right: -40px; top: -40px; width: 160px; height: 160px; border-radius: 50%; background: var(--acc); opacity: .16; }
  .spg-gift .gcv .shop { font-size: 12px; text-transform: uppercase; letter-spacing: .1em; opacity: .55; font-weight: 700; }
  .spg-gift .gcv .bigamt { font-size: 34px; font-weight: 800; margin-top: 10px; letter-spacing: -.02em; }
  .spg-gift .gcv .cardlbl { font-size: 10.5px; text-transform: uppercase; letter-spacing: .08em; opacity: .4; margin-top: 14px; }
  .spg-gift .gcv .code { font-family: ui-monospace, monospace; font-size: 14px; letter-spacing: .14em; margin-top: 6px; opacity: .85; }
  .spg-gift .sum-line { display: flex; justify-content: space-between; font-size: 13.5px; padding: 5px 0; }
  .spg-gift .sum-line.total { font-size: 16px; font-weight: 800; border-top: 1.5px solid rgba(0,0,0,.09); margin-top: 8px; padding-top: 12px; }
  .spg-gift .pay { display: block; width: 100%; text-align: center; font: inherit; font-size: 15px; font-weight: 700; padding: 15px 0; border: 0; border-radius: 12px; background: var(--acc); cursor: pointer; margin-top: 16px; }
  .spg-gift .pay:disabled { opacity: .5; cursor: wait; }
  .spg-gift .err { color: #b3261e; font-size: 13px; margin-top: 10px; display: none; }
  .spg-gift #gp-pay-panel { display: none; }
  .spg-gift #gp-payment-element { margin-top: 4px; }
</style>
<div class="spg-gift">
  <div class="wrap">
    <h1>Gift cards</h1>
    {{-- MARKER-GC-SETTINGS --}}
    <div class="sub">Good for anything — service, parts, rentals.@if($gift['policy_line']) {{ $gift['policy_line'] }}@endif <a href="/gift-cards/balance" style="text-decoration:underline">Check a balance</a></div>

    {{-- MARKER-GC-SETTINGS -- both channels off is a deliberate "register only"
         setting, so say so plainly instead of showing a dead form. --}}
    @if(!$stripePk || (!$gift['online_egift'] && !$gift['online_physical']))
      <div class="panel" style="margin-top:24px;max-width:560px">
        Gift cards aren't available to buy online right now — call or visit us in store and we'll set one up.
      </div>
    @else
    <div class="cols">
      <div>
        <div class="panel">
          <h2>1 · Choose a type</h2>
          <div class="ful" id="gp-type">
            @if($gift['online_egift'])
            <label class="on" data-type="egift"><b>E-gift card</b><span class="fee">Emailed instantly, or on a date you pick</span><input type="radio" name="gp_type" value="egift" checked></label>
            @endif
            @if($gift['online_physical'])
            <label class="{{ $gift['online_egift'] ? '' : 'on' }}" data-type="physical"><b>Physical card</b><span class="fee">Pick up in store</span><input type="radio" name="gp_type" value="physical"></label>
            @endif
          </div>
        </div>

        <div class="panel">
          <h2>2 · Amount</h2>
          <div class="amounts" id="gp-amounts">
            @foreach($gift['presets'] as $gpI => $gpAmt)
            <button type="button" class="amt {{ $gpI === 1 || count($gift['presets']) === 1 ? 'on' : '' }}" data-cents="{{ $gpAmt }}">${{ rtrim(rtrim(number_format($gpAmt / 100, 2), '0'), '.') }}</button>
            @endforeach
            <button type="button" class="amt {{ count($gift['presets']) ? '' : 'on' }}" data-cents="">Custom</button>
          </div>
          <div id="gp-custom-wrap" style="{{ count($gift['presets']) ? 'display:none;' : '' }}margin-top:10px">
            <input type="text" id="gp-custom" inputmode="decimal" placeholder="Amount in dollars (${{ rtrim(rtrim(number_format($gift['min_cents'] / 100, 2), '0'), '.') }}–${{ number_format($gift['max_cents'] / 100) }})">
          </div>
        </div>

        <div class="panel">
          <h2 id="gp-send-title">3 · Send it</h2>
          <div class="grid2">
            <div>
              <label class="lbl">Your name</label>
              <input type="text" id="gp-purchaser-name" maxlength="120">
            </div>
            <div>
              <label class="lbl">Your email</label>
              <input type="email" id="gp-purchaser-email" maxlength="160">
            </div>
          </div>
          <div id="gp-egift-fields">
            <label class="lbl">Recipient name</label>
            <input type="text" id="gp-recipient-name" maxlength="120">
            <label class="lbl">Recipient email</label>
            <input type="email" id="gp-recipient-email" maxlength="160">
            <label class="lbl">Gift message (optional)</label>
            <textarea id="gp-message" maxlength="200"></textarea>
            <div class="char"><span id="gp-message-count">0</span> / 200</div>
            <div class="grid2">
              <div>
                <label class="lbl">Deliver</label>
                <select id="gp-deliver-mode">
                  <option value="now">Right away</option>
                  <option value="date">On a date…</option>
                </select>
              </div>
              <div id="gp-date-wrap" style="display:none">
                <label class="lbl">Delivery date</label>
                <input type="date" id="gp-deliver-on">
              </div>
            </div>
            <div class="hint" id="gp-deliver-hint">We'll email the card as soon as your payment goes through, with a receipt to you.</div>
          </div>
          <div id="gp-physical-note" style="display:none" class="hint">
            We'll have your card ready at the shop — bring your confirmation.
          </div>
        </div>
      </div>

      <div>
        <div class="gcv">
          <div class="shop">{{ $tenant->name }}</div>
          <div class="bigamt" id="gp-preview-amt">$50</div>
          <div class="cardlbl">Gift card &middot; preview</div>
          <div class="code">GC-&bull;&bull;&bull;&bull;-&bull;&bull;&bull;&bull;-&bull;&bull;&bull;&bull;</div>
        </div>
        <div class="panel">
          <div class="sum-line"><span id="gp-sum-label">E-gift card</span><span id="gp-sum-amt">$50.00</span></div>
          <div class="sum-line"><span>Delivery</span><span>Free</span></div>
          <div class="sum-line total"><span>Total</span><span id="gp-sum-total">$50.00</span></div>
          <button type="button" class="pay" id="gp-continue">Continue to payment</button>
          <div class="err" id="gp-err"></div>
          <div class="hint" style="margin-top:10px;text-align:center">No expiration &middot; balance checkable any time</div>
        </div>
        <div class="panel" id="gp-pay-panel">
          <h2>Payment</h2>
          <div id="gp-payment-element"></div>
          <button type="button" class="pay" id="gp-pay-btn" disabled>Pay</button>
          <div class="err" id="gp-pay-err"></div>
        </div>
      </div>
    </div>
    @endif
  </div>
</div>
@if($stripePk)
<script>
(function () {
  // MARKER-GC-SETTINGS -- shop config drives the defaults and the client checks.
  var CFG = @json([
    'presets'         => $gift['presets'],
    'min'             => $gift['min_cents'],
    'max'             => $gift['max_cents'],
    'egift'           => $gift['online_egift'],
    'physical'        => $gift['online_physical'],
    'default_message' => $gift['default_message'],
  ]);
  var state = {
    type:  CFG.egift ? 'egift' : 'physical',
    cents: CFG.presets.length > 1 ? CFG.presets[1] : (CFG.presets[0] || null)
  };
  var stripe = null, elements = null;

  function fmt(c) { return '$' + (c / 100).toFixed(2); }
  function short(c) { return '$' + Math.round(c / 100); }

  function sync() {
    var cents = state.cents;
    if (cents === null) {
      var f = parseFloat((document.getElementById('gp-custom').value || '').replace(/[^0-9.]/g, ''));
      cents = (!isNaN(f) && f > 0) ? Math.round(f * 100) : 0;
    }
    document.getElementById('gp-preview-amt').textContent = cents ? short(cents) : '$—';
    document.getElementById('gp-sum-amt').textContent = cents ? fmt(cents) : '—';
    document.getElementById('gp-sum-total').textContent = cents ? fmt(cents) : '—';
    document.getElementById('gp-sum-label').textContent = state.type === 'egift' ? 'E-gift card' : 'Physical card';
    return cents;
  }

  document.querySelectorAll('#gp-type label').forEach(function (l) {
    l.addEventListener('click', function () {
      document.querySelectorAll('#gp-type label').forEach(function (x) { x.classList.remove('on'); });
      l.classList.add('on');
      state.type = l.dataset.type;
      var egift = state.type === 'egift';
      document.getElementById('gp-egift-fields').style.display = egift ? '' : 'none';
      document.getElementById('gp-physical-note').style.display = egift ? 'none' : '';
      document.getElementById('gp-send-title').textContent = egift ? '3 · Send it' : '3 · Your details';
      sync();
    });
  });

  document.querySelectorAll('#gp-amounts .amt').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('#gp-amounts .amt').forEach(function (x) { x.classList.remove('on'); });
      b.classList.add('on');
      state.cents = b.dataset.cents ? parseInt(b.dataset.cents, 10) : null;
      document.getElementById('gp-custom-wrap').style.display = state.cents === null ? '' : 'none';
      sync();
    });
  });
  document.getElementById('gp-custom').addEventListener('input', sync);

  document.getElementById('gp-message').addEventListener('input', function () {
    document.getElementById('gp-message-count').textContent = String(this.value.length);
  });
  document.getElementById('gp-deliver-mode').addEventListener('change', function () {
    var onDate = this.value === 'date';
    document.getElementById('gp-date-wrap').style.display = onDate ? '' : 'none';
    document.getElementById('gp-deliver-hint').textContent = onDate
      ? "We'll email the card the morning of your chosen date, with a receipt to you now."
      : "We'll email the card as soon as your payment goes through, with a receipt to you.";
  });

  function err(msg) {
    var el = document.getElementById('gp-err');
    el.textContent = msg; el.style.display = msg ? '' : 'none';
  }

  document.getElementById('gp-continue').addEventListener('click', function () {
    err('');
    var cents = sync();
    if (!cents) { err('Pick or enter an amount.'); return; }
    if (cents < CFG.min || cents > CFG.max) {
      err('Gift card amounts must be between $' + (CFG.min / 100).toFixed(2) + ' and $' + (CFG.max / 100).toFixed(2) + '.');
      return;
    }
    var payload = {
      type: state.type,
      amount: (cents / 100).toFixed(2),
      purchaser_name: document.getElementById('gp-purchaser-name').value.trim(),
      purchaser_email: document.getElementById('gp-purchaser-email').value.trim(),
    };
    if (!payload.purchaser_name || !payload.purchaser_email) { err('Your name and email are required.'); return; }
    if (state.type === 'egift') {
      payload.recipient_name = document.getElementById('gp-recipient-name').value.trim();
      payload.recipient_email = document.getElementById('gp-recipient-email').value.trim();
      if (!payload.recipient_name || !payload.recipient_email) { err('Recipient name and email are required for an e-gift.'); return; }
      var msg = document.getElementById('gp-message').value.trim();
      if (msg) payload.gift_message = msg;
      payload.deliver_mode = document.getElementById('gp-deliver-mode').value;
      if (payload.deliver_mode === 'date') {
        payload.deliver_on = document.getElementById('gp-deliver-on').value;
        if (!payload.deliver_on) { err('Pick a delivery date.'); return; }
      }
    }

    var btn = document.getElementById('gp-continue');
    btn.disabled = true;
    fetch('/gift-cards/purchase', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
      body: JSON.stringify(payload)
    }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
      .then(function (res) {
        btn.disabled = false;
        if (!res.ok || !res.d.ok) { err((res.d && (res.d.message || (res.d.errors && Object.values(res.d.errors)[0][0]))) || 'Could not start payment.'); return; }
        stripe = Stripe(@json($stripePk));
        elements = stripe.elements({ clientSecret: res.d.client_secret });
        elements.create('payment').mount('#gp-payment-element');
        document.getElementById('gp-pay-panel').style.display = '';
        document.getElementById('gp-pay-btn').disabled = false;
        window.gpReturnUrl = res.d.return_url;
        document.getElementById('gp-pay-panel').scrollIntoView({ behavior: 'smooth' });
      })
      .catch(function () { btn.disabled = false; err('Network error — try again.'); });
  });

  document.getElementById('gp-pay-btn').addEventListener('click', function () {
    var btn = this;
    btn.disabled = true;
    var pe = document.getElementById('gp-pay-err');
    pe.style.display = 'none';
    stripe.confirmPayment({ elements: elements, confirmParams: { return_url: window.gpReturnUrl } })
      .then(function (result) {
        if (result && result.error) {
          pe.textContent = result.error.message || 'Payment failed.'; pe.style.display = '';
          btn.disabled = false;
        }
      });
  });

  if (CFG.default_message) {
    document.getElementById('gp-message').value = CFG.default_message;
    document.getElementById('gp-message-count').textContent = String(CFG.default_message.length);
  }
  if (!CFG.egift) {
    document.getElementById('gp-egift-fields').style.display = 'none';
    document.getElementById('gp-physical-note').style.display = '';
    document.getElementById('gp-send-title').textContent = '3 · Your details';
  }
  sync();
})();
</script>
@endif
