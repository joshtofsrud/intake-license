@extends('layouts.tenant.app')
@php
  // MARKER-PATCH-375 — Option A (buy-box) item page, now live. Media + specs
  // left, sticky summary (status / price / margin / stock / identity) right,
  // tabbed Activity / Special orders / Sourced from below.
  $pageTitle = $item->name;
  $isMultiLocation = $locations->count() > 1;

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
    'sale' => 'Sale', 'sale_void' => 'Sale voided', 'refund' => 'Refund',
    'receive' => 'Received', 'adjustment' => 'Adjustment',
    'transfer_out' => 'Transfer out', 'transfer_in' => 'Transfer in',
    'initial' => 'Initial stock',
  ];

  $money = fn ($c) => $c !== null ? '$' . number_format($c / 100, 2) : '—';

  // --- here / status / locations (from show.blade.php hero) ---
  $hereIl = $currentLocation ? ($itemLocByLocId[$currentLocation->id] ?? null) : null;
  $hereStock = $hereIl ? (int) $hereIl->computed_stock_count : 0;
  $hereThreshold = $hereIl?->shop_reorder_threshold ?? $item->shop_reorder_threshold;
  if ($hereStock < 0) {
    $status = ['copy' => 'Oversold by ' . abs($hereStock), 'tone' => 'red'];
  } elseif ($hereStock === 0) {
    $status = ['copy' => 'Out of stock', 'tone' => 'red'];
  } elseif ($hereThreshold !== null && $hereStock <= $hereThreshold) {
    $status = ['copy' => 'Low — reorder soon', 'tone' => 'amber'];
  } else {
    $status = ['copy' => 'In stock', 'tone' => 'green'];
  }
  $otherLocations = $locations->filter(fn ($l) => !$currentLocation || $l->id !== $currentLocation->id);
  $totalAcrossLocations = (int) $item->computed_stock_count;

  // --- live HLC cost/avail ---
  $hlcSrc = $item->vendors->first(fn ($v) => ($v->pivot->distributor_code ?? null) === 'HLC');
  $liveCost = $hlcSrc?->pivot?->live_cost_cents;
  $liveAvail = $hlcSrc?->pivot?->live_avail;
  $liveCheckedRaw = $hlcSrc?->pivot?->live_checked_at;
  $liveChecked = $liveCheckedRaw ? \Illuminate\Support\Carbon::parse($liveCheckedRaw) : null;

  // --- effective + margin ---
  $effSell = $item->effectiveSellPriceCents();
  $effCost = $item->effectiveCostCents();
  $margin = ($effSell && $effCost && $effSell > 0) ? round(($effSell - $effCost) / $effSell * 100, 1) : null;

  // --- catalog image + specs ---
  $catImages = $item->distributorCatalog?->images ?? [];
  $catAttrs  = $item->distributorCatalog?->attributes ?? [];
  $catDesc   = $item->distributorCatalog?->description;
  $hideAttr  = ['inner pack','master pack','legacy #','legacy','ean','unit of measure',
               'shipping length (l)','shipping width (w)','shipping height (h)','shipping weight','case quantity'];
  $specRows = collect($catAttrs)
    ->filter(fn ($a) => is_array($a) && isset($a['Name'], $a['Value']) && trim((string) $a['Value']) !== '')
    ->reject(fn ($a) => in_array(strtolower(trim((string) $a['Name'])), $hideAttr, true));

  // --- special orders ---
  $openSos = $item->specialOrders->whereIn('status', ['needed', 'ordered', 'arrived'])->sortBy('expected_arrival_date');
  $closedSos = $item->specialOrders->whereIn('status', ['pulled', 'cancelled'])->sortByDesc('updated_at')->take(5);
  $onOrderQty = $openSos->sum('quantity');

  $brand = $item->distributorCatalog?->manufacturer;
@endphp

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">{{ $item->name }}</h1>
    <p class="ia-page-subtitle">
      <a href="{{ route('tenant.inventory.index') }}">← Inventory</a>
      &nbsp;·&nbsp;
      <code>{{ $item->sku }}</code>
      @if($item->category)&nbsp;·&nbsp; {{ $item->category->name }}@endif
      @php $mpn = $item->distributorCatalog?->display_subtitle ?: $item->distributorCatalog?->manufacturer_sku; @endphp
      @if($mpn)&nbsp;·&nbsp; MPN <code>{{ $mpn }}</code>@endif
    </p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.inventory.edit', $item->id) }}" class="ia-btn ia-btn--secondary">Edit</a>
    <button type="button" class="ia-btn ia-btn--primary" onclick="iaShowAdjust()">Adjust stock</button>
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

{{-- Adjust stock form (hidden until button clicked) --}}
<div id="adjust-stock-card" class="ia-card" style="display:{{ $errors->has('reason_code') || $errors->has('reason_text') || $errors->has('new_count') ? 'block' : 'none' }};margin-bottom:20px;border-left:4px solid var(--ia-accent)">
  <div class="ia-card-head">
    <span class="ia-card-title">Adjust stock</span>
    <button type="button" class="ia-card-action" onclick="iaHideAdjust()">Cancel</button>
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

{{-- ============ A LAYOUT: media + specs left, sticky summary right ============ --}}
<div class="ia-show-grid">

  <div class="ia-show-main">

    {{-- Media --}}
    <div class="ia-card">
      <div class="ia-card-body">
        @php
          $imgSrcs = collect($catImages)->map(function ($img) {
            return is_array($img) ? ($img['url'] ?? $img['Url'] ?? $img['path'] ?? null) : (is_string($img) ? $img : null);
          })->filter()->values();
        @endphp
        @if($imgSrcs->isNotEmpty())
          <div class="ia-media-main"><img id="ia-media-hero" src="{{ $imgSrcs->first() }}" alt="{{ $item->name }}"></div>
          @if($imgSrcs->count() > 1)
            <div class="ia-media-thumbs">
              @foreach($imgSrcs as $i => $s)
                <button type="button" class="ia-media-thumb @if($i === 0)is-active @endif" data-src="{{ $s }}" onclick="iaPickImage(this)"><img src="{{ $s }}" alt=""></button>
              @endforeach
            </div>
          @endif
          <div class="ia-media-cap">{{ $imgSrcs->count() }} image{{ $imgSrcs->count() === 1 ? '' : 's' }} from {{ $item->distributorCatalog?->distributor_name ?? 'distributor' }}</div>
        @else
          <div class="ia-media-empty">No image from the distributor catalog.</div>
        @endif
      </div>
    </div>

    {{-- Specs --}}
    @if($specRows->isNotEmpty() || $catDesc)
    <div class="ia-card">
      <div class="ia-card-head">
        <span class="ia-card-title">Specs</span>
        <span style="font-size:12px;color:var(--ia-text-muted);margin-left:8px">from {{ $item->distributorCatalog?->distributor_name ?? 'distributor' }}</span>
      </div>
      <div class="ia-card-body">
        @if($catDesc)<p style="color:var(--ia-text-muted);font-size:13.5px;line-height:1.5;margin:0 0 14px">{{ $catDesc }}</p>@endif
        @if($specRows->isNotEmpty())
          <table class="ia-key-value">
            @foreach($specRows as $a)
              <tr><td>{{ $a['Name'] }}</td><td>{{ $a['Value'] }}</td></tr>
            @endforeach
          </table>
        @endif
      </div>
    </div>
    @endif

    {{-- Pricing & catalog (consolidated: catalog / yours / effective) --}}
    <div class="ia-card">
      <div class="ia-card-head">
        <span class="ia-card-title">Pricing &amp; catalog</span>
        <span style="font-size:12px;color:var(--ia-text-muted);margin-left:8px">your settings override catalog · effective is what the register uses</span>
      </div>
      <div class="ia-card-body">
        <table class="ia-cmp">
          <thead><tr><th></th><th>Catalog</th><th>Your settings</th><th class="ia-cmp-eff">Effective</th></tr></thead>
          <tbody>
            <tr><td>Cost</td><td>{{ $money($item->catalog_cost_cents) }}</td><td>{{ $money($item->shop_cost_cents) }}</td><td class="ia-cmp-eff">{{ $money($effCost) }}</td></tr>
            <tr><td>Sell / MSRP</td><td>{{ $money($item->catalog_msrp_cents) }}</td><td>{{ $money($item->shop_sell_price_cents) }}</td><td class="ia-cmp-eff">{{ $money($effSell) }}</td></tr>
            <tr><td>Case qty</td><td>{{ $item->catalog_case_quantity ?? '—' }}</td><td>{{ $item->shop_case_quantity ?? '—' }}</td><td class="ia-cmp-eff">—</td></tr>
            <tr><td>Margin</td><td>—</td><td>—</td><td class="ia-cmp-eff">{{ $margin !== null ? $margin . '%' : '—' }}</td></tr>
          </tbody>
        </table>

        <table class="ia-key-value" style="margin-top:16px">
          <tr><td>Your dealer cost (live)</td><td>{{ $money($liveCost) }}</td></tr>
          <tr><td>Available (HLC)</td><td>{{ $liveAvail !== null ? $liveAvail : '—' }}</td></tr>
          <tr><td>Cost checked</td><td>{{ $liveChecked?->diffForHumans() ?? 'never' }}</td></tr>
          <tr><td>UPC</td><td><code>{{ $item->catalog_upc ?? '—' }}</code></td></tr>
          <tr><td>Last synced</td><td>{{ $item->catalog_synced_at?->diffForHumans() ?? 'never' }}</td></tr>
          <tr><td>Reorder at</td><td>{{ $item->shop_reorder_threshold ?? '—' }}</td></tr>
          <tr><td>Reorder qty</td><td>{{ $item->shop_reorder_quantity ?? '—' }}</td></tr>
          <tr><td>Bin location</td><td>{{ $item->shop_bin_location ?? '—' }}</td></tr>
        </table>
      </div>
    </div>

    @if($isMultiLocation)
    <div class="ia-card">
      <div class="ia-card-head">
        <span class="ia-card-title">Stock by location</span>
      </div>
      <div class="ia-card-body">
        <table class="ia-table">
          <thead><tr><th>Location</th><th style="text-align:right">On hand</th><th style="text-align:right">Reorder at</th><th>Bin</th></tr></thead>
          <tbody>
            @foreach($locations as $loc)
              @php $il = $itemLocByLocId[$loc->id] ?? null; @endphp
              <tr>
                <td>{{ $loc->name }} @if($loc->is_default)<span class="ia-badge">default</span>@endif</td>
                <td style="text-align:right">
                  <span @if($il && 0 > $il->computed_stock_count) style="color:#E24B4A;font-weight:600" @endif>{{ $il ? $il->computed_stock_count : 0 }}</span>
                  @if($il && $il->isLowStock())<span class="ia-badge ia-badge--amber">Low</span>@endif
                </td>
                <td style="text-align:right;color:var(--ia-text-muted)">{{ $il && $il->shop_reorder_threshold !== null ? $il->shop_reorder_threshold : '—' }}</td>
                <td>{{ $il && $il->shop_bin_location ? $il->shop_bin_location : '—' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
    @endif

  </div>

  {{-- sticky summary --}}
  <aside class="ia-show-side">
    <div class="ia-card">
      <div class="ia-card-body">
        <span class="ia-badge @if($status['tone']==='red')ia-badge--red @elseif($status['tone']==='amber')ia-badge--amber @else ia-badge--green @endif" style="padding:4px 10px;font-size:12px">{{ $status['copy'] }}</span>
        <div class="ia-sum-price">
          <span class="ia-sum-sell">{{ $money($effSell) }}</span>
          <span class="ia-sum-sub">sell</span>
        </div>
        <div class="ia-sum-ref">
          <span>Cost <b>{{ $money($effCost) }}</b></span>
          <span>Margin <b @if($margin!==null)style="color:var(--ia-success)"@endif>{{ $margin !== null ? $margin . '%' : '—' }}</b></span>
        </div>
        @if($item->catalog_msrp_cents !== null)
          <div class="ia-sum-msrp">MSRP {{ $money($item->catalog_msrp_cents) }} <span style="opacity:.6">(catalog)</span></div>
        @endif
        <button type="button" class="ia-btn ia-btn--primary" style="width:100%;justify-content:center;margin-top:14px" onclick="iaShowAdjust()">Adjust stock</button>
      </div>
    </div>

    <div class="ia-card">
      <div class="ia-card-head"><span class="ia-card-title">Stock</span></div>
      <div class="ia-card-body">
        <div class="ia-sum-stock">
          <div class="row"><span>Here @if($currentLocation)· {{ $currentLocation->name }}@endif</span><span class="n @if($status['tone']==='red')neg @endif">{{ $hereStock }}</span></div>
          @if($isMultiLocation)
            <div class="row"><span>All locations</span><span class="n">{{ $totalAcrossLocations }}</span></div>
          @endif
          <div class="row"><span>On special order</span><span class="n">{{ $onOrderQty }}</span></div>
        </div>
        @if($isMultiLocation && !$otherLocations->isEmpty())
          <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--ia-border)">
            @foreach($otherLocations as $ol)
              @php $oil = $itemLocByLocId[$ol->id] ?? null; $oStock = $oil ? (int)$oil->computed_stock_count : 0; @endphp
              <div style="display:flex;justify-content:space-between;font-size:13px;padding:3px 0;color:var(--ia-text-muted)"><span>{{ $ol->name }}</span><span>{{ $oStock }}</span></div>
            @endforeach
          </div>
        @endif
      </div>
    </div>

    <div class="ia-card">
      <div class="ia-card-head"><span class="ia-card-title">Identity</span></div>
      <div class="ia-card-body">
        <table class="ia-key-value">
          @if($brand)<tr><td>Brand</td><td>{{ $brand }}</td></tr>@endif
          @if($item->category)<tr><td>Category</td><td>{{ $item->category->name }}</td></tr>@endif
          <tr><td>MPN</td><td>{{ $mpn ?: '—' }}</td></tr>
          <tr><td>SKU</td><td><code>{{ $item->sku }}</code></td></tr>
          <tr><td>UPC</td><td><code>{{ $item->catalog_upc ?? '—' }}</code></td></tr>
          <tr><td>Source</td><td>{{ $item->distributor_catalog_id ? ($item->distributorCatalog?->distributor_name ?? 'distributor') : 'manual' }}</td></tr>
        </table>
      </div>
    </div>
  </aside>

</div>

{{-- ============ tabbed: activity / special orders / sourced from ============ --}}
<div class="ia-card ia-show-tabs" style="margin-top:4px">
  <div class="ia-tabbar">
    <button type="button" class="ia-tab is-active" data-tab="activity">Recent activity</button>
    <button type="button" class="ia-tab" data-tab="so">Special orders @if($openSos->count())<span class="ia-tab-badge">{{ $openSos->count() }}</span>@endif</button>
    @if($item->vendors->count() > 0)<button type="button" class="ia-tab" data-tab="src">Sourced from</button>@endif
  </div>

  {{-- Activity --}}
  <div class="ia-tabpanel" data-panel="activity">
    @if($recentMovements->isEmpty())
      <div style="text-align:center;color:var(--ia-text-muted);padding:20px">No movements yet.</div>
    @else
      <table class="ia-table">
        <thead><tr><th>When</th><th>Type</th><th>Location</th><th style="text-align:right">Delta</th><th>Reason / Notes</th></tr></thead>
        <tbody>
          @foreach($recentMovements as $mv)
            <tr>
              <td>{{ $mv->created_at?->diffForHumans() ?? '—' }}</td>
              <td>{{ $movementTypeLabels[$mv->movement_type] ?? $mv->movement_type }}</td>
              <td>{{ $mv->location?->name ?? '—' }}</td>
              <td style="text-align:right;color:{{ $mv->quantity_delta > 0 ? 'var(--ia-success)' : ($mv->quantity_delta < 0 ? 'var(--ia-error)' : 'inherit') }}">{{ $mv->quantity_delta > 0 ? '+' : '' }}{{ $mv->quantity_delta }}</td>
              <td style="font-size:13px;color:var(--ia-text-muted)">{{ $mv->reason ? $mv->reason . ($mv->notes ? ' · ' : '') : '' }}{{ $mv->notes }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>

  {{-- Special orders --}}
  <div class="ia-tabpanel" data-panel="so" hidden>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <div style="display:flex;align-items:baseline;gap:24px">
        <div>
          <div style="font-size:28px;font-weight:600">{{ $onOrderQty }}</div>
          <div style="font-size:13px;color:var(--ia-text-muted)">on order across {{ $openSos->count() }} SO{{ $openSos->count() === 1 ? '' : 's' }}</div>
        </div>
      </div>
      <button type="button" class="ia-btn ia-btn--primary ia-btn--sm" onclick='SoDrawer.open({item_id: @json($item->id), item_name: @json($item->name)})'>+ Special order this item</button>
    </div>

    @if($openSos->count() > 0)
      <table class="ia-table">
        <thead><tr><th>SO</th><th>Qty</th><th>For</th><th>Vendor</th><th>Status</th><th>ETA</th></tr></thead>
        <tbody>
          @foreach($openSos as $so)
            <tr style="cursor:pointer" onclick="window.location.href='{{ route('tenant.special-orders.show', ['id' => $so->id]) }}'">
              <td><strong>{{ $so->so_number }}</strong></td>
              <td>{{ $so->quantity }}</td>
              <td>@if($so->customer){{ $so->customer->first_name }} {{ $so->customer->last_name }}@else<span style="color:var(--ia-text-muted)">Shop stock</span>@endif</td>
              <td>{{ $so->vendor?->name ?? '—' }}</td>
              <td>
                @php $isOverdue = $so->status === 'ordered' && $so->expected_arrival_date && $so->expected_arrival_date->isPast(); @endphp
                <span class="so-status so-status--{{ $isOverdue ? 'overdue' : $so->status }}">{{ $isOverdue ? 'Overdue' : ucfirst($so->status) }}</span>
              </td>
              <td style="color:var(--ia-text-muted);font-size:12px">@if($so->expected_arrival_date){{ $so->expected_arrival_date->format('M j') }}@else — @endif</td>
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

  {{-- Sourced from --}}
  @if($item->vendors->count() > 0)
  <div class="ia-tabpanel" data-panel="src" hidden>
    <table class="ia-table">
      <thead><tr><th>Vendor</th><th>Vendor SKU</th><th>Cost</th><th>Lead time</th><th></th></tr></thead>
      <tbody>
        @foreach($item->vendors as $vendor)
          <tr>
            <td><a href="{{ route('tenant.vendors.show', ['id' => $vendor->id]) }}"><strong>{{ $vendor->name }}</strong></a></td>
            <td style="color:var(--ia-text-muted)">{{ $vendor->pivot->vendor_sku ?: '—' }}</td>
            <td>@if($vendor->pivot->unit_cost_cents !== null){{ format_money($vendor->pivot->unit_cost_cents) }}@else<span style="color:var(--ia-text-muted)">—</span>@endif</td>
            <td>@if($vendor->pivot->lead_time_days !== null){{ $vendor->pivot->lead_time_days }}d @else<span style="color:var(--ia-text-muted)">—</span>@endif</td>
            <td>@if($vendor->pivot->is_preferred)<span class="ia-badge ia-badge--accent">Preferred</span>@endif</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  @endif
</div>

@include('tenant.special-orders._drawer', ['vendors' => $vendors ?? collect()])

@push('styles')
<style>
  .ia-show-grid{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:20px;align-items:start;margin-bottom:20px}
  .ia-show-main{display:flex;flex-direction:column;gap:20px;min-width:0}
  .ia-show-side{position:sticky;top:20px;display:flex;flex-direction:column;gap:16px}

  .ia-media-main{background:#f3f3f1;border-radius:10px;overflow:hidden;display:flex;align-items:center;justify-content:center;aspect-ratio:4/3;max-height:360px}
  .ia-media-main img{max-width:100%;max-height:100%;object-fit:contain}
  .ia-media-thumbs{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap}
  .ia-media-thumb{width:56px;height:56px;border-radius:7px;background:#f3f3f1;border:1px solid var(--ia-border);overflow:hidden;padding:0;cursor:pointer}
  .ia-media-thumb.is-active{border-color:var(--ia-accent);box-shadow:0 0 0 1px var(--ia-accent)}
  .ia-media-thumb img{width:100%;height:100%;object-fit:contain}
  .ia-media-cap{margin-top:10px;font-size:11.5px;color:var(--ia-text-muted)}
  .ia-media-empty{color:var(--ia-text-muted);font-size:13px;padding:30px 0;text-align:center}

  .ia-sum-price{display:flex;align-items:baseline;gap:8px;margin-top:14px}
  .ia-sum-sell{font-size:30px;font-weight:680;letter-spacing:-.02em}
  .ia-sum-sub{color:var(--ia-text-muted);font-size:13px}
  .ia-sum-ref{display:flex;gap:18px;color:var(--ia-text-muted);font-size:12.5px;margin-top:4px}
  .ia-sum-ref b{color:var(--ia-text);font-weight:600}
  .ia-sum-msrp{color:var(--ia-text-muted);font-size:12px;margin-top:8px}
  .ia-sum-stock .row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--ia-border)}
  .ia-sum-stock .row:last-child{border-bottom:0}
  .ia-sum-stock .row span:first-child{color:var(--ia-text-muted)}
  .ia-sum-stock .n{font-variant-numeric:tabular-nums;font-weight:600}
  .ia-sum-stock .n.neg{color:#E24B4A}

  table.ia-cmp{width:100%;border-collapse:collapse;font-size:13px}
  table.ia-cmp th,table.ia-cmp td{padding:9px 10px;border-bottom:1px solid var(--ia-border);text-align:right;font-variant-numeric:tabular-nums}
  table.ia-cmp th:first-child,table.ia-cmp td:first-child{text-align:left;color:var(--ia-text-muted)}
  table.ia-cmp thead th{color:var(--ia-text-muted);font-weight:600;font-size:11px;letter-spacing:.04em;text-transform:uppercase}
  table.ia-cmp .ia-cmp-eff{color:var(--ia-text);font-weight:600}
  table.ia-cmp thead .ia-cmp-eff{color:var(--ia-accent)}
  table.ia-cmp tr:last-child td{border-bottom:0}

  .ia-tabbar{display:flex;gap:4px;border-bottom:1px solid var(--ia-border);padding:0 4px;margin-bottom:14px}
  .ia-tab{background:none;border:0;color:var(--ia-text-muted);font:inherit;font-size:13px;font-weight:600;
    padding:11px 14px;border-bottom:2px solid transparent;cursor:pointer}
  .ia-tab.is-active{color:var(--ia-text);border-bottom-color:var(--ia-accent)}
  .ia-tab-badge{display:inline-block;font-size:11px;background:var(--ia-accent);color:#1a1206;border-radius:99px;padding:1px 7px;margin-left:5px;font-weight:700}
  .ia-tabpanel[hidden]{display:none}

  .so-status{display:inline-block;padding:2px 8px;border-radius:99px;font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.05em}
  .so-status--needed{background:rgba(167,139,250,.10);color:#A78BFA}
  .so-status--ordered{background:rgba(96,165,250,.10);color:#60A5FA}
  .so-status--arrived{background:rgba(190,242,100,.10);color:var(--ia-accent)}
  .so-status--pulled{background:rgba(200,200,200,.06);color:var(--ia-text-muted)}
  .so-status--cancelled{background:rgba(248,113,113,.10);color:#F87171;text-decoration:line-through}
  .so-status--overdue{background:rgba(248,113,113,.15);color:#F87171}

  @media(max-width:900px){
    .ia-show-grid{grid-template-columns:1fr}
    .ia-show-side{position:static}
  }
</style>
<script>
  function iaPickImage(btn){var h=document.getElementById('ia-media-hero');if(h){h.src=btn.getAttribute('data-src');}var p=btn.parentElement;if(p){p.querySelectorAll('.ia-media-thumb').forEach(function(t){t.classList.toggle('is-active',t===btn);});}}
  function iaShowAdjust(){var c=document.getElementById('adjust-stock-card');if(c){c.style.display='block';c.scrollIntoView({behavior:'smooth',block:'nearest'});}}
  function iaHideAdjust(){var c=document.getElementById('adjust-stock-card');if(c){c.style.display='none';}}
  (function(){
    document.querySelectorAll('.ia-show-tabs .ia-tab').forEach(function(btn){
      btn.addEventListener('click', function(){
        var root = btn.closest('.ia-show-tabs'), t = btn.getAttribute('data-tab');
        root.querySelectorAll('.ia-tab').forEach(function(x){ x.classList.toggle('is-active', x === btn); });
        root.querySelectorAll('[data-panel]').forEach(function(p){ p.hidden = (p.getAttribute('data-panel') !== t); });
      });
    });
  })();
</script>
@endpush

@endsection

