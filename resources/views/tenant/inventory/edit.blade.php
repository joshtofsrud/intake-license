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

{{-- the Archive form used to be nested INSIDE this
     one. Nested forms are invalid HTML and the inner submit can post to the
     outer action. The two are siblings now; Save reaches this form by id. --}}
<form method="POST" action="{{ route('tenant.inventory.update', $item->id) }}"
      data-identify-url="{{ route('tenant.inventory.identify') }}" data-identify-mode="edit" data-identify-except="{{ $item->id }}">
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
          <div style="display:flex;gap:8px">
            <input type="text" name="sku" class="ia-input" required value="{{ old('sku', $item->sku) }}" style="flex:1;min-width:0">
            <button type="button" class="ia-btn ia-btn--secondary ia-btn--sm" data-sku-generate="{{ route('tenant.inventory.sku.next') }}" title="Replace with the next SKU on your prefix">Generate</button>
          </div>
        </div>
      </div>

      {{-- these three are what link an item to a
           distributor catalog. Without them a hand-entered item matches
           nothing, never updates its cost, and cannot be reordered. --}}
      <div class="ia-form-row">
        <div class="ia-form-group">
          <label class="ia-form-label">Barcode (UPC)</label>
          <input type="text" name="catalog_upc" class="ia-input ia-scan-field"
                 value="{{ old('catalog_upc', $item->catalog_upc) }}" placeholder="Scan or type">
          <div class="ia-form-hint">The number under the barcode. Not the same as your SKU.</div>
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">EAN</label>
          <input type="text" name="catalog_ean" class="ia-input ia-scan-field"
                 value="{{ old('catalog_ean', $item->catalog_ean) }}" placeholder="If different from the barcode">
        </div>
      </div>

      <div class="ia-form-row">
        <div class="ia-form-group">
          <label class="ia-form-label">Manufacturer part number</label>
          <input type="text" name="catalog_mpn" class="ia-input ia-scan-field"
                 value="{{ old('catalog_mpn', $item->catalog_mpn) }}" placeholder="Supplier or maker code">
          <div class="ia-form-hint">The maker's own code, if you have it.</div>
        </div>
        <div class="ia-form-group"></div>
      </div>

      <div class="ia-form-group">
        <label class="ia-form-label">Category <span class="ia-required">*</span></label>
        {{-- our picker, not a native select: macOS draws
             the native popup itself and ignored two attempts to make it dark,
             so a tenant on a light-mode machine got white on white. This one
             renders in our CSS. `required` is enforced server-side. --}}
        @php
          $catOpts = [];
          foreach ($categories as $opt) {
              // one marker per level, so a grandchild
              // reads as a grandchild instead of a sibling of its parent.
              $catOpts[$opt['cat']->id] = str_repeat("\u{00A0}\u{00A0}\u{00A0}", max(0, $opt['depth'] - 1))
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
          <input type="text" name="color" class="ia-input" maxlength="60" value="{{ old('color', $item->color ?? '') }}" placeholder="Color or finish">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Size</label>
          <input type="text" name="size" class="ia-input" maxlength="60" value="{{ old('size', $item->size ?? '') }}" placeholder="Size or dimensions">
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

      {{-- MARKER-SHIPPING-RATES --}}
      @php
        $spec = \App\Services\Shipping\ItemShipSpec::for($item);
        $shopOz = $item->shop_weight_oz !== null ? (float) $item->shop_weight_oz : null;
        $catOz  = $spec['weight_from'] === 'catalog' ? $spec['oz'] : null;
      @endphp
      <div class="ia-form-group">
        <label class="ia-form-label">Shipping weight</label>
        <div class="ia-input-grid-2">
          <input type="number" min="0" step="1" name="ship_weight_lb" class="ia-input" placeholder="lb"
            value="{{ old('ship_weight_lb', $shopOz !== null ? (int) floor($shopOz / 16) : '') }}">
          <input type="number" min="0" max="15.99" step="0.01" name="ship_weight_oz" class="ia-input" placeholder="oz"
            value="{{ old('ship_weight_oz', $shopOz !== null ? round(fmod($shopOz, 16), 2) : '') }}">
        </div>
        <label class="ia-form-label" style="margin-top:10px">Packed size (inches)</label>
        <div class="ia-input-grid-2" style="grid-template-columns:repeat(3,1fr)">
          <input type="number" min="0" step="0.01" name="shop_length_in" class="ia-input" placeholder="Length" value="{{ old('shop_length_in', $item->shop_length_in) }}">
          <input type="number" min="0" step="0.01" name="shop_width_in" class="ia-input" placeholder="Width" value="{{ old('shop_width_in', $item->shop_width_in) }}">
          <input type="number" min="0" step="0.01" name="shop_height_in" class="ia-input" placeholder="Height" value="{{ old('shop_height_in', $item->shop_height_in) }}">
        </div>
        <div class="ia-form-hint">
          Used for shipping rates on online orders. Leave blank to use
          @if($spec['weight_from'] === 'catalog' || $spec['size_from'] === 'catalog')
            the supplier's figure{{ $catOz ? ' (' . number_format($catOz / 16, 2) . ' lb' . ($spec['size_from'] === 'catalog' ? ', ' . $spec['l'] . ' × ' . $spec['w'] . ' × ' . $spec['h'] . ' in' : '') . ')' : '' }}.
          @else
            your default shipping weight (set under E-commerce settings).
          @endif
        </div>
      </div>

      {{-- MARKER-ITEM-SEO --}}
      @php
        $seoBrand = $item->shop_brand ?: $item->distributorCatalog?->manufacturer;
        $seoAutoTitle = \App\Support\ItemSeo::title(null, (string) $item->name, $seoBrand);
        $seoAutoDesc  = \App\Support\ItemSeo::description(null, $item->description, $item->display_subtitle, (string) $item->name, $seoBrand, (string) $currentTenant->name);
      @endphp
      <div class="ia-form-group">
        <label class="ia-form-label">Search &amp; sharing</label>
        <input type="text" name="seo_title" id="seo-title" class="ia-input" maxlength="120" placeholder="{{ $seoAutoTitle }}" value="{{ old('seo_title', $item->seo_title) }}">
        <textarea name="seo_description" id="seo-desc" class="ia-textarea" rows="2" maxlength="300" style="margin-top:8px" placeholder="{{ $seoAutoDesc }}">{{ old('seo_description', $item->seo_description) }}</textarea>
        <div class="ia-form-hint">
          Only used for items shown online. This is the headline and the sentence Google and link previews (text messages, Facebook, email) show for this item's page.
          Leave both blank to use the item's name and description. The item's first photo is the preview picture.
        </div>
        <input type="text" name="google_category" class="ia-input" maxlength="300" style="margin-top:8px" placeholder="Google product category (blank = this item's category)" value="{{ old('google_category', $item->google_category) }}">
        <label style="display:flex;gap:8px;align-items:center;margin-top:8px;font-size:13px"><input type="checkbox" name="feed_exclude" value="1" {{ old('feed_exclude', $item->feed_exclude) ? 'checked' : '' }}> Leave out of the Google feed</label>
        <div id="seo-prev" style="margin-top:8px;border:.5px solid var(--ia-border);border-radius:10px;padding:10px 12px;font-size:12.5px">
          <div style="color:var(--ia-text-muted);font-size:11px">What it looks like in search</div>
          <div id="seo-prev-t" style="font-weight:600;margin-top:3px"></div>
          <div id="seo-prev-d" style="color:var(--ia-text-muted);margin-top:2px"></div>
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

  {{-- Archive lives on the item page now. A
       destructive control next to Save is what turned a save into a delete. --}}
  @include('tenant.inventory._sources')

  <div style="display:flex;gap:8px;justify-content:flex-end;align-items:center">
    <a href="{{ route('tenant.inventory.show', $item->id) }}" class="ia-btn ia-btn--ghost">Cancel</a>
    <button type="submit" class="ia-btn ia-btn--primary">Save changes</button>
  </div>
</form>

@endsection

@push('scripts')
<script>
(function () {
  var t = document.getElementById('seo-title'), d = document.getElementById('seo-desc');
  if (!t || !d) return;
  var shop = @json((string) $currentTenant->name);
  function cut(s, n) { return s.length <= n ? s : s.slice(0, n - 1).replace(/\s+\S*$/, '') + '…'; }
  function paint() {
    document.getElementById('seo-prev-t').textContent = (t.value.trim() || t.placeholder) + ' — ' + shop;
    document.getElementById('seo-prev-d').textContent = cut(d.value.trim() || d.placeholder, 160);
  }
  t.addEventListener('input', paint); d.addEventListener('input', paint); paint();
})();
</script>
<script src="{{ asset('js/tenant/item-identify.js') }}?v=2"></script>
<script>
// see create.blade.php. A scanner's Enter moves on
// instead of submitting a half-edited item.
document.querySelectorAll('.ia-scan-field').forEach(function (el) {
  el.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') { return; }
    e.preventDefault();
    var fields = Array.prototype.slice.call(document.querySelectorAll('input, select, textarea'));
    var i = fields.indexOf(el);
    if (i > -1 && fields[i + 1]) { fields[i + 1].focus(); }
  });
});
</script>
@endpush
