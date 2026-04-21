<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Start your free trial — Intake</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root{--accent:#BEF264;--accent-text:#0a0a0a;--bg:#0c0c0c;--bg2:#141414;--bg3:#1a1a1a;--text:#f0f0f0;--muted:rgba(255,255,255,.45);--dim:rgba(255,255,255,.2);--border:rgba(255,255,255,.08);--border2:rgba(255,255,255,.14);--r:8px;--r-lg:12px}
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Inter',-apple-system,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;flex-direction:column;-webkit-font-smoothing:antialiased}
    a{color:inherit;text-decoration:none}
    .su-nav{padding:16px 28px;border-bottom:0.5px solid var(--border);display:flex;align-items:center;justify-content:space-between}
    .su-logo{display:flex;align-items:center;gap:8px;font-size:15px;font-weight:700}
    .su-logo-mark{width:24px;height:24px;background:var(--accent);border-radius:5px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:var(--accent-text)}
    .su-nav-signin{font-size:13px;color:var(--muted)}
    .su-nav-signin a{color:var(--accent)}
    .su-body{flex:1;display:flex;align-items:flex-start;justify-content:center;padding:40px 16px 60px;gap:48px;flex-wrap:wrap}
    .su-left{max-width:340px;padding-top:8px}
    .su-left h1{font-size:28px;font-weight:800;letter-spacing:-.02em;margin-bottom:10px}
    .su-left p{font-size:15px;color:var(--muted);line-height:1.65;margin-bottom:28px}
    .su-perks{display:flex;flex-direction:column;gap:10px}
    .su-perk{display:flex;align-items:flex-start;gap:10px;font-size:14px;color:rgba(255,255,255,.65)}
    .su-perk-dot{width:20px;height:20px;border-radius:50%;background:rgba(190,242,100,.1);border:0.5px solid rgba(190,242,100,.25);display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px}
    .su-perk-dot::after{content:'';width:6px;height:6px;border-radius:50%;background:var(--accent);opacity:.7}
    .su-card{background:var(--bg2);border:0.5px solid var(--border2);border-radius:var(--r-lg);padding:32px;width:100%;max-width:420px;flex-shrink:0}
    .su-card h2{font-size:18px;font-weight:700;margin-bottom:4px}
    .su-card-sub{font-size:13px;color:var(--muted);margin-bottom:24px}
    label{display:block;font-size:12px;font-weight:500;color:var(--muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em}
    input{width:100%;padding:10px 14px;background:rgba(255,255,255,.05);border:0.5px solid var(--border2);border-radius:var(--r);color:var(--text);font-size:14px;font-family:inherit;transition:border-color .12s;margin-bottom:16px}
    input:focus{outline:none;border-color:var(--accent)}
    input.err{border-color:rgba(226,75,74,.6)}
    .su-subdomain-wrap{position:relative;margin-bottom:16px}
    .su-subdomain-wrap input{margin-bottom:0;padding-right:140px}
    .su-subdomain-suffix{position:absolute;right:12px;top:50%;transform:translateY(-50%);font-size:13px;color:var(--muted);pointer-events:none;white-space:nowrap}
    .su-subdomain-status{font-size:12px;margin-top:5px;min-height:16px;transition:color .15s}
    .su-subdomain-status.avail{color:var(--accent)}
    .su-subdomain-status.taken{color:#F09595}
    .su-subdomain-status.checking{color:var(--muted)}
    .su-plan-select{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:20px}
    .su-plan-btn{padding:10px 8px;background:rgba(255,255,255,.04);border:0.5px solid var(--border);border-radius:var(--r);text-align:center;cursor:pointer;transition:all .12s}
    .su-plan-btn:hover{border-color:var(--border2)}
    .su-plan-btn.selected{border-color:rgba(190,242,100,.5);background:rgba(190,242,100,.05)}
    .su-plan-name{font-size:12px;font-weight:600;margin-bottom:2px}
    .su-plan-price{font-size:11px;color:var(--muted)}
    .su-plan-input{display:none}
    .su-btn{width:100%;padding:13px;background:var(--accent);color:var(--accent-text);border:none;border-radius:var(--r);font-size:15px;font-weight:700;cursor:pointer;transition:filter .12s}
    .su-btn:hover{filter:brightness(.92)}
    .su-btn:disabled{opacity:.5;cursor:not-allowed;filter:none}
    .su-error-msg{background:rgba(226,75,74,.1);border:0.5px solid rgba(226,75,74,.25);border-radius:var(--r);padding:10px 14px;font-size:13px;color:#F09595;margin-bottom:16px}
    .su-fine-print{font-size:12px;color:var(--dim);text-align:center;margin-top:14px;line-height:1.55}
    @media(max-width:600px){.su-left{display:none}.su-body{padding:24px 16px 48px}}
  </style>
</head>
<body>

<div class="su-nav">
  <a href="{{ route('marketing.home') }}" class="su-logo">
    <div class="su-logo-mark">I</div>
    intake
  </a>
  <div class="su-nav-signin">Already have an account? <a href="{{ route('platform.login') }}">Sign in →</a></div>
</div>

<div class="su-body">

  {{-- Left: value props --}}
  <div class="su-left">
    <h1>Start your free 14-day trial</h1>
    <p>No credit card required. Your shop is live in minutes.</p>
    <div class="su-perks">
      @foreach([
        'Online booking form with payments',
        'Customer CRM and work orders',
        'Your own branded website',
        'Full access to all features',
        'Cancel anytime — no lock-in',
      ] as $perk)
        <div class="su-perk"><div class="su-perk-dot"></div>{{ $perk }}</div>
      @endforeach
    </div>
  </div>

  {{-- Right: form --}}
  <div class="su-card">
    <h2>Create your account</h2>
    <p class="su-card-sub">Get your shop online in under 10 minutes.</p>

    @if($errors->any())
      <div class="su-error-msg">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('platform.signup.process') }}" id="signup-form">
      @csrf

      <label>Your name *</label>
      <input type="text" name="name" value="{{ old('name') }}" required
        placeholder="Jane Smith" {{ $errors->has('name') ? 'class=err' : '' }}>

      <label>Shop name *</label>
      <input type="text" name="shop_name" id="shop-name" value="{{ old('shop_name') }}" required
        placeholder="Spokes Cycle Works" autocomplete="organization">

      <label>Your booking URL *</label>
      <div class="su-subdomain-wrap">
        <input type="text" name="subdomain" id="subdomain-input" value="{{ old('subdomain') }}" required
          placeholder="spokes" autocomplete="off" {{ $errors->has('subdomain') ? 'class=err' : '' }}>
        <span class="su-subdomain-suffix">.intake.works</span>
      </div>
      <div class="su-subdomain-status" id="subdomain-status"></div>

      <label style="margin-top:16px">Email *</label>
      <input type="email" name="email" value="{{ old('email') }}" required
        placeholder="jane@yourshop.com" {{ $errors->has('email') ? 'class=err' : '' }}>

      <label style="margin-top:16px">Phone number</label>
      <input type="tel" name="phone" value="{{ old('phone') }}"
        placeholder="+1 (555) 000-0000" {{ $errors->has('phone') ? 'class=err' : '' }}>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div>
          <label>Password *</label>
          <input type="password" name="password" required placeholder="Min 8 characters" minlength="8">
        </div>
        <div>
          <label>Confirm *</label>
          <input type="password" name="password_confirmation" required placeholder="Repeat">
        </div>
      </div>

      {{-- Plan selector --}}
      <label>Plan</label>
      <div class="su-plan-select">
        @foreach(['starter' => '$29/mo', 'branded' => '$79/mo', 'scale' => '$199/mo'] as $slug => $price)
          <label class="su-plan-btn {{ $plan === $slug ? 'selected' : '' }}" id="plan-{{ $slug }}"
            onclick="selectPlan('{{ $slug }}')">
            <input type="radio" name="plan" value="{{ $slug }}" class="su-plan-input"
              {{ $plan === $slug ? 'checked' : '' }}>
            <div class="su-plan-name">{{ ucfirst($slug) }}</div>
            <div class="su-plan-price">{{ $price }}</div>
          </label>
        @endforeach
      </div>

      <button type="submit" class="su-btn" id="su-submit">
        Start free trial →
      </button>
    </form>

    <p class="su-fine-print">
      Free for 14 days — no credit card needed.<br>
      By signing up you agree to our <a href="#" style="color:var(--muted)">Terms</a> and <a href="#" style="color:var(--muted)">Privacy Policy</a>.
    </p>
  </div>

</div>

<script>
var checkTimer, lastChecked = '';
var csrf = document.querySelector('meta[name=csrf-token]').getAttribute('content');
var checkUrl = '{{ route("platform.subdomain.check") }}';

function selectPlan(slug) {
  document.querySelectorAll('.su-plan-btn').forEach(function(b) { b.classList.remove('selected'); });
  document.getElementById('plan-' + slug).classList.add('selected');
  document.querySelector('input[name=plan][value=' + slug + ']').checked = true;
}

// Auto-suggest subdomain from shop name
document.getElementById('shop-name').addEventListener('input', function() {
  var raw = this.value.toLowerCase()
    .replace(/[^a-z0-9\s-]/g, '')
    .trim()
    .replace(/\s+/g, '-')
    .replace(/-+/g, '-')
    .substring(0, 40);
  var inp = document.getElementById('subdomain-input');
  if (!inp._userEdited) inp.value = raw;
  checkSubdomain(raw);
});

document.getElementById('subdomain-input').addEventListener('input', function() {
  this._userEdited = true;
  checkSubdomain(this.value);
});

function checkSubdomain(val) {
  val = val.toLowerCase().trim();
  if (!val || val === lastChecked) return;
  lastChecked = val;
  var status = document.getElementById('subdomain-status');

  clearTimeout(checkTimer);
  if (val.length < 3) { status.textContent = ''; return; }

  status.textContent = 'Checking…';
  status.className = 'su-subdomain-status checking';

  checkTimer = setTimeout(function() {
    var fd = new FormData();
    fd.append('_token', csrf);
    fd.append('subdomain', val);
    fetch(checkUrl, { method: 'POST', body: fd })
      .then(function(r) { return r.json(); })
      .then(function(resp) {
        if (resp.available) {
          status.textContent = val + '.intake.works is available ✓';
          status.className = 'su-subdomain-status avail';
        } else {
          var msg = resp.reason === 'reserved' ? 'That subdomain is reserved.' :
                    resp.reason === 'invalid'  ? 'Only letters, numbers, and hyphens allowed.' :
                                                 val + '.intake.works is taken.';
          status.textContent = msg;
          status.className = 'su-subdomain-status taken';
        }
      })
      .catch(function() { status.textContent = ''; });
  }, 400);
}
</script>
</body>
</html>
