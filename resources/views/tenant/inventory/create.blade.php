@extends('layouts.tenant.app')
@php $pageTitle = 'New inventory item'; @endphp

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">New inventory item</h1>
    <p class="ia-page-subtitle">
      <a href="{{ route('tenant.inventory.index') }}">← Back to inventory</a>
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

<form method="POST" action="{{ route('tenant.inventory.store') }}">
  @csrf

  <div class="ia-card" style="margin-bottom:20px">
    <div class="ia-card-head"><span class="ia-card-title">Item details</span></div>
    <div class="ia-card-body">

      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Name <span class="ia-required">*</span></label>
          <input type="text" name="name" class="ia-input" required value="{{ old('name') }}">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">SKU <span class="ia-required">*</span></label>
          <input type="text" name="sku" class="ia-input" required value="{{ old('sku') }}"
            placeholder="e.g. CHN-105-11">
          <div class="ia-form-hint">Must be unique within your shop.</div>
        </div>
      </div>

      <div class="ia-form-group">
        <label class="ia-form-label">Category <span class="ia-required">*</span></label>
        <select name="category_id" class="ia-input" required>
          {{-- MARKER-CAT-PLACEHOLDER — a new item starts uncategorised, not in
               whichever category happens to sort first. --}}
          <option value="" @selected(old('category_id') === null)>— Select a category —</option>
          <option value="">Select category…</option>
          {{-- MARKER-ITEM-CAT-TREE — children indented under their parent, matching
               the index filter. A flat A-Z list put children rows above their own
               parents. --}}
          @foreach($categories as $opt)
            <option value="{{ $opt['cat']->id }}" @selected(old('category_id') === $opt['cat']->id)>{{ $opt['depth'] ? '   └ ' : '' }}{{ $opt['cat']->name }}</option>
          @endforeach
        </select>
      </div>

      {{-- patch-99 color/size fields --}}
      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Color</label>
          <input type="text" name="color" class="ia-input" maxlength="60" value="{{ old('color') }}" placeholder="Black, Red, Anodized…">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Size</label>
          <input type="text" name="size" class="ia-input" maxlength="60" value="{{ old('size') }}" placeholder="M, 27.2mm, 700x25c…">
        </div>
      </div>

      <div class="ia-form-group">
        <label class="ia-form-label">Description</label>
        <textarea name="description" class="ia-input" rows="3">{{ old('description') }}</textarea>
      </div>

    </div>
  </div>

  <div class="ia-card" style="margin-bottom:20px;border-left:4px solid var(--ia-accent)">
    <div class="ia-card-head">
      <span class="ia-card-title">Your settings</span>
      <span style="font-size:12px;color:var(--ia-text-muted);margin-left:8px">Never overwritten by distributor sync</span>
    </div>
    <div class="ia-card-body">

      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Your cost ($)</label>
          <input type="number" step="0.01" min="0" name="shop_cost_dollars" class="ia-input" value="{{ old('shop_cost_dollars') }}">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Sell price ($)</label>
          <input type="number" step="0.01" min="0" name="shop_sell_price_dollars" class="ia-input" value="{{ old('shop_sell_price_dollars') }}">
        </div>
      </div>

      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Reorder when stock hits</label>
          <input type="number" min="0" name="shop_reorder_threshold" class="ia-input" value="{{ old('shop_reorder_threshold') }}">
          <div class="ia-form-hint">Triggers a low-stock alert.</div>
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Reorder quantity</label>
          <input type="number" min="1" name="shop_reorder_quantity" class="ia-input" value="{{ old('shop_reorder_quantity') }}">
          <div class="ia-form-hint">How many to order when reordering.</div>
        </div>
      </div>

      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Bin location</label>
          <input type="text" name="shop_bin_location" class="ia-input" value="{{ old('shop_bin_location') }}"
            placeholder="e.g. A-3-2">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Case quantity</label>
          <input type="number" min="1" name="shop_case_quantity" class="ia-input" value="{{ old('shop_case_quantity') }}">
          <div class="ia-form-hint">Units per case from your distributor.</div>
        </div>
      </div>

      <div class="ia-form-group">
        <label class="ia-form-label">
          <input type="checkbox" name="allow_oversell" value="1" {{ old('allow_oversell', '1') === '1' ? 'checked' : '' }}>
          Allow selling below zero stock (recommended)
        </label>
        <div class="ia-form-hint">When on, sales go through even if stock count is 0. We log oversold sales for you to reconcile later.</div>
      </div>

    </div>
  </div>

  <div class="ia-card" style="margin-bottom:20px">
    <div class="ia-card-head"><span class="ia-card-title">Starting stock (optional)</span></div>
    <div class="ia-card-body">
      <div class="ia-form-group">
        <label class="ia-form-label">How many do you have on hand right now?</label>
        <input type="number" min="0" name="initial_stock" class="ia-input" value="{{ old('initial_stock', 0) }}" style="max-width:200px">
        <div class="ia-form-hint">Records this as the initial stock at your default location. You can adjust per-location after creating the item.</div>
      </div>
    </div>
  </div>

  @include('tenant.inventory._sources')

  <div style="display:flex;gap:8px;justify-content:flex-end">
    <a href="{{ route('tenant.inventory.index') }}" class="ia-btn ia-btn--ghost">Cancel</a>
    <button type="submit" class="ia-btn ia-btn--primary">Create item</button>
  </div>
</form>

@endsection
