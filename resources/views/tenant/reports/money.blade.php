@extends('layouts.tenant.app')
@section('title', 'Reports · Money')

@push('styles')
@include('tenant.reports._tab_styles')
<style>
/* MARKER-PATCH-185 — revenue split dots + composition bar */
.rep-rev-strip .lbl{display:flex;align-items:center;gap:7px}
.rep-rev-dot{width:8px;height:8px;border-radius:50%;flex:none;display:inline-block}
.rep-rev-dot.is-svc{background:var(--ia-accent,#bef264);box-shadow:0 0 8px rgba(190,242,100,0.45)}
.rep-rev-dot.is-ret{background:#5ea9ff}
.rep-rev-dot.is-unc{background:#8b8b94}
.rep-rev-bar{display:flex;height:8px;width:100%;border-radius:999px;overflow:hidden;margin-top:14px;background:var(--ia-surface-2,rgba(255,255,255,0.04));border:0.5px solid var(--ia-border)}
.rep-rev-bar span{display:block;height:100%}
.rep-rev-bar .b-svc{background:linear-gradient(90deg,#bef264,#a3e635)}
.rep-rev-bar .b-ret{background:linear-gradient(90deg,#5ea9ff,#3b82f6)}
.rep-rev-bar .b-unc{background:#8b8b94}
</style>
@endpush

@section('content')
<div style="padding: 32px 40px;">
  <h1 class="rep-h1">Reports</h1>
  <div class="rep-sub">{{ $today_label }}</div>

  <div class="rep-controls">{{-- MARKER-PATCH-432 --}}
  @include('tenant.reports._tab_subnav', ['active' => 'money'])

  <div class="rep-rangebar">
    <div><span class="rep-rangebar-label">Range</span><span class="rep-rangebar-current">{{ $range_label }}</span></div>
    <div class="rep-rangebar-controls">
      <nav class="rep-toggle">
        <a href="{{ route('tenant.reports.money', ['range' => 'today']) }}"  class="{{ $range === 'today' ? 'active' : '' }}">Today</a>
        <a href="{{ route('tenant.reports.money', ['range' => 'week']) }}"   class="{{ $range === 'week'  ? 'active' : '' }}">Week</a>
        <a href="{{ route('tenant.reports.money', ['range' => 'month']) }}"  class="{{ $range === 'month' ? 'active' : '' }}">Month</a>
        <a href="{{ route('tenant.reports.money', ['range' => 'last_30']) }}" class="{{ $range === 'last_30' ? 'active' : '' }}">Last 30</a>
      </nav>
    </div>
  </div>
  </div>

  {{-- Revenue summary — MARKER-PATCH-185: service/retail/uncategorized by line-item type. --}}
  @php
    $revTotal = (int) $revenueSummary['total_revenue_cents'];
    $revSvc   = (int) $revenueSummary['service_revenue_cents'];
    $revRet   = (int) $revenueSummary['retail_revenue_cents'];
    $revUnc   = (int) ($revenueSummary['uncategorized_revenue_cents'] ?? 0);
    $pct = fn($c) => $revTotal > 0 ? round($c / $revTotal * 100) : 0;
  @endphp
  <div class="rep-zone">
    <div class="rep-zone-head"><div><div class="rep-zone-title">💰 Revenue</div><div class="rep-zone-sub">Payments received in this period. Refunds netted out.</div></div></div>
    <div class="rep-stat-strip rep-rev-strip">
      <div class="rep-stat-cell"><div class="lbl"><span class="rep-rev-dot is-svc"></span>Service</div><div class="val">${{ number_format($revSvc / 100, 2) }}</div><div class="meta">{{ $pct($revSvc) }}% of revenue</div></div>
      <div class="rep-stat-cell"><div class="lbl"><span class="rep-rev-dot is-ret"></span>Retail</div><div class="val">${{ number_format($revRet / 100, 2) }}</div><div class="meta">{{ $pct($revRet) }}% of revenue</div></div>
      <div class="rep-stat-cell"><div class="lbl"><span class="rep-rev-dot is-unc"></span>Uncategorized</div><div class="val">${{ number_format($revUnc / 100, 2) }}</div><div class="meta">{{ $pct($revUnc) }}% of revenue</div></div>
      <div class="rep-stat-cell feat"><div class="lbl">Total revenue</div><div class="val">${{ number_format($revTotal / 100, 2) }}</div><div class="meta">combined</div></div>
    </div>
    @if($revTotal > 0)
    <div class="rep-rev-bar">
      <span class="b-svc" style="width:{{ $pct($revSvc) }}%"></span>
      <span class="b-ret" style="width:{{ $pct($revRet) }}%"></span>
      <span class="b-unc" style="width:{{ $pct($revUnc) }}%"></span>
    </div>
    @endif
  </div>

  {{-- Refunds --}}
  <div class="rep-zone">
    <div class="rep-zone-head"><div><div class="rep-zone-title">↩️ Refunds</div><div class="rep-zone-sub">Sale refunds issued in range. Appointment refunds tracked separately via payment status.</div></div></div>
    <div class="rep-stat-strip">
      <div class="rep-stat-cell {{ $refunds['refund_count'] > 0 ? 'warn' : '' }}"><div class="lbl">Refund count</div><div class="val">{{ number_format($refunds['refund_count']) }}</div></div>
      <div class="rep-stat-cell {{ $refunds['refund_total_cents'] > 0 ? 'warn' : '' }}"><div class="lbl">Refunded</div><div class="val">${{ number_format($refunds['refund_total_cents'] / 100, 2) }}</div></div>
    </div>
  </div>

  {{-- Tax & fees --}}
  <div class="rep-zone">
    <div class="rep-zone-head"><div><div class="rep-zone-title">🧾 Tax collected</div><div class="rep-zone-sub">Sales tax across services + retail. Set aside what you owe.</div></div></div>
    <div class="rep-stat-strip">
      <div class="rep-stat-cell"><div class="lbl">Service tax</div><div class="val">${{ number_format($taxAndFees['service_tax_cents'] / 100, 2) }}</div></div>
      <div class="rep-stat-cell"><div class="lbl">Retail tax</div><div class="val">${{ number_format($taxAndFees['retail_tax_cents'] / 100, 2) }}</div></div>
      <div class="rep-stat-cell feat"><div class="lbl">Total tax</div><div class="val">${{ number_format($taxAndFees['total_tax_cents'] / 100, 2) }}</div></div>
    </div>
  </div>

  {{-- Drawer & till stub --}}
  <div class="rep-zone">
    <div class="rep-zone-head"><div><div class="rep-zone-title">💵 Drawer & till</div><div class="rep-zone-sub">Cash drawer reconciliation. Open / close / variance.</div></div></div>
    <div class="rep-stub"><strong>Coming soon.</strong> {{ $drawerAndTill['reason'] }}</div>
  </div>

  {{-- Stripe payouts stub --}}
  <div class="rep-zone">
    <div class="rep-zone-head"><div><div class="rep-zone-title">🏦 Stripe payouts</div><div class="rep-zone-sub">Money landing in your bank account from Stripe.</div></div></div>
    <div class="rep-stub"><strong>Coming soon.</strong> {{ $stripePayouts['reason'] }}</div>
  </div>

</div>

@if($is_locked)
@include('tenant.reports._upsell_modal', ['title' => 'Money Reports', 'pitch' => 'See revenue, refunds, and tax collected across services and retail. Know what to set aside and where it came from.'])
@endif
@endsection
