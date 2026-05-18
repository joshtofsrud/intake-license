@extends('layouts.tenant.app')
@php
  $pageTitle = 'Inventory';
  $sortLabels = [
    'name_asc'   => 'Name A–Z',
    'name_desc'  => 'Name Z–A',
    'sku_asc'    => 'SKU A–Z',
    'sku_desc'   => 'SKU Z–A',
    'stock_asc'  => 'Stock low → high',
    'stock_desc' => 'Stock high → low',
  ];
  $stockLabels = [
    ''     => 'All stock levels',
    'low'  => 'Low stock only',
    'out'  => 'Out of stock only',
  ];
@endphp


@push('styles')
<style>
/* Inventory mobile list (patch #38) — scoped via .inv- prefix.
   Desktop ia-table stays. Mobile shows .inv-mobile via display swap. */
.inv-mobile{display:none}
.inv-mobile-list{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);overflow:hidden}
.inv-row-m{padding:14px 16px;border-bottom:0.5px solid var(--ia-border);display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit;transition:background var(--ia-t)}
.inv-row-m:last-child{border-bottom:none}
.inv-row-m:active{background:var(--ia-hover)}
.inv-dot{width:8px;height:8px;border-radius:50%;background:var(--ia-accent);flex-shrink:0}
.inv-dot.low{background:#FAB46A}
.inv-dot.out{background:#F47373}
.inv-identity-m{min-width:0;flex:1}
.inv-name-m{font-size:14.5px;font-weight:500;color:var(--ia-text);line-height:1.25;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.inv-meta-m{font-size:11.5px;color:var(--ia-text-muted);margin-top:2px;line-height:1.3;display:flex;gap:6px;flex-wrap:wrap}
.inv-sku-m{font-family:ui-monospace,monospace;font-size:11px;color:var(--ia-text-dim,rgba(255,255,255,.38))}
.inv-right-m{text-align:right;flex-shrink:0;min-width:64px}
.inv-stock-m{font-size:17px;font-weight:600;color:var(--ia-text);font-variant-numeric:tabular-nums;line-height:1}
.inv-stock-m.low{color:#FAB46A}
.inv-stock-m.out{color:#F47373}
.inv-price-m{font-size:11.5px;color:var(--ia-text-muted);font-variant-numeric:tabular-nums;margin-top:4px}

/* Page-head: stack on mobile, icon-button row right */
.inv-head-m{display:none}
.inv-actions-m{display:flex;gap:6px;align-items:center}
.inv-icon-btn-m{width:36px;height:36px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;background:var(--ia-surface);border:0.5px solid var(--ia-border);color:var(--ia-text-muted);text-decoration:none;font-family:inherit;font-size:16px;cursor:pointer}
.inv-icon-btn-m.primary{background:var(--ia-accent);color:#000;border-color:var(--ia-accent);font-weight:600}

/* Toolbar (search + filter sheet trigger) */
.inv-tb-m{display:none;gap:8px;margin-bottom:12px;align-items:center}
.inv-search-m{flex:1;position:relative}
.inv-search-m input{width:100%;padding:10px 12px 10px 36px;background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:10px;color:var(--ia-text);font-size:14px;font-family:inherit;outline:none}
.inv-search-icon-m{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--ia-text-dim,rgba(255,255,255,.38));pointer-events:none}
.inv-filter-m{width:40px;height:40px;border-radius:10px;background:var(--ia-surface);border:0.5px solid var(--ia-border);color:var(--ia-text-muted);display:inline-flex;align-items:center;justify-content:center;position:relative;cursor:pointer;font-family:inherit}
.inv-filter-m.has-dot::after{content:'';position:absolute;top:7px;right:7px;width:7px;height:7px;background:var(--ia-accent);border-radius:50%}

/* Active filter chips */
.inv-chips-m{display:none;gap:6px;margin-bottom:12px;overflow-x:auto;scrollbar-width:none;padding-bottom:2px}
.inv-chips-m::-webkit-scrollbar{display:none}
.inv-chip-m{flex-shrink:0;padding:5px 11px;border-radius:999px;background:var(--ia-surface);border:0.5px solid var(--ia-border);color:var(--ia-text);font-size:12px;display:inline-flex;align-items:center;gap:4px;text-decoration:none;font-family:inherit}
.inv-chip-m.muted{color:var(--ia-text-muted)}
.inv-chip-m .x{opacity:.6;padding-left:2px}

/* Filter sheet */
.inv-sheet-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:90;opacity:0;pointer-events:none;transition:opacity .15s}
.inv-sheet-overlay.is-open{opacity:1;pointer-events:all}
.inv-sheet{display:none;position:fixed;bottom:0;left:0;right:0;background:var(--ia-bg,#0a0a0a);border-radius:18px 18px 0 0;padding:12px 16px calc(20px + env(safe-area-inset-bottom, 0px));z-index:91;border-top:0.5px solid var(--ia-border);transform:translateY(100%);transition:transform .2s ease;max-height:80%;overflow-y:auto}
.inv-sheet.is-open{transform:translateY(0)}
.inv-sheet-handle{width:36px;height:4px;border-radius:2px;background:rgba(255,255,255,.2);margin:0 auto 14px}
.inv-sheet-title{font-size:16px;font-weight:600;margin-bottom:16px;color:var(--ia-text)}
.inv-sheet-group{margin-bottom:18px}
.inv-sheet-group-label{font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--ia-text-muted);font-weight:500;margin-bottom:8px}
.inv-sheet-options{display:flex;flex-wrap:wrap;gap:6px}
.inv-sheet-option{padding:8px 14px;border-radius:8px;background:var(--ia-surface);border:0.5px solid var(--ia-border);color:var(--ia-text);font-size:13px;cursor:pointer;font-family:inherit}
.inv-sheet-option.active{background:var(--ia-accent);color:#000;border-color:var(--ia-accent)}
.inv-sheet-primary{width:100%;padding:14px;background:var(--ia-accent);color:#000;border:none;border-radius:var(--ia-r-md);font-size:15px;font-weight:600;cursor:pointer;font-family:inherit;margin-top:4px}
.inv-sheet-secondary{width:100%;padding:12px;background:transparent;color:var(--ia-text-muted);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);font-size:14px;margin-top:8px;cursor:pointer;font-family:inherit;text-align:center;text-decoration:none;display:block}

@media(max-width:640px){
  /* Hide desktop chrome */
  .ia-toolbar,
  .ia-table-wrap{display:none !important}
  /* Hide the default ia-page-actions row that has 2 desktop buttons */
  .ia-page-head .ia-page-actions{display:none}
  /* Show mobile head row + toolbar + chip strip + card list */
  .inv-head-m{display:flex}
  .inv-tb-m{display:flex}
  .inv-chips-m{display:flex}
  .inv-mobile{display:block}
  .inv-sheet-overlay,
  .inv-sheet{display:block}
  /* Hide the desktop table wrapper on mobile so its empty .ia-card
     shell doesn't render between the search bar and the mobile cards. */
  .inv-desk-card{display:none}
}
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Inventory</h1>
    <p class="ia-page-subtitle">{{ number_format($total) }} {{ Str::plural('item', $total) }}</p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.inventory.receiving.index') }}" class="ia-btn">Receiving ↓</a>
    @if($hasCategories)
      <a href="{{ route('tenant.inventory.create') }}" class="ia-btn ia-btn--primary">+ New item</a>
    @else
      <a href="{{ route('tenant.inventory.categories.index') }}" class="ia-btn ia-btn--primary">Set up categories</a>
    @endif
  </div>
  {{-- Mobile-only action row (right-aligned icon buttons). --}}
  <div class="inv-head-m inv-actions-m" style="margin-left:auto">
    <a href="{{ route('tenant.inventory.receiving.index') }}" class="inv-icon-btn-m" title="Receiving" aria-label="Receiving">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.27 6.96L12 12.01l8.73-5.05"/><path d="M12 22.08V12"/></svg>
    </a>
    @if($hasCategories)
      <a href="{{ route('tenant.inventory.create') }}" class="inv-icon-btn-m primary" title="New item" aria-label="New item">+</a>
    @else
      <a href="{{ route('tenant.inventory.categories.index') }}" class="inv-icon-btn-m primary" title="Set up categories" aria-label="Set up categories">+</a>
    @endif
  </div>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--{{ session('flash')['type'] }}">{{ session('flash')['message'] }}</div>
@endif

{{-- POS inventory cap banner.
     Renders only for tenants without the `pos` capability (typically
     Branded plans that haven't added the POS module). Starter tenants
     never see inventory at all (blocked upstream by RequireRetailCapability).
     The banner surfaces friction at the add point; existing items above
     the cap are still fully usable. --}}
@if(!empty($posCap) && !$posCap['pos_enabled'])
  @php
    $atCap = $posCap['at_or_over'];
    $remaining = $posCap['remaining'];
  @endphp
  <div class="ia-card" style="border-left:3px solid {{ $atCap ? '#F59E0B' : 'var(--ia-border-strong)' }}; margin-bottom:20px; background:{{ $atCap ? 'rgba(245,158,11,0.04)' : 'transparent' }}">
    <div class="ia-card-body" style="display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap">
      <div>
        @if($atCap)
          <strong>You're at the inventory cap on your current plan.</strong>
          <span style="color:var(--ia-text-muted)">
            {{ number_format($posCap['item_count']) }} of {{ $posCap['cap'] }} items used.
            Add the POS add-on for unlimited inventory. Existing items keep working — edit, restock, and ring them as usual.
          </span>
        @else
          <strong>{{ number_format($posCap['item_count']) }} / {{ $posCap['cap'] }} items used</strong>
          <span style="color:var(--ia-text-muted)">
            · {{ $remaining }} {{ Str::plural('slot', $remaining) }} left on your current plan. Add the POS add-on for unlimited inventory.
          </span>
        @endif
      </div>
      <div>
        <a href="{{ route('tenant.feature_addons.index') }}" class="ia-btn ia-btn--primary ia-btn--sm">Upgrade to POS</a>
      </div>
    </div>
  </div>
@endif

@if(!$hasCategories)
  <div class="ia-card" style="border-left: 4px solid var(--ia-accent); margin-bottom: 20px">
    <div class="ia-card-body">
      <strong>Get started:</strong> Create at least one category before adding items. Categories help you organize and filter your inventory — Drivetrain, Tubes, Lubes, Tools, etc.
    </div>
  </div>
@else

<form method="get" action="{{ route('tenant.inventory.index') }}" class="ia-toolbar">
  <input type="search" name="s" class="ia-input" value="{{ $search }}"
    placeholder="Search name, SKU, or UPC…" style="max-width:300px">

  <select name="category" class="ia-input" style="width:auto">
    <option value="">All categories</option>
    @foreach($categories as $cat)
      <option value="{{ $cat->id }}" @selected($category === $cat->id)>{{ $cat->name }}</option>
    @endforeach
  </select>

  <select name="stock" class="ia-input" style="width:auto">
    @foreach($stockLabels as $val => $label)
      <option value="{{ $val }}" @selected($stock === $val)>{{ $label }}</option>
    @endforeach
  </select>

  <select name="sort" class="ia-input" style="width:auto">
    @foreach($sortLabels as $val => $label)
      <option value="{{ $val }}" @selected($sort === $val)>{{ $label }}</option>
    @endforeach
  </select>

  <button type="submit" class="ia-btn ia-btn--secondary">Filter</button>
  @if($search || $category || $stock || $sort !== 'name_asc')
    <a href="{{ route('tenant.inventory.index') }}" class="ia-btn ia-btn--ghost">Reset</a>
  @endif
</form>

{{-- Mobile toolbar — search + filter-sheet trigger.
     Same URL params as the desktop form. Search submits on Enter; the filter
     button opens the sheet which submits a form with category/stock/sort. --}}
<form method="get" action="{{ route('tenant.inventory.index') }}" class="inv-tb-m" id="inv-mobile-search-form">
  <input type="hidden" name="category" value="{{ $category }}">
  <input type="hidden" name="stock" value="{{ $stock }}">
  <input type="hidden" name="sort" value="{{ $sort }}">
  <div class="inv-search-m">
    <svg class="inv-search-icon-m" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35"/></svg>
    <input type="search" name="s" value="{{ $search }}" placeholder="Search name, SKU, or UPC…">
  </div>
  @php
    $hasActiveFilters = ($category || $stock || $sort !== 'name_asc');
  @endphp
  <button type="button" class="inv-filter-m {{ $hasActiveFilters ? 'has-dot' : '' }}" onclick="invOpenSheet()" aria-label="Filter">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/></svg>
  </button>
</form>

{{-- Active filter chip strip (mobile). Shows applied filters with a tap-to-clear
     × link for each. Chips link back to the current URL minus that one param. --}}
@if($hasActiveFilters || $search)
  <div class="inv-chips-m">
    @if($category)
      @php $catName = $categories->firstWhere('id', $category)?->name ?? 'Category'; @endphp
      <a href="{{ route('tenant.inventory.index', array_filter(['s'=>$search,'stock'=>$stock,'sort'=>$sort!=='name_asc'?$sort:null])) }}" class="inv-chip-m">{{ $catName }} <span class="x">×</span></a>
    @endif
    @if($stock)
      <a href="{{ route('tenant.inventory.index', array_filter(['s'=>$search,'category'=>$category,'sort'=>$sort!=='name_asc'?$sort:null])) }}" class="inv-chip-m">{{ $stockLabels[$stock] ?? $stock }} <span class="x">×</span></a>
    @endif
    @if($sort !== 'name_asc')
      <a href="{{ route('tenant.inventory.index', array_filter(['s'=>$search,'category'=>$category,'stock'=>$stock])) }}" class="inv-chip-m">{{ $sortLabels[$sort] ?? $sort }} <span class="x">×</span></a>
    @endif
    <button type="button" class="inv-chip-m muted" onclick="invOpenSheet()">+ Add filter</button>
  </div>
@endif

<div class="ia-card inv-desk-card">
  @if($items->isEmpty())
    <div class="ia-card-body" style="text-align:center;padding:40px 20px;color:var(--ia-text-muted)">
      No items match your filters.
    </div>
  @else
<div class="ia-table-wrap">
    <table class="ia-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>SKU</th>
          <th>Category</th>
          <th style="text-align:right">Stock</th>
          <th style="text-align:right">Price</th>
          <th style="text-align:right">Cost</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @foreach($items as $item)
          @include('tenant.inventory._partials.item-card', ['item' => $item])
        @endforeach
      </tbody>
    </table>
</div>
  @endif
</div>

{{-- Mobile card list (≤640px). Same data, different shape. --}}
<div class="inv-mobile">
  @if($items->isEmpty())
    <div class="inv-mobile-list" style="padding:40px 20px;text-align:center;color:var(--ia-text-muted);font-size:13px">
      No items match your filters.
    </div>
  @else
    <div class="inv-mobile-list">
      @foreach($items as $item)
        @php
          // patch-98 mobile per-location
          $totalStock = (int) $item->computed_stock_count;
          $stockCount = ($hereStocks ?? null) && array_key_exists($item->id, $hereStocks)
                          ? (int) $hereStocks[$item->id]
                          : $totalStock;
          $threshold  = $item->shop_reorder_threshold;
          $isLow  = $threshold !== null && $stockCount > 0 && $stockCount <= $threshold;
          $isOut  = $stockCount <= 0;
          $dotCls = $isOut ? 'out' : ($isLow ? 'low' : '');
          $sellPrice = $item->effectiveSellPriceCents();
          $showTotal = ($isMultiLocation ?? false) && $totalStock !== $stockCount;
        @endphp
        <a href="{{ route('tenant.inventory.show', $item->id) }}" class="inv-row-m">
          <div class="inv-dot {{ $dotCls }}"></div>
          <div class="inv-identity-m">
            <div class="inv-name-m">{{ $item->name }}</div>
            <div class="inv-meta-m">
              <span class="inv-sku-m">{{ $item->sku }}</span>
              @if($item->category)
                <span>· {{ $item->category->name }}</span>
              @endif
              @if($item->shop_bin_location)
                <span>· Bin {{ $item->shop_bin_location }}</span>
              @endif
            </div>
          </div>
          <div class="inv-right-m">
            <div class="inv-stock-m {{ $dotCls }}">{{ $stockCount }}</div>
            @if($showTotal)
              <div style="font-size:10.5px;color:var(--ia-text-muted);margin-top:1px">{{ $totalStock }} total</div>
            @endif
            <div class="inv-price-m">{{ $sellPrice !== null ? '$' . number_format($sellPrice / 100, 2) : '—' }}</div>
          </div>
        </a>
      @endforeach
    </div>
  @endif
</div>

{{-- Filter sheet (mobile) --}}
<div class="inv-sheet-overlay" id="inv-sheet-overlay" onclick="invCloseSheet()"></div>
<div class="inv-sheet" id="inv-sheet" role="dialog" aria-label="Filter & sort">
  <div class="inv-sheet-handle"></div>
  <div class="inv-sheet-title">Filter &amp; sort</div>
  <form method="get" action="{{ route('tenant.inventory.index') }}" id="inv-sheet-form">
    <input type="hidden" name="s" value="{{ $search }}">

    <div class="inv-sheet-group">
      <div class="inv-sheet-group-label">Category</div>
      <div class="inv-sheet-options">
        <button type="button" class="inv-sheet-option {{ $category === '' || $category === null ? 'active' : '' }}" data-field="category" data-value="">All</button>
        @foreach($categories as $cat)
          <button type="button" class="inv-sheet-option {{ $category === $cat->id ? 'active' : '' }}" data-field="category" data-value="{{ $cat->id }}">{{ $cat->name }}</button>
        @endforeach
      </div>
      <input type="hidden" name="category" value="{{ $category }}" id="inv-sheet-category">
    </div>

    <div class="inv-sheet-group">
      <div class="inv-sheet-group-label">Stock level</div>
      <div class="inv-sheet-options">
        @foreach($stockLabels as $val => $label)
          <button type="button" class="inv-sheet-option {{ $stock === $val ? 'active' : '' }}" data-field="stock" data-value="{{ $val }}">{{ $label }}</button>
        @endforeach
      </div>
      <input type="hidden" name="stock" value="{{ $stock }}" id="inv-sheet-stock">
    </div>

    <div class="inv-sheet-group">
      <div class="inv-sheet-group-label">Sort by</div>
      <div class="inv-sheet-options">
        @foreach($sortLabels as $val => $label)
          <button type="button" class="inv-sheet-option {{ $sort === $val ? 'active' : '' }}" data-field="sort" data-value="{{ $val }}">{{ $label }}</button>
        @endforeach
      </div>
      <input type="hidden" name="sort" value="{{ $sort }}" id="inv-sheet-sort">
    </div>

    <button type="submit" class="inv-sheet-primary">Apply filters</button>
  </form>
  <a href="{{ route('tenant.inventory.index') }}" class="inv-sheet-secondary">Reset all</a>
</div>

@push('scripts')
<script>
(function(){
  window.invOpenSheet = function(){
    document.getElementById('inv-sheet-overlay').classList.add('is-open');
    document.getElementById('inv-sheet').classList.add('is-open');
    document.body.style.overflow = 'hidden';
  };
  window.invCloseSheet = function(){
    document.getElementById('inv-sheet-overlay').classList.remove('is-open');
    document.getElementById('inv-sheet').classList.remove('is-open');
    document.body.style.overflow = '';
  };
  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape') invCloseSheet();
  });
  // Sheet option buttons act like radio buttons within their group.
  // They update the matching hidden input, mark themselves active,
  // and clear siblings in the same group. Form submits on "Apply".
  document.querySelectorAll('#inv-sheet-form .inv-sheet-option').forEach(function(btn){
    btn.addEventListener('click', function(){
      var field = btn.dataset.field;
      var value = btn.dataset.value;
      var hidden = document.getElementById('inv-sheet-' + field);
      if (hidden) hidden.value = value;
      // toggle active class within siblings
      btn.parentElement.querySelectorAll('.inv-sheet-option').forEach(function(b){
        b.classList.toggle('active', b === btn);
      });
    });
  });
})();
</script>
@endpush

@if($total > $perPage)
  <div class="ia-pagination">
    @php
      $pages = (int) ceil($total / $perPage);
      $qs = function($p) use ($search, $category, $stock, $sort) {
        return http_build_query(array_filter([
          's' => $search, 'category' => $category, 'stock' => $stock,
          'sort' => $sort, 'page' => $p,
        ]));
      };
    @endphp
    @if($page > 1)
      <a href="?{{ $qs($page - 1) }}" class="ia-btn ia-btn--ghost">← Prev</a>
    @endif
    <span class="ia-pagination-info">Page {{ $page }} of {{ $pages }}</span>
    @if($page < $pages)
      <a href="?{{ $qs($page + 1) }}" class="ia-btn ia-btn--ghost">Next →</a>
    @endif
  </div>
@endif

@endif

@endsection
