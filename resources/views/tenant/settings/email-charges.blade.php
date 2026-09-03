@extends('layouts.tenant.app')
@php $pageTitle = 'Email charges'; @endphp

{{-- MARKER-EMAIL-BILLING --}}
@push('styles')
<style>
  .ec-wrap{max-width:760px}
  .ec-crumb{color:var(--ia-text-3,#74747a);font-size:12.5px;margin-bottom:14px}
  .ec-crumb a{color:var(--ia-text-2,#a6a6ac);text-decoration:none}
  .ec-hero{background:var(--ia-surface,#161619);border:1px solid var(--ia-border,#2a2a2e);border-radius:14px;padding:22px 24px;margin-bottom:16px}
  .ec-total{font-size:34px;font-weight:760;letter-spacing:-.02em;color:var(--ia-text,#f4f4f5)}
  .ec-sub{font-size:12.5px;color:var(--ia-text-3,#74747a);margin-top:4px}
  .ec-sec{background:var(--ia-surface,#161619);border:1px solid var(--ia-border,#2a2a2e);border-radius:13px;padding:18px 20px;margin-bottom:16px}
  .ec-sec h2{font-size:14.5px;font-weight:660;margin:0 0 10px;color:var(--ia-text,#f4f4f5)}
  .ec-row{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--ia-border,#2a2a2e);font-size:13px;color:var(--ia-text-2,#a6a6ac)}
  .ec-row:last-child{border-bottom:none}
  .ec-note{font-size:12.5px;line-height:1.6;color:var(--ia-text-3,#74747a);margin-top:10px}
  .ec-in{background:var(--ia-bg,#0f0f11);border:1px solid var(--ia-border,#2a2a2e);border-radius:9px;color:var(--ia-text,#f4f4f5);font:inherit;font-size:15px;padding:9px 12px;width:140px}
  .ec-btn{appearance:none;border:none;cursor:pointer;font:inherit;font-weight:640;background:var(--ia-accent,#e0a82e);color:#141414;border-radius:9px;padding:9px 18px}
  .ec-check{display:flex;gap:8px;align-items:center;font-size:13px;color:var(--ia-text-2,#a6a6ac);margin-bottom:12px}
  .ec-warn{border:1px solid rgba(220,120,120,.5);border-radius:11px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:var(--ia-text-2,#a6a6ac)}
  .ec-flash{border:1px solid rgba(120,200,120,.4);border-radius:11px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:var(--ia-text-2,#a6a6ac)}
</style>
@endpush

@section('content')
<div class="ec-wrap">
  <div class="ec-crumb"><a href="{{ route('tenant.settings.index') }}">Settings</a> → Email charges</div>

  @if(session('success'))<div class="ec-flash">{{ session('success') }}</div>@endif

  @if($cap['capped'] && $cap['reached'])
    <div class="ec-warn">
      <strong style="color:var(--ia-text,#f4f4f5)">Monthly marketing limit reached.</strong>
      Campaigns won't send until the limit is raised or the month rolls over.
      Receipts, booking confirmations and reminders are unaffected and keep sending.
    </div>
  @endif

  <div class="ec-hero">
    <div class="ec-total">${{ number_format($mtd['total'], 2) }}</div>
    <div class="ec-sub">
      {{ number_format($mtd['count']) }} emails since {{ $mtd['since']->format('M j') }} ·
      ${{ number_format($rate, 5) }} each
    </div>
  </div>

  <div class="ec-sec">
    <h2>What made it up</h2>
    @php
      $labels = [
        'campaign' => 'Campaigns (marketing)',
        'receipt'  => 'Receipts and confirmations',
        'reminder' => 'Reminders',
        'reply'    => 'Inbox replies',
        'staff'    => 'Staff email',
        'test'     => 'Tests (not charged)',
        'other'    => 'Other',
      ];
    @endphp
    @forelse($mtd['by_kind'] as $kind => $row)
      <div class="ec-row">
        <span>{{ $labels[$kind] ?? ucfirst($kind) }} <span style="color:var(--ia-text-3,#74747a)">· {{ number_format($row['count']) }}</span></span>
        <span>{{ $kind === 'test' ? '—' : '$' . number_format($row['spend'], 2) }}</span>
      </div>
    @empty
      <div class="ec-row"><span>No email sent this month.</span><span>$0.00</span></div>
    @endforelse
    <div class="ec-note">
      Every email your shop sends is counted here, including receipts. Each line keeps
      the rate it was charged at, so a price change never rewrites a past month.
    </div>
  </div>

  @if($campaigns->count() > 0)
  <div class="ec-sec">
    <h2>Campaigns this month</h2>
    @foreach($campaigns as $c)
      <div class="ec-row">
        <span>{{ $names[$c->campaign_id] ?? 'Campaign' }} <span style="color:var(--ia-text-3,#74747a)">· {{ number_format($c->n) }} sent</span></span>
        <span>${{ number_format($c->spend, 2) }}</span>
      </div>
    @endforeach
  </div>
  @endif

  <div class="ec-sec">
    <h2>Monthly marketing limit</h2>
    <p style="font-size:13px;line-height:1.6;color:var(--ia-text-2,#a6a6ac);margin:0 0 12px">
      A ceiling on campaign spend per month, so a mistake can't run up a bill.
      When it's reached, campaigns stop sending —
      <strong style="color:var(--ia-text,#f4f4f5)">receipts, confirmations and reminders keep going regardless</strong>.
    </p>

{{-- MARKER-EMAIL-CHARGES-V2 — the balance, what caused it, and history --}}
<div class="ia-card" style="margin-bottom:16px">
  <div class="ia-card-head"><span class="ia-card-title">Balance</span></div>
  <div class="ia-card-body">
    <div style="display:flex;align-items:baseline;gap:10px;flex-wrap:wrap">
      <div style="font-size:30px;font-weight:700;letter-spacing:-.01em">
        ${{ number_format((float) ($balance->spend ?? 0), 2) }}
      </div>
      <div style="font-size:13px;color:var(--ia-text-muted)">
        {{ number_format((int) ($balance->n ?? 0)) }} emails metered to date
      </div>
    </div>
    <p style="font-size:12.5px;color:var(--ia-text-muted);line-height:1.55;margin:10px 0 0">
      Accrued, not yet billed — email is metered as it sends, and nothing is charged automatically today.
      Each row keeps the rate it was sent at, so changing the rate later never rewrites what you already owe.
    </p>
  </div>
</div>

<div class="ia-card" style="margin-bottom:16px">
  <div class="ia-card-head"><span class="ia-card-title">Charges per send</span>
    <span style="margin-left:auto;font-size:12px;color:var(--ia-text-muted)">last 90 days</span>
  </div>
  <div class="ia-card-body">
    @forelse($sends as $campaignId => $rows)
      @php
        $sent   = $rows->firstWhere('status', 'sent');
        $voided = $rows->firstWhere('status', 'voided');
        $spend  = (float) ($sent->spend ?? 0);
        $n      = (int) ($sent->n ?? 0);
        $lo     = (float) ($sent->rate_lo ?? 0);
        $hi     = (float) ($sent->rate_hi ?? 0);
      @endphp
      <div style="display:flex;align-items:baseline;gap:10px;padding:9px 0;border-bottom:1px solid var(--ia-border);flex-wrap:wrap">
        <a href="{{ route('tenant.campaigns.results', $campaignId) }}" style="font-weight:600;font-size:13.5px">
          {{ $sendNames[$campaignId] ?? 'Campaign' }}
        </a>
        <span style="font-size:12px;color:var(--ia-text-muted)">
          {{ \Carbon\Carbon::parse($sent->last_at ?? ($voided->last_at ?? now()))->format('M j') }}
        </span>
        <span style="font-size:12.5px;color:var(--ia-text-muted)">
          {{ number_format($n) }} sent @ ${{ $lo === $hi ? rtrim(rtrim(number_format($lo, 5), '0'), '.') : rtrim(rtrim(number_format($lo, 5), '0'), '.') . '–' . rtrim(rtrim(number_format($hi, 5), '0'), '.') }}
        </span>
        @if($voided)
          <span style="font-size:12px;color:var(--ia-text-muted);opacity:.8">
            · {{ number_format((int) $voided->n) }} voided, not charged
          </span>
        @endif
        <span style="margin-left:auto;font-weight:600">${{ number_format($spend, 2) }}</span>
      </div>
    @empty
      <p style="font-size:13px;color:var(--ia-text-muted);margin:0">No campaigns sent in the last 90 days.</p>
    @endforelse
  </div>
</div>

<div class="ia-card" style="margin-bottom:16px">
  <div class="ia-card-head"><span class="ia-card-title">Everything else</span>
    <span style="margin-left:auto;font-size:12px;color:var(--ia-text-muted)">this month</span>
  </div>
  <div class="ia-card-body">
    @forelse($other as $row)
      <div style="display:flex;gap:10px;padding:7px 0;border-bottom:1px solid var(--ia-border);font-size:13px">
        <span style="text-transform:capitalize">{{ str_replace('_', ' ', $row->kind) }}</span>
        <span style="color:var(--ia-text-muted)">{{ number_format((int) $row->n) }} emails</span>
        <span style="margin-left:auto">${{ number_format((float) $row->spend, 2) }}</span>
      </div>
    @empty
      <p style="font-size:13px;color:var(--ia-text-muted);margin:0">No receipts, reminders or tests billed this month.</p>
    @endforelse
    <p style="font-size:12px;color:var(--ia-text-muted);margin:10px 0 0;line-height:1.5">
      Receipts and reminders are part of running the shop; they are metered the same way but they are not marketing,
      so the monthly limit below applies to campaigns only.
    </p>
  </div>
</div>

@if(count($history) > 1)
<div class="ia-card" style="margin-bottom:16px">
  <div class="ia-card-head"><span class="ia-card-title">By month</span></div>
  <div class="ia-card-body">
    @php $peak = collect($history)->max('spend') ?: 1; @endphp
    @foreach($history as $h)
      <div style="display:flex;align-items:center;gap:10px;padding:5px 0;font-size:13px">
        <span style="width:74px;color:var(--ia-text-muted)">{{ \Carbon\Carbon::createFromFormat('Y-m', $h->ym)->format('M Y') }}</span>
        <span style="flex:1;height:6px;border-radius:3px;background:var(--ia-surface-2);overflow:hidden">
          <span style="display:block;height:100%;width:{{ max(2, round(((float) $h->spend / $peak) * 100)) }}%;background:var(--ia-accent)"></span>
        </span>
        <span style="width:64px;text-align:right;color:var(--ia-text-muted)">{{ number_format((int) $h->n) }}</span>
        <span style="width:70px;text-align:right;font-weight:600">${{ number_format((float) $h->spend, 2) }}</span>
      </div>
    @endforeach
  </div>
</div>
@endif

    <form method="POST" action="{{ route('tenant.settings.email_charges.cap') }}">
      @csrf
      <label class="ec-check">
        <input type="checkbox" name="cap_enabled" value="1" id="ec-cap-on" {{ $cap['capped'] ? 'checked' : '' }}
               onchange="document.getElementById('ec-cap-val').disabled = !this.checked">
        <span>Limit marketing spend each month</span>
      </label>
      <div style="display:flex;gap:10px;align-items:center">
        <span style="color:var(--ia-text-3,#74747a);font-size:14px">$</span>
        <input class="ec-in" type="number" step="0.01" min="0" name="cap_dollars" id="ec-cap-val"
               value="{{ $cap['cap'] !== null ? number_format($cap['cap'], 2, '.', '') : '' }}"
               placeholder="50.00" {{ $cap['capped'] ? '' : 'disabled' }}>
        <button type="submit" class="ec-btn">Save limit</button>
      </div>
    </form>
    @if($cap['capped'])
      <div class="ec-note">
        ${{ number_format($cap['spent'], 2) }} of ${{ number_format($cap['cap'], 2) }} used this month
        @if(!$cap['reached']) · ${{ number_format($cap['remaining'], 2) }} left @endif
      </div>
    @endif
  </div>

  <div class="ec-sec">
    <h2>How this gets billed</h2>
    <p style="font-size:13px;line-height:1.6;color:var(--ia-text-2,#a6a6ac);margin:0">
      This page is the record of what you've sent. Email charges aren't on your
      Stripe invoice yet — until they are, nothing here is being collected.
      You'll be told before that changes.
    </p>
  </div>
</div>
@endsection
