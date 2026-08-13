{{-- MARKER-GIFTCARDS — e-gift delivery email, per the approved mockup --}}
@php
  $accent = $tenant->accent_color ?: '#BEF264';
  $amount = '$' . number_format($card->original_cents / 100, 2);
  $from   = $card->purchaser_name ?: null;
@endphp
<div style="background:#f2f2f2;padding:34px 16px;font-family:'Inter',-apple-system,BlinkMacSystemFont,sans-serif;">
  <div style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid rgba(0,0,0,.07);color:#111111;">
    <div style="padding:22px 26px 0;font-weight:700;font-size:15px;">You've received a gift card 🎁</div>
    <div style="padding:18px 26px 26px;font-size:14px;line-height:1.65;">
      <p style="margin:0 0 6px;">
        @if($from)<b>{{ $from }}</b> sent you a gift card to <b>{{ $tenant->name }}</b>.
        @else You've been sent a gift card to <b>{{ $tenant->name }}</b>.@endif
      </p>
      @if(filled($card->gift_message))
        <div style="background:rgba(0,0,0,.035);border-radius:12px;padding:14px 16px;font-style:italic;margin:14px 0;">&ldquo;{{ $card->gift_message }}&rdquo;</div>
      @endif
      <div style="border-radius:16px;padding:22px 24px;background:#161616;color:#ffffff;margin:16px 0;">
        <div style="font-size:12px;text-transform:uppercase;letter-spacing:.1em;opacity:.55;font-weight:700;">{{ $tenant->name }}</div>
        <div style="font-size:34px;font-weight:800;margin-top:10px;letter-spacing:-.02em;">{{ $amount }}</div>
        <div style="font-size:10.5px;text-transform:uppercase;letter-spacing:.08em;opacity:.4;margin-top:14px;">Gift card</div>
        <div style="font-family:ui-monospace,monospace;font-size:14px;letter-spacing:.14em;margin-top:6px;opacity:.85;">{{ $card->code }}</div>
      </div>
      <p style="font-size:13px;opacity:.65;margin:0;">Use this code at checkout online, or show this email in store. Check your balance any time.</p>
    </div>
    <div style="padding:16px 26px;border-top:1px solid rgba(0,0,0,.06);font-size:12px;opacity:.5;">{{ $tenant->name }} · Sent by Intake on behalf of {{ $tenant->name }}</div>
  </div>
</div>
