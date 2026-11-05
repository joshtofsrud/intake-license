{{-- one special-order row, shared by the needs-a-vendor
     box and each vendor box. Item name gets its own line so long product
     names cannot collide with the vendor picker. --}}
@php
  $opts = $voptions[$so->inventory_item_id] ?? [];
  // MARKER-PO: an item not in the catalog has no vendor rows, so any vendor can supply it
  if (! $so->inventory_item_id && empty($opts)) {
      $opts = collect($vvendors ?? [])->map(fn ($v) => ['vendor_id' => $v->id, 'name' => $v->name, 'avail' => null, 'cost' => null, 'lead' => null, 'preferred' => false])->values()->all();
  }
  $og   = $origins[$so->id] ?? null;
  $onPo = $so->purchase_order_line_id ? $so->poLine?->purchaseOrder : null; // MARKER-PO
  $loc  = $so->location_id ?: '';
@endphp
<div class="sog-row" data-so="{{ $so->id }}" data-loc="{{ $loc }}" data-locname="{{ $so->location?->name }}" data-name="{{ $so->item_name_snapshot }}" data-who="{{ $so->customer ? trim($so->customer->fullName()) : 'stock' }}">
  @if($selectable && ! $onPo)
    <span class="sog-cb on" data-sog-cb></span>
  @elseif($selectable)
    <span class="sog-cb" style="visibility:hidden"></span>
  @endif

  {{-- the flat table opened the order on row click and
       the grouped view lost it. The name is the link, so clicks on the
       picker, checkbox, and inline buttons stay put. --}}
  <div class="sog-ident">
    <a class="sog-nm sog-open" href="{{ route('tenant.special-orders.show', ['id' => $so->id]) }}">{{ $so->item_name_snapshot }}</a>
    <div class="sog-mt">
      <span>{{ $so->so_number }} · qty {{ $so->quantity }} ·
        {{ $so->customer ? trim($so->customer->fullName()) : 'stock' }}</span>
      @if($og)
        <span class="so-origin so-origin--{{ $og['state'] }}">{{ $og['label'] }}</span>
      @endif
      @if($so->created_at)
        <span style="opacity:.6">{{ (int) $so->created_at->diffInDays(now()) }}d old</span>
      @endif
      @if($onPo)
        <a class="so-origin so-origin--live" href="{{ route('tenant.purchase-orders.show', ['id' => $onPo->id]) }}" style="text-decoration:none">On draft {{ $onPo->po_number }}</a>
      @endif
      @if($so->vendor_assigned_rule && $so->vendor_assigned_rule !== 'manual')
        <span style="opacity:.6">auto: {{ str_replace('_', ' ', $so->vendor_assigned_rule) }}</span>
      @endif
      @if($og && in_array($og['state'], ['orphan', 'unknown'], true))
        <span class="so-origin-acts" data-so="{{ $so->id }}">
          <button type="button" class="so-oa" data-so-keep>Still needed</button>
          <button type="button" class="so-oa danger" data-so-drop>Cancel</button>
        </span>
      @endif
    </div>
  </div>

  <a class="sog-openall" href="{{ route('tenant.special-orders.show', ['id' => $so->id]) }}">Details →</a>

  @if($onPo)
    <a class="sog-assign" href="{{ route('tenant.purchase-orders.show', ['id' => $onPo->id]) }}" style="text-decoration:none">Open {{ $onPo->po_number }}</a>
  @elseif(empty($opts))
    <a class="sog-noopt" href="{{ $so->inventory_item_id ? route('tenant.inventory.show', ['id' => $so->inventory_item_id]) . '#item-sources' : route('tenant.special-orders.show', ['id' => $so->id]) }}">No vendor carries this yet — add one on the item</a>
  @else
    <span class="sog-pick">
      <select class="sog-sel" data-sog-select>
        @foreach($opts as $o)
          <option value="{{ $o['vendor_id'] }}" @selected($o['vendor_id'] === $vendorId)>
            {{ $o['name'] }}
            · {{ $o['avail'] === null ? 'stock unknown' : ($o['avail'] > 0 ? $o['avail'] . ' avail' : 'none in stock') }}
            @if($o['cost']) · ${{ number_format($o['cost'] / 100, 2) }} @endif
            @if($o['lead']) · {{ $o['lead'] }}d @endif
            @if($o['preferred']) · preferred @endif
          </option>
        @endforeach
      </select>
      <button type="button" class="sog-assign" data-sog-assign>{{ $vendorId === '' ? 'Assign' : 'Move' }}</button>
    </span>
  @endif
</div>
