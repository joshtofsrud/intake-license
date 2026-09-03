@extends('layouts.tenant.app')
@php $pageTitle = 'Payment method'; @endphp

{{-- MARKER-BILLING-CARD — the card is entered in Stripe's own element; the
     number never reaches Intake, and nothing here charges anything. --}}
@section('content')

<a href="{{ route('tenant.settings.email_charges') }}" class="ia-back-link">&larr; Email charges</a>

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Payment method</h1>
    <p class="ia-page-subtitle">The card your usage will be charged to. Nothing is charged today — this is so it can be, later, without chasing you for details.</p>
  </div>
</div>

@if(session('success'))
  <div style="border:.5px solid rgba(142,217,143,.4);border-radius:var(--ia-r-md);padding:10px 14px;font-size:13px;margin-bottom:16px">{{ session('success') }}</div>
@endif
@if(session('error'))
  <div style="border:.5px solid rgba(240,138,138,.45);border-radius:var(--ia-r-md);padding:10px 14px;font-size:13px;margin-bottom:16px">{{ session('error') }}</div>
@endif

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:18px;align-items:start">

  <div class="ia-card">
    <div class="ia-card-head"><span class="ia-card-title">Card on file</span></div>

    @if($card['has_card'])
      <div style="display:flex;align-items:center;gap:12px">
        <div style="width:46px;height:30px;border-radius:6px;background:var(--ia-surface-2);display:grid;place-items:center;font-size:11px;letter-spacing:.04em;text-transform:uppercase">
          {{ $card['brand'] ?? 'card' }}
        </div>
        <div>
          <div style="font-weight:500">•••• •••• •••• {{ $card['last4'] }}</div>
          <div style="font-size:12px;color:var(--ia-text-dim)">
            @if($card['expires']) Expires {{ $card['expires'] }} @endif
            @if($card['added_at']) · added {{ \Carbon\Carbon::parse($card['added_at'])->format('M Y') }} @endif
          </div>
        </div>
      </div>

      @if($card['expiring'])
        <p style="font-size:12.5px;color:#F0C46A;line-height:1.55;margin-top:12px">
          This card expires soon. Replace it before it does, or a charge will fail and campaigns will pause.
        </p>
      @endif

      <div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap">
        <button type="button" class="ia-btn ia-btn--secondary" data-start-card>Replace card</button>
        <form method="POST" action="{{ route('tenant.settings.billing_card.forget') }}"
              onsubmit="return false" data-forget-form style="display:inline">
          @csrf
          <button type="submit" class="ia-btn ia-btn--secondary">Remove</button>
        </form>
      </div>
    @elseif(! $configured || ! $pubKey)
      <p style="font-size:13px;color:var(--ia-text-dim);line-height:1.6">
        Card payments aren't switched on yet. Nothing you've used is at risk — the balance simply waits.
      </p>
    @else
      <p style="font-size:13px;color:var(--ia-text-dim);line-height:1.6;margin-bottom:14px">
        No card saved. Usage keeps accruing without one; a card is what lets it be settled rather than
        building up.
      </p>
      <button type="button" class="ia-btn ia-btn--primary" data-start-card>Add a card</button>
    @endif

    <div data-card-form hidden style="margin-top:16px">
      <div data-stripe-element style="background:var(--ia-input-bg);border:.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:12px"></div>
      <div data-card-error style="font-size:12.5px;color:#F08A8A;margin-top:8px" hidden></div>
      <div style="display:flex;gap:8px;margin-top:12px">
        <button type="button" class="ia-btn ia-btn--primary" data-save-card>Save card</button>
        <button type="button" class="ia-btn ia-btn--secondary" data-cancel-card>Cancel</button>
      </div>
      <p style="font-size:12px;color:var(--ia-text-dim);line-height:1.5;margin-top:10px">
        Card details go straight to Stripe — they never pass through Intake. Saving it authorises us to
        charge usage as it builds up; you'll always be able to see what for.
      </p>
    </div>
  </div>

  {{-- MARKER-BILLING-ADDRESS — where the shop is registered. Used on receipts,
       and it is what a tax calculation would be based on if tax is ever
       switched on. None is charged today. --}}
  <div class="ia-card">
    <div class="ia-card-head"><span class="ia-card-title">Billing address</span></div>
    <p style="font-size:13px;color:var(--ia-text-dim);line-height:1.6;margin-bottom:12px">
      Your registered business address. It appears on receipts, and would be used to work out sales tax
      where that applies — no tax is charged today.
    </p>
    @php $inp = 'width:100%;background:var(--ia-input-bg);border:.5px solid var(--ia-border);border-radius:var(--ia-r-md);color:var(--ia-text);padding:8px 11px;font:inherit;font-size:13px;margin-bottom:8px'; @endphp
    <form method="POST" action="{{ route('tenant.settings.billing_card.address') }}">
      @csrf
      <input name="billing_address_line1" value="{{ $currentTenant->billing_address_line1 }}" placeholder="Street address" style="{{ $inp }}">
      <input name="billing_address_line2" value="{{ $currentTenant->billing_address_line2 }}" placeholder="Suite, unit (optional)" style="{{ $inp }}">
      <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:8px">
        <input name="billing_city"     value="{{ $currentTenant->billing_city }}"     placeholder="City"  style="{{ $inp }}">
        <input name="billing_state"    value="{{ $currentTenant->billing_state }}"    placeholder="State" style="{{ $inp }}">
        <input name="billing_postcode" value="{{ $currentTenant->billing_postcode }}" placeholder="ZIP"   style="{{ $inp }}">
      </div>
      <button type="submit" class="ia-btn ia-btn--secondary">Save address</button>
    </form>
  </div>

  <div class="ia-card">
    <div class="ia-card-head"><span class="ia-card-title">Billing email</span></div>
    <p style="font-size:13px;color:var(--ia-text-dim);line-height:1.6;margin-bottom:12px">
      Where receipts go. Leave it blank and they go to the shop's main address.
    </p>
    <form method="POST" action="{{ route('tenant.settings.billing_card.email') }}" style="display:flex;gap:8px;flex-wrap:wrap">
      @csrf
      <input type="email" name="billing_email" value="{{ $billingEmail }}" placeholder="accounts@yourshop.com"
             style="flex:1;min-width:220px;background:var(--ia-input-bg);border:.5px solid var(--ia-border);border-radius:var(--ia-r-md);color:var(--ia-text);padding:8px 11px;font:inherit;font-size:13px">
      <button type="submit" class="ia-btn ia-btn--secondary">Save</button>
    </form>
  </div>

</div>

@if($configured && $pubKey)
@push('scripts')
<script src="https://js.stripe.com/v3/"></script>
<script>
(function () {
  // MARKER-BILLING-CARD — Stripe's element, so no card data touches this app.
  var stripe   = Stripe(@json($pubKey));
  var elements = null, card = null, clientSecret = null;

  var box   = document.querySelector('[data-card-form]');
  var mount = document.querySelector('[data-stripe-element]');
  var err   = document.querySelector('[data-card-error]');

  function fail(msg) { err.hidden = false; err.textContent = msg; }

  document.querySelectorAll('[data-start-card]').forEach(function (b) {
    b.addEventListener('click', function () {
      b.disabled = true;
      fetch(@json(route('tenant.settings.billing_card.intent')), {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
          'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin'
      })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          b.disabled = false;
          if (!j || !j.success) { fail((j && j.error) || 'Could not start card setup.'); box.hidden = false; return; }
          clientSecret = j.client_secret;
          elements = stripe.elements({ clientSecret: clientSecret });
          card = elements.create('payment');
          card.mount(mount);
          box.hidden = false;
        })
        .catch(function () { b.disabled = false; fail('Could not reach Stripe.'); box.hidden = false; });
    });
  });

  var saveBtn = document.querySelector('[data-save-card]');
  if (saveBtn) saveBtn.addEventListener('click', function () {
    if (!elements) return;
    saveBtn.disabled = true;
    err.hidden = true;
    stripe.confirmSetup({
      elements: elements,
      confirmParams: { return_url: @json(route('tenant.settings.billing_card.complete')) }
    }).then(function (result) {
      saveBtn.disabled = false;
      if (result.error) { fail(result.error.message || 'That card could not be saved.'); }
    });
  });

  var cancelBtn = document.querySelector('[data-cancel-card]');
  if (cancelBtn) cancelBtn.addEventListener('click', function () { box.hidden = true; });

  // house rule: no native dialogs
  var forget = document.querySelector('[data-forget-form]');
  if (forget) {
    forget.querySelector('button').addEventListener('click', function (e) {
      e.preventDefault();
      IntakeConfirm.show({
        title: 'Remove this card?',
        message: 'Usage keeps accruing without a card — it simply cannot be settled until you add another.',
        confirmText: 'Remove card',
        danger: true
      }).then(function (ok) { if (ok) { forget.onsubmit = null; forget.submit(); } });
    });
  }
})();
</script>
@endpush
@endif

@endsection
