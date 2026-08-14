@extends('layouts.tenant.app')

{{-- MARKER-GIFTCARDS-ADMIN — card detail + ledger, per the approved mockup --}}

@php
  $pageTitle = 'Gift card';
  $money = fn ($c) => '$' . number_format(($c ?? 0) / 100, 2);
  $badge = match($card->status) {
    'active' => 'completed', 'used' => 'pending',
    'deactivated' => 'cancelled', default => 'partial',
  };
  $kindBadge = fn ($k) => match($k) {
    'issue' => ['completed', 'Issued'],
    'redeem' => ['partial', 'Redeemed'],
    'adjust' => ['shipped', 'Adjustment'],
    'deactivate' => ['cancelled', 'Deactivated'],
    'refund' => ['completed', 'Refunded'], // MARKER-GC-FUNCTIONS
    default => ['pending', ucfirst($k)],
  };
@endphp

@push('styles')
<style>
  .gcd-stats { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:12px; margin-bottom:24px }
  .gcd-stat { padding:16px; border-radius:var(--ia-r-md); background:var(--ia-surface); border:0.5px solid var(--ia-border) }
  .gcd-row { display:flex; align-items:center; gap:12px; padding:11px 0; border-bottom:0.5px solid rgba(255,255,255,.06); font-size:13px }
  .gcd-row:last-child { border-bottom:none }
  .gcd-kind { min-width:96px }
  .gcd-desc { flex:1; color:var(--ia-text-muted) }
  .gcd-when { font-size:12px; color:var(--ia-text-dim) }
  .gcd-amt { font-variant-numeric:tabular-nums; font-weight:600; min-width:76px; text-align:right }
  .gcd-amt.credit { color:#9ccf5f }
  .gcd-amt.debit { color:#F09595 }
  .gcd-bal { font-variant-numeric:tabular-nums; min-width:76px; text-align:right; color:var(--ia-text-dim) }
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div>
    <div class="ia-page-title ia-mono" style="letter-spacing:.06em">{{ $card->code }}</div>
    <div class="ia-page-subtitle">
      {{ $card->type === 'egift' ? 'E-gift' : 'Physical' }}
      @if($card->purchaser) · Purchased by {{ $card->purchaser->fullName() }}
      @elseif($card->purchaser_name) · Purchased by {{ $card->purchaser_name }} @endif
      · Issued {{ tlocal($card->created_at)->format('M j, Y') }}
      @if($card->recipient_email) · To {{ $card->recipient_email }} @endif
    </div>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.gift-cards.index') }}" class="ia-btn ia-btn--ghost ia-btn--sm">← All gift cards</a>
    @if($canManage && $card->status !== 'deactivated')
      <button type="button" class="ia-btn ia-btn--secondary ia-btn--sm" onclick="document.getElementById('gcAdjustPanel').style.display=''">Adjust balance</button>
      <button type="button" class="ia-btn ia-btn--danger ia-btn--sm" onclick="document.getElementById('gcDeactivatePanel').style.display=''">Deactivate</button>
    @endif
  </div>
</div>

@if($canManage && $card->status !== 'deactivated')
<div id="gcAdjustPanel" class="ia-card" style="display:none;margin-bottom:16px;max-width:560px">
  <div class="ia-card-head"><div class="ia-card-title">Adjust balance</div></div>
  <form method="POST" action="{{ route('tenant.gift-cards.adjust', ['cardId' => $card->id]) }}">
    @csrf
    <div class="ia-input-grid-2">
      <div class="ia-form-group">
        <label class="ia-form-label">Direction</label>
        <select name="direction" class="ia-select">
          <option value="credit">Add (credit)</option>
          <option value="debit">Remove (debit)</option>
        </select>
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Amount ($)</label>
        <input class="ia-input" name="amount" inputmode="decimal" required>
      </div>
    </div>
    <div class="ia-form-group">
      <label class="ia-form-label">Reason <span class="ia-required">*</span></label>
      <input class="ia-input" name="reason" maxlength="200" required placeholder="e.g. Goodwill — broken spoke on first ride">
    </div>
    <div style="display:flex;gap:8px">
      <button type="submit" class="ia-btn ia-btn--primary">Record adjustment</button>
      <button type="button" class="ia-btn ia-btn--secondary" onclick="document.getElementById('gcAdjustPanel').style.display='none'">Cancel</button>
    </div>
  </form>
</div>

<div id="gcDeactivatePanel" class="ia-card" style="display:none;margin-bottom:16px;max-width:560px">
  <div class="ia-card-head"><div class="ia-card-title">Deactivate card</div></div>
  <form method="POST" action="{{ route('tenant.gift-cards.deactivate', ['cardId' => $card->id]) }}">
    @csrf
    <div class="ia-form-group">
      <label class="ia-form-label">Reason <span class="ia-required">*</span></label>
      <input class="ia-input" name="reason" maxlength="200" required placeholder="e.g. Reported lost">
    </div>
    <div style="font-size:12.5px;color:var(--ia-text-dim);margin-bottom:14px">The remaining balance stays on record but the card can no longer be redeemed.</div>
    <div style="display:flex;gap:8px">
      <button type="submit" class="ia-btn ia-btn--danger">Deactivate</button>
      <button type="button" class="ia-btn ia-btn--secondary" onclick="document.getElementById('gcDeactivatePanel').style.display='none'">Cancel</button>
    </div>
  </form>
</div>
@endif

<div class="gcd-stats">
  <div class="gcd-stat">
    <div class="ia-label" style="color:var(--ia-text-dim);margin-bottom:8px">Current balance</div>
    <div style="font-size:24px;font-weight:500;color:var(--ia-accent)">{{ $money($card->balance_cents) }}</div>
  </div>
  <div class="gcd-stat">
    <div class="ia-label" style="color:var(--ia-text-dim);margin-bottom:8px">Original value</div>
    <div style="font-size:24px;font-weight:500">{{ $money($card->original_cents) }}</div>
  </div>
  <div class="gcd-stat">
    <div class="ia-label" style="color:var(--ia-text-dim);margin-bottom:8px">Status</div>
    <div style="padding-top:5px"><span class="ia-badge ia-badge--{{ $badge }}">{{ ucfirst($card->status) }}</span></div>
    @if($card->status === 'deactivated' && $card->deactivated_reason)
      <div style="font-size:12px;color:var(--ia-text-dim);margin-top:6px">{{ $card->deactivated_reason }}</div>
    @endif
  </div>
</div>

{{-- MARKER-GC-FUNCTIONS -- physical cards bought online carry a generated
     code until a preprinted card is handed over at pickup. --}}
@if($card->type === 'physical' && $card->status !== 'deactivated')
<div class="ia-card" style="margin-bottom:18px">
  <div class="ia-card-head"><div class="ia-card-title">Printed card</div></div>
  <div style="padding:16px">
    <div style="font-size:12.5px;color:var(--ia-text-dim);margin-bottom:12px">
      Handing this customer a preprinted card? Scan it here and it takes over from
      the generated code. The balance and history stay with the card.
    </div>
    <form method="POST" action="{{ route('tenant.gift-cards.bind-code', $card->id) }}" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      @csrf
      <input type="text" name="printed_code" class="ia-input" placeholder="Scan or type the printed code"
             style="font-family:var(--ia-font-mono);min-width:260px" required>
      <button type="submit" class="ia-btn ia-btn--secondary">Bind card</button>
    </form>
  </div>
</div>
@endif

<div class="ia-card">
  <div class="ia-card-head"><div class="ia-card-title">Ledger</div></div>
  @forelse($card->transactions as $t)
    @php [$tb, $tl] = $kindBadge($t->kind); @endphp
    <div class="gcd-row">
      <span class="gcd-kind"><span class="ia-badge ia-badge--{{ $tb }}">{{ $tl }}</span></span>
      <span class="gcd-desc">
        {{ $t->note }}
        @if($t->sale_id)
          · <a href="{{ route('tenant.register.sales.receipt', ['id' => $t->sale_id]) }}" style="color:var(--ia-text);border-bottom:1px dotted var(--ia-border-strong)" onclick="event.stopPropagation()">View sale</a>
        @endif
      </span>
      <span class="gcd-when">{{ tlocal($t->created_at)->format('M j, g:i A') }}</span>
      <span class="gcd-amt {{ $t->amount_cents >= 0 ? 'credit' : 'debit' }}">{{ $t->amount_cents >= 0 ? '+' : '−' }}{{ $money(abs($t->amount_cents)) }}</span>
      <span class="gcd-bal">{{ $money($t->balance_after_cents) }}</span>
    </div>
  @empty
    <div style="padding:20px 0;color:var(--ia-text-dim);font-size:13px">No ledger entries.</div>
  @endforelse
</div>

@endsection
