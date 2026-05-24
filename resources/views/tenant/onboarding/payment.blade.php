@extends('tenant.onboarding._layout')

@section('extra-styles')
  /* Force wizard colors over tenant theme injection. */
  .screen { color: #f0f0f0; }
  .screen .screen-eyebrow { color: #D4FF3F !important; }
  .screen .screen-sub { color: #888 !important; }
  .screen .btn-primary { color: #0a0a0a !important; background: #D4FF3F !important; }
  .screen .btn-skip { color: #888 !important; }

  .pay-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
  }
  @media (max-width: 700px) { .pay-grid { grid-template-columns: 1fr; } }

  .pay-card {
    background: #1a1a1a !important; border: 1px solid #2a2a2a;
    border-radius: 10px; padding: 20px;
    cursor: pointer; transition: all 0.15s;
    position: relative;
    color: #f0f0f0 !important;
  }
  .pay-card:hover:not(.disabled) { border-color: #5a5a5a; }
  .pay-card.selected {
    border-color: #D4FF3F;
    background: linear-gradient(180deg, rgba(212,255,63,0.05), #1a1a1a) !important;
  }
  .pay-card.disabled { opacity: 0.55; cursor: not-allowed; }

  .pay-card-head {
    display: flex; align-items: center; gap: 12px; margin-bottom: 10px;
  }
  .pay-card-logo {
    width: 36px; height: 36px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 14px; letter-spacing: -0.02em;
    flex-shrink: 0;
    color: white;
  }
  .pay-card-logo.stripe  { background: #635BFF; }
  .pay-card-logo.paypal  { background: #0070BA; }
  .pay-card-logo.square  { background: #1a1a1a; border: 1px solid #2a2a2a; }
  .pay-card-logo.offline { background: #131313; color: #f0f0f0; border: 1px solid #2a2a2a; }

  .pay-card-name {
    font-size: 14px; font-weight: 700;
    color: #f0f0f0 !important;
  }
  .pay-card-desc {
    font-size: 11.5px; color: #888 !important; line-height: 1.5;
  }
  .pay-card-status {
    position: absolute; top: 14px; right: 14px;
    font-size: 9px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.08em;
    padding: 2px 7px; border-radius: 3px;
  }
  .pay-card-status.coming   { background: #131313; color: #888 !important; border: 1px solid #2a2a2a; }
  .pay-card-status.now      { background: rgba(212,255,63,0.18); color: #D4FF3F !important; }
  .pay-card-status.next     { background: rgba(212,255,63,0.18); color: #D4FF3F !important; }

  .helper-block {
    font-size: 11.5px; color: #888 !important;
    margin-top: 16px; line-height: 1.5;
  }
@endsection

@section('screen')
  <div class="screen-header">
    <div class="screen-eyebrow">Step 7 of 8</div>
    <h2 class="screen-title">How will you take payments?</h2>
    <p class="screen-sub">Pick your processor now — we'll set up the connection from your dashboard once that processor is wired in. Skip if you take payments outside the app.</p>
  </div>

  <div id="ob-error" class="err"></div>

  @php
    $selected = $tenant->payment_processor;
  @endphp

  <div class="pay-grid">
    <div class="pay-card {{ $selected === 'stripe' ? 'selected' : '' }}" data-processor="stripe">
      <div class="pay-card-status next">Available soon</div>
      <div class="pay-card-head">
        <div class="pay-card-logo stripe">S</div>
        <div class="pay-card-name">Stripe</div>
      </div>
      <div class="pay-card-desc">Card payments, deposits, and refunds. Industry standard. Connect via Stripe Connect — your money lands in your bank account, we never hold funds.</div>
    </div>

    <div class="pay-card {{ $selected === 'paypal' ? 'selected' : '' }}" data-processor="paypal">
      <div class="pay-card-status coming">Coming soon</div>
      <div class="pay-card-head">
        <div class="pay-card-logo paypal">P</div>
        <div class="pay-card-name">PayPal</div>
      </div>
      <div class="pay-card-desc">PayPal Business + PayPal Checkout. Good for shops with existing PayPal customer base.</div>
    </div>

    <div class="pay-card {{ $selected === 'square' ? 'selected' : '' }}" data-processor="square">
      <div class="pay-card-status coming">Coming soon</div>
      <div class="pay-card-head">
        <div class="pay-card-logo square">◼</div>
        <div class="pay-card-name">Square</div>
      </div>
      <div class="pay-card-desc">Square payments + reader integration. Useful if you already use Square in-store.</div>
    </div>

    <div class="pay-card {{ $selected === 'offline' ? 'selected' : '' }}" data-processor="offline">
      <div class="pay-card-status now">Available now</div>
      <div class="pay-card-head">
        <div class="pay-card-logo offline">$</div>
        <div class="pay-card-name">I take payments outside the app</div>
      </div>
      <div class="pay-card-desc">Cash, Venmo, deposit-on-arrival, invoicing. Skip processor setup — connect a processor anytime later from your dashboard.</div>
    </div>
  </div>

  <div class="helper-block">
    Picking a "coming soon" option records your interest. We'll surface a connect prompt on your dashboard once that integration ships. You can change this anytime from Settings → Payments.
  </div>

  <div class="actions">
    <a href="{{ route('tenant.onboarding.wizard.team', []) }}" class="btn btn-ghost">← Back</a>
    <button type="button" class="btn btn-primary" id="ob-continue" {{ $selected ? '' : 'disabled' }}>Continue → Done</button>
  </div>
@endsection

@section('scripts')
<script>
(function () {
  const SAVE_URL = @json(route('tenant.onboarding.wizard.payment.save', []));

  const cards  = document.querySelectorAll('.pay-card');
  const cont   = document.getElementById('ob-continue');
  const errBox = document.getElementById('ob-error');

  let selected = @json($tenant->payment_processor);

  function csrf() {
    return document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  }
  function showError(msg) { errBox.textContent = msg; errBox.classList.add('show'); }
  function hideError() { errBox.classList.remove('show'); }

  cards.forEach(c => {
    c.addEventListener('click', () => {
      cards.forEach(x => x.classList.remove('selected'));
      c.classList.add('selected');
      selected = c.dataset.processor;
      cont.disabled = false;
    });
  });

  cont.addEventListener('click', async () => {
    if (!selected) {
      showError('Pick a payment option to continue.');
      return;
    }
    hideError();
    cont.disabled = true;
    cont.textContent = 'Saving…';

    try {
      const fd = new FormData();
      fd.append('payment_processor', selected);

      const res = await fetch(SAVE_URL, {
        method: 'POST',
        body: fd,
        headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
        credentials: 'same-origin',
      });
      if (!res.ok) {
        const text = await res.text();
        throw new Error('Save failed (' + res.status + '). ' + text.substring(0, 140));
      }
      const json = await res.json();
      window.location.href = json.next_url;
    } catch (err) {
      showError(err.message || 'Something went wrong.');
      cont.disabled = false;
      cont.textContent = 'Continue → Done';
    }
  });
})();
</script>
@endsection
