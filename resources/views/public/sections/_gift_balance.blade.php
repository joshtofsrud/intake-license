{{-- MARKER-GIFTCARDS-PUBLIC — public balance check, per the approved mockup.
     Rate-limited at the route; result shows balance + masked code ONLY. --}}
@php
  $accent = $tenant->accent_color ?? '#BEF264';
@endphp
<style>
  :root { --acc: {{ $accent }}; }
  .spg-gcbal .wrap { max-width: 560px; margin: 0 auto; padding: 28px 20px 80px; }
  .spg-gcbal h1 { font-size: 26px; font-weight: 650; letter-spacing: -.02em; }
  .spg-gcbal .sub { font-size: 14px; opacity: .55; margin-top: 4px; }
  .spg-gcbal .panel { background: #fff; border: 1.5px solid rgba(0,0,0,.09); border-radius: 16px; padding: 20px; margin-top: 24px; }
  .spg-gcbal .lbl { display: block; font-size: 12.5px; font-weight: 650; margin-bottom: 5px; }
  .spg-gcbal input { width: 100%; font: inherit; font-size: 14px; padding: 11px 13px; border: 1.5px solid rgba(0,0,0,.13); border-radius: 10px; background: #fff; margin-bottom: 10px; font-family: ui-monospace, monospace; letter-spacing: .1em; }
  .spg-gcbal .pay { display: block; width: 100%; text-align: center; font: inherit; font-size: 15px; font-weight: 700; padding: 15px 0; border: 0; border-radius: 12px; background: var(--acc); cursor: pointer; margin-top: 6px; }
  .spg-gcbal .result { text-align: center; padding: 28px 20px; }
  .spg-gcbal .result .rlbl { font-size: 12px; text-transform: uppercase; letter-spacing: .08em; opacity: .5; font-weight: 700; }
  .spg-gcbal .result .amt { font-size: 44px; font-weight: 800; letter-spacing: -.03em; margin-top: 8px; }
  .spg-gcbal .result .meta { font-size: 13px; opacity: .55; margin-top: 8px; }
  .spg-gcbal .errbox { color: #b3261e; font-size: 13.5px; padding: 14px 4px 0; }
</style>
<div class="spg-gcbal">
  <div class="wrap">
    <h1>Check a balance</h1>
    <div class="sub">Enter the code from your card or e-gift email.@if(!empty($gift['policy_line'])) {{ $gift['policy_line'] }}@endif</div>

    <form class="panel" method="POST" action="/gift-cards/balance">
      @csrf
      <label class="lbl" for="gcbal-code">Gift card code</label>
      <input id="gcbal-code" name="code" value="{{ old('code') }}" placeholder="GC-0000-0000-0000" maxlength="40" required>
      <button type="submit" class="pay">Check balance</button>
      @if(!empty($error))
        <div class="errbox">{{ $error }}</div>
      @endif
    </form>

    @if(!empty($result))
      <div class="panel result">
        <div class="rlbl">Current balance</div>
        <div class="amt">${{ number_format($result['balance_cents'] / 100, 2) }}</div>
        <div class="meta">
          {{ $result['masked'] }}
          @if($result['status'] === 'active') &middot; Redeemable in store and online
          @elseif($result['status'] === 'used') &middot; Fully used
          @else &middot; This card has been deactivated — contact us
          @endif
        </div>
      </div>
    @endif
  </div>
</div>
