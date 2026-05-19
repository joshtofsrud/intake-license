<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Who's here? — {{ $currentTenant->name }}</title>
  @if($currentTenant->favicon_url)
    <link rel="icon" href="{{ $currentTenant->favicon_url }}">
  @endif
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Inter',-apple-system,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;-webkit-font-smoothing:antialiased}
    :root{
      --accent: {{ $currentTenant->accent_color ?? '#BEF264' }};
      --accent-text: {{ \App\Support\ColorHelper::accentTextColor($currentTenant->accent_color ?? '#BEF264') }};
      --bg:     #0f0f0f;
      --bg2:    #1a1a1a;
      --text:   #f0f0f0;
      --muted:  rgba(255,255,255,.4);
      --border: rgba(255,255,255,.1);
      --error:  #F09595;
    }
    .card{background:var(--bg2);border:0.5px solid var(--border);border-radius:16px;padding:32px;width:100%;max-width:520px}
    .logo-wrap{text-align:center;margin-bottom:24px}
    .logo-wrap img{height:40px;margin:0 auto 10px;display:block;border-radius:6px}
    .shop-name{font-size:18px;font-weight:600;color:var(--text)}
    .shop-sub{font-size:13px;color:var(--muted);margin-top:4px}
    h1{font-size:20px;font-weight:600;margin-bottom:6px;text-align:center}
    .lede{font-size:13px;color:var(--muted);text-align:center;margin-bottom:22px}

    .staff-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}
    @media (max-width:480px){.staff-grid{grid-template-columns:1fr}}
    .staff-card{
      display:flex;align-items:center;gap:12px;
      padding:14px 14px;
      background:rgba(255,255,255,.04);border:0.5px solid var(--border);border-radius:10px;
      color:var(--text);font-family:inherit;font-size:14px;text-align:left;
      cursor:pointer;transition:all .12s;width:100%
    }
    .staff-card:hover{background:rgba(255,255,255,.07);border-color:var(--accent)}
    .staff-card .avatar{
      width:36px;height:36px;border-radius:50%;background:var(--accent);color:var(--accent-text);
      display:flex;align-items:center;justify-content:center;font-weight:600;font-size:12px;flex-shrink:0;
    }
    .staff-card .meta{flex:1;min-width:0}
    .staff-card .name{font-weight:600;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .staff-card .role{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-top:2px}

    /* PIN stage */
    .pin-stage{display:none}
    .pin-stage.active{display:block}
    .pin-header{display:flex;align-items:center;gap:14px;margin-bottom:22px;padding-bottom:18px;border-bottom:0.5px solid var(--border)}
    .pin-header .avatar{width:48px;height:48px;border-radius:50%;background:var(--accent);color:var(--accent-text);display:flex;align-items:center;justify-content:center;font-weight:600;font-size:16px;flex-shrink:0}
    .pin-header .who{flex:1}
    .pin-header .name{font-weight:600;font-size:16px}
    .pin-header .role{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-top:2px}

    .pin-input-wrap{display:flex;justify-content:center;gap:10px;margin-bottom:18px}
    .pin-input{
      width:54px;height:64px;
      background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:10px;
      color:var(--text);font-size:24px;font-weight:600;text-align:center;font-family:inherit;
      transition:border-color .12s
    }
    .pin-input:focus{outline:none;border-color:var(--accent)}
    .pin-input.error{border-color:var(--error)}

    .pin-msg{font-size:13px;text-align:center;min-height:18px;margin-bottom:14px}
    .pin-msg.error{color:var(--error)}
    .pin-msg.info{color:var(--muted)}

    .btn{width:100%;padding:12px;background:var(--accent);color:var(--accent-text);border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;transition:filter .12s}
    .btn:hover:not(:disabled){filter:brightness(.93)}
    .btn:disabled{opacity:.5;cursor:not-allowed}
    .btn-ghost{background:transparent;color:var(--muted);border:0.5px solid var(--border)}
    .btn-ghost:hover:not(:disabled){background:rgba(255,255,255,.04);color:var(--text)}

    .row{display:flex;gap:10px;margin-top:10px}
    .row .btn{flex:1}

    .links{text-align:center;margin-top:14px;font-size:13px}
    .links a, .links button{color:var(--muted);transition:color .12s;background:none;border:none;cursor:pointer;font:inherit;text-decoration:underline;text-underline-offset:2px}
    .links a:hover, .links button:hover{color:var(--text)}

    /* Set-initial-PIN stage */
    label{display:block;font-size:12px;font-weight:500;color:var(--muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em;margin-top:14px}
    input[type=password],input[type=text]{width:100%;padding:10px 14px;background:rgba(255,255,255,.05);border:0.5px solid var(--border);border-radius:8px;color:var(--text);font-size:14px;font-family:inherit;transition:border-color .12s}
    input:focus{outline:none;border-color:var(--accent)}
    .hint{font-size:11px;color:var(--muted);margin-top:6px;line-height:1.45}
  </style>
</head>
<body>
<div class="card" id="root">

  <div class="logo-wrap">
    @if($currentTenant->logo_url)
      <img src="{{ $currentTenant->logo_url }}" alt="{{ $currentTenant->name }}">
    @endif
    <div class="shop-name">{{ $currentTenant->name }}</div>
    <div class="shop-sub">Staff sign-in</div>
  </div>

  {{-- STAGE 1: STAFF GRID --}}
  <div id="stage-grid">
    <h1>Who's here?</h1>
    <p class="lede">Tap your name to continue.</p>
    <div class="staff-grid">
      @foreach($staff as $s)
        <button class="staff-card" data-staff-card
                data-user-id="{{ $s->id }}"
                data-name="{{ $s->name }}"
                data-role="{{ ucfirst($s->role) }}"
                data-pin-set="{{ $s->pin_hash ? '1' : '0' }}">
          <div class="avatar">{{ strtoupper(substr($s->name, 0, 2)) }}</div>
          <div class="meta">
            <div class="name">{{ $s->name }}</div>
            <div class="role">{{ ucfirst($s->role) }}</div>
          </div>
        </button>
      @endforeach
    </div>
    <div class="links" style="margin-top:18px">
      <a href="{{ route('tenant.login') }}">Use email + password instead</a>
    </div>
  </div>

  {{-- STAGE 2: PIN ENTRY --}}
  <div id="stage-pin" class="pin-stage">
    <div class="pin-header">
      <div class="avatar" id="pin-avatar"></div>
      <div class="who">
        <div class="name" id="pin-name"></div>
        <div class="role" id="pin-role"></div>
      </div>
    </div>
    <p class="lede">Enter your 4-digit PIN.</p>
    <div class="pin-input-wrap">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="pin-input" data-pin-pos="0" autocomplete="off">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="pin-input" data-pin-pos="1" autocomplete="off">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="pin-input" data-pin-pos="2" autocomplete="off">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="pin-input" data-pin-pos="3" autocomplete="off">
    </div>
    <div class="pin-msg" id="pin-msg"></div>
    <div class="row">
      <button type="button" class="btn btn-ghost" data-action="back">← Not you?</button>
      <button type="button" class="btn" data-action="submit-pin">Sign in</button>
    </div>
    <div class="links">
      <button type="button" data-action="forgot">Forgot PIN?</button>
    </div>
  </div>

  {{-- STAGE 3: SET INITIAL PIN --}}
  <div id="stage-set" class="pin-stage">
    <div class="pin-header">
      <div class="avatar" id="set-avatar"></div>
      <div class="who">
        <div class="name" id="set-name"></div>
        <div class="role">First-time setup</div>
      </div>
    </div>
    <p class="lede">Choose a 4-digit PIN. Use it on this device from now on.</p>

    <div class="pin-input-wrap">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="pin-input" data-set-pos="0" autocomplete="off">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="pin-input" data-set-pos="1" autocomplete="off">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="pin-input" data-set-pos="2" autocomplete="off">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="pin-input" data-set-pos="3" autocomplete="off">
    </div>

    <p class="lede" style="margin:14px 0 6px;font-size:12px">Confirm:</p>
    <div class="pin-input-wrap">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="pin-input" data-confirm-pos="0" autocomplete="off">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="pin-input" data-confirm-pos="1" autocomplete="off">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="pin-input" data-confirm-pos="2" autocomplete="off">
      <input type="text" inputmode="numeric" pattern="\d*" maxlength="1" class="pin-input" data-confirm-pos="3" autocomplete="off">
    </div>

    <label>Your account password (anyone at this shop)</label>
    <input type="password" id="set-device-password" placeholder="••••••••" autocomplete="off">
    <div class="hint">Second factor: re-enter the email password from this device. Prevents someone setting a PIN on your behalf.</div>

    <div class="pin-msg" id="set-msg" style="margin-top:18px"></div>

    <div class="row">
      <button type="button" class="btn btn-ghost" data-action="back-from-set">← Back</button>
      <button type="button" class="btn" data-action="submit-set">Save PIN &amp; sign in</button>
    </div>
  </div>

</div>

<script>
(function(){
  const csrf = document.querySelector('meta[name=csrf-token]').content;

  const stageGrid = document.getElementById('stage-grid');
  const stagePin  = document.getElementById('stage-pin');
  const stageSet  = document.getElementById('stage-set');

  const pinInputs     = Array.from(document.querySelectorAll('[data-pin-pos]'));
  const setInputs     = Array.from(document.querySelectorAll('[data-set-pos]'));
  const confirmInputs = Array.from(document.querySelectorAll('[data-confirm-pos]'));

  let activeUser = null; // { id, name, role, pinSet }

  function showStage(which) {
    stageGrid.style.display = (which === 'grid') ? '' : 'none';
    stagePin.classList.toggle('active', which === 'pin');
    stageSet.classList.toggle('active', which === 'set');

    if (which === 'pin') {
      pinInputs.forEach(i => i.value = '');
      pinInputs[0].focus();
      msg('pin', '');
    }
    if (which === 'set') {
      setInputs.forEach(i => i.value = '');
      confirmInputs.forEach(i => i.value = '');
      document.getElementById('set-device-password').value = '';
      setInputs[0].focus();
      msg('set', '');
    }
  }

  function msg(stage, text, kind) {
    const el = document.getElementById(stage === 'set' ? 'set-msg' : 'pin-msg');
    el.textContent = text || '';
    el.className = 'pin-msg' + (kind ? ' ' + kind : '');
  }

  function avatarLetters(name) {
    return (name || '?').substring(0, 2).toUpperCase();
  }

  // Auto-advance digit inputs.
  function wireDigitGroup(inputs, onComplete) {
    inputs.forEach((inp, idx) => {
      inp.addEventListener('input', (e) => {
        inp.value = inp.value.replace(/\D/g, '').slice(0, 1);
        if (inp.value && idx < inputs.length - 1) {
          inputs[idx + 1].focus();
        }
        if (inputs.every(i => i.value)) {
          onComplete && onComplete();
        }
      });
      inp.addEventListener('keydown', (e) => {
        if (e.key === 'Backspace' && !inp.value && idx > 0) {
          inputs[idx - 1].focus();
        }
        if (e.key === 'Enter') {
          onComplete && onComplete();
        }
      });
    });
  }

  // === Wire stage 1: staff card click ===
  document.querySelectorAll('[data-staff-card]').forEach(card => {
    card.addEventListener('click', () => {
      activeUser = {
        id: card.dataset.userId,
        name: card.dataset.name,
        role: card.dataset.role,
        pinSet: card.dataset.pinSet === '1',
      };
      if (activeUser.pinSet) {
        document.getElementById('pin-avatar').textContent = avatarLetters(activeUser.name);
        document.getElementById('pin-name').textContent   = activeUser.name;
        document.getElementById('pin-role').textContent   = activeUser.role;
        showStage('pin');
      } else {
        document.getElementById('set-avatar').textContent = avatarLetters(activeUser.name);
        document.getElementById('set-name').textContent   = activeUser.name;
        showStage('set');
      }
    });
  });

  // === Wire stage 2: PIN entry ===
  wireDigitGroup(pinInputs, () => submitPin());

  async function submitPin() {
    const pin = pinInputs.map(i => i.value).join('');
    if (pin.length !== 4 || !activeUser) return;
    msg('pin', 'Checking…', 'info');

    try {
      const res = await fetch('{{ route('tenant.pin.verify') }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: JSON.stringify({ user_id: activeUser.id, pin })
      });
      const body = await res.json();

      if (res.ok && body.ok) {
        window.location.href = body.redirect;
        return;
      }

      if (body.error === 'pin_not_set') {
        // Race: PIN got reset between card load and submit. Fall into set flow.
        document.getElementById('set-avatar').textContent = avatarLetters(activeUser.name);
        document.getElementById('set-name').textContent   = activeUser.name;
        showStage('set');
        return;
      }
      if (body.error === 'pin_locked') {
        msg('pin', 'Too many wrong attempts. Ask an owner to unlock you.', 'error');
        pinInputs.forEach(i => i.classList.add('error'));
        return;
      }
      if (body.error === 'pin_mismatch') {
        msg('pin', "That PIN didn't match. Try again.", 'error');
        pinInputs.forEach(i => { i.value = ''; i.classList.add('error'); });
        pinInputs[0].focus();
        setTimeout(() => pinInputs.forEach(i => i.classList.remove('error')), 600);
        return;
      }
      msg('pin', 'Something went wrong. Try again.', 'error');
    } catch (err) {
      msg('pin', 'Network error. Try again.', 'error');
    }
  }
  document.querySelector('[data-action=submit-pin]').addEventListener('click', submitPin);
  document.querySelector('[data-action=back]').addEventListener('click', () => showStage('grid'));
  document.querySelector('[data-action=back-from-set]').addEventListener('click', () => showStage('grid'));

  // === Wire stage 3: set-initial-PIN ===
  wireDigitGroup(setInputs, () => confirmInputs[0].focus());
  wireDigitGroup(confirmInputs, () => document.getElementById('set-device-password').focus());

  document.querySelector('[data-action=submit-set]').addEventListener('click', submitSetPin);
  document.getElementById('set-device-password').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') submitSetPin();
  });

  async function submitSetPin() {
    const pin = setInputs.map(i => i.value).join('');
    const pinConfirm = confirmInputs.map(i => i.value).join('');
    const devicePassword = document.getElementById('set-device-password').value;

    if (pin.length !== 4) { msg('set', 'PIN must be 4 digits.', 'error'); return; }
    if (pin !== pinConfirm) { msg('set', "Those PINs don't match.", 'error'); return; }
    if (!devicePassword) { msg('set', 'Enter the device password.', 'error'); return; }
    if (!activeUser) return;

    msg('set', 'Saving…', 'info');

    try {
      const res = await fetch('{{ route('tenant.pin.set') }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: JSON.stringify({
          user_id: activeUser.id,
          pin,
          pin_confirm: pinConfirm,
          device_password: devicePassword,
        })
      });
      const body = await res.json();

      if (res.ok && body.ok) {
        window.location.href = body.redirect;
        return;
      }

      if (body.error === 'device_password_mismatch') {
        msg('set', "That password didn't match any account on this device.", 'error');
        return;
      }
      if (body.error === 'pin_already_set') {
        msg('set', 'That account already has a PIN. Use it to sign in.', 'error');
        return;
      }
      msg('set', 'Something went wrong. Try again.', 'error');
    } catch (err) {
      msg('set', 'Network error. Try again.', 'error');
    }
  }

  // === Forgot PIN ===
  document.querySelector('[data-action=forgot]').addEventListener('click', async () => {
    if (!activeUser) return;
    msg('pin', 'Sending reset link to your email…', 'info');
    try {
      const res = await fetch('{{ route('tenant.pin.reset-request') }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: JSON.stringify({ user_id: activeUser.id })
      });
      const body = await res.json();
      if (body.ok) {
        msg('pin', 'Reset link sent. Check your email.', 'info');
      } else {
        msg('pin', 'Could not send reset right now.', 'error');
      }
    } catch {
      msg('pin', 'Network error.', 'error');
    }
  });
})();
</script>
</body>
</html>
