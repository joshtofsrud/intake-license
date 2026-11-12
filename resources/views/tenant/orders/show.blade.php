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
{{-- order detail: items, contact, fulfillment, linked
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
      {{-- MARKER-DESTINATION-TAX: where this order's tax came from --}}
      @php $ti = (array) (((array) ($order->metadata ?? []))['tax'] ?? []); @endphp
      @if(($ti['source'] ?? 'rate') === 'stripe')
        <div style="font-size:11.5px;color:var(--ia-text-muted);margin:-2px 0 6px">Tax figured for the shipping address by Stripe Tax{{ ! empty($ti['jurisdictions']) ? ': ' . implode(', ', $ti['jurisdictions']) : '' }}.@if(! empty($ti['record_failed'])) <b style="color:#A32D2D">It could not be recorded in Stripe Tax for filing — check your Stripe dashboard.</b>@endif</div>
      @elseif(($ti['source'] ?? 'rate') === 'fallback')
        <div style="font-size:11.5px;color:#A32D2D;margin:-2px 0 6px">Stripe Tax could not be reached, so this order was taxed at your shop rate, not the destination's. Check what is owed for where it shipped.</div>
      @endif
      @if($order->shipping_cents > 0)<div class="od-tot"><span>{{ $order->fulfillment_type === 'ship' ? 'Shipping' : 'Delivery fee' }}</span><span>{{ $money($order->shipping_cents) }}</span></div>@endif
      <div class="od-tot g"><span>Total</span><span>{{ $money($order->total_cents) }}</span></div>
    </div>

    {{-- MARKER-ORDER-SHIPMENTS: one box per source, each priced on its own --}}
    @php $ships = $order->fulfillment_type === 'ship' ? $order->shipments()->with('items.orderItem')->get() : collect(); @endphp
    @if($ships->isNotEmpty())
    <div class="od-card">
      <h3>Shipments</h3>
      @foreach($ships as $i => $sh)
        <div style="padding:10px 0;{{ $loop->last ? '' : 'border-bottom:.5px solid var(--ia-border);' }}">
          <div style="display:flex;justify-content:space-between;gap:10px;font-weight:600">
            <span>Shipment {{ $i + 1 }} · {{ $sh->source === 'supplier' ? 'From supplier: ' . ($sh->warehouse_name ?: 'warehouse') : 'From your shop' }}</span>
            <span>{{ $sh->shipping_cents > 0 ? $money($sh->shipping_cents) : 'Free' }}</span>
          </div>
          @if($sh->service_name)
            @php $pk = (array) ($sh->packages ?? []); @endphp
            <div style="font-size:12.5px;margin-top:3px">{{ $sh->service_name }}{{ $sh->rate_source === 'fallback' ? ' (your flat price; live prices were unavailable)' : '' }}@if($pk) · {{ count($pk) }} {{ count($pk) === 1 ? 'package' : 'packages' }}: {{ collect($pk)->map(fn ($p) => ($p['box'] ?? 'package') . ' ' . number_format(($p['oz'] ?? 0) / 16, 1) . ' lb' . (! empty($p['l']) ? ' (' . $p['l'] . '×' . $p['w'] . '×' . $p['h'] . ' in)' : ''))->implode(', ') }}@endif</div>
          @endif
          @foreach($sh->items as $it)
            <div style="font-size:13px;color:var(--ia-text-muted);margin-top:2px">{{ $it->orderItem?->name_snapshot }} ×{{ $it->quantity }}{{ $it->special_quantity > 0 ? ' (' . $it->special_quantity . ' special order)' : '' }}</div>
          @endforeach
          @if($sh->source === 'supplier')
            <div style="font-size:12px;color:var(--ia-text-muted);margin-top:6px">Order these from {{ $sh->warehouse_name ?: 'the supplier' }} and have them ship straight to the customer's address above. Your shelf was not touched.</div>
          @endif
          {{-- MARKER-SHIPMENT-WORKFLOW --}}
          @php
            $st = $sh->status ?: 'pending';
            $acts = \App\Services\Tenant\ShipmentFlow::actions($st);
            $turl = \App\Services\Tenant\ShipmentFlow::trackingUrl($sh->carrier, $sh->tracking_number);
            $canMail = filled($order->contact_email);
            $action = route('tenant.orders.shipment', [$order->id, $sh->id]);
          @endphp
          <div style="margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <span class="od-st {{ ['shipped'=>'fulfilling','out_for_delivery'=>'fulfilling','delivered'=>'fulfilled','cancelled'=>'cancelled'][$st] ?? 'paid' }}">{{ \App\Services\Tenant\ShipmentFlow::LABELS[$st] ?? $st }}</span>
            @if($sh->tracking_number)
              <span style="font-size:12.5px">{{ \App\Services\Tenant\ShipmentFlow::carriers()[$sh->carrier] ?? '' }}
                @if($turl)<a href="{{ $turl }}" target="_blank" rel="noopener">{{ $sh->tracking_number }}</a>@else{{ $sh->tracking_number }}@endif</span>
            @endif
            @if($sh->cost_cents !== null)<span style="font-size:12px;color:var(--ia-text-muted)">cost you ${{ number_format($sh->cost_cents / 100, 2) }}</span>@endif
            @if($sh->shipped_at)<span style="font-size:12px;color:var(--ia-text-muted)">shipped {{ $sh->shipped_at->format('M j') }}</span>@endif
            @if($sh->delivered_at)<span style="font-size:12px;color:var(--ia-text-muted)">delivered {{ $sh->delivered_at->format('M j') }}</span>@endif
          </div>
          @if($sh->note)<div style="font-size:12px;color:var(--ia-text-muted);margin-top:4px">Note: {{ $sh->note }}</div>@endif

          @if(in_array('ship', $acts))
          <form method="POST" action="{{ $action }}" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            @csrf <input type="hidden" name="op" value="ship">
            <select name="carrier" class="ia-input" style="width:auto">
              @foreach(\App\Services\Tenant\ShipmentFlow::carriers() as $k => $lbl)
                <option value="{{ $k }}" @selected(($sh->carrier ?: str_before((string) $sh->service_code, '_')) === $k)>{{ $lbl }}</option>
              @endforeach
            </select>
            <input type="text" name="tracking_number" class="ia-input" style="flex:1;min-width:150px" placeholder="Tracking number" value="{{ $sh->tracking_number }}">
            <input type="number" name="shipping_cost" class="ia-input" style="width:130px" step="0.01" min="0" placeholder="What it cost you" title="What this shipment cost you ($). Used in the Online report. Leave blank if you don't know yet." value="{{ $sh->cost_cents !== null ? number_format($sh->cost_cents / 100, 2, '.', '') : '' }}">
            @if($canMail)<label class="od-chk" style="padding:0"><input type="checkbox" name="notify" value="1" checked> Email the customer</label>@endif
            <button class="ia-btn ia-btn--primary ia-btn--sm">{{ $st === 'shipped' ? 'Save tracking' : 'Mark shipped' }}</button>
          </form>
          @endif

          @if(in_array('out_for_delivery', $acts) || in_array('delivered', $acts))
          <form method="POST" action="{{ $action }}" style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            @csrf
            @if($canMail && in_array('out_for_delivery', $acts))<label class="od-chk" style="padding:0"><input type="checkbox" name="notify" value="1" checked> Email the customer</label>@endif
            @if(in_array('out_for_delivery', $acts))<button name="op" value="out_for_delivery" class="ia-btn ia-btn--ghost ia-btn--sm">Out for delivery</button>@endif
            <button name="op" value="delivered" class="ia-btn ia-btn--ghost ia-btn--sm">Delivered</button>
          </form>
          @endif

          @if(in_array('special_order', $acts) || in_array('cancel', $acts))
          <form method="POST" action="{{ $action }}" style="margin-top:10px;border-top:.5px dashed var(--ia-border);padding-top:10px">
            @csrf
            <div style="font-size:12px;color:var(--ia-text-muted);margin-bottom:6px">{{ $sh->source === 'supplier' ? 'Supplier can\'t send this?' : 'Can\'t send this?' }} Add a note for the customer, then choose what happens.</div>
            <input type="text" name="note" class="ia-input" style="width:100%" maxlength="300" placeholder="Note to the customer (optional)">
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px">
              @if($canMail)<label class="od-chk" style="padding:0"><input type="checkbox" name="notify" value="1" checked> Email the customer</label>@endif
              @if(in_array('special_order', $acts))<button name="op" value="special_order" class="ia-btn ia-btn--ghost ia-btn--sm">Make it a special order</button>@endif
              @if(in_array('cancel', $acts))<button name="op" value="cancel" class="ia-btn ia-btn--ghost ia-btn--sm">Cancel this shipment</button>@endif
            </div>
          </form>
          @endif

          @if(in_array($st, ['shipped','out_for_delivery','special_order','cancelled']) && $canMail)
          <form method="POST" action="{{ $action }}" style="margin-top:6px">
            @csrf <input type="hidden" name="op" value="resend">
            <button class="ia-btn ia-btn--ghost ia-btn--sm">Send the email again</button>
          </form>
          @endif
        </div>
      @endforeach
      <div style="font-size:11.5px;color:var(--ia-text-muted);margin-top:10px;line-height:1.5">
        <b>To ship</b> → <b>Shipped</b> → <b>Out for delivery</b> → <b>Delivered</b>. The order moves on its own as its shipments do.
        <b>Special order</b> adds the items to your special orders list and tells the customer. <b>Cancel</b> tells the customer a refund is coming; ring the refund up from the linked sale on the register.
      </div>
    </div>
    @endif

    <div class="od-card">
      <h3>Fulfillment</h3>
      <div class="od-kv"><span class="k">Method</span><span>{{ $order->fulfillment_type === 'ship' ? 'Shipping' : ($order->fulfillment_type === 'local_delivery' ? 'Local delivery' : 'Pickup in store') }}</span></div>
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
    @if($order->fulfillment_type === 'local_delivery' && in_array($order->status, ['paid','fulfilling','fulfilled']))
      <div class="od-card">
        <h3>Local delivery</h3>
        <form method="POST" action="{{ route('tenant.orders.update', $order->id) }}">
          @csrf <input type="hidden" name="op" value="out_for_delivery">
          <button class="ia-btn ia-btn--ghost" style="width:100%" @disabled(! filled($order->contact_email))>Tell the customer it's out for delivery</button>
          <div style="font-size:11.5px;color:var(--ia-text-muted);margin-top:6px">{{ filled($order->contact_email) ? 'Sends the "out for delivery" email.' : 'No email on this order.' }}</div>
        </form>
      </div>
    @endif

    @if($advance || in_array($order->status, ['paid', 'fulfilling']))
      <div class="od-card">
        <h3>Move it along</h3>
        @if($advance)
          @if($order->status === \App\Models\Tenant\TenantOrder::STATUS_PENDING_PAYMENT && $order->payment_method)
          {{-- manual payment confirmation --}}
          <div style="border:1px solid rgba(245,158,11,.4);background:rgba(245,158,11,.08);border-radius:10px;padding:12px 14px;margin-bottom:12px;font-size:12.5px">
            Awaiting <b>{{ tender_label($order->payment_method) }}</b> — {{ number_format($order->total_cents / 100, 2) }} expected with note "{{ $order->order_number }}".
          </div>
          <form method="POST" action="{{ route('tenant.orders.update', $order->id) }}" style="margin-bottom:10px"
                onsubmit="return confirm('Confirm the {{ tender_label($order->payment_method) }} payment arrived? This builds the sale and marks the order paid.')">
            @csrf
            <input type="hidden" name="op" value="mark_paid">
            <button class="ia-btn ia-btn--primary" style="width:100%">Payment received — mark paid</button>
          </form>
        @endif
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

