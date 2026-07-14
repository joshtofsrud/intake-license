<!DOCTYPE html>
{{-- MARKER-REGISTER-RECON-DISPLAY — full-screen customer display for one register.
     Token in the URL is the credential; page is read-only and polls for state. --}}
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>{{ $tenant->business_name }} — Register {{ $register->number }}</title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; -webkit-user-select:none; user-select:none }
  html,body { height:100% }
  body {
    font-family: -apple-system, 'Inter', system-ui, sans-serif;
    background:#0B0B0B; color:#EDEDED; display:flex; flex-direction:column;
    overflow:hidden;
  }
  .top { display:flex; justify-content:space-between; align-items:center; padding:22px 30px; border-bottom:1px solid #1E1E1E }
  .biz { font-size:19px; font-weight:800; letter-spacing:-.02em }
  .reg { font-size:12px; font-weight:700; letter-spacing:.12em; text-transform:uppercase; color:#8A8A8A }
  .main { flex:1; display:flex; flex-direction:column; overflow:hidden }

  /* idle */
  .idle { flex:1; display:none; align-items:center; justify-content:center; flex-direction:column; gap:14px; text-align:center; padding:30px }
  .idle .hello { font-size:clamp(30px,5vw,52px); font-weight:800; letter-spacing:-.03em }
  .idle .sub { color:#8A8A8A; font-size:16px }

  /* cart */
  .cart { flex:1; display:none; flex-direction:column; overflow:hidden }
  .lines { flex:1; overflow-y:auto; padding:18px 30px }
  .line { display:flex; justify-content:space-between; gap:16px; padding:13px 0; border-bottom:1px solid #181818; font-size:19px }
  .line .n { flex:1 }
  .line .q { color:#8A8A8A; font-size:15px }
  .line.refund { color:#F09595 }
  .totals { border-top:1px solid #242424; padding:16px 30px 22px; background:#101010 }
  .trow { display:flex; justify-content:space-between; padding:4px 0; font-size:16px; color:#B9B9B9 }
  .trow.grand { font-size:clamp(26px,4vw,38px); font-weight:800; color:#fff; padding-top:10px }
  .trow.grand .v { color:#BEF264 }

  /* pay */
  .pay { flex:1; display:none; align-items:center; justify-content:center; flex-direction:column; gap:20px; padding:30px; text-align:center }
  .pay .amt { font-size:clamp(34px,6vw,56px); font-weight:800; letter-spacing:-.03em }
  .pay .amt span { color:#BEF264 }
  #payQr { background:#fff; border-radius:16px; padding:16px; width:min(46vh,300px); height:min(46vh,300px) }
  .pay .hint { color:#8A8A8A; font-size:15px }
</style>
</head>
<body>
  <div class="top">
    <div class="biz">{{ $tenant->business_name }}</div>
    <div class="reg">Register {{ $register->number }} · {{ $register->name }}</div>
  </div>

  <div class="main">
    <div class="idle" id="vIdle" style="display:flex">
      <div class="hello">Welcome to {{ $tenant->business_name }}</div>
      <div class="sub">Your order will appear here.</div>
    </div>

    <div class="cart" id="vCart">
      <div class="lines" id="cartLines"></div>
      <div class="totals" id="cartTotals"></div>
    </div>

    <div class="pay" id="vPay">
      <div class="amt">Total due <span id="payAmt"></span></div>
      <div id="payQr"></div>
      <div class="hint">Scan with your phone camera to pay</div>
    </div>
  </div>

<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
<script>
const POLL_URL = @json(route('tenant.pay_display.poll', ['token' => $register->display_token]));
const fmt = c => '$' + ((c || 0) / 100).toFixed(2);
let lastPayUrl = null;

function show(which) {
  for (const id of ['vIdle', 'vCart', 'vPay']) {
    document.getElementById(id).style.display = (id === which) ? 'flex' : 'none';
  }
}

function render(data) {
  const snap = data.snap;
  if (data.state === 'idle' || !snap || !(snap.items || []).length) { show('vIdle'); return; }

  if (data.state === 'pay' && snap.pay_url) {
    document.getElementById('payAmt').textContent = fmt(snap.total_cents);
    if (snap.pay_url !== lastPayUrl && typeof qrcode === 'function') {
      const qr = qrcode(0, 'M');
      qr.addData(snap.pay_url); qr.make();
      const el = document.getElementById('payQr');
      el.innerHTML = qr.createSvgTag({ scalable: true, margin: 0 });
      el.querySelector('svg').style.cssText = 'width:100%;height:100%';
      lastPayUrl = snap.pay_url;
    }
    show('vPay'); return;
  }

  let html = '';
  for (const i of snap.items) {
    html += '<div class="line' + (i.refund ? ' refund' : '') + '">'
          + '<div class="n">' + esc(i.name) + ' <span class="q">× ' + i.qty + '</span></div>'
          + '<div>' + (i.refund ? '-' : '') + fmt(Math.abs(i.line_cents)) + '</div></div>';
  }
  document.getElementById('cartLines').innerHTML = html;

  let t = '';
  t += trow('Subtotal', fmt(snap.subtotal_cents));
  if (snap.discount_cents)  t += trow('Discount', '-' + fmt(snap.discount_cents));
  if (snap.tax_cents)       t += trow(snap.tax_label || 'Tax', fmt(snap.tax_cents));
  if (snap.surcharge_cents) t += trow('Card surcharge', fmt(snap.surcharge_cents));
  if (snap.tip_cents)       t += trow('Tip', fmt(snap.tip_cents));
  t += '<div class="trow grand"><div>Total</div><div class="v">' + fmt(snap.total_cents) + '</div></div>';
  document.getElementById('cartTotals').innerHTML = t;
  show('vCart');
}

const trow = (l, v) => '<div class="trow"><div>' + l + '</div><div>' + v + '</div></div>';
const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

async function poll() {
  try {
    const r = await fetch(POLL_URL, { cache: 'no-store' });
    if (r.ok) render(await r.json());
  } catch (e) { /* keep last state; retry next tick */ }
}
poll();
setInterval(poll, 1500);
// Keep the screen awake where supported
if ('wakeLock' in navigator) {
  const lock = () => navigator.wakeLock.request('screen').catch(() => {});
  lock();
  document.addEventListener('visibilitychange', () => { if (!document.hidden) lock(); });
}
</script>
</body>
</html>
