@extends('layouts.tenant.app')
@php
  $pageTitle = $order->order_number;
  $money = fn ($c) => '$' . number_format(($c ?? 0) / 100, 2);
  $advance = match ($order->status) {
      'paid'       => ['fulfilling', 'Start fulfilling'],
      'fulfilling' => ['fulfilled',  $order->fulfillment_type === 'pickup' ? 'Mark ready for pickup' : 'Mark ready'],
      'fulfilled'  => ['completed',  $order->fulfillment_type === 'pickup' ? 'Picked up — complete' : 'Delivered — complete'],
      default      => null,
  };
@endphp

@section('content')
{{-- MARKER-PATCH-567 — order detail: items, contact, fulfillment, linked
     sale, and the one-button status advance. --}}
<style>
  .od-grid{display:grid;grid-template-columns:1fr 340px;gap:18px;align-items:start}
  @media(max-width:900px){.od-grid{grid-template-columns:1fr}}
  .od-card{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:12px;padding:16px 18px;margin-bottom:14px}
  .od-card h3{font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;color:var(--ia-text-muted);margin-bottom:11px;font-weight:600}
  .od-line{display:flex;gap:12px;padding:10px 0;border-bottom:0.5px solid var(--ia-border);font-size:13px;align-items:center}
  .od-line:last-child{border-bottom:0}
  .od-line img{width:40px;height:40px;object-fit:contain;background:#fff;border-radius:8px}
  .od-line .p{margin-left:auto;font-weight:650;font-variant-numeric:tabular-nums}
  .od-kv{display:flex;gap:14px;padding:6px 0;font-size:13px}
  .od-kv .k{flex:none;width:92px;color:var(--ia-text-muted)}
  .od-tot{display:flex;justify-content:space-between;padding:7px 0;font-size:13px}
  .od-tot.g{font-weight:800;font-size:15px;border-top:0.5px solid var(--ia-border);margin-top:6px;padding-top:11px}
  .od-st{font-size:11px;font-weight:700;border-radius:99px;padding:4px 12px;text-transform:capitalize;display:inline-block}
  .od-st.paid{background:rgba(219,168,79,.14);color:var(--ia-accent)}
  .od-st.fulfilling{background:rgba(143,184,216,.14);color:#8FB8D8}
  .od-st.fulfilled{background:rgba(143,209,79,.14);color:#8FD14F}
  .od-st.completed{background:rgba(255,255,255,.06);color:var(--ia-text-muted)}
  .od-st.cancelled{background:rgba(242,109,109,.12);color:#F26D6D}
  .od-chk{display:flex;gap:8px;align-items:center;font-size:12.5px;color:var(--ia-text-muted);padding:8px 0 2px;cursor:pointer}
  .od-chk input{accent-color:var(--ia-accent)}
</style>

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <a href="{{ route('tenant.orders.index') }}" style="font-size:12.5px;color:var(--ia-text-muted);text-decoration:none">← Orders</a>
    <h1 class="ia-page-title" style="margin-top:4px">{{ $order->order_number }}
      <span class="od-st {{ $order->status }}" style="vertical-align:3px;margin-left:8px">{{ str_replace('_', ' ', $order->status) }}</span>
    </h1>
  </div>
</div>

@if(session('success'))
  <div class="ia-banner ia-banner--success" style="margin-bottom:14px">{{ session('success') }}</div>
@endif

<div class="od-grid">
  <div>
    <div class="od-card">
      <h3>Items</h3>
      @foreach($order->items as $l)
        <div class="od-line">
          @if($l->image_snapshot)<img src="{{ $l->image_snapshot }}" alt="">@endif
          <div>
            <div style="font-weight:600">{{ $l->name_snapshot }}</div>
            <div style="font-size:11.5px;color:var(--ia-text-muted)">×{{ (int) $l->quantity }} @ {{ $money($l->unit_price_cents) }}</div>
          </div>
          <span class="p">{{ $money($l->line_total_cents) }}</span>
        </div>
      @endforeach
      <div class="od-tot"><span>Subtotal</span><span>{{ $money($order->subtotal_cents) }}</span></div>
      <div class="od-tot"><span>Tax</span><span>{{ $money($order->tax_cents) }}</span></div>
      @if($order->shipping_cents > 0)<div class="od-tot"><span>Delivery fee</span><span>{{ $money($order->shipping_cents) }}</span></div>@endif
      <div class="od-tot g"><span>Total</span><span>{{ $money($order->total_cents) }}</span></div>
    </div>

    <div class="od-card">
      <h3>Fulfillment</h3>
      <div class="od-kv"><span class="k">Method</span><span>{{ $order->fulfillment_type === 'local_delivery' ? 'Local delivery' : 'Pickup in store' }}</span></div>
      @if($order->fulfillment_address)
        <div class="od-kv"><span class="k">Address</span><span>{{ $order->fulfillment_address['line'] ?? '' }}</span></div>
      @endif
      @if($order->fulfillment_notes)
        <div class="od-kv"><span class="k">Notes</span><span>{{ $order->fulfillment_notes }}</span></div>
      @endif
      @if($order->wants_install)
        <div class="od-kv"><span class="k">Install</span><span style="color:var(--ia-accent);font-weight:600">Customer requested installation — reach out to schedule</span></div>
      @endif
    </div>
  </div>

  <div>
    @if($advance || in_array($order->status, ['paid', 'fulfilling']))
      <div class="od-card">
        <h3>Move it along</h3>
        @if($advance)
          <form method="POST" action="{{ route('tenant.orders.update', $order->id) }}">
            @csrf
            <input type="hidden" name="op" value="advance">
            <button class="ia-btn ia-btn--primary" style="width:100%">{{ $advance[1] }}</button>
            @if($advance[0] === 'fulfilled' && filled($order->contact_phone))
              <label class="od-chk"><input type="checkbox" name="notify_text" value="1" checked>
                Text {{ $order->contact_first_name }} that it's ready</label>
            @endif
          </form>
        @endif
        @if(in_array($order->status, ['paid', 'fulfilling']))
          <form method="POST" action="{{ route('tenant.orders.update', $order->id) }}" style="margin-top:8px"
                onsubmit="return confirm('Cancel this order on the board? Money stays put — refund from the linked sale if needed.')">
            @csrf
            <input type="hidden" name="op" value="cancel">
            <button class="ia-btn ia-btn--ghost" style="width:100%">Cancel order</button>
          </form>
        @endif
      </div>
    @endif

    <div class="od-card">
      <h3>Customer</h3>
      <div class="od-kv"><span class="k">Name</span>
        <span>@if($order->customer)<a href="{{ route('tenant.customers.show', $order->customer_id) }}" style="color:var(--ia-accent);text-decoration:none">{{ $order->contactName() }}</a>@else{{ $order->contactName() }}@endif</span>
      </div>
      <div class="od-kv"><span class="k">Email</span><span>{{ $order->contact_email }}</span></div>
      @if($order->contact_phone)<div class="od-kv"><span class="k">Phone</span><span>{{ $order->contact_phone }}</span></div>@endif
    </div>

    <div class="od-card">
      <h3>Payment</h3>
      <div class="od-kv"><span class="k">Paid</span><span>{{ $order->paid_at ? tlocal($order->paid_at, 'M j, g:i a') : '—' }}</span></div>
      @if($order->card_last4)<div class="od-kv"><span class="k">Card</span><span>{{ ucfirst($order->card_brand) }} ····{{ $order->card_last4 }}</span></div>@endif
      @if($order->sale)
        <div class="od-kv"><span class="k">Sale</span>
          <span><a href="{{ route('tenant.register.sales.page', $order->sale_id) }}" style="color:var(--ia-accent);text-decoration:none">{{ $order->sale->sale_number }}</a>
          <span style="color:var(--ia-text-muted);font-size:11.5px"> — refunds happen there</span></span>
        </div>
      @endif
    </div>
  </div>
</div>
@endsection
