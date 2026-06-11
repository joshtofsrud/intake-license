@extends('layouts.tenant.app')
@php $pageTitle = 'Sale ' . $sale->sale_number; @endphp

{{-- MARKER-PATCH-231A — sale detail page (stub; expand with actions next). --}}

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">{{ $sale->sale_number }}</h1>
    <p class="ia-page-subtitle">{{ tlocal_date($sale->sale_date ?? $sale->created_at) }}@if($sale->location) · {{ $sale->location->name }}@endif</p>
  </div>
  <a href="{{ route('tenant.register.history.index') }}" class="ia-btn">All sales</a>
</div>

@php
  $statusCls = match($sale->payment_status) {
    'paid'    => 'ia-badge--paid',
    'overage' => 'ia-badge--paid',
    'refunded','partial_refund' => 'ia-badge--unpaid',
    'draft','quote' => 'ia-badge--confirmed',
    default   => 'ia-badge--unpaid',
  };
@endphp

<div style="display:grid;grid-template-columns:1.5fr 1fr;gap:16px">
  <div class="ia-card" style="padding:18px 20px">
    <h2 class="ia-h3" style="margin-bottom:12px">Items</h2>
    @forelse($sale->items->sortBy('position') as $item)
      <div style="display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-bottom:.5px solid var(--ia-border)">
        <div>
          <div style="font-size:13.5px;font-weight:550">{{ $item->name_snapshot }}</div>
          @if($item->quantity > 1)<div style="font-size:11.5px;opacity:.5">{{ rtrim(rtrim(number_format($item->quantity,2),'0'),'.') }} × {{ format_money($item->unit_price_cents) }}</div>@endif
        </div>
        <div style="font-variant-numeric:tabular-nums;font-size:13.5px">{{ format_money($item->line_total_cents) }}</div>
      </div>
    @empty
      <p style="font-size:13px;opacity:.5">No line items.</p>
    @endforelse

    @if($sale->payments->count())
      <h2 class="ia-h3" style="margin:18px 0 12px">Payments</h2>
      @foreach($sale->payments as $p)
        <div style="display:flex;justify-content:space-between;gap:12px;padding:7px 0;border-bottom:.5px solid var(--ia-border);font-size:13px">
          <span style="opacity:.7">{{ ucfirst(str_replace('_',' ', $p->kind ?? 'payment')) }}@if($p->created_at) · {{ tlocal_date($p->created_at) }}@endif</span>
          <span style="font-variant-numeric:tabular-nums;{{ ($p->amount_cents ?? 0) < 0 ? 'color:#E0573E' : '' }}">{{ format_money($p->amount_cents ?? 0) }}</span>
        </div>
      @endforeach
    @endif

    @if($refunds->count())
      <h2 class="ia-h3" style="margin:18px 0 12px">Refunds</h2>
      @foreach($refunds as $r)
        <div style="display:flex;justify-content:space-between;gap:12px;padding:7px 0;border-bottom:.5px solid var(--ia-border);font-size:13px">
          <a href="{{ route('tenant.register.sales.page', $r->id) }}" style="font-family:var(--ia-font-mono);font-size:12px">{{ $r->sale_number }}</a>
          <span style="font-variant-numeric:tabular-nums;color:#E0573E">{{ format_money($r->total_cents) }}</span>
        </div>
      @endforeach
    @endif
  </div>

  <div class="ia-card" style="padding:18px 20px;align-self:start">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <h2 class="ia-h3" style="margin:0">Summary</h2>
      <span class="ia-badge {{ $statusCls }}">{{ ucfirst(str_replace('_',' ', $sale->payment_status)) }}</span>
    </div>
    <div style="display:flex;flex-direction:column;gap:8px;font-size:13px">
      @if($sale->customer)
        <div style="display:flex;justify-content:space-between"><span style="opacity:.55">Customer</span>
          <a href="{{ route('tenant.customers.show', $sale->customer->id) }}">{{ trim(($sale->customer->first_name ?? '').' '.($sale->customer->last_name ?? '')) ?: '—' }}</a>
        </div>
      @endif
      @if($sale->rangUpBy)<div style="display:flex;justify-content:space-between"><span style="opacity:.55">Rung up by</span><span>{{ $sale->rangUpBy->name }}</span></div>@endif
      <div style="border-top:.5px solid var(--ia-border);margin-top:6px;padding-top:10px;display:flex;justify-content:space-between"><span style="opacity:.55">Subtotal</span><span>{{ format_money($sale->subtotal_cents) }}</span></div>
      @if($sale->tax_cents)<div style="display:flex;justify-content:space-between"><span style="opacity:.55">Tax</span><span>{{ format_money($sale->tax_cents) }}</span></div>@endif
      <div style="display:flex;justify-content:space-between;font-weight:600;font-size:14px"><span>Total</span><span>{{ format_money($sale->total_cents) }}</span></div>
    </div>
  </div>
</div>

@endsection
