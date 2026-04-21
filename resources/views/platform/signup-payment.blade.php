<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Start your trial — Intake</title>
<script src="https://js.stripe.com/v3/"></script>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg: #0a0a0a; --card: #141414; --card-hover: #1a1a1a;
    --border: #262626; --text: #ffffff; --muted: #8a8a87;
    --accent: #BEF264; --accent-dim: #9ED856; --danger: #F87171;
    --radius: 10px;
  }
  body { background: var(--bg); color: var(--text); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; font-size: 14px; line-height: 1.5; min-height: 100vh; padding: 40px 20px; -webkit-font-smoothing: antialiased; }

  .sp-wrap { max-width: 540px; margin: 0 auto; }

  .sp-brand { display: flex; align-items: center; gap: 10px; margin-bottom: 40px; }
  .sp-brand .mark { width: 30px; height: 30px; background: var(--accent); color: #000; border-radius: 7px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 17pt; }
  .sp-brand .name { font-weight: 500; font-size: 14pt; letter-spacing: -0.01em; }

  h1 { font-size: 26pt; font-weight: 700; letter-spacing: -0.03em; margin-bottom: 8px; line-height: 1.15; }
  h1 .accent { color: var(--accent); }
  .sp-lede { color: var(--muted); font-size: 13pt; margin-bottom: 28px; }

  .sp-reassure { background: rgba(190, 242, 100, 0.08); border: 1px solid rgba(190, 242, 100, 0.2); border-left: 3px solid var(--accent); border-radius: var(--radius); padding: 12px 16px; margin-bottom: 24px; }
  .sp-reassure strong { color: var(--accent); font-weight: 600; }
  .sp-reassure p { color: #d7d6d1; font-size: 13px; line-height: 1.5; }

  .sp-summary { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 18px 20px; margin-bottom: 20px; }
  .sp-summary .row { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 10px; }
  .sp-summary .row:last-child { margin-bottom: 0; }
  .sp-summary .lbl { color: var(--muted); font-size: 13px; }
  .sp-summary .val { font-weight: 600; font-size: 14px; }
  .sp-summary .val.big { font-size: 22pt; font-weight: 700; letter-spacing: -0.02em; }
  .sp-summary hr { border: none; border-top: 1px solid var(--border); margin: 14px 0; }

  .sp-cadence { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 24px; }
  .sp-cadence label { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px 16px; cursor: pointer; position: relative; transition: all 0.15s; }
  .sp-cadence label:hover { background: var(--card-hover); }
  .sp-cadence label.selected { border-color: var(--accent); background: rgba(190, 242, 100, 0.04); }
  .sp-cadence label.selected::after { content: "✓"; position: absolute; top: 10px; right: 12px; color: var(--accent); font-weight: 800; }
  .sp-cadence .cad-name { font-weight: 600; font-size: 14px; margin-bottom: 2px; }
  .sp-cadence .cad-price { color: var(--muted); font-size: 12px; }
  .sp-cadence .cad-save { color: var(--accent); font-size: 11px; font-weight: 600; letter-spacing: 0.04em; margin-top: 3px; text-transform: uppercase; }
  .sp-cadence input[type="radio"] { display: none; }

  .sp-card-section { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 18px 20px; margin-bottom: 20px; }
  .sp-card-section label { display: block; color: var(--muted); font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase; margin-bottom: 10px; font-weight: 500; }
  #stripe-card-element { background: #000; border: 1px solid var(--border); border-radius: 6px; padding: 12px 14px; }
  #stripe-card-errors { color: var(--danger); font-size: 12px; margin-top: 8px; min-height: 16px; }

  .sp-btn { width: 100%; background: var(--accent); color: #000; border: none; border-radius: var(--radius); padding: 15px; font-size: 15px; font-weight: 700; cursor: pointer; transition: background 0.15s; letter-spacing: -0.01em; }
  .sp-btn:hover:not(:disabled) { background: var(--accent-dim); }
  .sp-btn:disabled { opacity: 0.5; cursor: not-allowed; }
  .sp-btn .spin { display: none; }
  .sp-btn.loading .lbl { display: none; }
  .sp-btn.loading .spin { display: inline-block; }

  .sp-fine { color: var(--muted); font-size: 11px; text-align: center; margin-top: 16px; line-height: 1.5; }
  .sp-fine a { color: var(--accent); text-decoration: none; }

  .sp-test-banner { background: rgba(251, 191, 36, 0.1); border: 1px solid rgba(251, 191, 36, 0.3); color: #FBBF24; padding: 10px 14px; border-radius: 8px; margin-bottom: 20px; font-size: 12px; text-align: center; }
  .sp-test-banner strong { font-weight: 600; }

  .sp-error { background: rgba(248, 113, 113, 0.1); border: 1px solid rgba(248, 113, 113, 0.3); color: var(--danger); padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; }

  @keyframes spin { 0% { transform: rotate(0); } 100% { transform: rotate(360deg); } }
  .spin-icon { display: inline-block; width: 14px; height: 14px; border: 2px solid currentColor; border-right-color: transparent; border-radius: 50%; animation: spin 0.7s linear infinite; vertical-align: middle; }
</style>
</head>
<body>

<div class="sp-wrap">

  <div class="sp-brand">
    <div class="mark">I</div>
    <div class="name">intake</div>
  </div>

  <h1>Almost there, <span class="accent">{{ explode(' ', $pending['name'])[0] }}</span>.</h1>
  <p class="sp-lede">Add your card to start your 14-day free trial. {{ $pending['subdomain'] }}.intake.works is reserved for you.</p>

  @if($isTestMode)
    <div class="sp-test-banner">
      <strong>Test mode</strong> — use card <code style="background: rgba(0,0,0,0.3); padding: 1px 5px; border-radius: 3px;">4242 4242 4242 4242</code>, any future expiry, any CVC.
    </div>
  @endif

  @if($errors->any())
    <div class="sp-error">
      {{ $errors->first() }}
    </div>
  @endif

  <div class="sp-reassure">
    <p><strong>Picked the wrong plan?</strong> No problem — you can change plans anytime during your 14-day trial. You won't be charged until the trial ends.</p>
  </div>

  <form id="signup-complete-form" action="{{ route('platform.signup.complete') }}" method="POST">
    @csrf

    <div class="sp-summary">
      <div class="row">
        <div class="lbl">Plan</div>
        <div class="val">{{ ucfirst($pending['plan']) }}</div>
      </div>
      <div class="row">
        <div class="lbl">Monthly price (after trial)</div>
        <div class="val big" id="summary-price">${{ number_format($planPrice) }}</div>
      </div>
      <hr>
      <div class="row">
        <div class="lbl">Today</div>
        <div class="val accent" style="color: var(--accent);">$0.00</div>
      </div>
      <div class="row">
        <div class="lbl">Trial ends</div>
        <div class="val">{{ now()->addDays(14)->format('M j, Y') }}</div>
      </div>
    </div>

    <div style="color: var(--muted); font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase; margin-bottom: 10px; font-weight: 500;">
      Billing cadence
    </div>
    <div class="sp-cadence" id="cadence-picker">
      <label class="cad-opt selected" data-cadence="monthly">
        <input type="radio" name="cadence" value="monthly" checked>
        <div class="cad-name">Monthly</div>
        <div class="cad-price">${{ number_format($planPrice) }}/month</div>
      </label>
      <label class="cad-opt" data-cadence="annual">
        <input type="radio" name="cadence" value="annual">
        <div class="cad-name">Annual</div>
        <div class="cad-price">${{ number_format($planPrice * 10) }}/year</div>
        <div class="cad-save">Save 2 months</div>
      </label>
    </div>

    <div class="sp-card-section">
      <label>Card details</label>
      <div id="stripe-card-element"></div>
      <div id="stripe-card-errors"></div>
    </div>

    <input type="hidden" name="payment_method_id" id="payment_method_id">

    <button type="submit" class="sp-btn" id="submit-btn">
      <span class="lbl">Start 14-day free trial</span>
      <span class="spin"><span class="spin-icon"></span> Processing…</span>
    </button>

    <p class="sp-fine">
      By starting your trial, you agree to Intake's Terms of Service and Privacy Policy.<br>
      Secure payment by <strong>Stripe</strong>. Cards are never stored on Intake servers.
    </p>
  </form>
</div>

<script>
  const stripe = Stripe(@json($publishableKey));
  const elements = stripe.elements();

  // Card element styled to match dark theme
  const card = elements.create('card', {
    style: {
      base: {
        color: '#ffffff',
        fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
        fontSize: '15px',
        '::placeholder': { color: '#666' },
        iconColor: '#BEF264',
      },
      invalid: { color: '#F87171', iconColor: '#F87171' },
    },
  });
  card.mount('#stripe-card-element');

  card.on('change', ({error}) => {
    document.getElementById('stripe-card-errors').textContent = error ? error.message : '';
  });

  // Cadence toggle
  document.querySelectorAll('.cad-opt').forEach(opt => {
    opt.addEventListener('click', () => {
      document.querySelectorAll('.cad-opt').forEach(o => o.classList.remove('selected'));
      opt.classList.add('selected');
      opt.querySelector('input').checked = true;
    });
  });

  // Form submit handler — create PM, then submit form
  const form = document.getElementById('signup-complete-form');
  const btn = document.getElementById('submit-btn');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    btn.classList.add('loading');
    btn.disabled = true;

    try {
      const {paymentMethod, error} = await stripe.createPaymentMethod({
        type: 'card',
        card: card,
        billing_details: {
          email: @json($pending['email']),
          name:  @json($pending['name']),
        },
      });

      if (error) {
        document.getElementById('stripe-card-errors').textContent = error.message;
        btn.classList.remove('loading');
        btn.disabled = false;
        return;
      }

      document.getElementById('payment_method_id').value = paymentMethod.id;
      form.submit();
    } catch (err) {
      document.getElementById('stripe-card-errors').textContent = 'Something went wrong. Please try again.';
      btn.classList.remove('loading');
      btn.disabled = false;
    }
  });
</script>

</body>
</html>
