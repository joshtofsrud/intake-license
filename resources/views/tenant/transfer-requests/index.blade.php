@extends('layouts.tenant.app')
@php $pageTitle = 'Transfer Requests'; @endphp

@section('content')

{{-- patch-102 location-scoped tabs --}}
<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Transfer requests</h1>
    <p class="ia-page-subtitle">
      @if($counts['to_send'] > 0)
        {{ $counts['to_send'] }} to send from this location
      @endif
      @if($counts['to_send'] > 0 && $counts['to_receive'] > 0) · @endif
      @if($counts['to_receive'] > 0)
        {{ $counts['to_receive'] }} arriving here
      @endif
      @if($counts['to_send'] === 0 && $counts['to_receive'] === 0)
        Nothing pending at this location
      @endif
    </p>
  </div>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--{{ session('flash')['type'] }}">{{ session('flash')['message'] }}</div>
@endif

<div class="tr-tabs">
  @php
    $tabs = [
      'to_send'     => ['label' => 'To send',    'count' => $counts['to_send']],
      'to_receive'  => ['label' => 'Arriving',   'count' => $counts['to_receive']],
      'all_pending' => ['label' => 'All pending','count' => $counts['pending']],
      'in_transit'  => ['label' => 'In transit', 'count' => $counts['in_transit']],
      'fulfilled'   => ['label' => 'Completed',  'count' => $counts['fulfilled']],
      'cancelled'   => ['label' => 'Cancelled',  'count' => $counts['cancelled']],
    ];
  @endphp
  @foreach($tabs as $key => $tab)
    <a href="{{ route('tenant.transfer-requests.index', ['view' => $key]) }}"
       class="tr-tab {{ $view === $key ? 'active' : '' }}">
      {{ $tab['label'] }}
      @if($tab['count'] !== null)
        <span class="tr-tab-count">{{ $tab['count'] }}</span>
      @endif
    </a>
  @endforeach
</div>

@if($requests->isEmpty())
  <div class="ia-card">
    <div class="ia-card-body" style="text-align:center;padding:48px 24px;color:var(--ia-text-muted)">
      @switch($view)
        @case('to_send') Nothing to send from this location. @break
        @case('to_receive') Nothing arriving here right now. @break
        @case('fulfilled') No completed transfers yet. @break
        @case('cancelled') No cancelled transfers. @break
        @default No transfer requests in this view.
      @endswitch
    </div>
  </div>
@else
  <div class="ia-card">
    <table class="ia-table">
      <thead>
        <tr>
          <th style="width:4px;padding:0"></th>
          <th>Item</th>
          <th>Qty</th>
          <th>From</th>
          <th>To</th>
          <th>Status</th>
          <th>Requested</th>
        </tr>
      </thead>
      <tbody>
        @foreach($requests as $tr)
          @php
            $statusColor = match($tr->status) {
              'pending'    => '#EF9F27',
              'in_transit' => '#60A5FA',
              'fulfilled'  => '#639922',
              'cancelled'  => '#71717a',
              default      => 'transparent',
            };
          @endphp
          <tr style="cursor:pointer" onclick="window.location='{{ route('tenant.transfer-requests.show', $tr->id) }}'">
            <td style="width:4px;padding:0;background:{{ $statusColor }}"></td>
            <td>
              <div style="font-weight:500">{{ $tr->inventoryItem->name ?? '—' }}</div>
              @if($tr->inventoryItem)
                <code style="font-size:11.5px;color:var(--ia-text-muted)">{{ $tr->inventoryItem->sku }}</code>
              @endif
            </td>
            <td>
              {{ $tr->quantity_sent ?? $tr->quantity }}
              @if($tr->quantity_sent !== null && $tr->quantity_sent !== $tr->quantity)
                <span style="font-size:11px;color:var(--ia-text-muted)">of {{ $tr->quantity }}</span>
              @endif
            </td>
            <td>{{ $tr->fromLocation->name ?? '—' }}</td>
            <td>{{ $tr->toLocation->name ?? '—' }}</td>
            <td>
              <span class="tr-status tr-status--{{ $tr->status }}">{{ str_replace('_', ' ', ucfirst($tr->status)) }}</span>
            </td>
            <td style="color:var(--ia-text-muted);font-size:12px">
              {{ $tr->created_at?->diffForHumans() }}
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
@endif

@push('styles')
<style>
.tr-tabs {
  display: flex; gap: 4px; margin-bottom: 16px;
  border-bottom: 0.5px solid var(--ia-border);
  flex-wrap: wrap;
}
.tr-tab {
  padding: 10px 16px; font-size: 13px; font-weight: 500;
  color: var(--ia-text-muted); text-decoration: none;
  border-bottom: 2px solid transparent;
  transition: color 120ms ease, border-color 120ms ease;
}
.tr-tab:hover { color: var(--ia-text); }
.tr-tab.active { color: var(--ia-text); border-bottom-color: var(--ia-accent); }
.tr-tab-count {
  display: inline-block; margin-left: 4px; padding: 1px 7px;
  background: var(--ia-hover); border-radius: 99px; font-size: 11px;
}
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
