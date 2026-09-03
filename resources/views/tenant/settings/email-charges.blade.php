@extends('layouts.tenant.app')
@php $pageTitle = 'Email charges'; @endphp

{{-- MARKER-EMAIL-BILLING / MARKER-EMAIL-CHARGES-V3
     One hierarchy: the balance is the only hero, then this month, then the
     detail behind it, then history beside the limit. Every section states the
     window it covers, because the previous version showed four different
     totals and never said which was which. --}}
@push('styles')
<style>
  /* MARKER-EMAIL-CHARGES-V3 — no private width and no hard-coded colours:
     this page inherits the same container and theme variables as the rest. */
  .ec-stack > * + * { margin-top: 18px; }
  .ec-hero-n { font-size: 38px; font-weight: 700; letter-spacing: -.025em; line-height: 1; }
  .ec-hero-row { display: flex; align-items: flex-end; gap: 18px; flex-wrap: wrap; }
  .ec-hero-right { margin-left: auto; text-align: right; font-size: 12.5px; color: var(--ia-text-dim); }
  .ec-table { width: 100%; border-collapse: collapse; font-size: 13.5px; font-variant-numeric: tabular-nums; }
  .ec-table th { text-align: left; font-size: 10.5px; letter-spacing: .08em; text-transform: uppercase;
                 color: var(--ia-text-dim); font-weight: 500; padding: 0 12px 8px 0; }
  .ec-table td { padding: 9px 12px 9px 0; border-top: .5px solid var(--ia-border); }
  .ec-table td.r, .ec-table th.r { text-align: right; padding-right: 0; }
  .ec-table tr.ec-total td { border-top: .5px solid var(--ia-border-strong); font-weight: 600; }
  .ec-free td { color: #8ED98F; }
  .ec-sub2 { display: block; font-size: 12px; color: var(--ia-text-dim); margin-top: 2px; }
  .ec-win { margin-left: auto; font-size: 12px; color: var(--ia-text-dim); text-transform: none; letter-spacing: 0; }
  .ec-bar { height: 6px; border-radius: 3px; background: var(--ia-surface-2); overflow: hidden; margin-top: 8px; }
  .ec-bar i { display: block; height: 100%; background: var(--ia-accent); }
  .ec-note { font-size: 12px; color: var(--ia-text-dim); line-height: 1.55; margin-top: 12px; }
  .ec-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); gap: 18px; align-items: stretch; }
  .ec-grid > .ia-card { display: flex; flex-direction: column; }
  .ec-flash { border: .5px solid rgba(142,217,143,.4); border-radius: var(--ia-r-md); padding: 10px 14px; font-size: 13px; }
  .ec-warn  { border: .5px solid rgba(240,138,138,.45); border-radius: var(--ia-r-md); padding: 10px 14px; font-size: 13px; line-height: 1.55; }
  @media (max-width: 880px) { .ec-grid { grid-template-columns: 1fr; } }
</style>
@endpush

@section('content')

{{-- the same header every other settings page uses --}}
{{-- MARKER-SETTINGS-BACKLINK --}}
<a href="{{ route('tenant.settings.index') }}#account" class="ia-back-link">&larr; Account settings</a>
<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Email charges</h1>
    <p class="ia-page-subtitle">What your messages have cost, the charge behind each send, and the monthly limit that stops a campaign before it runs away.</p>
  </div>
</div>

<div class="ec-stack">

  @if(session('success'))
    <div class="ec-flash">{{ session('success') }}</div>
  @endif

  @if($cap['capped'] && $cap['reached'])
    <div class="ec-warn">
      <strong>Monthly campaign limit reached.</strong>
      Campaigns won't send until the limit is raised or the month rolls over.
      Receipts, booking confirmations and reminders are unaffected and keep sending.
    </div>
  @endif

  {{-- ONE hero: the balance. Nothing else on the page claims to be the answer. --}}
  <div class="ia-card">
    <div class="ec-hero-row">
      <div>
        <div class="ec-hero-n">${{ number_format((float) ($balance->spend ?? 0), 2) }}</div>
        <div style="font-size:13px;color:var(--ia-text-dim);margin-top:6px">
          accrued to date · {{ number_format((int) ($balance->n ?? 0)) }} messages metered
        </div>
      </div>
      <div class="ec-hero-right">
        Nothing is charged automatically yet.<br>
        You'll be told before that changes.
      </div>
    </div>
  </div>

  {{-- THIS MONTH — the table that replaces three of the old cards --}}
  @php
    $u        = $statement['usage'];
    $freeUsed = $u['email']['free']['count'] ?? 0;
    $freeCap  = $u['email']['free']['allowance'] ?? 0;
    $money    = fn ($c) => '$' . number_format($c / 100, 2);
  @endphp
  <div class="ia-card">
    <div class="ia-card-head">
      <span class="ia-card-title">This month</span>
      <span class="ec-win">{{ $statement['period']['label'] }}</span>
    </div>

    <table class="ec-table">
      <thead>
        <tr><th>What</th><th class="r">Sent</th><th class="r">Rate</th><th class="r">Cost</th></tr>
      </thead>
      <tbody>
        @if($freeCap > 0)
          <tr class="ec-free">
            <td>Included with your plan<span class="ec-sub2">first {{ number_format($freeCap) }} emails each month</span></td>
            <td class="r">{{ number_format($freeUsed) }}</td>
            <td class="r">free</td>
            <td class="r">$0.00</td>
          </tr>
        @endif

        <tr>
          <td>Campaigns<span class="ec-sub2">marketing you chose to send</span></td>
          <td class="r">{{ number_format($u['email']['marketing']['count']) }}</td>
          <td class="r">{{ $u['email']['marketing']['rate'] ?? '—' }}</td>
          <td class="r">{{ $money($u['email']['marketing']['cents']) }}</td>
        </tr>

        <tr>
          <td>Receipts &amp; reminders<span class="ec-sub2">confirmations, reminders, resets</span></td>
          <td class="r">{{ number_format($u['email']['transactional']['count']) }}</td>
          <td class="r">{{ $u['email']['transactional']['rate'] ?? '—' }}</td>
          <td class="r">{{ $money($u['email']['transactional']['cents']) }}</td>
        </tr>

        @if($u['sms']['count'] > 0 || $u['sms']['byo'])
          <tr>
            <td>Text messages<span class="ec-sub2">
              charged per segment, not per message
              @if($u['sms']['byo']) · on your own Twilio, billed by them @endif
            </span></td>
            <td class="r">{{ number_format($u['sms']['segments']) }}</td>
            <td class="r">{{ $u['sms']['rate'] ?? '—' }}</td>
            <td class="r">{{ $money($u['sms']['cents']) }}</td>
          </tr>
        @endif

        <tr class="ec-total">
          <td>Total this month</td>
          <td class="r">{{ number_format($u['email']['count'] + $u['sms']['segments']) }}</td>
          <td class="r"></td>
          <td class="r">{{ $money($u['cents']) }}</td>
        </tr>
      </tbody>
    </table>

    <p class="ec-note">
      Every send keeps the rate it went out at, so a price change never rewrites a past month.
      Anything that failed to send isn't charged.
    </p>
  </div>

  {{-- THE DETAIL BEHIND IT --}}
  <div class="ia-card">
    <div class="ia-card-head">
      <span class="ia-card-title">Charges per send</span>
      <span class="ec-win">last 90 days</span>
    </div>

    @if(count($sends))
      <table class="ec-table">
        <thead>
          <tr><th>Campaign</th><th>Sent</th><th class="r">Recipients</th><th class="r">Rate</th><th class="r">Cost</th></tr>
        </thead>
        <tbody>
        @foreach($sends as $campaignId => $rows)
          @php
            $sent   = $rows->firstWhere('status', 'sent');
            $voided = $rows->firstWhere('status', 'voided');
            $lo     = (float) ($sent->rate_lo ?? 0);
            $hi     = (float) ($sent->rate_hi ?? 0);
            $rateLabel = $lo === $hi
              ? '$' . rtrim(rtrim(number_format($lo, 5), '0'), '.')
              : '$' . rtrim(rtrim(number_format($lo, 5), '0'), '.') . '–' . rtrim(rtrim(number_format($hi, 5), '0'), '.');
          @endphp
          <tr>
            <td><a href="{{ route('tenant.campaigns.results', $campaignId) }}" style="color:var(--ia-accent);text-decoration:none">{{ $sendNames[$campaignId] ?? 'Campaign' }}</a></td>
            <td style="color:var(--ia-text-dim)">{{ \Carbon\Carbon::parse($sent->last_at ?? ($voided->last_at ?? now()))->format('M j') }}</td>
            <td class="r">{{ number_format((int) ($sent->n ?? 0)) }}@if($voided)<span class="ec-sub2">{{ number_format((int) $voided->n) }} voided, not charged</span>@endif</td>
            <td class="r">{{ $sent ? $rateLabel : '—' }}</td>
            <td class="r">${{ number_format((float) ($sent->spend ?? 0), 2) }}</td>
          </tr>
        @endforeach
        </tbody>
      </table>
      <p class="ec-note">
        A campaign shows the rate it actually sent at, so an older one can read lower than today's price.
        Voided rows appear on that campaign's own results page.
      </p>
    @else
      <p class="ec-note" style="margin-top:0">No campaigns sent in the last 90 days.</p>
    @endif
  </div>

  {{-- MARKER-BILLING-RECEIPT — what has actually been taken from the card --}}
  @php
    $runs = \App\Models\TenantChargeRun::where('tenant_id', $currentTenant->id)
        ->whereIn('status', ['charged', 'refunded', 'written_off'])
        ->orderByDesc('created_at')->limit(12)->get();
  @endphp
  @if($runs->isNotEmpty())
    <div class="ia-card">
      <div class="ia-card-head"><span class="ia-card-title">Card charges</span></div>
      <table class="ec-table">
        <thead><tr><th>Date</th><th>What</th><th class="r">Amount</th><th class="r">Receipt</th></tr></thead>
        <tbody>
          @foreach($runs as $run)
            <tr>
              <td style="color:var(--ia-text-dim)">{{ ($run->charged_at ?? $run->created_at)->format('M j, Y') }}</td>
              <td>
                {{ number_format($run->message_count) }} messages
                @if($run->status === 'refunded')<span class="ec-sub2">refunded</span>@endif
                @if($run->status === 'written_off')<span class="ec-sub2">written off — not charged</span>@endif
              </td>
              <td class="r">${{ number_format($run->amount_cents / 100, 2) }}</td>
              <td class="r"><a href="{{ route('tenant.settings.charge_receipt', $run->id) }}" target="_blank" style="color:var(--ia-accent)">PDF</a></td>
            </tr>
          @endforeach
        </tbody>
      </table>
      <p class="ec-note">Charges happen when the balance reaches your threshold, not on a fixed date.</p>
    </div>
  @endif

  {{-- HISTORY AND THE LIMIT — different questions, similar weight --}}
  <div class="ec-grid">
    <div class="ia-card">
      <div class="ia-card-head"><span class="ia-card-title">By month</span></div>
      @if(count($history))
        @php $peak = collect($history)->max('spend') ?: 1; @endphp
        @foreach($history as $h)
          <div style="display:flex;align-items:center;gap:10px;padding:6px 0;font-size:13px">
            <span style="width:78px;color:var(--ia-text-dim)">{{ \Carbon\Carbon::createFromFormat('Y-m', $h->ym)->format('M Y') }}</span>
            <span style="flex:1;height:6px;border-radius:3px;background:var(--ia-surface-2);overflow:hidden">
              <span style="display:block;height:100%;width:{{ max(2, round(((float) $h->spend / $peak) * 100)) }}%;background:var(--ia-accent)"></span>
            </span>
            <span style="width:64px;text-align:right;color:var(--ia-text-dim)">{{ number_format((int) $h->n) }}</span>
            <span style="width:72px;text-align:right;font-weight:600">${{ number_format((float) $h->spend, 2) }}</span>
          </div>
        @endforeach
      @else
        <p class="ec-note" style="margin-top:0">Nothing metered yet.</p>
      @endif
    </div>

    <div class="ia-card">
      <div class="ia-card-head"><span class="ia-card-title">Monthly campaign limit</span></div>
      <p style="font-size:13px;color:var(--ia-text-dim);line-height:1.55;margin-bottom:14px">
        A ceiling on campaign spend, so a mistake can't run up a bill. When it's reached campaigns stop
        until next month — <strong style="color:var(--ia-text-muted)">receipts, confirmations and reminders
        keep sending regardless</strong>.
      </p>

      @if($cap['capped'])
        <div style="display:flex;align-items:baseline;gap:8px;font-size:12.5px">
          <span style="color:var(--ia-text-dim)">Used this month</span>
          <span style="margin-left:auto">
            <b>${{ number_format($cap['spent'], 2) }}</b>
            <span style="color:var(--ia-text-dim)">of ${{ number_format($cap['cap'], 2) }}</span>
          </span>
        </div>
        <div class="ec-bar">
          <i style="width:{{ $cap['cap'] > 0 ? max(2, min(100, round($cap['spent'] / $cap['cap'] * 100))) : 0 }}%"></i>
        </div>
      @endif

      <form method="POST" action="{{ route('tenant.settings.email_charges.cap') }}" style="margin-top:14px">
        @csrf
        <label style="display:flex;gap:8px;align-items:center;font-size:13px;margin-bottom:10px">
          <input type="checkbox" name="cap_enabled" value="1" id="ec-cap-on" {{ $cap['capped'] ? 'checked' : '' }}
                 onchange="document.getElementById('ec-cap-val').disabled = !this.checked">
          <span>Limit campaign spend each month</span>
        </label>
        <div style="display:flex;gap:10px;align-items:center">
          <span style="color:var(--ia-text-dim)">$</span>
          <input type="number" step="0.01" min="0" name="cap_dollars" id="ec-cap-val"
                 class="rounded-lg" style="width:130px;background:var(--ia-input-bg);border:.5px solid var(--ia-border);border-radius:var(--ia-r-md);color:var(--ia-text);padding:8px 11px;font:inherit;font-size:13px"
                 value="{{ $cap['cap'] !== null ? number_format($cap['cap'], 2, '.', '') : '' }}"
                 placeholder="50.00" {{ $cap['capped'] ? '' : 'disabled' }}>
          <button type="submit" class="ia-btn ia-btn--primary">Save limit</button>
        </div>
      </form>

      <p class="ec-note">
        This is your ceiling on marketing. It is not the same as the amount at which your card gets charged.
      </p>
    </div>
  </div>

</div>
@endsection
