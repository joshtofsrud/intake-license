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
