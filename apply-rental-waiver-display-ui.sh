#!/usr/bin/env bash
# apply-rental-waiver-display-ui.sh
# MARKER-RENTAL-WAIVER-DISPLAY-UI — patch 3 of 3: the two screens + the PDF.
#
# Loop-back guarantees wired here:
#   * The tablet re-renders the waiver only when the NONCE changes, so the
#     1.5s poll can't wipe a half-drawn signature.
#   * After signing, the tablet holds its own thank-you for 6s and ignores
#     polls, then releases — the server already cleared the override, so it
#     lands back on idle or the live cart with no manual step.
#   * A closed/expired/recalled waiver renders a plain message and releases
#     the same way. Nothing on the tablet requires a staff touch to clear.
#   * The staff page polls status: the moment a signature lands, step 2
#     flips to signed and Continue enables without a reload.
#   * "No register selected" answers with a picker inline, so the button
#     never dead-ends.
set -e

python3 <<'PY'
import io

# =====================================================================
# 1. CUSTOMER DISPLAY
# =====================================================================
p = 'resources/views/tenant/register/display.blade.php'
s = io.open(p, encoding='utf-8').read()

# ---- styles
old = """  .pay .hint { color:#8A8A8A; font-size:15px }
</style>"""
assert s.count(old) == 1, 'V1 display style anchor'
s = s.replace(old, """  .pay .hint { color:#8A8A8A; font-size:15px }

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
</style>""")

# ---- markup
old = """    <div class="pay" id="vPay">
      <div class="amt">Total due <span id="payAmt"></span></div>
      <div id="payQr"></div>
      <div class="hint">Scan with your phone camera to pay</div>
    </div>
  </div>"""
assert s.count(old) == 1, 'V2 display markup anchor'
s = s.replace(old, """    <div class="pay" id="vPay">
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
  </div>""")

# ---- show() gains the two new panels
old = """  for (const id of ['vIdle', 'vCart', 'vPay']) {"""
assert s.count(old) == 1, 'V3 show() anchor'
s = s.replace(old, """  for (const id of ['vIdle', 'vCart', 'vPay', 'vAgree', 'vAgreeMsg']) {""")

# ---- render() branches to the waiver before any cart handling
old = """function render(data) {
  const snap = data.snap;"""
assert s.count(old) == 1, 'V4 render() anchor'
s = s.replace(old, """function render(data) {
  // MARKER-RENTAL-WAIVER-DISPLAY-UI — the waiver owns the screen while it is
  // up, and agHold keeps the local thank-you / closed message on screen for
  // its few seconds even though the server has already cleared the override.
  if (agHold) { return; }
  if (data.state === 'agreement' && data.agreement) { agShowWaiver(data.agreement); return; }
  if (agActiveNonce) { agReset(); }

  const snap = data.snap;""")

# ---- waiver behaviour, inserted ahead of the poll loop
old = """async function poll() {"""
assert s.count(old) == 1, 'V5 poll anchor'
s = s.replace(old, """// ---------------------------------------------------------------- waiver
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
    a.rental_number + (a.customer_name ? ' \\u00b7 ' + a.customer_name : '') + ' \\u00b7 version ' + a.version;
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

async function poll() {""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# =====================================================================
# 2. STAFF CHECK-OUT — step 2
# =====================================================================
p = 'resources/views/tenant/rentals/bookings/check-out.blade.php'
s = io.open(p, encoding='utf-8').read()

# ---- signed card gains signer + signature thumb
old = """          <h2 class="ia-h3" style="margin-bottom:8px">Agreement signed</h2>
          <p style="font-size:12.5px;opacity:.55">v{{ $rental->agreement_template_version }} · {{ tlocal_datetime($rental->agreement_signed_at, 'M j, g:i A') }}"""
assert s.count(old) == 1, 'S1 signed card anchor'
s = s.replace(old, """          <h2 class="ia-h3" style="margin-bottom:8px">Agreement signed</h2>
          @if($rental->agreement_signature_path)
            <img src="{{ Storage::disk('public')->url($rental->agreement_signature_path) }}" alt="Signature"
                 style="max-height:64px;background:#F7F7F5;border-radius:7px;padding:5px 9px;margin-bottom:9px;display:block">
          @endif
          <p style="font-size:12.5px;opacity:.55">
            @if($rental->agreement_signer_name){{ $rental->agreement_signer_name }} · @endif
            v{{ $rental->agreement_template_version }} · {{ tlocal_datetime($rental->agreement_signed_at, 'M j, g:i A') }}
            @if($rental->agreement_method === 'display') · signed on the customer display @endif""")

# ---- unsigned branch: method chooser + push controls
old = """          <div class="co-agree-body">{{ $agreementTemplate->body }}</div>
          <form method="POST" action="{{ route('tenant.rentals.bookings.agreement.sign', $rental->id) }}" style="margin-top:14px">
            @csrf
            <div style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
              <div style="flex:1;min-width:220px">
                <label class="ia-label" style="display:block;margin-bottom:5px">Customer signs by typing their full name</label>
                <input type="text" name="signer_name" maxlength="160" required class="ia-input" style="width:100%" placeholder="{{ $rental->customer?->fullName() }}">
              </div>
              <button type="submit" class="ia-btn ia-btn--primary">Sign agreement</button>
            </div>
            <label style="display:flex;gap:9px;align-items:center;font-size:12.5px;margin-top:10px;cursor:pointer">
              <input type="checkbox" name="agreed" value="1" required> Customer has read and agrees to the terms above
            </label>
          </form>"""
assert s.count(old) == 1, 'S2 unsigned branch anchor'
s = s.replace(old, """          <div class="co-agree-body">{{ $agreementTemplate->body }}</div>

          {{-- MARKER-RENTAL-WAIVER-DISPLAY-UI — where does the customer sign? --}}
          <div id="ag-methods" style="display:grid;grid-template-columns:1fr 1fr;gap:11px;margin:14px 0 4px">
            <div class="ag-method on" data-method="display" style="border:1.5px solid var(--ia-accent);border-radius:10px;padding:13px;cursor:pointer;background:rgba(190,242,100,.05)">
              <div style="font-size:13px;font-weight:650;margin-bottom:4px">Send to customer display</div>
              <div style="font-size:11.5px;opacity:.55;line-height:1.55">They read and sign on the paired screen.</div>
            </div>
            <div class="ag-method" data-method="desk" style="border:1.5px solid var(--ia-border,#282828);border-radius:10px;padding:13px;cursor:pointer">
              <div style="font-size:13px;font-weight:650;margin-bottom:4px">Sign at this screen</div>
              <div style="font-size:11.5px;opacity:.55;line-height:1.55">Type their name and confirm here.</div>
            </div>
          </div>

          {{-- display path --}}
          <div id="ag-display-pane" style="margin-top:14px">
            <div id="ag-send-row">
              <button type="button" id="ag-send" class="ia-btn ia-btn--primary">Send to display →</button>
              <span id="ag-send-note" style="font-size:11.5px;opacity:.55;margin-left:10px"></span>
            </div>

            <div id="ag-waiting" style="display:none;border:1px solid #2A3317;background:rgba(190,242,100,.06);border-radius:9px;padding:13px 15px">
              <div style="font-size:12.5px;font-weight:650;color:var(--ia-accent);margin-bottom:4px">Waiting on the renter</div>
              <div style="font-size:11.5px;opacity:.7;line-height:1.6" id="ag-waiting-note">The waiver is up on the customer display. This step completes on its own when they sign — you can start the condition check and come back.</div>
              <div style="margin-top:11px;display:flex;gap:9px;flex-wrap:wrap">
                <button type="button" id="ag-recall" class="ia-btn">Recall from display</button>
                <button type="button" class="ia-btn" onclick="coGo(3)">Start condition check →</button>
              </div>
            </div>

            <div id="ag-pick" style="display:none;border:1px solid #3A3117;background:rgba(240,198,116,.07);border-radius:9px;padding:13px 15px;margin-top:10px">
              <div style="font-size:12.5px;font-weight:650;margin-bottom:4px">Pick the register at this counter</div>
              <div style="font-size:11.5px;opacity:.7;line-height:1.6">The customer display is paired to a register, so choose the one in front of you — or have them sign at this screen instead.</div>
              <div style="display:flex;gap:9px;margin-top:11px;flex-wrap:wrap">
                <select id="ag-registers" class="ia-input" style="min-width:220px"></select>
                <button type="button" id="ag-select-register" class="ia-btn">Use this register</button>
              </div>
            </div>
          </div>

          {{-- desk path --}}
          <form method="POST" action="{{ route('tenant.rentals.bookings.agreement.sign', $rental->id) }}" style="margin-top:14px;display:none" id="ag-desk-pane">
            @csrf
            <div style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
              <div style="flex:1;min-width:220px">
                <label class="ia-label" style="display:block;margin-bottom:5px">Customer signs by typing their full name</label>
                <input type="text" name="signer_name" maxlength="160" class="ia-input" style="width:100%" placeholder="{{ $rental->customer?->fullName() }}">
              </div>
              <button type="submit" class="ia-btn ia-btn--primary">Sign agreement</button>
            </div>
            <label style="display:flex;gap:9px;align-items:center;font-size:12.5px;margin-top:10px;cursor:pointer">
              <input type="checkbox" name="agreed" value="1"> Customer has read and agrees to the terms above
            </label>
          </form>

          {{-- filled by the status poll the moment a signature lands --}}
          <div id="ag-signed-live" style="display:none;margin-top:14px;border:1px solid #2A3317;background:rgba(190,242,100,.06);border-radius:9px;padding:14px 15px">
            <div style="font-size:13.5px;font-weight:650" id="ag-signed-name"></div>
            <div style="font-size:11.5px;opacity:.6;margin-top:3px" id="ag-signed-meta"></div>
          </div>""")

# ---- Continue button needs an id so the poll can enable it
old = """      <div class="co-foot"><button type="button" class="ia-btn" onclick="coGo(1)">← Back</button><button type="button" class="ia-btn ia-btn--primary" onclick="coGo(3)" {{ $agreementDone ? '' : 'disabled' }}>Continue →</button></div>"""
assert s.count(old) == 1, 'S3 continue button anchor'
s = s.replace(old, """      <div class="co-foot"><button type="button" class="ia-btn" onclick="coGo(1)">← Back</button><button type="button" id="ag-continue" class="ia-btn ia-btn--primary" onclick="coGo(3)" {{ $agreementDone ? '' : 'disabled' }}>Continue →</button></div>""")

# ---- behaviour, inside the section (a script after @endsection is discarded)
old = """@endif

@endsection"""
assert s.count(old) == 1, 'S4 endsection anchor'
s = s.replace(old, """@endif

{{-- MARKER-RENTAL-WAIVER-DISPLAY-UI — push, recall, and live status --}}
@if($agreementTemplate && !$agreementSigned)
<script>
(function () {
  var SEND   = @json(route('tenant.rentals.bookings.agreement.send_display', $rental->id));
  var RECALL = @json(route('tenant.rentals.bookings.agreement.recall_display', $rental->id));
  var STATUS = @json(route('tenant.rentals.bookings.agreement.status', $rental->id));
  var SELECT = @json(route('tenant.register.select'));
  var CSRF   = @json(csrf_token());

  var sendRow = document.getElementById('ag-send-row');
  var waiting = document.getElementById('ag-waiting');
  var pick    = document.getElementById('ag-pick');
  var note    = document.getElementById('ag-send-note');
  var signed  = document.getElementById('ag-signed-live');
  var timer   = null;

  function post(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify(body || {})
    }).then(function (r) { return r.json(); });
  }

  // method chooser
  document.querySelectorAll('.ag-method').forEach(function (m) {
    m.addEventListener('click', function () {
      var isDisplay = m.dataset.method === 'display';
      document.querySelectorAll('.ag-method').forEach(function (x) {
        var on = x === m;
        x.classList.toggle('on', on);
        x.style.borderColor = on ? 'var(--ia-accent)' : '#282828';
        x.style.background  = on ? 'rgba(190,242,100,.05)' : 'transparent';
      });
      document.getElementById('ag-display-pane').style.display = isDisplay ? '' : 'none';
      document.getElementById('ag-desk-pane').style.display    = isDisplay ? 'none' : '';
    });
  });

  function showWaiting(on, label) {
    sendRow.style.display = on ? 'none' : '';
    waiting.style.display = on ? '' : 'none';
    if (on && label) {
      document.getElementById('ag-waiting-note').textContent =
        'The waiver is up on ' + label + '. This step completes on its own when they sign — you can start the condition check and come back.';
    }
  }

  document.getElementById('ag-send').addEventListener('click', function () {
    note.textContent = 'Sending…';
    post(SEND).then(function (j) {
      note.textContent = '';
      if (j.ok) {
        pick.style.display = 'none';
        showWaiting(true, 'Register ' + j.register.number + ' · ' + j.register.name);
        start();
        return;
      }
      if (j.code === 'no_register') {
        var sel = document.getElementById('ag-registers');
        sel.innerHTML = '';
        (j.registers || []).forEach(function (r) {
          var o = document.createElement('option');
          o.value = r.id; o.textContent = r.label; sel.appendChild(o);
        });
        if (!(j.registers || []).length) {
          sel.innerHTML = '<option value="">No registers set up yet</option>';
        }
        pick.style.display = '';
        return;
      }
      if (j.code === 'already_signed') { check(); return; }
      note.textContent = j.code === 'no_template'
        ? 'No agreement template is configured.'
        : 'This rental is no longer reserved.';
    }).catch(function () { note.textContent = 'Could not reach the server — try again.'; });
  });

  document.getElementById('ag-select-register').addEventListener('click', function () {
    var v = document.getElementById('ag-registers').value;
    if (!v) { return; }
    post(SELECT, { register_id: parseInt(v, 10) }).then(function () {
      pick.style.display = 'none';
      document.getElementById('ag-send').click();
    });
  });

  document.getElementById('ag-recall').addEventListener('click', function () {
    post(RECALL).then(function () { showWaiting(false); stop(); });
  });

  function check() {
    return fetch(STATUS, { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) { return; }
      if (j.signed) {
        stop();
        showWaiting(false);
        sendRow.style.display = 'none';
        pick.style.display = 'none';
        document.getElementById('ag-methods').style.display = 'none';
        document.getElementById('ag-desk-pane').style.display = 'none';
        document.getElementById('ag-signed-name').textContent = 'Signed by ' + (j.signer_name || 'the renter');
        document.getElementById('ag-signed-meta').textContent =
          'v' + j.version + ' \\u00b7 ' + (j.method === 'display' ? 'on the customer display' : 'at the desk') + ' \\u00b7 ' + j.signed_at;
        signed.style.display = '';
        var cont = document.getElementById('ag-continue');
        if (cont) { cont.removeAttribute('disabled'); }
        var chip = document.querySelector('.co-step[data-step="2"]');
        if (chip) { chip.classList.add('done'); chip.querySelector('.co-n').textContent = '\\u2713'; }
        return;
      }
      if (j.display === 'expired') {
        // The push aged out. Say so rather than leaving a Waiting box that
        // will never resolve.
        showWaiting(false);
        note.textContent = 'That waiver timed out on the screen — send it again.';
        stop();
      }
    }).catch(function () { /* transient; next tick */ });
  }

  function start() { stop(); timer = setInterval(check, 3000); }
  function stop()  { if (timer) { clearInterval(timer); timer = null; } }

  // If a waiver is already live for this rental (staff reloaded the page or
  // came back from the condition step), pick the waiting state back up.
  check().then(function () {
    fetch(STATUS, { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) {
      if (j.ok && !j.signed && j.display === 'waiting') { showWaiting(true, j.register); start(); }
    }).catch(function () {});
  });

  window.addEventListener('beforeunload', stop);
})();
</script>
@endif

@endsection""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# =====================================================================
# 3. PDF — embed the signature when there is one
# =====================================================================
p = 'resources/views/tenant/rentals/agreement-pdf.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """  <div class="sig">
    <b>{{ $signerName }}</b>
    <div class="meta">Signed at the counter · {{ tlocal_datetime($signedAt, 'M j, Y g:i A') }} · Agreement v{{ $template->version }} · Customer: {{ $rental->customer?->fullName() }}</div>
  </div>"""
assert s.count(old) == 1, 'P1 pdf sig anchor'
s = s.replace(old, """  <div class="sig">
    @php
      // MARKER-RENTAL-WAIVER-DISPLAY-UI — inline the drawn signature. Base64
      // rather than a path so DomPDF never depends on filesystem access.
      $sigPath = $signaturePath ?? null;
      $sigData = null;
      if ($sigPath && \\Illuminate\\Support\\Facades\\Storage::disk('public')->exists($sigPath)) {
          try {
              $sigData = 'data:image/png;base64,' . base64_encode(
                  \\Illuminate\\Support\\Facades\\Storage::disk('public')->get($sigPath)
              );
          } catch (\\Throwable $e) {
              $sigData = null;
          }
      }
    @endphp
    @if($sigData)
      <img src="{{ $sigData }}" alt="Signature" style="max-height:60px;margin-bottom:4px">
    @endif
    <b>{{ $signerName }}</b>
    <div class="meta">{{ ($rental->agreement_method ?? 'desk') === 'display' ? 'Signed on the customer display' : 'Signed at the counter' }} · {{ tlocal_datetime($signedAt, 'M j, Y g:i A') }} · Agreement v{{ $template->version }} · Customer: {{ $rental->customer?->fullName() }}</div>
  </div>""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo "--- blade directive-glued-to-word sweep (new files only) ---"
python3 - <<'PY'
import io, re
for f in ['resources/views/tenant/register/display.blade.php',
          'resources/views/tenant/rentals/bookings/check-out.blade.php',
          'resources/views/tenant/rentals/agreement-pdf.blade.php']:
    s = io.open(f, encoding='utf-8').read()
    s = re.sub(r'\{\{--.*?--\}\}', '', s, flags=re.S)
    hits = re.findall(r'\w@(?:if|endif|foreach|endforeach|elseif|else|unless|php|endphp)\b', s)
    print(f, 'glued directives:', len(hits), hits[:4])
PY

echo "--- blade directive pairing ---"
python3 - <<'PY'
import io, re
for f in ['resources/views/tenant/register/display.blade.php',
          'resources/views/tenant/rentals/bookings/check-out.blade.php',
          'resources/views/tenant/rentals/agreement-pdf.blade.php']:
    s = io.open(f, encoding='utf-8').read()
    s = re.sub(r'\{\{--.*?--\}\}', '', s, flags=re.S)
    for a, b in [('@if', '@endif'), ('@foreach', '@endforeach'), ('@php', '@endphp'), ('@section', '@endsection')]:
        o = len(re.findall(r'\B' + a + r'\b', s))
        c = len(re.findall(r'\B' + b + r'\b', s))
        if a == '@if':
            o -= len(re.findall(r'\B@endif\b', s)) * 0
        if o != c:
            print(' MISMATCH', f, a, o, b, c)
    print(f, 'checked')
PY

echo
echo "apply-rental-waiver-display-ui: OK"
