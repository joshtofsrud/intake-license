@extends('layouts.tenant.app')
@php $pageTitle = 'Orders'; @endphp

@section('content')
{{-- MARKER-PATCH-567 — online orders queue. Underline tabs per the tab rule. --}}
<style>
  .od-tabs{display:flex;gap:24px;border-bottom:0.5px solid var(--ia-border);margin:4px 0 20px}
  .od-tab{padding:0 2px 11px;margin-bottom:-0.5px;font-weight:600;font-size:13px;color:var(--ia-text-muted);border-bottom:2px solid transparent;text-decoration:none}
  .od-tab.on{color:var(--ia-text);border-bottom-color:var(--ia-accent)}
  .od-tbl{width:100%;border-collapse:collapse;font-size:13px;background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:12px;overflow:hidden}
  .od-tbl th{font-size:10.5px;letter-spacing:.08em;text-transform:uppercase;color:var(--ia-text-muted);text-align:left;padding:12px 14px;border-bottom:0.5px solid var(--ia-border);font-weight:600}
  .od-tbl td{padding:13px 14px;border-bottom:0.5px solid var(--ia-border);vertical-align:middle}
  .od-tbl tr:last-child td{border-bottom:0}
  .od-tbl tr{cursor:pointer}
  .od-tbl tr:hover td{background:rgba(255,255,255,.02)}
  .od-st{font-size:10.5px;font-weight:700;border-radius:99px;padding:3px 10px;text-transform:capitalize}
  .od-st.paid{background:rgba(219,168,79,.14);color:var(--ia-accent)}
  .od-st.fulfilling{background:rgba(143,184,216,.14);color:#8FB8D8}
  .od-st.fulfilled{background:rgba(143,209,79,.14);color:#8FD14F}
  .od-st.completed{background:rgba(255,255,255,.06);color:var(--ia-text-muted)}
  .od-st.cancelled{background:rgba(242,109,109,.12);color:#F26D6D}
  .od-ful{font-size:11px;color:var(--ia-text-muted)}
  .od-empty{text-align:center;padding:60px 20px;color:var(--ia-text-muted);background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:12px}
</style>

<div class="ia-page-head">
  <div class="ia-page-head-left"><h1 class="ia-page-title">Orders</h1></div>
</div>

<div class="od-tabs">
  <a class="od-tab {{ $tab === 'open' ? 'on' : '' }}" href="{{ route('tenant.orders.index') }}">Open ({{ $counts['open'] }})</a>
  <a class="od-tab {{ $tab === 'completed' ? 'on' : '' }}" href="{{ route('tenant.orders.index', ['tab' => 'completed']) }}">Completed ({{ $counts['completed'] }})</a>
  <a class="od-tab {{ $tab === 'all' ? 'on' : '' }}" href="{{ route('tenant.orders.index', ['tab' => 'all']) }}">All ({{ $counts['all'] }})</a>
</div>

@if(session('success'))
  <div class="ia-banner ia-banner--success" style="margin-bottom:14px">{{ session('success') }}</div>
@endif

@if($orders->isEmpty())
  <div class="od-empty">
    {{ $tab === 'open' ? 'No open orders — new online orders land here the moment they\'re paid.' : 'Nothing here yet.' }}
  </div>
@else
  <table class="od-tbl">
    <tr><th>Order</th><th>Customer</th><th>Items</th><th>Get it to them</th><th style="text-align:right">Total</th><th>Status</th><th>Placed</th></tr>
    @foreach($orders as $o)
      <tr onclick="window.location='{{ route('tenant.orders.show', $o->id) }}'">
        <td style="font-weight:700">{{ $o->order_number }}</td>
        <td>{{ $o->contactName() ?: '—' }}</td>
        <td>{{ (int) $o->items->sum('quantity') }} item{{ $o->items->sum('quantity') == 1 ? '' : 's' }}</td>
        <td><span class="od-ful">{{ $o->fulfillment_type === 'local_delivery' ? '🚚 Delivery' : '🏪 Pickup' }}{{ $o->wants_install ? ' · install' : '' }}</span></td>
        <td style="text-align:right;font-variant-numeric:tabular-nums">${{ number_format($o->total_cents / 100, 2) }}</td>
        <td><span class="od-st {{ $o->status }}">{{ str_replace('_', ' ', $o->status) }}</span></td>
        <td style="color:var(--ia-text-muted)">{{ $o->paid_at ? tlocal($o->paid_at, 'M j, g:i a') : tlocal($o->created_at, 'M j, g:i a') }}</td>
      </tr>
    @endforeach
  </table>
  <div style="margin-top:16px">{{ $orders->links() }}</div>
@endif
@endsection
