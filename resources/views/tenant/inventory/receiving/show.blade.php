@extends('layouts.tenant.app')
@php
  $pageTitle = 'Shipment ' . $shipment->shipment_number;
  $statusLabels = [
    'expected' => 'Expected',
    'received' => 'Received',
    'backorder' => 'Backorder',
    'unexpected_pending' => 'Pending',
    'unexpected_added' => 'Added',
    'unexpected_hold' => 'On hold',
  ];
@endphp

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">{{ $shipment->shipment_number }}</h1>
    <p class="ia-page-subtitle">
      {{ ucfirst($shipment->status) }} ·
      {{ $shipment->location?->name ?? '—' }} ·
      {{ $shipment->received_date?->format('M j, Y') ?? '—' }}
    </p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.inventory.receiving.index') }}" class="ia-btn ia-btn--ghost">← Back</a>
  </div>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--{{ session('flash')['type'] }}">{{ session('flash')['message'] }}</div>
@endif

<div class="ia-card" style="margin-bottom:14px">
  <div class="ia-card-body" style="padding:16px 20px">
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px 18px;font-size:13px">
      <div>
        <div style="font-size:11px;color:var(--ia-text-muted);text-transform:uppercase;letter-spacing:.05em">Distributor</div>
        <div style="margin-top:2px">{{ $shipment->distributor_name ?? '—' }} @if($shipment->distributor_code) <span style="color:var(--ia-text-muted)">· {{ $shipment->distributor_code }}</span>@endif</div>
      </div>
      <div>
        <div style="font-size:11px;color:var(--ia-text-muted);text-transform:uppercase;letter-spacing:.05em">Shipping cost</div>
        <div style="margin-top:2px">{{ '$' . number_format($shipment->shipping_cost_cents / 100, 2) }}</div>
      </div>
      <div>
        <div style="font-size:11px;color:var(--ia-text-muted);text-transform:uppercase;letter-spacing:.05em">Committed</div>
        <div style="margin-top:2px">
          @if($shipment->committed_at)
            {{ $shipment->committed_at->format('M j, Y g:i A') }}
            @if($shipment->committedBy) <span style="color:var(--ia-text-muted)">· {{ $shipment->committedBy->name }}</span>@endif
          @else
            —
          @endif
        </div>
      </div>
      @if($shipment->notes)
        <div style="grid-column:1 / -1">
          <div style="font-size:11px;color:var(--ia-text-muted);text-transform:uppercase;letter-spacing:.05em">Notes</div>
          <div style="margin-top:2px;white-space:pre-wrap">{{ $shipment->notes }}</div>
        </div>
      @endif
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:0;margin-bottom:14px;border:1px solid var(--ia-border);border-radius:6px;overflow:hidden">
  <div style="padding:12px 14px;border-right:1px solid var(--ia-border)">
    <div style="font-size:11px;color:var(--ia-text-muted);letter-spacing:.05em;text-transform:uppercase">Expected</div>
    <div style="font-size:22px;font-weight:600;margin-top:2px">{{ $shipment->expected_count }}</div>
  </div>
  <div style="padding:12px 14px;border-right:1px solid var(--ia-border)">
    <div style="font-size:11px;color:var(--ia-text-muted);letter-spacing:.05em;text-transform:uppercase">Received</div>
    <div style="font-size:22px;font-weight:600;color:var(--ia-accent);margin-top:2px">{{ $shipment->received_count }}</div>
  </div>
  <div style="padding:12px 14px;border-right:1px solid var(--ia-border)">
    <div style="font-size:11px;color:var(--ia-text-muted);letter-spacing:.05em;text-transform:uppercase">Backorder</div>
    <div style="font-size:22px;font-weight:600;color:#f4b400;margin-top:2px">{{ $shipment->backorder_count }}</div>
  </div>
  <div style="padding:12px 14px">
    <div style="font-size:11px;color:var(--ia-text-muted);letter-spacing:.05em;text-transform:uppercase">Unexpected</div>
    <div style="font-size:22px;font-weight:600;color:#f4b400;margin-top:2px">{{ $shipment->unexpected_count }}</div>
  </div>
</div>

<div class="ia-card">
  <table class="ia-table">
    <thead>
      <tr>
        <th>Item</th>
        <th>SKU</th>
        <th style="text-align:right">Expected</th>
        <th style="text-align:right">Received</th>
        <th>Status</th>
        <th style="text-align:right">Unit cost</th>
        <th style="text-align:right">Line total</th>
      </tr>
    </thead>
    <tbody>
      @foreach($shipment->items as $line)
        @php $isUnx = str_starts_with($line->status, 'unexpected_'); @endphp
        <tr @if($isUnx) style="background:rgba(244,180,0,.06)" @endif>
          <td>
            <div style="font-weight:500">{{ $line->name }}</div>
            @if($line->item?->category?->name)
              <div style="font-size:11px;color:var(--ia-text-muted);margin-top:1px">{{ $line->item->category->name }}</div>
            @endif
          </td>
          <td><code style="font-size:11.5px;color:var(--ia-accent)">{{ $line->sku }}</code></td>
          <td style="text-align:right;font-variant-numeric:tabular-nums">{{ $isUnx ? '—' : $line->expected_quantity }}</td>
          <td style="text-align:right;font-variant-numeric:tabular-nums">{{ $line->received_quantity }}</td>
          <td>{{ $statusLabels[$line->status] ?? $line->status }}</td>
          <td style="text-align:right;font-variant-numeric:tabular-nums">
            {{ $line->unit_cost_cents !== null ? '$' . number_format($line->unit_cost_cents / 100, 2) : '—' }}
          </td>
          <td style="text-align:right;font-variant-numeric:tabular-nums">
            {{ $line->total_cost_cents !== null ? '$' . number_format($line->total_cost_cents / 100, 2) : '—' }}
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
</div>

<div style="margin-top:18px;padding-top:14px;border-top:1px solid var(--ia-border);font-size:12.5px;color:var(--ia-text-muted)">
  Started by {{ $shipment->createdBy?->name ?? 'Unknown' }} on {{ $shipment->created_at->format('M j, Y g:i A') }}.
  @if($shipment->isCommitted())
    Movements written under reference <code style="font-size:11.5px;color:var(--ia-accent)">receive_shipment / {{ $shipment->id }}</code>.
  @endif
</div>

@endsection
