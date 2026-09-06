@extends('layouts.tenant.app')
@php $pageTitle = 'Edit ' . $item->name; @endphp

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Edit item</h1>
    <p class="ia-page-subtitle">
      <a href="{{ route('tenant.inventory.show', $item->id) }}">← Back to {{ $item->name }}</a>
    </p>
  </div>
</div>

@if($errors->any())
  <div class="ia-flash ia-flash--error">
    <strong>Please fix the following:</strong>
    <ul style="margin:8px 0 0 0;padding-left:20px">
      @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
    </ul>
  </div>
@endif

{{-- MARKER-BLADE-GLUE-FIX — the Archive form used to be nested INSIDE this
     one. Nested forms are invalid HTML and the inner submit can post to the
     outer action. The two are siblings now; Save reaches this form by id. --}}
<form method="POST" action="{{ route('tenant.inventory.update', $item->id) }}">
  @csrf
  @method('PATCH')

  <div class="ia-card" style="margin-bottom:20px">
    <div class="ia-card-head"><span class="ia-card-title">Item details</span></div>
    <div class="ia-card-body">

      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Name <span class="ia-required">*</span></label>
          <input type="text" name="name" class="ia-input" required value="{{ old('name', $item->name) }}">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">SKU <span class="ia-required">*</span></label>
          <input type="text" name="sku" class="ia-input" required value="{{ old('sku', $item->sku) }}">
        </div>
      </div>

      <div class="ia-form-group">
        <label class="ia-form-label">Category <span class="ia-required">*</span></label>
        {{-- MARKER-SSEL-CATS — our picker, not a native select: macOS draws
             the native popup itself and ignored two attempts to make it dark,
             so a tenant on a light-mode machine got white on white. This one
             renders in our CSS. `required` is enforced server-side. --}}
        @php
          $catOpts = [];
          foreach ($categories as $opt) {
              // MARKER-CAT-DEPTH-INDENT — one marker per level, so a grandchild
              // reads as a grandchild instead of a sibling of its parent.
              $catOpts[$opt['cat']->id] = str_repeat('\u{00A0}\u{00A0}\u{00A0}', max(0, $opt['depth'] - 1))
                  . ($opt['depth'] ? '└ ' : '') . $opt['cat']->name;
          }
        @endphp
        <x-tenant.searchable-select name="category_id" :options="$catOpts"
          :selected="old('category_id', $item->category_id) ?? ''"
          any="— Select a category —" noun="categories" :searchable="count($catOpts) >= 12" />
      </div>

      {{-- patch-99 color/size fields --}}
      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Color</label>
          <input type="text" name="color" class="ia-input" maxlength="60" value="{{ old('color', $item->color ?? '') }}" placeholder="Black, Red, Anodized…">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Size</label>
          <input type="text" name="size" class="ia-input" maxlength="60" value="{{ old('size', $item->size ?? '') }}" placeholder="M, 27.2mm, 700x25c…">
        </div>
      </div>

      <div class="ia-form-group">
        <label class="ia-form-label">Description</label>
        <textarea name="description" class="ia-input" rows="3">{{ old('description', $item->description) }}</textarea>
      </div>

    </div>
  </div>

  @if($item->distributor_catalog_id)
    <div class="ia-card" style="margin-bottom:20px;opacity:0.85">
      <div class="ia-card-head">
        <span class="ia-card-title">Catalog data</span>
        <span style="font-size:12px;color:var(--ia-text-muted);margin-left:8px">
          synced from {{ $item->distributorCatalog?->distributor_name ?? 'distributor' }} — not editable here
        </span>
      </div>
      <div class="ia-card-body">
        <table class="ia-key-value">
          <tr><td>Cost</td><td>{{ $item->catalog_cost_cents !== null ? '$' . number_format($item->catalog_cost_cents / 100, 2) : '—' }}</td></tr>
          <tr><td>MSRP</td><td>{{ $item->catalog_msrp_cents !== null ? '$' . number_format($item->catalog_msrp_cents / 100, 2) : '—' }}</td></tr>
          <tr><td>Case quantity</td><td>{{ $item->catalog_case_quantity ?? '—' }}</td></tr>
          <tr><td>UPC</td><td><code>{{ $item->catalog_upc ?? '—' }}</code></td></tr>
          {{-- MARKER-DETAILS-WATCH --}}
          <tr><td>Color</td><td>{{ $item->distributorCatalog?->color ?? '—' }}</td></tr>
          <tr><td>Size</td><td>{{ $item->distributorCatalog?->size ?? '—' }}</td></tr>
          <tr><td>Description</td><td>{{ blank($item->distributorCatalog?->description) ? '—' : \Illuminate\Support\Str::limit($item->distributorCatalog->description, 160) }}</td></tr>
        </table>
      </div>
    </div>
  @endif

  <div class="ia-card" style="margin-bottom:20px;border-left:4px solid var(--ia-accent)">
    <div class="ia-card-head">
      <span class="ia-card-title">Your settings</span>
      <span style="font-size:12px;color:var(--ia-text-muted);margin-left:8px">never overwritten by distributor sync</span>
    </div>
    <div class="ia-card-body">

      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Your cost ($)</label>
          <input type="number" step="0.01" min="0" name="shop_cost_dollars" class="ia-input"
            value="{{ old('shop_cost_dollars', $item->shop_cost_cents !== null ? number_format($item->shop_cost_cents / 100, 2, '.', '') : '') }}">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Sell price ($)</label>
          <input type="number" step="0.01" min="0" name="shop_sell_price_dollars" class="ia-input"
            value="{{ old('shop_sell_price_dollars', $item->shop_sell_price_cents !== null ? number_format($item->shop_sell_price_cents / 100, 2, '.', '') : '') }}">
        </div>
      </div>

      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Reorder when stock hits</label>
          <input type="number" min="0" name="shop_reorder_threshold" class="ia-input"
            value="{{ old('shop_reorder_threshold', $item->shop_reorder_threshold) }}">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Reorder quantity</label>
          <input type="number" min="1" name="shop_reorder_quantity" class="ia-input"
            value="{{ old('shop_reorder_quantity', $item->shop_reorder_quantity) }}">
        </div>
      </div>

      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Bin location</label>
          <input type="text" name="shop_bin_location" class="ia-input"
            value="{{ old('shop_bin_location', $item->shop_bin_location) }}">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Case quantity</label>
          <input type="number" min="1" name="shop_case_quantity" class="ia-input"
            value="{{ old('shop_case_quantity', $item->shop_case_quantity) }}">
        </div>
      </div>

      <div class="ia-form-group">
        <label class="ia-form-label">
          <input type="checkbox" name="allow_oversell" value="1" {{ old('allow_oversell', $item->allow_oversell) ? 'checked' : '' }}>
          Allow selling below zero stock
        </label>
        <div class="ia-form-hint">When on, sales go through even if stock count is 0.</div>
      </div>

      <div class="ia-form-group">
        <label class="ia-form-label">
          <input type="checkbox" name="is_active" value="1" {{ old('is_active', $item->is_active) ? 'checked' : '' }}>
          Active
        </label>
        <div class="ia-form-hint">Inactive items don't show on the register or in reports.</div>
      </div>

    </div>
  </div>

  {{-- MARKER-ARCHIVE-MOVE — Archive lives on the item page now. A
       destructive control next to Save is what turned a save into a delete. --}}
  @include('tenant.inventory._sources')

  <div style="display:flex;gap:8px;justify-content:flex-end;align-items:center">
    <a href="{{ route('tenant.inventory.show', $item->id) }}" class="ia-btn ia-btn--ghost">Cancel</a>
    <button type="submit" class="ia-btn ia-btn--primary">Save changes</button>
  </div>
</form>

@endsection
