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

      {{-- MARKER-ITEM-IDENT-ENTRY — these three are what link an item to a
           distributor catalog. Without them a hand-entered item matches
           nothing, never updates its cost, and cannot be reordered. --}}
            {{-- MARKER-ITEM-IDENT-ENTRY — look it up before typing it. Picking a row
           fills the fields below AND links the item, so it behaves like an
           imported one from then on: cost updates, rename flags, reordering. --}}
      <div class="ia-form-group" id="cat-lookup-wrap">
        <label class="ia-form-label">Find it in a distributor catalog</label>
        <input type="text" id="cat-lookup" class="ia-input"
               placeholder="Scan a barcode, or type a name — e.g. Hans Dampf 29 x 2.6"
               autocomplete="off">
        <div class="ia-form-hint" id="cat-lookup-hint">
          Searches the distributors your shop is connected to. Optional — fill the form in by
          hand for anything they do not carry.
        </div>
        <div id="cat-lookup-results" style="display:none;margin-top:8px;border:0.5px solid var(--ia-border);border-radius:6px;max-height:280px;overflow:auto"></div>
        <input type="hidden" name="distributor_catalog_id" id="cat-chosen-id" value="">
        <div id="cat-chosen" style="display:none;margin-top:8px;padding:8px 10px;border:0.5px solid var(--ia-accent);border-radius:6px;font-size:12.5px">
          <span id="cat-chosen-label"></span>
          <button type="button" id="cat-clear" class="ia-btn ia-btn--sm" style="margin-left:8px">Unlink</button>
        </div>
      </div>

      <div class="ia-form-row">
        <div class="ia-form-group">
          <label class="ia-form-label">Barcode (UPC)</label>
          <input type="text" name="catalog_upc" class="ia-input ia-scan-field"
                 value="{{ old('catalog_upc') }}" placeholder="Scan or type">
          <div class="ia-form-hint">The number under the barcode. Not the same as your SKU.</div>
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">EAN</label>
          <input type="text" name="catalog_ean" class="ia-input ia-scan-field"
                 value="{{ old('catalog_ean') }}" placeholder="13-digit, if different">
        </div>
      </div>

      <div class="ia-form-row">
        <div class="ia-form-group">
          <label class="ia-form-label">Manufacturer part number</label>
          <input type="text" name="catalog_mpn" class="ia-input ia-scan-field"
                 value="{{ old('catalog_mpn') }}" placeholder="e.g. TR00641">
          <div class="ia-form-hint">The maker's own code, if you have it.</div>
        </div>
        <div class="ia-form-group"></div>
      </div>

      <div class="ia-form-group">
        <label class="ia-form-label">Category <span class="ia-required">*</span></label>
        {{-- MARKER-SSEL-CATS — see edit.blade.php --}}
        @php
          $catOpts = [];
          foreach ($categories as $opt) {
              // MARKER-CAT-DEPTH-INDENT — one marker per level, so a grandchild
              // reads as a grandchild instead of a sibling of its parent.
              $catOpts[$opt['cat']->id] = str_repeat("\u{00A0}\u{00A0}\u{00A0}", max(0, $opt['depth'] - 1))
                  . ($opt['depth'] ? '└ ' : '') . $opt['cat']->name;
          }
        @endphp
        <x-tenant.searchable-select name="category_id" :options="$catOpts"
          :selected="old('category_id') ?? ''"
          any="— Select a category —" noun="categories" :searchable="count($catOpts) >= 12" />
        
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

@push('scripts')
<script>
// MARKER-ITEM-IDENT-ENTRY — barcode scanners send Enter the moment they finish
// reading. On a form that submits on Enter this saves a half-filled item and
// clears the screen, which is why WMM had been typing everything else FIRST and
// scanning last. These fields swallow it and move on instead.
document.querySelectorAll('.ia-scan-field').forEach(function (el) {
  el.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') { return; }
    e.preventDefault();
    var fields = Array.prototype.slice.call(document.querySelectorAll('input, select, textarea'));
    var i = fields.indexOf(el);
    if (i > -1 && fields[i + 1]) { fields[i + 1].focus(); }
  });
});

(function () {
  var box     = document.getElementById('cat-lookup');
  var results = document.getElementById('cat-lookup-results');
  var hint    = document.getElementById('cat-lookup-hint');
  var chosen  = document.getElementById('cat-chosen');
  var label   = document.getElementById('cat-chosen-label');
  var hidden  = document.getElementById('cat-chosen-id');
  if (!box) { return; }

  var timer = null;
  var rows  = [];

  function val(name) { return document.querySelector('[name="' + name + '"]'); }

  function setIfEmpty(name, v) {
    var el = val(name);
    if (el && v !== null && v !== undefined && v !== '' && !el.value) { el.value = v; }
  }

  box.addEventListener('keydown', function (e) {
    // A scanner firing Enter here must search, never submit the form.
    if (e.key === 'Enter') { e.preventDefault(); run(); }
  });

  box.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(run, 250);
  });

  function run() {
    var q = box.value.trim();
    if (q.length < 3) { results.style.display = 'none'; return; }

    fetch('{{ route('tenant.inventory.catalog-lookup') }}?q=' + encodeURIComponent(q), {
      headers: { 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        rows = data.rows || [];

        if (data.note === 'no_subscriptions') {
          hint.textContent = 'Your shop is not connected to any distributor catalogs yet, so there is nothing to search. Fill the form in by hand.';
          results.style.display = 'none';
          return;
        }
        if (!rows.length) {
          results.innerHTML = '<div style="padding:10px 12px;font-size:12.5px;color:var(--ia-text-dim)">Nothing matched. Fill the form in by hand.</div>';
          results.style.display = '';
          return;
        }

        results.innerHTML = rows.map(function (r, i) {
          var bits = [r.manufacturer, r.product_key, r.upc || r.ean].filter(Boolean).join(' · ');
          return '<button type="button" data-i="' + i + '" style="display:block;width:100%;text-align:left;background:none;border:0;border-bottom:0.5px solid var(--ia-border);padding:9px 12px;color:var(--ia-text);cursor:pointer;font-family:inherit">'
            + '<div style="font-size:13px">' + escapeHtml(r.name || '') + '</div>'
            + '<div style="font-size:11.5px;color:var(--ia-text-dim)">' + escapeHtml(r.distributor + ' · ' + bits) + '</div>'
            + '</button>';
        }).join('');
        results.style.display = '';
      })
      .catch(function () {
        results.innerHTML = '<div style="padding:10px 12px;font-size:12.5px;color:var(--ia-text-dim)">Lookup failed. Fill the form in by hand.</div>';
        results.style.display = '';
      });
  }

  results.addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-i]');
    if (!btn) { return; }
    var r = rows[parseInt(btn.dataset.i, 10)];
    if (!r) { return; }

    // Only ever fills blanks — anything already typed is the shop's own choice.
    setIfEmpty('name', r.name);
    setIfEmpty('sku', r.product_key);
    setIfEmpty('catalog_upc', r.upc);
    setIfEmpty('catalog_ean', r.ean);
    setIfEmpty('catalog_mpn', r.mpn);
    setIfEmpty('color', r.color);
    setIfEmpty('size', r.size);
    setIfEmpty('shop_cost_dollars', r.cost);
    setIfEmpty('shop_sell_price_dollars', r.map || r.msrp);
    setIfEmpty('shop_case_quantity', r.case_qty);

    hidden.value = r.id;
    label.textContent = 'Linked to ' + r.distributor + ' · ' + (r.name || '');
    chosen.style.display = '';
    results.style.display = 'none';
    box.value = '';
  });

  document.getElementById('cat-clear').addEventListener('click', function () {
    hidden.value = '';
    chosen.style.display = 'none';
  });

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
})();
</script>
@endpush
