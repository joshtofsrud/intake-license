@extends('layouts.tenant.app')
@php
  $pageTitle = $order->order_number;
  $money = fn ($c) => '$' . number_format(($c ?? 0) / 100, 2);
  // MARKER-ORDER-STEPS: steps, labels and the next button follow how the order leaves
  $steps   = \App\Support\OrderSteps::steps($order);
  $stepNow = \App\Support\OrderSteps::next($order);
  $reached = \App\Support\OrderSteps::reached($order);
  $history = \App\Support\OrderSteps::history($order);
  $waited  = \App\Support\OrderSteps::waitingDays($order);
  $remDays = \App\Support\OrderSteps::reminderDays(tenant());

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
  /* MARKER-ORDER-STEPS */
  .os{display:flex;align-items:center;margin:4px 0 18px;flex-wrap:wrap;row-gap:8px}
  .os .s{display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--ia-text-dim);white-space:nowrap}
  .os .d{width:20px;height:20px;border-radius:50%;border:1.5px solid var(--ia-border-strong);display:grid;place-content:center;font-size:11px;flex:none}
  .os .ln{flex:1;height:1.5px;background:var(--ia-border-strong);margin:0 10px;min-width:24px}
  .os .s.done{color:var(--ia-text-muted)} .os .s.done .d{background:var(--ia-accent);border-color:var(--ia-accent);color:#111}
  .os .s.now{color:var(--ia-text);font-weight:600} .os .s.now .d{border-color:var(--ia-accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--ia-accent) 22%,transparent)}
  .os .ln.done{background:var(--ia-accent)}
  .os .when{font-size:11px;color:var(--ia-text-dim);font-weight:400;margin-left:2px}
  .od-nx{font-size:12.5px;color:var(--ia-text-muted);margin:8px 0 0;line-height:1.5}
  .od-prog{height:6px;border-radius:99px;background:var(--ia-hover);overflow:hidden;margin:8px 0 6px}
  .od-prog i{display:block;height:100%;background:var(--ia-accent);border-radius:99px}
  .od-flag{font-size:12px;border:1px solid rgba(239,159,39,.4);background:rgba(239,159,39,.08);color:#EF9F27;border-radius:10px;padding:9px 12px;margin-bottom:10px;line-height:1.5}
  .od-hist{font-size:12px;color:var(--ia-text-muted);line-height:1.9}
  .od-hist b{color:var(--ia-text);font-weight:500}
  .od-scrim{position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;z-index:200}
  .od-scrim.on{display:flex}
  .od-dlg{width:min(540px,92vw);max-height:90vh;overflow:auto;background:var(--ia-surface);border:.5px solid var(--ia-border-strong);border-radius:14px;padding:20px 22px}
  .od-dlg h4{font-size:16px;font-weight:600;margin:0 0 4px}
  .od-dlg .sub{font-size:12.5px;color:var(--ia-text-muted);margin-bottom:12px;line-height:1.5}
  .od-rl{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:.5px solid var(--ia-border);font-size:13px}
  .od-rl input[type=checkbox]{accent-color:var(--ia-accent)} .od-rl .p{margin-left:auto;font-variant-numeric:tabular-nums}
  .od-rl input[type=number]{width:58px}
  .od-rl.gone{opacity:.45}
  .od-rf{display:flex;justify-content:space-between;font-size:14px;font-weight:700;border-top:.5px solid var(--ia-border);margin-top:8px;padding-top:10px}
  .od-dlg .acts{display:flex;gap:8px;justify-content:flex-end;margin-top:14px}
  /* MARKER-ORDER-PRINT */
  .od-print{position:relative}
  .od-print summary{list-style:none;cursor:pointer}
  .od-print summary::-webkit-details-marker{display:none}
  .od-print-menu{position:absolute;right:0;top:calc(100% + 6px);width:250px;background:var(--ia-surface);border:.5px solid var(--ia-border-strong);border-radius:12px;padding:6px;z-index:30;box-shadow:0 10px 30px rgba(0,0,0,.35)}
  .od-print-menu a{display:block;padding:8px 10px;border-radius:8px;color:var(--ia-text);text-decoration:none;font-size:13px}
  .od-print-menu a:hover{background:var(--ia-hover)}
  .od-print-menu small{display:block;font-size:11.5px;color:var(--ia-text-muted)}
</style>

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <a href="{{ route('tenant.orders.index') }}" style="font-size:12.5px;color:var(--ia-text-muted);text-decoration:none">← Orders</a>
    <h1 class="ia-page-title" style="margin-top:4px">{{ $order->order_number }}
      <span class="od-st {{ $order->status }}" style="vertical-align:3px;margin-left:8px">{{ \App\Support\OrderSteps::label($order) }}</span>
    </h1>
  </div>
  {{-- MARKER-ORDER-PRINT --}}
  <div class="ia-page-actions">
    <details class="od-print">
      <summary class="ia-btn ia-btn--secondary ia-btn--sm">Print ▾</summary>
      <div class="od-print-menu">
        <a href="{{ route('tenant.orders.print', [$order->id, 'pick-list']) }}" target="_blank">Pick list<small>What to pull, with bins</small></a>
        @if($order->fulfillment_type !== 'ship')<a href="{{ route('tenant.orders.print', [$order->id, 'tag']) }}" target="_blank">Bag tag<small>Name and order number for the counter</small></a>@endif
        @if($order->sale_id)<a href="{{ route('tenant.register.sales.receipt', $order->sale_id) }}" target="_blank">Receipt<small>The sale, on your receipt printer</small></a>@endif
        @if($order->fulfillment_type === 'ship')
          <a href="{{ route('tenant.orders.print', [$order->id, 'packing-slip']) }}" target="_blank">Packing slips<small>A full page for each box</small></a>
        @else
          <a href="{{ route('tenant.orders.print', [$order->id, 'packing-slip']) }}" target="_blank">Full-page receipt<small>Letter size, with your logo</small></a>
        @endif
      </div>
    </details>
  </div>
</div>

{{-- MARKER-ORDER-STEPS: where the order is, for how it leaves --}}
@if(array_key_exists($order->status, $steps))
  @php $keys = array_keys($steps); $at = array_search($order->status, $keys, true); @endphp
  <div class="os">
    @foreach($steps as $st => $lbl)
      @php $i = $loop->index; $cls = $i < $at || ($i === $at && $st === 'completed') ? 'done' : ($i === $at ? 'now' : ''); @endphp
      <span class="s {{ $cls }}"><span class="d">{{ $cls === 'done' ? '✓' : '' }}</span>{{ $lbl }}@if(! empty($reached[$st]))<span class="when">{{ tlocal(\Illuminate\Support\Carbon::parse($reached[$st]), 'M j, g:ia') }}</span>@endif</span>
      @unless($loop->last)<span class="ln {{ $i < $at ? 'done' : '' }}"></span>@endunless
    @endforeach
  </div>
@endif

@if(session('success'))
  <div class="ia-flash ia-flash--success" style="margin-bottom:14px">{{ session('success') }}</div>
@endif
@if(session('error'))
  <div class="ia-flash ia-flash--error" style="margin-bottom:14px">{{ session('error') }}</div>
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
            @if($st !== 'cancelled')<a href="{{ route('tenant.orders.print', [$order->id, 'packing-slip']) }}?shipment={{ $sh->id }}" target="_blank" style="font-size:12px;margin-left:auto">Print packing slip</a>@endif
          </div>
          @if($sh->note)<div style="font-size:12px;color:var(--ia-text-muted);margin-top:4px">Note: {{ $sh->note }}</div>@endif

          @if(in_array('ship', $acts))
          <form method="POST" action="{{ $action }}" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            @csrf <input type="hidden" name="op" value="ship">
            <select name="carrier" class="ia-input" style="width:auto">
              @foreach(\App\Services\Tenant\ShipmentFlow::carriers() as $k => $lbl)
                <option value="{{ $k }}" @selected(($sh->carrier ?: \Illuminate\Support\Str::before((string) $sh->service_code, '_')) === $k)>{{ $lbl }}</option>
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
        <b>Special order</b> adds the items to your special orders list and tells the customer. <b>Cancel</b> tells the customer a refund is coming; then refund its lines with <b>Cancel and refund</b> on this order.
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
      {{-- MARKER-ORDER-STEPS: who moved it along, and when --}}
      @if($history)
        <div class="od-kv"><span class="k">History</span><span class="od-hist">
          @foreach($history as $h)
            <b>{{ $h['what'] }}</b> · {{ tlocal(\Illuminate\Support\Carbon::parse($h['at']), 'M j, g:ia') }}@if(! empty($h['by'])) · {{ $h['by'] }}@endif @if(! empty($h['note'])) · {{ $h['note'] }}@endif<br>
          @endforeach
        </span></div>
      @endif
    </div>
  </div>

  <div>

    {{-- MARKER-ORDER-MANUAL-PAY: an order waiting on cash, Venmo etc. gets its buttons too.
         The mark-paid form used to sit inside @if($advance), which is always null while an
         order is pending, so a manual-payment order had no way forward. --}}
    @php $awaitingManual = $order->status === \App\Models\Tenant\TenantOrder::STATUS_PENDING_PAYMENT && $order->payment_method && ! $order->sale_id; @endphp
    @php
      $canRefund = $order->sale_id && in_array($order->status, ['paid', 'fulfilling', 'fulfilled', 'completed'], true);
      $refundPlan = $canRefund ? \App\Support\OrderRefund::plan($order, $order->sale) : null;
      $refundLeft = $refundPlan ? collect($refundPlan['lines'])->sum(fn ($l) => $l['left'] > 0 ? 1 : 0) : 0;
      $shipsAll   = $order->fulfillment_type === 'ship' ? $order->shipments()->get(['status']) : collect();
      $shipsLive  = $shipsAll->where('status', '!=', 'cancelled');
      $shipsSent  = $shipsLive->whereIn('status', ['shipped', 'out_for_delivery', 'delivered'])->count();
    @endphp
    @if($awaitingManual || $stepNow || ($order->fulfillment_type === 'ship' && $shipsLive->isNotEmpty() && in_array($order->status, ['paid', 'fulfilling', 'fulfilled'], true)) || ($canRefund && $refundLeft))
      <div class="od-card">
        <h3>{{ $awaitingManual ? 'Payment' : ($order->fulfillment_type === 'ship' ? 'Where it stands' : 'Next step') }}</h3>
        @if($awaitingManual)
          <div style="border:1px solid rgba(245,158,11,.4);background:rgba(245,158,11,.08);border-radius:10px;padding:12px 14px;margin-bottom:12px;font-size:12.5px">
            Awaiting <b>{{ tender_label($order->payment_method) }}</b> — {{ number_format($order->total_cents / 100, 2) }} expected with note "{{ $order->order_number }}".
            {{-- MARKER-PAY-LATER: the hold, and anything that sold in the meantime --}}
            @php
              $plMeta = (array) ($order->metadata ?? []);
              $plGone = \App\Services\Tenant\OrderStock::forTenant(tenant())->inspect($order, \App\Services\Tenant\OrderStock::MODE_PICKUP)['blocked'] ?? [];
            @endphp
            <div style="margin-top:6px;opacity:.85">
              @if(! empty($plMeta['held']) && ! empty($plMeta['held_until']))
                Items held until {{ tlocal(\Illuminate\Support\Carbon::parse($plMeta['held_until']), 'D M j, g:ia') }}.
              @else
                Items are not held for this order.
              @endif
            </div>
            @if($plGone)
              <div style="margin-top:6px;color:#A32D2D">Sold since this order was placed: {{ collect($plGone)->pluck('name')->implode(', ') }}. Let the customer know before they pay — marking it paid turns the missing units into a special order.</div>
            @endif
          </div>
          <form method="POST" action="{{ route('tenant.orders.update', $order->id) }}" style="margin-bottom:10px" id="odMarkPaid">
            @csrf
            <input type="hidden" name="op" value="mark_paid">
            <button type="button" class="ia-btn ia-btn--primary" style="width:100%"
              onclick="IntakeConfirm.show({ title: 'Payment received?', message: {{ \Illuminate\Support\Js::from('Confirm the ' . tender_label($order->payment_method) . ' payment of ' . $money($order->total_cents) . ' arrived. This builds the sale and marks the order paid.') }}, confirmText: 'Mark paid' }).then(function (ok) { if (ok) document.getElementById('odMarkPaid').submit(); })">Payment received — mark paid</button>
          </form>
        @endif
        {{-- MARKER-ORDER-STEPS: the next step, for how it leaves --}}
        @if($stepNow)
          @if($waited !== null && $remDays > 0 && $waited >= $remDays)
            <div class="od-flag">Ready for {{ $waited }} days and not collected.@if(! empty(((array) $order->metadata)['reminded_at'])) A reminder went to {{ $order->contact_first_name ?: 'the customer' }} {{ \Illuminate\Support\Carbon::parse(((array) $order->metadata)['reminded_at'])->diffForHumans() }}.@endif</div>
          @elseif($waited !== null)
            <div class="od-nx" style="margin:0 0 10px">Ready for {{ $waited }} {{ $waited === 1 ? 'day' : 'days' }}.@if($remDays > 0) A reminder goes out at {{ $remDays }}.@endif</div>
          @endif
          <form method="POST" action="{{ route('tenant.orders.update', $order->id) }}">
            @csrf
            <input type="hidden" name="op" value="advance">
            <button class="ia-btn ia-btn--primary" style="width:100%">{{ $stepNow[1] }}</button>
            @if($stepNow[0] === 'fulfilled' && (filled($order->contact_phone) || filled($order->contact_email)))
              <label class="od-chk"><input type="checkbox" name="notify" value="1" checked>
                @if($order->fulfillment_type === 'local_delivery')
                  Email {{ $order->contact_first_name ?: 'the customer' }} that it's on its way
                @else
                  {{ filled($order->contact_phone) && filled($order->contact_email) ? 'Text and email' : (filled($order->contact_phone) ? 'Text' : 'Email') }} {{ $order->contact_first_name ?: 'the customer' }} that it's ready
                @endif
              </label>
            @endif
            <div class="od-nx">{{ $stepNow[2] }}</div>
          </form>
        @elseif($order->fulfillment_type === 'ship' && $shipsLive->isNotEmpty() && in_array($order->status, ['paid', 'fulfilling', 'fulfilled'], true))
          <div style="font-size:13px;font-weight:600">{{ $shipsSent }} of {{ $shipsLive->count() }} {{ $shipsLive->count() === 1 ? 'shipment' : 'shipments' }} sent</div>
          <div class="od-prog"><i style="width:{{ round($shipsSent / max(1, $shipsLive->count()) * 100) }}%"></i></div>
          <div class="od-nx">The order moves on its own as its shipments do: <b>Shipping</b> once one leaves, <b>Delivered</b> when every one arrives. Ship from the Shipments box.</div>
        @endif

        @if($awaitingManual)
          <form method="POST" action="{{ route('tenant.orders.update', $order->id) }}" style="margin-top:8px" id="odCancel">
            @csrf
            <input type="hidden" name="op" value="cancel">
            <button type="button" class="ia-btn ia-btn--ghost" style="width:100%"
              onclick="IntakeConfirm.show({ title: 'Cancel this order?', message: 'No payment was taken, so nothing needs refunding. Anything held for it goes back on the shelf.', confirmText: 'Cancel order', cancelText: 'Keep it', danger: true }).then(function (ok) { if (ok) document.getElementById('odCancel').submit(); })">Cancel order</button>
          </form>
        @elseif($canRefund && $refundLeft)
          <button type="button" class="ia-btn ia-btn--ghost" style="width:100%;margin-top:12px" onclick="document.getElementById('odRefund').classList.add('on')">{{ $order->status === 'completed' ? 'Refund' : 'Cancel and refund' }}</button>
        @endif
      </div>
    @endif

    {{-- MARKER-ORDER-STEPS: cancel and refund, the way the money came in --}}
    @if($canRefund && $refundLeft)
      <div class="od-scrim" id="odRefund" onclick="if (event.target === this) this.classList.remove('on')">
        <form class="od-dlg" method="POST" action="{{ route('tenant.orders.update', $order->id) }}" id="odRefundForm">
          @csrf <input type="hidden" name="op" value="refund">
          <h4>{{ $order->status === 'completed' ? 'Refund' : 'Cancel and refund' }}</h4>
          <div class="sub">Goes back {{ $refundPlan['method_label'] }}, the way it was paid, and is recorded like a refund at the register.@if($order->fulfillment_type === 'ship' && collect($refundPlan['lines'])->contains('shipped', true)) Lines that already shipped are unticked; tick them if they're coming back.@endif</div>
          @foreach($refundPlan['lines'] as $l)
            <label class="od-rl {{ $l['left'] <= 0 ? 'gone' : '' }}">
              <input type="checkbox" data-each="{{ $l['each_cents'] }}" data-row="{{ $l['sale_item_id'] }}" @checked($l['left'] > 0 && ! $l['shipped']) @disabled($l['left'] <= 0)>
              <span>{{ $l['name'] }}@if($l['left'] <= 0) <small style="color:var(--ia-text-muted)">· already refunded</small>@endif</span>
              @if($l['left'] > 1)<input type="number" class="ia-input" min="1" max="{{ $l['left'] }}" step="1" value="{{ (int) $l['left'] }}" data-qty="{{ $l['sale_item_id'] }}" title="How many">@endif
              <span class="p">{{ $money($l['each_cents'] * max(1, $l['left'])) }}</span>
            </label>
            <input type="hidden" name="lines[{{ $l['sale_item_id'] }}]" value="0" data-out="{{ $l['sale_item_id'] }}">
          @endforeach
          <div class="od-rf"><span>Refund {{ $refundPlan['method_label'] }}</span><span id="odRefTot">$0.00</span></div>
          <input class="ia-input" name="reason" maxlength="300" placeholder="Reason (optional, kept on the refund)" style="width:100%;margin-top:12px">
          @if(filled($order->contact_email))<label class="od-chk"><input type="checkbox" name="notify" value="1" checked> Email {{ $order->contact_first_name ?: 'the customer' }}</label>@endif
          <label class="od-chk"><input type="checkbox" name="restock" value="1" checked> Put the items back in stock</label>
          <div class="od-nx">Supplier-shipped lines are never restocked, since they didn't come off your shelf. The refund is sent on to your Connected POS when one is connected.</div>
          <div class="acts">
            <button type="button" class="ia-btn ia-btn--secondary" onclick="document.getElementById('odRefund').classList.remove('on')">{{ $order->status === 'completed' ? 'Close' : 'Keep the order' }}</button>
            <button type="submit" class="ia-btn ia-btn--primary" id="odRefGo" style="background:#E24B4A;border-color:#E24B4A;color:#fff">Refund</button>
          </div>
        </form>
      </div>
      <script>
      (function () {
        var f = document.getElementById('odRefundForm'), money = function (c) { return '$' + (c / 100).toFixed(2); };
        function calc() {
          var t = 0;
          f.querySelectorAll('input[data-row]').forEach(function (b) {
            var id = b.dataset.row, q = f.querySelector('[data-qty="' + id + '"]'), n = b.checked ? (q ? Math.max(1, parseInt(q.value || '1', 10)) : 1) : 0;
            f.querySelector('[data-out="' + id + '"]').value = n;
            t += n * parseInt(b.dataset.each, 10);
          });
          document.getElementById('odRefTot').textContent = money(t);
          var go = document.getElementById('odRefGo'); go.textContent = t ? 'Refund ' + money(t) : 'Choose what to refund'; go.disabled = !t;
        }
        f.addEventListener('input', calc); f.addEventListener('change', calc); calc();
        f.addEventListener('submit', function () { var g = document.getElementById('odRefGo'); g.disabled = true; g.textContent = 'Refunding…'; });
      })();
      </script>
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

