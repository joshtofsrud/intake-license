<!DOCTYPE html>
{{-- MARKER-REGISTER-RECON-DISPLAY — full-screen customer display for one register.
     Token in the URL is the credential; page is read-only and polls for state. --}}
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>{{ $tenant->name }} — Register {{ $register->number }}</title>
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

  /* MARKER-RENTAL-WAIVER-DISPLAY-UI — rental waiver + signature */
  .ag { flex:1; display:none; flex-direction:column; overflow:hidden }
  .ag-head { padding:20px 34px 10px; flex:none }
  .ag-title { font-size:25px; font-weight:800; letter-spacing:-.03em }
  .ag-meta { font-size:13px; color:#8A8A8A; margin-top:5px }
  .ag-body { flex:1; min-height:0; overflow-y:auto; padding:6px 34px 18px;
             font-size:15px; line-height:1.85; color:#BFBFBF; white-space:pre-wrap;
             -webkit-overflow-scrolling:touch }
  .ag-foot { flex:none; border-top:1px solid #1E1E1E; background:#101010; padding:14px 34px 18px }
  .ag-siglabel { display:flex; justify-content:space-between; align-items:baseline; margin-bottom:8px }
  .ag-siglabel .l { font-size:11px; font-weight:700; letter-spacing:.13em; text-transform:uppercase; color:#8A8A8A }
  .ag-clear { background:none; border:0; color:#8A8A8A; font-size:12px; font-family:inherit;
              cursor:pointer; text-decoration:underline; text-underline-offset:3px; -webkit-user-select:none }
  .ag-sigwrap { position:relative; background:#F7F7F5; border-radius:11px; height:120px;
                overflow:hidden; touch-action:none }
  #agPad { display:block; width:100%; height:100% }
  .ag-sigline { position:absolute; left:26px; right:26px; bottom:30px; height:1px; background:#C9C9C4; pointer-events:none }
  .ag-sigx { position:absolute; left:26px; bottom:34px; font-size:17px; color:#A8A8A2; pointer-events:none; font-weight:600 }
  .ag-sighint { position:absolute; left:0; right:0; bottom:8px; text-align:center; font-size:12px;
                color:#9A9A94; pointer-events:none; transition:opacity .2s }
  .ag-actions { display:flex; align-items:flex-end; gap:16px; margin-top:13px }
  .ag-name { flex:1 }
  .ag-name label { display:block; font-size:11px; font-weight:700; letter-spacing:.13em;
                   text-transform:uppercase; color:#8A8A8A; margin-bottom:6px }
  .ag-name input { width:100%; background:#0A0A0A; border:1px solid #282828; border-radius:9px;
                   padding:12px 14px; color:#EDEDED; font-size:16px; font-family:inherit;
                   -webkit-user-select:text; user-select:text }
  .ag-name input:focus { outline:none; border-color:#3C4A22 }
  .ag-go { background:#BEF264; border:0; border-radius:11px; padding:16px 32px; color:#0a0a0a;
           font-size:16px; font-weight:750; font-family:inherit; cursor:pointer; flex:none }
  .ag-go:disabled { opacity:.3 }
  .ag-msg { flex:1; display:none; align-items:center; justify-content:center; flex-direction:column;
            gap:16px; text-align:center; padding:30px }
  .ag-tick { width:88px; height:88px; border-radius:50%; background:#BEF264; color:#0a0a0a;
             display:flex; align-items:center; justify-content:center; font-size:44px; font-weight:800 }
  .ag-msg .h { font-size:32px; font-weight:800; letter-spacing:-.03em }
  .ag-msg .s { color:#8A8A8A; font-size:16px; line-height:1.6; max-width:460px }
</style>
</head>
<body>
  <div class="top">
    <div class="biz">{{ $tenant->name }}</div>
    <div class="reg">Register {{ $register->number }} · {{ $register->name }}</div>
  </div>

  <div class="main">
    <div class="idle" id="vIdle" style="display:flex">
      {{-- MARKER-REGISTER-RECON-DISPLAY — Brand Kit logo (light variant for dark screen) --}}
      @php
        $displayLogo = match ($register->display_logo ?? 'auto') {
            'none'  => null,
            'main'  => $tenant->logo_url,
            'light' => $tenant->logo_light_url ?: $tenant->logo_url,
            default => $tenant->logo_light_url ?: $tenant->logo_url,
        };
      @endphp
      @if ($displayLogo)
        <img src="{{ $displayLogo }}" alt="{{ $tenant->name }}"
             style="max-width:min(60vw,420px);max-height:30vh;object-fit:contain;margin-bottom:6px">
      @else
        <div class="hello">Welcome to {{ $tenant->name }}</div>
      @endif
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

    {{-- MARKER-RENTAL-WAIVER-DISPLAY-UI --}}
    <div class="ag" id="vAgree">
      <div class="ag-head">
        <div class="ag-title" id="agTitle"></div>
        <div class="ag-meta" id="agMeta"></div>
      </div>
      <div class="ag-body" id="agBody"></div>
      <div class="ag-foot">
        <div class="ag-siglabel">
          <span class="l">Sign here</span>
          <button type="button" class="ag-clear" id="agClear">Clear</button>
        </div>
        <div class="ag-sigwrap">
          <canvas id="agPad"></canvas>
          <div class="ag-sigline"></div>
          <div class="ag-sigx">&#10005;</div>
          <div class="ag-sighint" id="agHint">Draw your signature with a finger or stylus</div>
        </div>
        <div class="ag-actions">
          <div class="ag-name">
            <label for="agName">Your full name</label>
            <input id="agName" type="text" autocomplete="off" autocapitalize="words">
          </div>
          <button class="ag-go" id="agGo" disabled>Agree &amp; sign</button>
        </div>
      </div>
    </div>

    <div class="ag-msg" id="vAgreeMsg">
      <div class="ag-tick" id="agMsgIcon">&#10003;</div>
      <div class="h" id="agMsgH"></div>
      <div class="s" id="agMsgS"></div>
    </div>
  </div>

{{-- MARKER-REGISTER-RECON-DISPLAY — fullscreen toggle --}}
<button id="fsBtn" style="position:fixed;bottom:18px;right:18px;z-index:10;background:#1E1E1E;color:#BEF264;border:1px solid #333;border-radius:10px;padding:10px 16px;font:600 14px -apple-system,'Inter',sans-serif;cursor:pointer">&#x26F6; Full screen</button>
<script>
(function () {
  const btn = document.getElementById('fsBtn');
  const el = document.documentElement;
  btn.addEventListener('click', () => {
    (el.requestFullscreen || el.webkitRequestFullscreen).call(el);
  });
  const sync = () => {
    btn.style.display = (document.fullscreenElement || document.webkitFullscreenElement) ? 'none' : 'block';
  };
  document.addEventListener('fullscreenchange', sync);
  document.addEventListener('webkitfullscreenchange', sync);
})();
</script>

<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
<script>
const POLL_URL = @json(route('tenant.pay_display.poll', ['token' => $register->display_token]));
const fmt = c => '$' + ((c || 0) / 100).toFixed(2);
let lastPayUrl = null;

function show(which) {
  for (const id of ['vIdle', 'vCart', 'vPay', 'vAgree', 'vAgreeMsg']) {
    document.getElementById(id).style.display = (id === which) ? 'flex' : 'none';
  }
}

function render(data) {
  // MARKER-RENTAL-WAIVER-DISPLAY-UI — the waiver owns the screen while it is
  // up, and agHold keeps the local thank-you / closed message on screen for
  // its few seconds even though the server has already cleared the override.
  if (agHold) { return; }
  if (data.state === 'agreement' && data.agreement) { agShowWaiver(data.agreement); return; }
  if (agActiveNonce) { agReset(); }

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

// ---------------------------------------------------------------- waiver
const SIGN_URL = @json(route('tenant.pay_display.agreement.sign', ['token' => $register->display_token]));

let agActiveNonce = null;   // nonce of the waiver currently rendered
let agHold        = false;  // suppress polling while a message is showing
let agSending     = false;
let agHasInk      = false;
let agDrawing     = false;
let agLastPt      = null;

const agPad  = document.getElementById('agPad');
const agCtx  = agPad.getContext('2d');
const agName = document.getElementById('agName');
const agGo   = document.getElementById('agGo');

function agSizePad() {
  const r = agPad.parentElement.getBoundingClientRect();
  if (!r.width) { return; }
  const dpr = window.devicePixelRatio || 1;
  agPad.width  = Math.round(r.width * dpr);
  agPad.height = Math.round(r.height * dpr);
  agCtx.setTransform(dpr, 0, 0, dpr, 0, 0);
  agCtx.lineWidth = 2.6; agCtx.lineCap = 'round'; agCtx.lineJoin = 'round';
  agCtx.strokeStyle = '#15150F';
}

function agPoint(e) {
  const r = agPad.getBoundingClientRect();
  const t = e.touches ? e.touches[0] : e;
  return { x: t.clientX - r.left, y: t.clientY - r.top };
}
function agStart(e) {
  e.preventDefault(); agDrawing = true; agLastPt = agPoint(e);
  document.getElementById('agHint').style.opacity = '0';
}
function agMove(e) {
  if (!agDrawing) { return; }
  e.preventDefault();
  const p = agPoint(e);
  agCtx.beginPath(); agCtx.moveTo(agLastPt.x, agLastPt.y); agCtx.lineTo(p.x, p.y); agCtx.stroke();
  agLastPt = p; agHasInk = true; agSync();
}
function agEnd() { agDrawing = false; agLastPt = null; }

agPad.addEventListener('mousedown', agStart);
agPad.addEventListener('touchstart', agStart, { passive: false });
window.addEventListener('mousemove', agMove);
agPad.addEventListener('touchmove', agMove, { passive: false });
window.addEventListener('mouseup', agEnd);
agPad.addEventListener('touchend', agEnd);
window.addEventListener('resize', function () { if (agActiveNonce) { agSizePad(); agWipe(); } });

function agWipe() {
  agCtx.clearRect(0, 0, agPad.width, agPad.height);
  agHasInk = false;
  document.getElementById('agHint').style.opacity = '1';
  agSync();
}
function agSync() {
  agGo.disabled = agSending || !(agHasInk && agName.value.trim().length > 1);
}
document.getElementById('agClear').addEventListener('click', agWipe);
agName.addEventListener('input', agSync);

function agReset() {
  agActiveNonce = null; agSending = false;
  agWipe(); agName.value = '';
}

function agShowWaiver(a) {
  // Only rebuild when the push actually changed — otherwise every 1.5s poll
  // would clear a signature the customer is halfway through drawing.
  if (a.nonce === agActiveNonce) { return; }
  agActiveNonce = a.nonce;
  document.getElementById('agTitle').textContent = a.title;
  document.getElementById('agMeta').textContent =
    a.rental_number + (a.customer_name ? ' \u00b7 ' + a.customer_name : '') + ' \u00b7 version ' + a.version;
  document.getElementById('agBody').textContent = a.body;
  document.getElementById('agBody').scrollTop = 0;
  agName.value = a.customer_name || '';
  agWipe();
  show('vAgree');
  agSizePad();
}

function agMessage(icon, head, sub, seconds) {
  document.getElementById('agMsgIcon').innerHTML = icon;
  document.getElementById('agMsgH').textContent = head;
  document.getElementById('agMsgS').textContent = sub;
  show('vAgreeMsg');
  agHold = true;
  setTimeout(function () { agHold = false; agReset(); poll(); }, seconds * 1000);
}

agGo.addEventListener('click', async function () {
  if (agGo.disabled) { return; }
  agSending = true; agSync();
  let payload = {
    signer_name: agName.value.trim(),
    nonce: agActiveNonce,
    signature: agHasInk ? agPad.toDataURL('image/png') : null
  };
  try {
    const r = await fetch(SIGN_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(payload)
    });
    const j = await r.json();
    if (j.ok) {
      agMessage('&#10003;', 'Thank you, ' + payload.signer_name.split(' ')[0],
                'Your agreement is signed. Please hand the screen back.', 6);
    } else {
      agMessage('&#8635;', 'This waiver was closed',
                'It was taken back at the counter. Nothing to do here.', 6);
    }
  } catch (e) {
    // Network hiccup: let them try again rather than stranding the screen.
    agSending = false; agSync();
    agMessage('&#8635;', 'Could not send that', 'Please try signing again.', 4);
  }
});

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
