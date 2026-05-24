@extends('layouts.tenant.app')
@section('title', 'Reports · Money')

@push('styles')
@include('tenant.reports._tab_styles')
@endpush

@section('content')
<div style="padding: 32px 40px;">
  <h1 class="rep-h1">Reports</h1>
  <div class="rep-sub">{{ $today_label }}</div>

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

  {{-- Revenue summary --}}
  <div class="rep-zone">
    <div class="rep-zone-head"><div><div class="rep-zone-title">💰 Revenue</div><div class="rep-zone-sub">Paid service revenue + paid retail revenue. Refunds excluded.</div></div></div>
    <div class="rep-stat-strip">
      <div class="rep-stat-cell"><div class="lbl">Service</div><div class="val">${{ number_format($revenueSummary['service_revenue_cents'] / 100, 2) }}</div><div class="meta">paid appointments</div></div>
      <div class="rep-stat-cell"><div class="lbl">Retail</div><div class="val">${{ number_format($revenueSummary['retail_revenue_cents'] / 100, 2) }}</div><div class="meta">paid sales</div></div>
      <div class="rep-stat-cell feat"><div class="lbl">Total</div><div class="val">${{ number_format($revenueSummary['total_revenue_cents'] / 100, 2) }}</div><div class="meta">combined</div></div>
    </div>
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
