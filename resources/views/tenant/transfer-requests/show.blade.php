@extends('layouts.tenant.app')
@php $pageTitle = 'Transfer request'; @endphp

@section('content')

@php
  $sessionLocId = session('current_location_id');
  $atSource = $sessionLocId && $tr->from_location_id === $sessionLocId;
  $atDest   = $sessionLocId && $tr->to_location_id === $sessionLocId;
@endphp

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Transfer request</h1>
    <p class="ia-page-subtitle">
      <a href="{{ route('tenant.transfer-requests.index') }}">← Transfer requests</a>
      &nbsp;·&nbsp;
      <span class="tr-status tr-status--{{ $tr->status }}">{{ str_replace('_', ' ', ucfirst($tr->status)) }}</span>
    </p>
  </div>

  <div class="ia-page-actions" style="display:flex;gap:8px">
    @if($tr->status === 'pending' && $atSource)
      <button type="button" class="ia-btn ia-btn--primary" onclick="document.getElementById('send-form').style.display='block';this.style.display='none'">
        Send
      </button>
      <form method="POST" action="{{ route('tenant.transfer-requests.cancel', $tr->id) }}"
            style="display:inline" onsubmit="return confirm('Cancel this transfer request?')">
        @csrf
        <button type="submit" class="ia-btn ia-btn--ghost">Cancel request</button>
      </form>
    @elseif($tr->status === 'pending' && !$atSource)
      <span style="font-size:12px;color:var(--ia-text-muted)">
        Waiting on {{ $tr->fromLocation->name ?? 'source location' }} to send.
      </span>
    @elseif($tr->status === 'in_transit' && $atDest)
      <form method="POST" action="{{ route('tenant.transfer-requests.receive', $tr->id) }}" style="display:inline">
        @csrf
        <button type="submit" class="ia-btn ia-btn--primary">Mark received</button>
      </form>
    @elseif($tr->status === 'in_transit' && !$atDest)
      <span style="font-size:12px;color:var(--ia-text-muted)">
        Waiting on {{ $tr->toLocation->name ?? 'destination' }} to receive.
      </span>
    @endif
  </div>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--{{ session('flash')['type'] }}">{{ session('flash')['message'] }}</div>
@endif

{{-- Inline send form (revealed when source clicks "Send") --}}
@if($tr->status === 'pending' && $atSource)
  @php
    $fromIl = $tr->inventoryItem?->locations?->firstWhere('location_id', $tr->from_location_id);
    $fromStock = $fromIl ? (int) $fromIl->computed_stock_count : 0;
  @endphp
  <div id="send-form" class="ia-card" style="display:none;margin-bottom:20px;border-left:4px solid var(--ia-accent)">
    <div class="ia-card-head">
      <span class="ia-card-title">Send transfer</span>
    </div>
    <form method="POST" action="{{ route('tenant.transfer-requests.send', $tr->id) }}">
      @csrf
      <div class="ia-card-body">
        <p style="font-size:13px;margin-bottom:14px">
          On hand at {{ $tr->fromLocation->name }}: <strong>{{ $fromStock }}</strong>.
          Requested: <strong>{{ $tr->quantity }}</strong>.
        </p>
        <div class="ia-form-group">
          <label class="ia-form-label">Quantity to send <span class="ia-required">*</span></label>
          <input type="number" name="quantity_sent" class="ia-input" min="1" max="{{ max(1, $fromStock) }}"
                 value="{{ min($tr->quantity, max(1, $fromStock)) }}" required>
          <div class="ia-form-hint">Can be less than requested if you only have some on hand.</div>
        </div>
      </div>
      <div class="ia-card-foot" style="display:flex;gap:8px;justify-content:flex-end;padding:12px 16px">
        <button type="button" class="ia-btn ia-btn--ghost"
                onclick="document.getElementById('send-form').style.display='none'">
          Cancel
        </button>
        <button type="submit" class="ia-btn ia-btn--primary">Send now</button>
      </div>
    </form>
  </div>
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
        <td>Quantity requested</td>
        <td><strong>{{ $tr->quantity }}</strong></td>
      </tr>
      @if($tr->quantity_sent !== null)
        <tr>
          <td>Quantity sent</td>
          <td>
            <strong>{{ $tr->quantity_sent }}</strong>
            @if($tr->quantity_sent !== $tr->quantity)
              <span style="font-size:12px;color:var(--ia-text-muted)">(partial — {{ $tr->quantity - $tr->quantity_sent }} short)</span>
            @endif
          </td>
        </tr>
      @endif
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
      @if($tr->sent_at)
        <tr>
          <td>Sent</td>
          <td>{{ $tr->sent_at->format('M j, Y g:i a') }}</td>
        </tr>
      @endif
      @if($tr->fulfilled_at)
        <tr>
          <td>Received</td>
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
.tr-status--pending    { background: rgba(239,159,39,0.12);  color: #EF9F27; }
.tr-status--in_transit { background: rgba(96,165,250,0.12);  color: #60A5FA; }
.tr-status--fulfilled  { background: rgba(99,153,34,0.12);   color: #639922; }
.tr-status--cancelled  { background: rgba(113,113,122,0.12); color: #71717a; text-decoration: line-through; }
</style>
@endpush

@endsection
