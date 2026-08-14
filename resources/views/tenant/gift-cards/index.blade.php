@extends('layouts.tenant.app')

{{-- MARKER-GIFTCARDS-ADMIN — gift card manager list, per the approved mockup --}}

@php
  $pageTitle = 'Gift cards';
  $money = fn ($c) => '$' . number_format(($c ?? 0) / 100, 2);
@endphp

@push('styles')
<style>
  .gc-stats { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:12px; margin-bottom:24px }
  .gc-stat { padding:16px; border-radius:var(--ia-r-md); background:var(--ia-surface); border:0.5px solid var(--ia-border) }
  .gc-stat-label { font-size:11px; text-transform:uppercase; letter-spacing:.07em; font-weight:500; margin-bottom:8px; color:var(--ia-text-dim) }
  .gc-stat-value { font-size:24px; font-weight:500; line-height:1 }
  .gc-stat-sub { font-size:12px; margin-top:5px; color:var(--ia-text-dim) }
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div>
    <div class="ia-page-title">Gift cards</div>
    <div class="ia-page-subtitle">Issue, look up, and adjust gift card balances</div>
  </div>
  <div class="ia-page-actions">
    @if($canManage)
      <button type="button" class="ia-btn ia-btn--primary" onclick="document.getElementById('gcIssuePanel').style.display=''">+ Issue gift card</button>
    @endif
  </div>
</div>

@if($canManage)
<div id="gcIssuePanel" class="ia-card" style="display:none;margin-bottom:20px;max-width:640px">
  <div class="ia-card-head"><div class="ia-card-title">Issue a gift card</div></div>
  <form method="POST" action="{{ route('tenant.gift-cards.store') }}">
    @csrf
    <div class="ia-input-grid-2">
      <div class="ia-form-group">
        <label class="ia-form-label">Type</label>
        <select name="type" class="ia-select" id="gcIssueType">
          <option value="physical">Physical card</option>
          <option value="egift">E-gift card</option>
        </select>
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Amount ($)</label>
        <input class="ia-input" name="amount" inputmode="decimal" required>
      </div>
    </div>
    <div class="ia-form-group" id="gcIssueCodeWrap">
      <label class="ia-form-label">Card code (from the physical card)</label>
      <input class="ia-input ia-mono" name="code" placeholder="GC-0000-0000-0000">
    </div>
    <div class="ia-input-grid-2" id="gcIssueEmailWrap" style="display:none">
      <div class="ia-form-group">
        <label class="ia-form-label">Recipient name</label>
        <input class="ia-input" name="recipient_name">
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Recipient email</label>
        <input class="ia-input" name="recipient_email" type="email">
      </div>
    </div>
    <div class="ia-form-group">
      <label class="ia-form-label">Note (why it's being issued)</label>
      <input class="ia-input" name="note" maxlength="200">
    </div>
    <div style="display:flex;gap:8px">
      <button type="submit" class="ia-btn ia-btn--primary">Issue card</button>
      <button type="button" class="ia-btn ia-btn--secondary" onclick="document.getElementById('gcIssuePanel').style.display='none'">Cancel</button>
    </div>
  </form>
</div>
@endif

<div class="gc-stats">
  <div class="gc-stat">
    <div class="gc-stat-label">Outstanding balance</div>
    <div class="gc-stat-value">{{ $money($stats['outstanding_cents']) }}</div>
    <div class="gc-stat-sub">across {{ $stats['active_count'] }} active {{ \Illuminate\Support\Str::plural('card', $stats['active_count']) }}</div>
  </div>
  <div class="gc-stat">
    <div class="gc-stat-label">Sold — 30 days</div>
    <div class="gc-stat-value">{{ $money($stats['sold_cents']) }}</div>
    <div class="gc-stat-sub">{{ $stats['sold_count'] }} {{ \Illuminate\Support\Str::plural('card', $stats['sold_count']) }}</div>
  </div>
  <div class="gc-stat">
    <div class="gc-stat-label">Redeemed — 30 days</div>
    <div class="gc-stat-value">{{ $money($stats['redeemed_cents']) }}</div>
    <div class="gc-stat-sub">{{ $stats['redeemed_count'] }} {{ \Illuminate\Support\Str::plural('redemption', $stats['redeemed_count']) }}</div>
  </div>
  <div class="gc-stat">
    <div class="gc-stat-label">Fully used</div>
    <div class="gc-stat-value">{{ $stats['used_count'] }}</div>
    <div class="gc-stat-sub">all time</div>
  </div>
</div>

<form method="GET" class="ia-toolbar">
  <input class="ia-input" name="q" value="{{ $q }}" placeholder="Search code, customer, or email…">
  <select class="ia-select" name="status" onchange="this.form.submit()">
    <option value="">All statuses</option>
    <option value="active" @selected($status==='active')>Active</option>
    <option value="used" @selected($status==='used')>Used</option>
    <option value="deactivated" @selected($status==='deactivated')>Deactivated</option>
    <option value="pending" @selected($status==='pending')>Pending payment</option>
  </select>
  <select class="ia-select" name="type" onchange="this.form.submit()">
    <option value="">All types</option>
    <option value="physical" @selected($type==='physical')>Physical</option>
    <option value="egift" @selected($type==='egift')>E-gift</option>
  </select>
</form>

@if($rows->isEmpty())
  <div class="ia-empty" style="background:var(--ia-surface);border:0.5px dashed var(--ia-border)">
    <div class="ia-empty-title">No gift cards yet</div>
    <div class="ia-empty-desc">Sell one at the register with "+ Sell gift card", or issue one from here.</div>
  </div>
@else
  <div class="ia-table-wrap">
    <table class="ia-table">
      <thead><tr>
        <th>Code</th><th>Type</th><th>Purchaser</th><th>Recipient</th>
        <th class="ia-num">Original</th><th class="ia-num">Balance</th><th>Status</th><th>Last activity</th>
      </tr></thead>
      <tbody>
        @foreach($rows as $r)
          <tr onclick="window.location='{{ route('tenant.gift-cards.show', ['cardId' => $r->id]) }}'">
            <td class="ia-mono">{{ $r->maskedCode() }}</td>
            <td>{{ $r->type === 'egift' ? 'E-gift' : 'Physical' }}</td>
            <td>{{ $r->purchaser?->fullName() ?? $r->purchaser_name ?? 'Walk-in' }}</td>
            <td>{{ $r->recipient_email ?: '—' }}</td>
            <td class="ia-num">{{ $money($r->original_cents) }}</td>
            <td class="ia-num" style="font-weight:600">{{ $money($r->balance_cents) }}</td>
            <td>
              @php
                $badge = match($r->status) {
                  'active' => 'completed', 'used' => 'pending',
                  'deactivated' => 'cancelled', default => 'partial',
                };
              @endphp
              <span class="ia-badge ia-badge--{{ $badge }}">{{ ucfirst($r->status) }}</span>
            </td>
            <td style="opacity:.55">{{ tlocal_date($r->updated_at) }}{{-- MARKER-GC-TLOCAL --}}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  <div style="margin-top:16px">{{ $rows->links() }}</div>
@endif

@endsection

@push('scripts')
<script>
  // MARKER-GIFTCARDS-ADMIN — issue panel field toggle
  (function () {
    var sel = document.getElementById('gcIssueType');
    if (!sel) return;
    sel.addEventListener('change', function () {
      var egift = sel.value === 'egift';
      document.getElementById('gcIssueCodeWrap').style.display = egift ? 'none' : '';
      document.getElementById('gcIssueEmailWrap').style.display = egift ? '' : 'none';
    });
  })();
</script>
@endpush
