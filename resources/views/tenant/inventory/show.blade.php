@extends('layouts.tenant.app')
@php
  $pageTitle = $item->name;
  $isMultiLocation = $locations->count() > 1;

  // Build a location_id -> item_location map for quick lookup
  $itemLocByLocId = [];
  foreach ($item->locations as $il) {
      $itemLocByLocId[$il->location_id] = $il;
  }

  $reasonCodes = [
    'damaged' => 'Damaged',
    'expired' => 'Expired',
    'theft_shrinkage' => 'Theft / shrinkage',
    'count_correction' => 'Count correction',
    'found' => 'Found unexpectedly',
    'vendor_credit' => 'Returned to vendor',
    'donation' => 'Donation',
    'internal_use' => 'Internal use',
    'display' => 'Moved to display',
    'sample' => 'Sample / giveaway',
    'other' => 'Other (specify)',
  ];

  $movementTypeLabels = [
    'sale' => 'Sale',
    'sale_void' => 'Sale voided',
    'refund' => 'Refund',
    'receive' => 'Received',
    'adjustment' => 'Adjustment',
    'transfer_out' => 'Transfer out',
    'transfer_in' => 'Transfer in',
    'initial' => 'Initial stock',
  ];
@endphp

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">{{ $item->name }}</h1>
    <p class="ia-page-subtitle">
      <a href="{{ route('tenant.inventory.index') }}">← Inventory</a>
      &nbsp;·&nbsp;
      <code>{{ $item->sku }}</code>
      @if($item->category)
        &nbsp;·&nbsp; {{ $item->category->name }}
      @endif
      {{-- MARKER-PATCH-HLC18 --}}
      @php $mpn = $item->distributorCatalog?->display_subtitle ?: $item->distributorCatalog?->manufacturer_sku; @endphp
      @if($mpn)
        &nbsp;·&nbsp; MPN <code>{{ $mpn }}</code>
      @endif
    </p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.inventory.edit', $item->id) }}" class="ia-btn ia-btn--secondary">Edit</a>
    <button type="button" class="ia-btn ia-btn--primary"
      onclick="document.getElementById('adjust-stock-card').style.display='block';this.style.display='none'">
      Adjust stock
    </button>
  </div>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--{{ session('flash')['type'] }}">{{ session('flash')['message'] }}</div>
@endif

@if($errors->any())
  <div class="ia-flash ia-flash--error">
    @foreach($errors->all() as $error){{ $error }}<br>@endforeach
  </div>
@endif

{{-- patch-97 hero card — three-zone status: Here · Elsewhere · Special orders. --}}
@php
  $hereIl = $currentLocation ? ($itemLocByLocId[$currentLocation->id] ?? null) : null;
  $hereStock = $hereIl ? (int) $hereIl->computed_stock_count : 0;
  $hereThreshold = $hereIl?->shop_reorder_threshold ?? $item->shop_reorder_threshold;

  // Status — drives the pill color/copy
  if ($hereStock < 0) {
    $status = ['copy' => 'Oversold by ' . abs($hereStock), 'tone' => 'red'];
  } elseif ($hereStock === 0) {
    $status = ['copy' => 'Out of stock', 'tone' => 'red'];
  } elseif ($hereThreshold !== null && $hereStock <= $hereThreshold) {
    $status = ['copy' => 'Low — reorder soon', 'tone' => 'amber'];
  } else {
    $status = ['copy' => 'In stock', 'tone' => 'green'];
  }

  // Other locations
  $otherLocations = $locations->filter(fn($l) => !$currentLocation || $l->id !== $currentLocation->id);
  $totalAcrossLocations = (int) $item->computed_stock_count;

  // Best location to transfer from (any other loc with positive stock)
  $transferCandidate = null;
  foreach ($otherLocations as $ol) {
    $oil = $itemLocByLocId[$ol->id] ?? null;
    $oStock = $oil ? (int) $oil->computed_stock_count : 0;
    if ($oStock > 0 && (!$transferCandidate || $oStock > $transferCandidate['stock'])) {
      $transferCandidate = ['location' => $ol, 'stock' => $oStock];
    }
  }

  // SO summary string e.g. "1 needed, 1 ordered"
  $soMix = [];
  foreach ($soSummary['by_status'] as $st => $cnt) {
    $soMix[] = $cnt . ' ' . $st;
  }
@endphp

<div class="ia-card" style="margin-bottom:14px">
  <div class="ia-card-body" style="padding:20px 24px">
    <div style="display:grid;grid-template-columns:1.1fr 0.9fr 1fr;gap:28px;align-items:flex-start">

      {{-- Zone 1: Here --}}
      <div>
        <div style="font-size:11px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--ia-text-muted);margin-bottom:8px">
          Here @if($currentLocation)· {{ $currentLocation->name }}@endif
        </div>
        <div style="display:flex;align-items:baseline;gap:10px;margin-bottom:10px">
          <div style="font-size:44px;font-weight:600;line-height:1;@if($status['tone']==='red')color:#E24B4A;@endif">{{ $hereStock }}</div>
          <div style="font-size:13px;color:var(--ia-text-muted)">on hand</div>
        </div>
        <span class="ia-badge @if($status['tone']==='red')ia-badge--red @elseif($status['tone']==='amber')ia-badge--amber @else ia-badge--green @endif" style="padding:4px 10px;font-size:12px">
          {{ $status['copy'] }}
        </span>
      </div>

      {{-- Zone 2: Elsewhere --}}
      <div>
        <div style="font-size:11px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--ia-text-muted);margin-bottom:8px">
          Elsewhere
        </div>
        @if($otherLocations->isEmpty())
          <div style="font-size:13px;color:var(--ia-text-muted)">Single-location tenant</div>
        @else
          <div style="display:flex;flex-direction:column;gap:6px">
            @foreach($otherLocations as $ol)
              @php $oil = $itemLocByLocId[$ol->id] ?? null; $oStock = $oil ? (int)$oil->computed_stock_count : 0; @endphp
              <div style="display:flex;justify-content:space-between;align-items:baseline;font-size:14px">
                <span>{{ $ol->name }}</span>
                <span style="font-weight:600;@if(0 > $oStock)color:#E24B4A;@endif">{{ $oStock }}</span>
              </div>
            @endforeach
            <div style="font-size:12px;color:var(--ia-text-muted);margin-top:4px">
              Total across all locations: <span style="color:var(--ia-text);font-weight:600">{{ $totalAcrossLocations }}</span>
            </div>
            @if($transferCandidate)
              <a href="#" onclick="alert('Transfer UI coming soon.'); return false;"
                 style="font-size:12px;color:var(--ia-accent);text-decoration:none;margin-top:2px">
                Transfer from {{ $transferCandidate['location']->name }} →
              </a>
            @endif
          </div>
        @endif
      </div>

      {{-- Zone 3: Special orders --}}
      <div>
        <div style="font-size:11px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--ia-text-muted);margin-bottom:8px">
          Special orders
        </div>
        @if($soSummary['open_count'] === 0)
          <div style="font-size:14px;color:var(--ia-text-muted);margin-bottom:6px">None open</div>
        @else
          <div style="font-size:14px;margin-bottom:6px">
            <span style="font-weight:600">{{ $soSummary['open_count'] }} open</span>
            @if(!empty($soMix))
              <span style="color:var(--ia-text-muted)"> · {{ implode(', ', $soMix) }}</span>
            @endif
          </div>
          @if($soSummary['earliest_eta'])
            <div style="font-size:12px;color:var(--ia-text-muted);margin-bottom:6px">
              Earliest ETA · {{ \Carbon\Carbon::parse($soSummary['earliest_eta'])->format('M j') }}
            </div>
          @endif
        @endif
        <a href="{{ route('tenant.special-orders.index') }}?inventory_item_id={{ $item->id }}&prefill=1"
           style="font-size:12px;color:var(--ia-accent);text-decoration:none">
          + Special order →
        </a>
      </div>

    </div>
  </div>
</div>

@if($isMultiLocation)
  {{-- Stock by location — detail table moved here from the old stock card --}}
  <div class="ia-card" style="margin-bottom:20px">
    <div class="ia-card-head">
      <span class="ia-card-title">Stock by location</span>
      <span style="font-size:12px;color:var(--ia-text-muted);margin-left:8px">
        @if($item->shop_reorder_threshold !== null) reorder threshold: {{ $item->shop_reorder_threshold }} @endif
        @if($item->shop_reorder_quantity !== null) · reorder qty: {{ $item->shop_reorder_quantity }} @endif
      </span>
    </div>
    <div class="ia-card-body">
      <table class="ia-table">
        <thead>
          <tr>
            <th>Location</th>
            <th style="text-align:right">On hand</th>
            <th style="text-align:right">Reorder at</th>
            <th>Bin</th>
          </tr>
        </thead>
        <tbody>
          @foreach($locations as $loc)
            @php $il = $itemLocByLocId[$loc->id] ?? null; @endphp
            <tr>
              <td>{{ $loc->name }} @if($loc->is_default)<span class="ia-badge">default</span>@endif</td>
              <td style="text-align:right">
                <span @if($il && 0 > $il->computed_stock_count) style="color:#E24B4A;font-weight:600" @endif>
                  {{ $il ? $il->computed_stock_count : 0 }}
                </span>
                @if($il && $il->isLowStock())<span class="ia-badge ia-badge--amber">Low</span>@endif
              </td>
              <td style="text-align:right;color:var(--ia-text-muted)">
                {{ $il && $il->shop_reorder_threshold !== null ? $il->shop_reorder_threshold : '—' }}
              </td>
              <td>{{ $il && $il->shop_bin_location ? $il->shop_bin_location : '—' }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
@endif

{{-- Adjust stock form (hidden until button clicked) --}}
<div id="adjust-stock-card" class="ia-card" style="display:{{ $errors->has('reason_code') || $errors->has('reason_text') || $errors->has('new_count') ? 'block' : 'none' }};margin-bottom:20px;border-left:4px solid var(--ia-accent)">
  <div class="ia-card-head">
    <span class="ia-card-title">Adjust stock</span>
    <button type="button" class="ia-card-action"
      onclick="document.getElementById('adjust-stock-card').style.display='none';document.querySelector('.ia-btn--primary').style.display=''">
      Cancel
    </button>
  </div>
  <form method="POST" action="{{ route('tenant.inventory.stock', $item->id) }}">
    @csrf
    <div class="ia-card-body">

      @if($isMultiLocation)
        <div class="ia-form-group">
          <label class="ia-form-label">Location <span class="ia-required">*</span></label>
          <select name="location_id" class="ia-input" required>
            @foreach($locations as $loc)
              <option value="{{ $loc->id }}" @selected(old('location_id') === $loc->id)>
                {{ $loc->name }} ({{ $itemLocByLocId[$loc->id]->computed_stock_count ?? 0 }} on hand)
              </option>
            @endforeach
          </select>
        </div>
      @else
        @php $singleLoc = $locations->first(); @endphp
        <input type="hidden" name="location_id" value="{{ $singleLoc->id }}">
      @endif

      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">New count <span class="ia-required">*</span></label>
          <input type="number" min="0" name="new_count" class="ia-input" required value="{{ old('new_count') }}">
          <div class="ia-form-hint">The actual count on hand right now. We'll calculate the difference.</div>
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Reason <span class="ia-required">*</span></label>
          <select name="reason_code" class="ia-input" required onchange="document.getElementById('reason-other-row').style.display = this.value === 'other' ? '' : 'none'">
            <option value="">Select reason…</option>
            @foreach($reasonCodes as $code => $label)
              <option value="{{ $code }}" @selected(old('reason_code') === $code)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
      </div>

      <div id="reason-other-row" class="ia-form-group" style="display:{{ old('reason_code') === 'other' ? '' : 'none' }}">
        <label class="ia-form-label">Reason details <span class="ia-required">*</span></label>
        <input type="text" name="reason_text" class="ia-input" value="{{ old('reason_text') }}" maxlength="500">
      </div>

      <div class="ia-form-group">
        <label class="ia-form-label">Notes (optional)</label>
        <textarea name="notes" class="ia-input" rows="2" maxlength="1000">{{ old('notes') }}</textarea>
      </div>

    </div>
    <div class="ia-card-foot" style="display:flex;gap:8px;justify-content:flex-end;padding:12px 16px">
      <button type="submit" class="ia-btn ia-btn--primary">Save adjustment</button>
    </div>
  </form>
</div>

{{-- Catalog / Shop fields side-by-side --}}
<div class="ia-input-grid-2" style="margin-bottom:20px">

  {{-- MARKER-PATCH-HLC15 — tier-2 live cost/availability from the HLC vendor pivot --}}
  @php
    $hlcSrc = $item->vendors->first(fn ($v) => ($v->pivot->distributor_code ?? null) === 'HLC');
    $liveCost = $hlcSrc?->pivot?->live_cost_cents;
    $liveAvail = $hlcSrc?->pivot?->live_avail;
    $liveCheckedRaw = $hlcSrc?->pivot?->live_checked_at;
    $liveChecked = $liveCheckedRaw ? \Illuminate\Support\Carbon::parse($liveCheckedRaw) : null;
  @endphp

  {{-- CATALOG (synced) --}}
  <div class="ia-card" style="opacity:{{ $item->distributor_catalog_id ? '1' : '0.6' }}">
    <div class="ia-card-head">
      <span class="ia-card-title">Catalog data</span>
      <span style="font-size:12px;color:var(--ia-text-muted);margin-left:8px">
        @if($item->distributor_catalog_id)
          synced from {{ $item->distributorCatalog?->distributor_name ?? 'distributor' }}
        @else
          not linked to a distributor catalog
        @endif
      </span>
    </div>
    <div class="ia-card-body">
      <table class="ia-key-value">
        <tr><td>Cost</td><td>{{ $item->catalog_cost_cents !== null ? '$' . number_format($item->catalog_cost_cents / 100, 2) : '—' }}</td></tr>
        <tr><td>MSRP</td><td>{{ $item->catalog_msrp_cents !== null ? '$' . number_format($item->catalog_msrp_cents / 100, 2) : '—' }}</td></tr>
        <tr><td>Case quantity</td><td>{{ $item->catalog_case_quantity ?? '—' }}</td></tr>
        <tr><td>UPC</td><td><code>{{ $item->catalog_upc ?? '—' }}</code></td></tr>
        <tr><td>Last synced</td><td>{{ $item->catalog_synced_at?->diffForHumans() ?? 'never' }}</td></tr>
        <tr><td>Your dealer cost (live)</td><td>{{ $liveCost !== null ? '$' . number_format($liveCost / 100, 2) : '—' }}</td></tr>
        <tr><td>Available (HLC)</td><td>{{ $liveAvail !== null ? $liveAvail : '—' }}</td></tr>
        <tr><td>Cost checked</td><td>{{ $liveChecked?->diffForHumans() ?? 'never' }}</td></tr>
      </table>
    </div>
  </div>

  {{-- SHOP (your overrides) --}}
  <div class="ia-card" style="border-left:4px solid var(--ia-accent)">
    <div class="ia-card-head">
      <span class="ia-card-title">Your settings</span>
      <span style="font-size:12px;color:var(--ia-text-muted);margin-left:8px">never overwritten by sync</span>
    </div>
    <div class="ia-card-body">
      <table class="ia-key-value">
        <tr><td>Your cost</td><td>{{ ($item->shop_cost_cents ?? $liveCost) !== null ? '$' . number_format(($item->shop_cost_cents ?? $liveCost) / 100, 2) : '—' }}</td></tr>
        <tr><td>Sell price</td><td>{{ $item->shop_sell_price_cents !== null ? '$' . number_format($item->shop_sell_price_cents / 100, 2) : '—' }}</td></tr>
        <tr><td>Case quantity</td><td>{{ $item->shop_case_quantity ?? '—' }}</td></tr>
        <tr><td>Reorder at</td><td>{{ $item->shop_reorder_threshold ?? '—' }}</td></tr>
        <tr><td>Reorder qty</td><td>{{ $item->shop_reorder_quantity ?? '—' }}</td></tr>
        <tr><td>Bin location</td><td>{{ $item->shop_bin_location ?? '—' }}</td></tr>
      </table>
    </div>
  </div>

</div>

{{-- Effective values (what actually shows up at the register) --}}
<div class="ia-card" style="margin-bottom:20px">
  <div class="ia-card-head">
    <span class="ia-card-title">Effective values</span>
    <span style="font-size:12px;color:var(--ia-text-muted);margin-left:8px">what the register and reports use</span>
  </div>
  <div class="ia-card-body">
    <table class="ia-key-value">
      <tr><td>Cost</td><td>{{ $item->effectiveCostCents() !== null ? '$' . number_format($item->effectiveCostCents() / 100, 2) : '—' }}</td></tr>
      <tr><td>Sell price</td><td>{{ $item->effectiveSellPriceCents() !== null ? '$' . number_format($item->effectiveSellPriceCents() / 100, 2) : '—' }}</td></tr>
      <tr><td>Margin</td><td>
        @php
          $sp = $item->effectiveSellPriceCents();
          $c = $item->effectiveCostCents();
          $margin = ($sp && $c && $sp > 0) ? round(($sp - $c) / $sp * 100, 1) : null;
        @endphp
        {{ $margin !== null ? $margin . '%' : '—' }}
      </td></tr>
    </table>
  </div>
</div>

{{-- Activity log --}}
<div class="ia-card">
  <div class="ia-card-head">
    <span class="ia-card-title">Recent activity</span>
    <span style="font-size:12px;color:var(--ia-text-muted);margin-left:8px">latest 50 movements</span>
  </div>
  <div class="ia-card-body">
    @if($recentMovements->isEmpty())
      <div style="text-align:center;color:var(--ia-text-muted);padding:20px">No movements yet.</div>
    @else
      <table class="ia-table">
        <thead>
          <tr>
            <th>When</th>
            <th>Type</th>
            <th>Location</th>
            <th style="text-align:right">Delta</th>
            <th>Reason / Notes</th>
          </tr>
        </thead>
        <tbody>
          @foreach($recentMovements as $mv)
            <tr>
              <td>{{ $mv->created_at?->diffForHumans() ?? '—' }}</td>
              <td>{{ $movementTypeLabels[$mv->movement_type] ?? $mv->movement_type }}</td>
              <td>{{ $mv->location?->name ?? '—' }}</td>
              <td style="text-align:right;color:{{ $mv->quantity_delta > 0 ? 'var(--ia-success)' : ($mv->quantity_delta < 0 ? 'var(--ia-error)' : 'inherit') }}">
                {{ $mv->quantity_delta > 0 ? '+' : '' }}{{ $mv->quantity_delta }}
              </td>
              <td style="font-size:13px;color:var(--ia-text-muted)">
                {{ $mv->reason ? $mv->reason . ($mv->notes ? ' · ' : '') : '' }}{{ $mv->notes }}
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>

{{-- ════════════════════════════════════════════════════════════
     Special Orders integration (added by patch 88, Stage 5)
     - Stat: total quantity on open SOs
     - List: open + recent closed SOs for this item
     - Action: + SO this item button (opens prefilled drawer)
     - Read-only vendor sources from pivot
     ════════════════════════════════════════════════════════════ --}}

@php
  $openSos = $item->specialOrders->whereIn('status', ['needed', 'ordered', 'arrived'])->sortBy('expected_arrival_date');
  $closedSos = $item->specialOrders->whereIn('status', ['pulled', 'cancelled'])->sortByDesc('updated_at')->take(5);
  $onOrderQty = $openSos->sum('quantity');
@endphp

<div class="ia-card" style="margin-bottom:20px">
  <div class="ia-card-head">
    <span class="ia-card-title">Special orders</span>
    <button type="button" class="ia-btn ia-btn--primary ia-btn--sm"
            onclick='SoDrawer.open({item_id: @json($item->id), item_name: @json($item->name)})'>
      + Special order this item
    </button>
  </div>
  <div class="ia-card-body">

    <div style="display:flex;align-items:baseline;gap:24px;margin-bottom:16px">
      <div>
        <div style="font-size:30px;font-weight:600">{{ $onOrderQty }}</div>
        <div style="font-size:13px;color:var(--ia-text-muted)">on order across {{ $openSos->count() }} SO{{ $openSos->count() === 1 ? '' : 's' }}</div>
      </div>
      @if($item->vendors->count() > 0)
        <div>
          <div style="font-size:18px">{{ $item->vendors->count() }}</div>
          <div style="font-size:13px;color:var(--ia-text-muted)">vendor source{{ $item->vendors->count() === 1 ? '' : 's' }}</div>
        </div>
      @endif
    </div>

    @if($openSos->count() > 0)
      <table class="ia-table" style="margin-top:8px">
        <thead>
          <tr>
            <th>SO</th>
            <th>Qty</th>
            <th>For</th>
            <th>Vendor</th>
            <th>Status</th>
            <th>ETA</th>
          </tr>
        </thead>
        <tbody>
          @foreach($openSos as $so)
            <tr style="cursor:pointer" onclick="window.location.href='{{ route('tenant.special-orders.show', ['id' => $so->id]) }}'">
              <td><strong>{{ $so->so_number }}</strong></td>
              <td>{{ $so->quantity }}</td>
              <td>
                @if($so->customer)
                  {{ $so->customer->first_name }} {{ $so->customer->last_name }}
                @else
                  <span style="color:var(--ia-text-muted)">Shop stock</span>
                @endif
              </td>
              <td>{{ $so->vendor?->name ?? '—' }}</td>
              <td>
                @php
                  $isOverdue = $so->status === 'ordered' && $so->expected_arrival_date && $so->expected_arrival_date->isPast();
                @endphp
                <span class="so-status so-status--{{ $isOverdue ? 'overdue' : $so->status }}">{{ $isOverdue ? 'Overdue' : ucfirst($so->status) }}</span>
              </td>
              <td style="color:var(--ia-text-muted);font-size:12px">
                @if($so->expected_arrival_date){{ $so->expected_arrival_date->format('M j') }}@else — @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @else
      <p style="font-size:13px;color:var(--ia-text-muted);margin:0">No open special orders for this item.</p>
    @endif

    @if($closedSos->count() > 0)
      <details style="margin-top:16px">
        <summary style="font-size:12px;color:var(--ia-text-muted);cursor:pointer">Recent closed ({{ $closedSos->count() }})</summary>
        <table class="ia-table" style="margin-top:8px">
          <tbody>
            @foreach($closedSos as $so)
              <tr style="cursor:pointer;opacity:.7" onclick="window.location.href='{{ route('tenant.special-orders.show', ['id' => $so->id]) }}'">
                <td><strong>{{ $so->so_number }}</strong></td>
                <td>{{ $so->quantity }}</td>
                <td>{{ $so->customer ? $so->customer->first_name . ' ' . $so->customer->last_name : 'Stock' }}</td>
                <td><span class="so-status so-status--{{ $so->status }}">{{ ucfirst($so->status) }}</span></td>
                <td style="color:var(--ia-text-muted);font-size:12px">{{ $so->updated_at->format('M j, Y') }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </details>
    @endif

  </div>
</div>

@if($item->vendors->count() > 0)
<div class="ia-card" style="margin-bottom:20px">
  <div class="ia-card-head">
    <span class="ia-card-title">Sourced from</span>
    <span style="font-size:11.5px;color:var(--ia-text-muted)">vendor sources for this item</span>
  </div>
  <div class="ia-card-body">
    <table class="ia-table">
      <thead>
        <tr>
          <th>Vendor</th>
          <th>Vendor SKU</th>
          <th>Cost</th>
          <th>Lead time</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @foreach($item->vendors as $vendor)
          <tr>
            <td>
              <a href="{{ route('tenant.vendors.show', ['id' => $vendor->id]) }}">
                <strong>{{ $vendor->name }}</strong>
              </a>
            </td>
            <td style="color:var(--ia-text-muted)">{{ $vendor->pivot->vendor_sku ?: '—' }}</td>
            <td>
              @if($vendor->pivot->unit_cost_cents !== null)
                {{ format_money($vendor->pivot->unit_cost_cents) }}
              @else
                <span style="color:var(--ia-text-muted)">—</span>
              @endif
            </td>
            <td>
              @if($vendor->pivot->lead_time_days !== null)
                {{ $vendor->pivot->lead_time_days }}d
              @else
                <span style="color:var(--ia-text-muted)">—</span>
              @endif
            </td>
            <td>
              @if($vendor->pivot->is_preferred)
                <span class="ia-badge ia-badge--accent">Preferred</span>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    <p style="font-size:11.5px;color:var(--ia-text-muted);margin-top:10px">
      Vendor management UI ships in a future patch. Edit via <code>php artisan tinker</code> or wait for Stage 5b.
    </p>
  </div>
</div>
@endif

@include('tenant.special-orders._drawer', ['vendors' => $vendors ?? collect()])

@push('styles')
<style>
.so-status {
  display: inline-block; padding: 2px 8px; border-radius: 99px;
  font-size: 10.5px; font-weight: 600;
  text-transform: uppercase; letter-spacing: 0.05em;
}
.so-status--needed   { background: rgba(167,139,250,0.10); color: #A78BFA; }
.so-status--ordered  { background: rgba(96,165,250,0.10);  color: #60A5FA; }
.so-status--arrived  { background: rgba(190,242,100,0.10); color: var(--ia-accent); }
.so-status--pulled   { background: rgba(200,200,200,0.06); color: var(--ia-text-muted); }
.so-status--cancelled{ background: rgba(248,113,113,0.10); color: #F87171; text-decoration: line-through; }
.so-status--overdue  { background: rgba(248,113,113,0.15); color: #F87171; }
</style>
@endpush

@endsection
