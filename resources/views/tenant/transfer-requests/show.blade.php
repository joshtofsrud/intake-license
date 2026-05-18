@extends('layouts.tenant.app')
@php $pageTitle = 'Transfer request'; @endphp

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Transfer request</h1>
    <p class="ia-page-subtitle">
      <a href="{{ route('tenant.transfer-requests.index') }}">← Transfer requests</a>
      &nbsp;·&nbsp;
      <span class="tr-status tr-status--{{ $tr->status }}">{{ ucfirst($tr->status) }}</span>
    </p>
  </div>
  @if($tr->status === 'pending')
    <div class="ia-page-actions" style="display:flex;gap:8px">
      <form method="POST" action="{{ route('tenant.transfer-requests.fulfill', $tr->id) }}" style="display:inline">
        @csrf
        <button type="submit" class="ia-btn ia-btn--primary">Mark fulfilled</button>
      </form>
      <form method="POST" action="{{ route('tenant.transfer-requests.cancel', $tr->id) }}"
            style="display:inline"
            onsubmit="return confirm('Cancel this transfer request?')">
        @csrf
        <button type="submit" class="ia-btn ia-btn--ghost">Cancel request</button>
      </form>
    </div>
  @endif
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--{{ session('flash')['type'] }}">{{ session('flash')['message'] }}</div>
@endif

<div class="ia-card" style="margin-bottom:20px">
  <div class="ia-card-head">
    <span class="ia-card-title">Details</span>
  </div>
  <div class="ia-card-body">
    <table class="ia-key-value">
      <tr>
        <td>Item</td>
        <td>
          @if($tr->inventoryItem)
            <a href="{{ route('tenant.inventory.show', $tr->inventoryItem->id) }}">
              <strong>{{ $tr->inventoryItem->name }}</strong>
            </a>
            <br>
            <code style="font-size:11.5px;color:var(--ia-text-muted)">{{ $tr->inventoryItem->sku }}</code>
          @else
            <span style="color:var(--ia-text-muted)">Item deleted</span>
          @endif
        </td>
      </tr>
      <tr>
        <td>Quantity</td>
        <td><strong>{{ $tr->quantity }}</strong></td>
      </tr>
      <tr>
        <td>From</td>
        <td>
          {{ $tr->fromLocation->name ?? '—' }}
          @if($tr->fromLocation && $tr->inventoryItem)
            @php
              $fromIl = $tr->inventoryItem->locations->firstWhere('location_id', $tr->fromLocation->id);
              $fromStock = $fromIl ? (int) $fromIl->computed_stock_count : 0;
            @endphp
            <span style="color:var(--ia-text-muted);font-size:12px">
              ({{ $fromStock }} on hand)
            </span>
          @endif
        </td>
      </tr>
      <tr>
        <td>To</td>
        <td>
          {{ $tr->toLocation->name ?? '—' }}
          @if($tr->toLocation && $tr->inventoryItem)
            @php
              $toIl = $tr->inventoryItem->locations->firstWhere('location_id', $tr->toLocation->id);
              $toStock = $toIl ? (int) $toIl->computed_stock_count : 0;
            @endphp
            <span style="color:var(--ia-text-muted);font-size:12px">
              ({{ $toStock }} on hand)
            </span>
          @endif
        </td>
      </tr>
      <tr>
        <td>Requested</td>
        <td>{{ $tr->created_at?->format('M j, Y g:i a') }} ({{ $tr->created_at?->diffForHumans() }})</td>
      </tr>
      @if($tr->fulfilled_at)
        <tr>
          <td>Fulfilled</td>
          <td>{{ $tr->fulfilled_at->format('M j, Y g:i a') }}</td>
        </tr>
      @endif
      @if($tr->notes)
        <tr>
          <td>Notes</td>
          <td>{{ $tr->notes }}</td>
        </tr>
      @endif
    </table>
  </div>
</div>

@push('styles')
<style>
.tr-status {
  display: inline-block; padding: 2px 8px; border-radius: 99px;
  font-size: 10.5px; font-weight: 600;
  text-transform: uppercase; letter-spacing: 0.05em;
}
.tr-status--pending   { background: rgba(239,159,39,0.12);  color: #EF9F27; }
.tr-status--fulfilled { background: rgba(99,153,34,0.12);   color: #639922; }
.tr-status--cancelled { background: rgba(113,113,122,0.12); color: #71717a; text-decoration: line-through; }
</style>
@endpush

@endsection
