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

{{-- Stock summary --}}
<div class="ia-card" style="margin-bottom:20px">
  <div class="ia-card-head">
    <span class="ia-card-title">Stock</span>
    @if($isMultiLocation)
      <span style="font-size:12px;color:var(--ia-text-muted);margin-left:8px">across {{ $locations->count() }} locations</span>
    @endif
  </div>
  <div class="ia-card-body">

    <div style="display:flex;align-items:baseline;gap:24px;margin-bottom:16px">
      <div>
        <div style="font-size:36px;font-weight:600">{{ $item->computed_stock_count }}</div>
        <div style="font-size:13px;color:var(--ia-text-muted)">total on hand</div>
      </div>
      @if($item->shop_reorder_threshold !== null)
        <div>
          <div style="font-size:18px">{{ $item->shop_reorder_threshold }}</div>
          <div style="font-size:13px;color:var(--ia-text-muted)">reorder threshold</div>
        </div>
      @endif
      @if($item->shop_reorder_quantity !== null)
        <div>
          <div style="font-size:18px">{{ $item->shop_reorder_quantity }}</div>
          <div style="font-size:13px;color:var(--ia-text-muted)">reorder quantity</div>
        </div>
      @endif
    </div>

    @if($isMultiLocation)
      <table class="ia-table" style="margin-top:8px">
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
                {{ $il ? $il->computed_stock_count : 0 }}
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
    @endif

  </div>
</div>

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
        <tr><td>Your cost</td><td>{{ $item->shop_cost_cents !== null ? '$' . number_format($item->shop_cost_cents / 100, 2) : '—' }}</td></tr>
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

@endsection
