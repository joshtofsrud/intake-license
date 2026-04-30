@extends('tenant.onboarding._layout')

@section('extra-styles')
  /* Force wizard colors over tenant theme injection. */
  .screen { color: #f0f0f0; }
  .screen .screen-eyebrow { color: #D4FF3F !important; }
  .screen .screen-sub { color: #888 !important; }
  .screen .btn-primary { color: #0a0a0a !important; background: #D4FF3F !important; }
  .screen .btn-skip { color: #888 !important; }

  .field { margin-bottom: 18px; }
  .label {
    display: block; font-size: 12px; font-weight: 600;
    color: #c8c8c8 !important; margin-bottom: 7px;
  }
  .input, .select {
    width: 100%; background: #1a1a1a !important;
    border: 1px solid #2a2a2a; border-radius: 10px;
    padding: 11px 14px; color: #f0f0f0 !important;
    font-family: inherit; font-size: 14px;
    transition: border-color 0.15s;
  }
  .input:focus, .select:focus { outline: none; border-color: #D4FF3F; }
  .input.invalid, .select.invalid { border-color: #ef4444; }
  .helper { font-size: 11.5px; color: #888 !important; margin-top: 6px; }

  .row-3 {
    display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 12px;
  }
  @media (max-width: 700px) { .row-3 { grid-template-columns: 1fr; } }

  .price-wrap { position: relative; }
  .price-wrap::before {
    content: '$'; position: absolute;
    left: 14px; top: 50%; transform: translateY(-50%);
    color: #888; font-size: 14px; font-weight: 500;
    pointer-events: none;
  }
  .price-wrap .input { padding-left: 24px; }

  .existing-list {
    margin-bottom: 22px; padding: 16px;
    background: #131313; border: 1px solid #1f1f1f;
    border-radius: 10px;
  }
  .existing-row {
    display: grid; grid-template-columns: 1fr auto auto;
    gap: 12px; align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid #1f1f1f;
    font-size: 13px;
  }
  .existing-row:last-child { border-bottom: none; }
  .existing-row .name { color: #f0f0f0 !important; font-weight: 600; }
  .existing-row .meta { color: #888 !important; font-size: 11.5px; }
  .existing-row .price { color: #D4FF3F !important; font-weight: 700; }
  .existing-label {
    font-size: 10.5px; font-weight: 700; color: #888 !important;
    text-transform: uppercase; letter-spacing: 0.08em;
    margin-bottom: 8px;
  }
@endsection

@section('screen')
  <div class="screen-header">
    <div class="screen-eyebrow">Step 5 of 8</div>
    <h2 class="screen-title">What do you offer?</h2>
    <p class="screen-sub">Add your first service so customers have something to book. You can build out your full catalog from the Services section later.</p>
  </div>

  <div id="ob-error" class="err"></div>

  @php
    $existingServices = \App\Models\Tenant\TenantServiceItem::where('tenant_id', $tenant->id)
        ->where('is_active', true)
        ->orderBy('sort_order')
        ->orderBy('id')
        ->take(10)
        ->get();
  @endphp

  @if($existingServices->count() > 0)
    <div class="existing-list">
      <div class="existing-label">{{ $existingServices->count() }} service{{ $existingServices->count() === 1 ? '' : 's' }} already on your booking page</div>
      @foreach($existingServices as $svc)
        <div class="existing-row">
          <div>
            <div class="name">{{ $svc->name }}</div>
            <div class="meta">{{ $svc->duration_minutes }} min</div>
          </div>
          <div class="price">${{ number_format($svc->price_cents / 100, 2) }}</div>
        </div>
      @endforeach
    </div>
  @endif

  <div class="row-3">
    <div class="field">
      <label class="label" for="ob-name">Service name</label>
      <input type="text" class="input" id="ob-name"
             placeholder="e.g., Standard Tune-up" maxlength="100">
      <div class="helper">What customers will see when booking.</div>
    </div>

    <div class="field">
      <label class="label" for="ob-duration">Duration</label>
      <select class="select" id="ob-duration">
        <option value="15">15 min</option>
        <option value="30" selected>30 min</option>
        <option value="45">45 min</option>
        <option value="60">1 hour</option>
        <option value="90">1.5 hours</option>
        <option value="120">2 hours</option>
        <option value="180">3 hours</option>
      </select>
      <div class="helper">How long it takes.</div>
    </div>

    <div class="field">
      <label class="label" for="ob-price">Price</label>
      <div class="price-wrap">
        <input type="number" class="input" id="ob-price"
               placeholder="0.00" min="0" step="0.01">
      </div>
      <div class="helper">Customer-facing price.</div>
    </div>
  </div>

  <div class="actions">
    <a href="{{ route('tenant.onboarding.wizard.hours', ['subdomain' => $tenant->subdomain]) }}" class="btn btn-ghost">← Back</a>
    <button type="button" class="btn btn-primary" id="ob-continue">
      {{ $existingServices->count() > 0 ? 'Add another → Team' : 'Continue → Team' }}
    </button>
  </div>
@endsection

@section('scripts')
<script>
(function () {
  const SAVE_URL = @json(route('tenant.onboarding.wizard.services.save', ['subdomain' => $tenant->subdomain]));
  const SKIP_OK  = @json($existingServices->count() > 0);

  const nameEl    = document.getElementById('ob-name');
  const durEl     = document.getElementById('ob-duration');
  const priceEl   = document.getElementById('ob-price');
  const cont      = document.getElementById('ob-continue');
  const errBox    = document.getElementById('ob-error');

  function csrf() {
    return document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  }
  function showError(msg) {
    errBox.textContent = msg;
    errBox.classList.add('show');
  }
  function hideError() { errBox.classList.remove('show'); }

  cont.addEventListener('click', async () => {
    hideError();
    nameEl.classList.remove('invalid');
    priceEl.classList.remove('invalid');

    const name  = nameEl.value.trim();
    const dur   = parseInt(durEl.value, 10);
    const price = priceEl.value.trim();

    // If a service was already added on this page (existing services) and
    // the user left the form blank, treat Continue as "skip to Team."
    if (!name && SKIP_OK) {
      cont.disabled = true;
      cont.textContent = 'Continuing…';
      window.location.href = @json(route('tenant.onboarding.wizard.team', ['subdomain' => $tenant->subdomain]));
      return;
    }

    if (!name) {
      nameEl.classList.add('invalid');
      showError('Give your first service a name so customers know what to book.');
      return;
    }
    if (price === '' || isNaN(parseFloat(price)) || parseFloat(price) < 0) {
      priceEl.classList.add('invalid');
      showError('Enter a price (use 0 for free).');
      return;
    }

    cont.disabled = true;
    cont.textContent = 'Saving…';

    try {
      const fd = new FormData();
      fd.append('name',     name);
      fd.append('duration', dur);
      fd.append('price',    price);

      const res = await fetch(SAVE_URL, {
        method: 'POST',
        body: fd,
        headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
        credentials: 'same-origin',
      });
      if (!res.ok) {
        const text = await res.text();
        throw new Error(`Save failed (${res.status}). ${text.substring(0, 140)}`);
      }
      const json = await res.json();
      // Reload so the new service appears in the existing-list block,
      // and the user can either add another or hit Continue to skip.
      window.location.reload();
    } catch (err) {
      showError(err.message || 'Something went wrong.');
      cont.disabled = false;
      cont.textContent = SKIP_OK ? 'Add another → Team' : 'Continue → Team';
    }
  });
})();
</script>
@endsection
