{{-- MARKER-GIFTCARDS-PUBLIC — purchase confirmation. Never shows the code:
     e-gifts go to the recipient; physical cards are read out at pickup. --}}
@php
  $accent = $tenant->accent_color ?? '#BEF264';
@endphp
<style>
  :root { --acc: {{ $accent }}; }
  .spg-gcconf .wrap { max-width: 560px; margin: 0 auto; padding: 28px 20px 80px; }
  .spg-gcconf .panel { background: #fff; border: 1.5px solid rgba(0,0,0,.09); border-radius: 16px; padding: 26px; margin-top: 24px; }
  .spg-gcconf h1 { font-size: 24px; font-weight: 650; letter-spacing: -.02em; }
  .spg-gcconf .row { display: flex; justify-content: space-between; font-size: 13.5px; padding: 6px 0; border-bottom: 1px solid rgba(0,0,0,.05); }
  .spg-gcconf .row:last-child { border-bottom: 0; }
</style>
<div class="spg-gcconf">
  <div class="wrap">
    @if($card && $card->status === 'active')
      <h1>Gift card purchased 🎉</h1>
      <div class="panel">
        <div class="row"><span>Amount</span><b>${{ number_format($card->original_cents / 100, 2) }}</b></div>
        <div class="row"><span>Type</span><b>{{ $card->type === 'egift' ? 'E-gift card' : 'Physical card' }}</b></div>
        @if($card->type === 'egift')
          <div class="row"><span>Going to</span><b>{{ $card->recipient_email }}</b></div>
          <div class="row"><span>Delivery</span><b>@if($card->deliver_on){{ $card->deliver_on->format('M j, Y') }}@else On its way now @endif</b></div>
        @else
          <div class="row"><span>Pickup</span><b>Ready at the shop — bring this confirmation</b></div>
        @endif
        <p style="font-size:13px;opacity:.6;margin:14px 0 0">
          @if($card->type === 'egift')The card code is in the recipient's email. Balance can be checked any time at <a href="/gift-cards/balance" style="text-decoration:underline">/gift-cards/balance</a>.
          @else We'll match this purchase to your card when you pick it up.@endif
        </p>
      </div>
    @elseif($card)
      <h1>Almost there…</h1>
      <div class="panel">
        <p style="font-size:14px;margin:0">Your payment is still processing. This page will show the confirmation once it goes through — refresh in a moment, or contact us if it doesn't.</p>
      </div>
    @else
      <h1>Gift card</h1>
      <div class="panel">
        <p style="font-size:14px;margin:0">We couldn't find that purchase. If you were charged, contact us and we'll sort it out.</p>
      </div>
    @endif
  </div>
</div>
