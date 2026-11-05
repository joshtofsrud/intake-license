{{-- MARKER-PO: the shipment's lines. Re-rendered by the server after every
     change, because freight shares and special-order fills depend on all of
     them. Expects $lines, $shares, $shipment. --}}
@php
  $money = fn ($c) => $c === null ? '—' : '$' . number_format($c / 100, 2);
  $statusOptions = [
    'expected' => 'Expected', 'received' => 'Received', 'backorder' => 'Backorder',
    'unexpected_pending' => 'Pending', 'unexpected_added' => 'Added', 'unexpected_hold' => 'On hold',
  ];
@endphp
@foreach($lines as $line)
  @php
    $pl = $line->poLine;
    $isUnexpected = str_starts_with($line->status, 'unexpected_');
    $acc = \App\Services\Tenant\ReceivingFromPo::accepted($line);
    $share = $shares[$line->id] ?? null;
  @endphp
  <tr data-line-id="{{ $line->id }}" data-status="{{ $line->status }}" @if($isUnexpected) class="rcv-unexp" @endif>
    <td class="rcv-item">
      <div class="rcv-nm">{{ $line->name }}</div>
      @if($line->item?->category?->name)
        <div class="rcv-sub">{{ $line->item->category->name }}</div>
      @elseif(! $line->inventory_item_id)
        <div class="rcv-sub rcv-warn">Not in your catalog</div>
      @endif
      @if($line->sku)<div class="rcv-sub"><code>{{ $line->sku }}</code></div>@endif
    </td>
    <td class="rcv-nw">
      @if($pl)
        <a class="rcv-a" href="{{ route('tenant.purchase-orders.show', ['id' => $pl->purchase_order_id]) }}" target="_blank">{{ $pl->purchaseOrder?->po_number }}</a>
      @else
        <span class="rcv-muted">—</span>
      @endif
    </td>
    <td>
      @if($pl)
        @php
          $left = $line->status === 'received' ? $acc : 0;
          $waiting = $pl->specialOrders->where('status', \App\Models\Tenant\TenantSpecialOrder::STATUS_ORDERED)
            ->sortBy(fn ($so) => [($so->customer_id || $so->appointment_id) ? 0 : 1, $so->created_at?->timestamp ?? 0])->values();
          $soUnits = (int) $waiting->sum('quantity');
        @endphp
        @foreach($waiting as $so)
          @php
            $who = $so->customer?->fullName() ?? ($so->appointment ? $so->appointment->ra_number : 'Shop stock');
            $gets = $left >= (int) $so->quantity; $left -= (int) $so->quantity;
          @endphp
          <span class="rcv-chip {{ $gets ? 'done' : '' }}" title="{{ $so->so_number }}">{{ $who }}{{ $gets ? ' ✓' : ' · waits' }}</span>
        @endforeach
        @php $toStock = max(0, ($line->status === 'received' ? $acc : 0) - $soUnits); @endphp
        @if($toStock)<span class="rcv-chip stock">{{ $toStock }} to stock</span>@endif
      @else
        <span class="rcv-muted">—</span>
      @endif
    </td>
    <td class="rcv-r">
      @if($pl)
        {{ $pl->owed() }}
      @elseif($isUnexpected)
        <span class="rcv-muted">—</span>
      @else
        <input class="ia-input rcv-cell rcv-num" data-field="expected_quantity" type="number" min="0" max="99999" value="{{ $line->expected_quantity }}" title="Expected">
      @endif
    </td>
    <td class="rcv-r">
      <input class="ia-input rcv-cell rcv-num" data-field="received_quantity" type="number" min="0" max="99999" value="{{ $line->received_quantity }}">
    </td>
    <td class="rcv-result">
      @if(! $line->inventory_item_id && ($pl || $line->status === 'unexpected_pending'))
        <span class="rcv-pill warn">Not in your catalog</span>
        <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" onclick="rcvNewItem(@js($line->id), @js($line->name), @js($line->sku))">Create item</button>
      @elseif($line->rejected_quantity > 0)
        <span class="rcv-pill bad">{{ $line->rejected_quantity }} not accepted · {{ strtolower($line->rejected_reason) }}</span>
        <div class="rcv-sub">{{ $pl ? ($line->rejected_replace ? 'Still owed: the vendor is replacing them' : 'Cancelled on ' . $pl->purchaseOrder?->po_number) : "Won't go into stock" }}</div>
        <a href="#" class="rcv-link" data-rcv-unreject="{{ $line->id }}">Undo</a>
      @endif
      @if($pl && $line->inventory_item_id)
        @php $owed = $pl->owed(); $diff = $owed - ($line->status === 'received' ? $acc : 0); @endphp
        @if($diff === 0)
          <span class="rcv-pill ok">Full</span>
        @elseif($diff < 0)
          <span class="rcv-pill warn">Over by {{ -$diff }}</span><div class="rcv-sub">Extra goes to stock</div>
        @else
          @if($line->received_quantity == 0)
            <span class="rcv-pill">Not in this box</span>
          @else
            <span class="rcv-pill warn">Short {{ $diff }}</span>
          @endif
          <div class="rcv-rest">
            <label><input type="radio" name="rest-{{ $line->id }}" value="owed" data-rcv-rest="{{ $line->id }}" @checked($line->rest_action !== 'cancel')> Still coming
              <input type="date" class="ia-input rcv-date" data-rcv-restdate="{{ $line->id }}"
                     value="{{ ($line->rest_expected_date ?? $pl->expected_date ?? $pl->purchaseOrder?->expected_date)?->toDateString() }}" @disabled($line->rest_action === 'cancel')></label>
            <label><input type="radio" name="rest-{{ $line->id }}" value="cancel" data-rcv-rest="{{ $line->id }}" @checked($line->rest_action === 'cancel')> Cancel the {{ $diff }}</label>
          </div>
        @endif
      @elseif(! $pl && $line->inventory_item_id)
        <select class="ia-input rcv-cell rcv-sel" data-field="status">
          @foreach($statusOptions as $val => $label)
            <option value="{{ $val }}" @selected($line->status === $val)>{{ $label }}</option>
          @endforeach
        </select>
        @if($isUnexpected || ($shipment->vendor_id && $line->expected_quantity == 0))
          <div class="rcv-sub rcv-warn">Not on a PO</div>
        @endif
      @endif
    </td>
    <td class="rcv-r">
      <input class="ia-input rcv-cell rcv-num rcv-cost" data-field="unit_cost_dollars" type="text" inputmode="decimal"
             value="{{ $line->unit_cost_cents !== null ? number_format($line->unit_cost_cents / 100, 2, '.', '') : '' }}" placeholder="0.00">
      @if($pl && $pl->unit_cost_cents !== null && $line->unit_cost_cents !== null && $pl->unit_cost_cents !== $line->unit_cost_cents)
        <div class="rcv-sub rcv-warn rcv-nw">PO {{ $money($pl->unit_cost_cents) }} · {{ $line->unit_cost_cents > $pl->unit_cost_cents ? '+' : '−' }}{{ $money(abs($line->unit_cost_cents - $pl->unit_cost_cents)) }}</div>
      @endif
    </td>
    <td class="rcv-r rcv-nw">
      {{ $share && $line->unit_cost_cents !== null ? $money($share[1]) : '—' }}
    </td>
    <td class="rcv-r rcv-nw">
      @if($line->received_quantity > 0 && $line->rejected_quantity == 0 && $line->inventory_item_id)
        <a href="#" class="rcv-link" data-rcv-reject="{{ $line->id }}" data-name="{{ $line->name }}" data-max="{{ $line->received_quantity }}" data-po="{{ $pl?->purchaseOrder?->po_number }}" data-sos="{{ $pl ? $pl->specialOrders->where('status', 'ordered')->count() : 0 }}">Not accepted…</a>
      @endif
      @if($line->inventory_item_id)
        <button type="button" class="ia-btn ia-btn--ghost rcv-icon" onclick="rcvEditItem(@js($line->inventory_item_id), @js($line->id))" title="Edit item">✎</button>
      @endif
      <button type="button" class="ia-btn ia-btn--ghost rcv-icon" onclick="rcvRemoveLine(@js($line->id))" title="{{ $pl ? 'Remove (it stays owed on its PO)' : 'Remove' }}">×</button>
    </td>
  </tr>
@endforeach
<tr id="rcv-newline" data-newline="1">
  <td>
    <span class="rcv-addlbl">+ Add line</span>
    <div class="rcv-sub">Scan or type to find an item</div>
  </td>
  <td colspan="3">
    <input type="text" class="ia-input" id="rcv-newline-sku" autocomplete="off" placeholder="SKU, UPC, or name + Enter">
  </td>
  <td colspan="5" class="rcv-sub" style="vertical-align:middle">
    @if($shipment->vendor_id)Anything on an open PO from this vendor links to it.@else Units go into stock when you commit.@endif
  </td>
</tr>
