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
    'brand_asc'  => 'Brand A–Z',
    'brand_desc' => 'Brand Z–A',
  ];
  $stockLabels = [
    ''     => 'All stock levels',
    // MARKER-INV-IN-STOCK — the common case, and first after "all": with
    // catalog imports the list is mostly items the shop does not hold.
    'in'   => 'In stock only',
    'low'  => 'Low stock only',
    'out'  => 'Out of stock only',
    // MARKER-INV-LIST — was a header button; it's a state, not a place.
    'archived' => 'Archived',
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

/* patch-99 list redesign — row styling */
.inv-row { transition: background 120ms ease; }
.inv-row:hover { background: var(--ia-hover); }
.inv-row td { vertical-align: middle; }
.inv-row-bar { padding: 0 !important; }
.inv-row-identity { padding-left: 12px !important; }
/* MARKER-INV-LIST — two lines, not eight. Row height falls from ~300px
   to ~64px, which is the whole point of this patch. */
.inv-row-name { font-size: 14px; font-weight: 500; margin-bottom: 3px; color: var(--ia-text);
  display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.inv-row-meta { display: flex; align-items: center; gap: 8px; font-size: 12px; flex-wrap: wrap; }
/* MARKER-CAT-TREE */
.inv-split{display:flex;gap:16px;align-items:flex-start}
.inv-cattree{width:230px;flex:none;background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);padding:10px}
.inv-cattree .hd{font-size:10.5px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:var(--ia-text-muted);padding:4px 8px 8px}
.inv-cattree a{display:flex;align-items:center;gap:8px;padding:7px 9px;border-radius:8px;text-decoration:none;color:var(--ia-text);font-size:13px}
.inv-cattree a:hover{background:var(--ia-hover)}
.inv-cattree a.sel{background:color-mix(in srgb, var(--ia-accent) 14%, transparent);color:var(--ia-accent);font-weight:700}
.inv-cattree a .cnt{margin-left:auto;font-size:11.5px;color:var(--ia-text-muted);font-variant-numeric:tabular-nums}
.inv-cattree a.sel .cnt{color:var(--ia-accent)}
.inv-cattree .kids{margin-left:12px;border-left:0.5px solid var(--ia-border);padding-left:5px}
.inv-cattree .kids a{font-size:12.5px;color:var(--ia-text-2,var(--ia-text-muted))}
.inv-scope{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;font-size:12.5px}
.inv-loc{font-size:10.5px;font-weight:600;border-radius:100px;padding:2px 8px;border:0.5px solid var(--ia-border);color:var(--ia-text-muted);white-space:nowrap}
.inv-loc.here{background:color-mix(in srgb, var(--ia-accent) 12%, transparent);border-color:color-mix(in srgb, var(--ia-accent) 40%, transparent);color:var(--ia-accent)}
.inv-loc.zero{opacity:.45}
.inv-locs{display:flex;gap:5px;flex-wrap:wrap;margin-top:4px}
.inv-catpath .par{color:var(--ia-text-muted)}
@media(max-width:900px){.inv-cattree{display:none}.inv-split{display:block}}
.inv-row-sku { font-family: var(--font-mono, monospace); color: var(--ia-text-muted); font-size: 11.5px; background: transparent; padding: 0; }
.inv-row-pill { display: inline-block; padding: 1px 8px; background: var(--ia-hover); color: var(--ia-text-muted); border-radius: 99px; font-size: 11px; }
.inv-row-bin { color: var(--ia-text-muted); font-size: 11px; }
.inv-row-upc code { font-family: var(--font-mono, monospace); font-size: 11.5px; color: var(--ia-text-muted); }
.inv-row-color, .inv-row-size { font-size: 13px; color: var(--ia-text); }
.inv-row-dash { color: var(--ia-text-muted); }
.inv-row-stock { text-align: right; }
.inv-row-stock-num { font-size: 16px; font-weight: 500; font-variant-numeric: tabular-nums; }
.inv-row-stock-meta { font-size: 11px; color: var(--ia-text-muted); margin-top: 1px; }
.inv-row-price, .inv-row-cost { text-align: right; font-variant-numeric: tabular-nums; }
.inv-row-cost { color: var(--ia-text-muted); }
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Inventory</h1>
    <p class="ia-page-subtitle">{{ number_format($total) }} {{ Str::plural('item', $total) }}</p>
  </div>
  <div class="ia-page-actions">
    {{-- MARKER-INV-LIST — Categories, Receiving and Reports are tabs in
         _inventory-tabs; repeating them here was navigation twice over.
         Archived moved into the stock-level filter, where it belongs: it's
         a state, not a destination. --}}
    @if($hasCategories)
      <a href="{{ route('tenant.inventory.create') }}" class="ia-btn ia-btn--primary">+ New item</a>
    @else
      <a href="{{ route('tenant.inventory.categories.index') }}" class="ia-btn ia-btn--primary">Set up categories</a>
    @endif
  </div>
  {{-- Mobile-only action row (right-aligned icon buttons). --}}
  <div class="inv-head-m inv-actions-m" style="margin-left:auto">
    {{-- MARKER-PATCH-158-G10 — Categories icon button on mobile too --}}
    <a href="{{ route('tenant.inventory.categories.index') }}" class="inv-icon-btn-m" title="Categories" aria-label="Categories">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M3 7h18M3 12h18M3 17h18"/></svg>
    </a>
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

@include('layouts.tenant._inventory-tabs')

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

{{-- MARKER-ARCHIVE-MOVE — reachable, because nobody guesses a URL parameter
     when an item goes missing. --}}
@if($archived)
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;padding:10px 14px;border:.5px solid var(--ia-border);border-radius:var(--ia-r-md)">
    <span style="font-size:13px">Showing archived items. Open one to restore it.</span>
    <a href="{{ route('tenant.inventory.index') }}" class="ia-btn ia-btn--sm" style="margin-left:auto">Back to inventory</a>
  </div>
@endif

{{-- MARKER-INV-AUTOFILTER — id so the change listener can find this form without depending on the class, which is styling. --}}
<form method="get" action="{{ route('tenant.inventory.index') }}" class="ia-toolbar" id="inv-toolbar-form">
  <input type="search" name="s" class="ia-input" value="{{ $search }}"
    placeholder="Search name, SKU, or UPC…" style="max-width:300px">

  {{-- MARKER-CAT-TREE — parents first, children indented beneath them --}}
  {{-- MARKER-SSEL-FILTERS — our picker: the native popup is OS-drawn and
       ignores the dark theme. "All categories" stays, because here an empty
       value is a real choice rather than a placeholder. --}}
  @php
    $sselCats = [];
    foreach ($categoryTree as $node) {
        $sselCats[(string) $node['cat']->id] =
            str_repeat("\u{00A0}\u{00A0}", (int) $node['depth'])
            . ($node['depth'] ? '└ ' : '') . $node['cat']->name;
    }
  @endphp
  <div style="min-width:210px">
    <x-tenant.searchable-select name="category" :options="$sselCats" :assoc="true"
      :selected="(string) ($category ?? '')" any="All categories" noun="categories"
      :searchable="count($sselCats) >= 12" />
  </div>
  @unless($includeSubs)<input type="hidden" name="subs" value="0">@endunless

  {{-- MARKER-INV-BRAND-DIST --}}
  @if($brandOptions->isNotEmpty())
    {{-- MARKER-SSEL-FILTERS --}}
    <div style="min-width:170px">
      <x-tenant.searchable-select name="brand" :options="$brandOptions->values()->all()"
        :selected="(string) ($brand ?? '')" any="All brands" noun="brands"
        :searchable="$brandOptions->count() >= 12" />
    </div>
  @endif

  @if($distributorOptions->count() > 1)
    {{-- MARKER-SSEL-FILTERS --}}
    @php
      $sselDist = [];
      foreach ($distributorOptions as $d) { $sselDist[(string) $d] = 'Available from ' . $d; }
    @endphp
    <div style="min-width:180px">
      <x-tenant.searchable-select name="distributor" :options="$sselDist" :assoc="true"
        :selected="(string) ($distributor ?? '')" any="All distributors" noun="distributors"
        :searchable="false" />
    </div>
  @endif

  {{-- MARKER-CAT-PLACEHOLDER — the list lands on in-stock, so say so. A
       default nobody can see is the same as a bug. --}}
  @if($stock === 'in')
    <span style="font-size:11.5px;color:var(--ia-text-dim);align-self:center">
      Showing what you have on hand ·
      <a href="{{ route('tenant.inventory.index', array_filter(['s' => $search, 'category' => $category, 'stock' => ''], fn ($v) => $v !== null)) }}"
         style="text-decoration:underline">show everything</a>
    </span>
  @endif
  {{-- MARKER-SSEL-FILTERS — $stockLabels already has '' => 'All stock levels'
       as a real option, so no separate any row. --}}
  <div style="min-width:180px">
    <x-tenant.searchable-select name="stock" :options="$stockLabels" :assoc="true"
      :selected="(string) ($stock ?? '')" any="" noun="stock levels" :searchable="false" />
  </div>

  {{-- MARKER-SSEL-FILTERS --}}
  <div style="min-width:180px">
    <x-tenant.searchable-select name="sort" :options="$sortLabels" :assoc="true"
      :selected="(string) ($sort ?? '')" any="" noun="sort orders" :searchable="false" />
  </div>

  <button type="submit" class="ia-btn ia-btn--secondary">Filter</button>
  @if($search || $category || $stock || $brand || $distributor || $sort !== 'name_asc')
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
  <input type="hidden" name="brand" value="{{ $brand }}">
  <input type="hidden" name="distributor" value="{{ $distributor }}">
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

{{-- MARKER-CAT-TREE — the hierarchy the category admin already builds,
     finally visible where items are browsed. Plain links keep filters
     deep-linkable and need no JS. --}}
@php
  // MARKER-INV-PAGER — computed once, used by all three pager includes.
  $pages = max(1, (int) ceil($total / max(1, $perPage)));

  // MARKER-PAGER-FILTERS — brand and distributor were missing here, so paging
  // out of a filtered list landed on the unfiltered one. Every filter the page
  // reads lives in this one array; anything added to the form belongs here too,
  // and nowhere else. perPage is deliberately ABSENT: it lives in the session,
  // so page links do not need to carry it.
  $qs = function ($p) use ($search, $category, $stock, $sort, $brand, $distributor) {
    return http_build_query(array_filter([
      's'           => $search,
      'category'    => $category,
      'brand'       => $brand,
      'distributor' => $distributor,
      'stock'       => $stock,
      'sort'        => $sort,
      'page'        => $p,
    ], fn ($v) => $v !== null && $v !== ''));
  };
@endphp

@push('styles')
<style>
  .inv-pager{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:10px 14px}
  .inv-pager--top{border-bottom:0.5px solid var(--ia-border)}
  .inv-pager--bottom{border-top:0.5px solid var(--ia-border)}
  .inv-pager--mobile{justify-content:center}
  .inv-pager-count{font-size:12px;color:var(--ia-text-muted)}
  .inv-pager-size{display:flex;align-items:center;gap:6px;margin:0}
  .inv-pager-size label{font-size:12px;color:var(--ia-text-muted)}
  .inv-pager-size select{background:var(--ia-surface);color:var(--ia-text);
    border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm,6px);
    padding:4px 8px;font-size:12px}
  .inv-pager-nav{display:flex;align-items:center;gap:4px;margin-left:auto}
  .inv-pager--mobile .inv-pager-nav{margin-left:0}
  .inv-pager-num{display:inline-flex;align-items:center;justify-content:center;
    min-width:28px;height:28px;padding:0 6px;border-radius:var(--ia-r-sm,6px);
    font-size:12px;color:var(--ia-text-muted);text-decoration:none}
  .inv-pager-num:hover{background:var(--ia-surface-2,rgba(255,255,255,.06));color:var(--ia-text)}
  .inv-pager-num.is-here{background:var(--ia-accent,#7cc00a);color:#0b0b0b;font-weight:600}
  .inv-pager-gap{color:var(--ia-text-muted);font-size:12px;padding:0 2px}
  .ia-btn.is-off{opacity:.35;pointer-events:none}
  @media(max-width:640px){.inv-pager--top{display:none}}
</style>
@endpush

<div class="inv-split">
@if($hasCategories)
<aside class="inv-cattree">
  <div class="hd">Categories</div>
  <a href="{{ route('tenant.inventory.index', array_filter(['s'=>$search,'stock'=>$stock,'sort'=>$sort!=='name_asc'?$sort:null])) }}"
     class="{{ $category ? '' : 'sel' }}">All items</a>
  {{-- MARKER-CAT-DEPTH — one loop at any depth. Indent is capped at 3
       steps so a deep tree still fits the rail instead of sliding off.
       MARKER-CAT-COLLAPSE — the tree is a DFS flat list, so every node after
       a root and before the next root is that root's descendant. That lets
       one wrapper per root hold the whole branch without nesting the loop. --}}
  @php
    $rootOf   = [];
    $curRoot  = null;
    foreach ($categoryTree as $n) {
      if ($n['depth'] === 0) { $curRoot = $n['cat']->id; }
      $rootOf[$n['cat']->id] = $curRoot;
    }
    // The selected category's branch opens server-side: no flash, and a
    // shared link never arrives with its own category hidden.
    $forceOpen = $category ? ($rootOf[$category] ?? null) : null;
    $branchIsOpen = false;
  @endphp
  @foreach($categoryTree as $node)
    @php
      $inDepth = min($node['depth'], 3);
      $isRoot  = $node['depth'] === 0;
      $catId   = $node['cat']->id;
    @endphp

    @if($isRoot && $branchIsOpen)
      </div>
      @php $branchIsOpen = false; @endphp
    @endif

    @if($isRoot)
      @php $openNow = $forceOpen === $catId; @endphp
      <div class="cat-row">
        @if($node['kids'] > 0)
          <button type="button" class="cat-toggle" data-root="{{ $catId }}"
                  aria-expanded="{{ $openNow ? 'true' : 'false' }}"
                  aria-label="Show or hide subcategories of {{ $node['cat']->name }}">▸</button>
        @else
          <span class="cat-toggle is-leaf" aria-hidden="true"></span>
        @endif
        <a href="{{ route('tenant.inventory.index', array_filter(['s'=>$search,'stock'=>$stock,'sort'=>$sort!=='name_asc'?$sort:null,'category'=>$catId,'subs'=>$includeSubs?null:'0'])) }}"
           class="{{ $category === $catId ? 'sel' : '' }}">
          <span>{{ $node['cat']->name }}</span><span class="cnt">{{ $node['count'] }}</span>
        </a>
      </div>
      @if($node['kids'] > 0)
        <div class="cat-kids" data-root="{{ $catId }}" @if(! $openNow) hidden @endif>
        @php $branchIsOpen = true; @endphp
      @endif
    @else
      <a href="{{ route('tenant.inventory.index', array_filter(['s'=>$search,'stock'=>$stock,'sort'=>$sort!=='name_asc'?$sort:null,'category'=>$catId,'subs'=>$includeSubs?null:'0'])) }}"
         class="{{ $category === $catId ? 'sel' : '' }} is-child"
         style="padding-left:{{ 10 + $inDepth * 13 }}px"
         @if($node['depth'] > 3) title="{{ $node['cat']->name }}" @endif>
        <span class="cat-dash" aria-hidden="true">{{ str_repeat('–', $inDepth) }}</span>
        <span>{{ $node['cat']->name }}</span><span class="cnt">{{ $node['count'] }}</span>
      </a>
    @endif
  @endforeach
  @if($branchIsOpen)
    </div>
  @endif
</aside>

@push('styles')
<style>
  /* MARKER-CAT-COLLAPSE */
  .cat-row{display:flex;align-items:center;gap:2px}
  .cat-row > a{flex:1;min-width:0}
  .cat-toggle{flex:0 0 18px;width:18px;height:22px;padding:0;border:0;background:none;
    color:var(--ia-text-muted);font-size:10px;line-height:1;cursor:pointer;
    transition:transform .12s ease}
  .cat-toggle[aria-expanded="true"]{transform:rotate(90deg)}
  .cat-toggle.is-leaf{cursor:default}
  .cat-dash{color:var(--ia-text-muted);margin-right:4px;letter-spacing:-1px}
</style>
@endpush

@push('scripts')
<script>
// MARKER-CAT-COLLAPSE — open set per tenant. A branch forced open server-side
// because it holds the selected category is left alone on load: the stored
// state is a preference, not an instruction to hide what you just filtered by.
(function () {
  var KEY = 'inv-cats-open:{{ tenant()->id }}';

  function read() {
    try { return JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { return []; }
  }
  function write(list) {
    try { localStorage.setItem(KEY, JSON.stringify(list)); } catch (e) {}
  }

  var open = read();

  document.querySelectorAll('.cat-kids').forEach(function (box) {
    var root = box.getAttribute('data-root');
    var btn  = document.querySelector('.cat-toggle[data-root="' + root + '"]');
    if (!box.hasAttribute('hidden')) { return; }   // forced open by the server
    if (open.indexOf(root) === -1) { return; }
    box.removeAttribute('hidden');
    if (btn) { btn.setAttribute('aria-expanded', 'true'); }
  });

  document.querySelectorAll('.cat-toggle[data-root]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var root = btn.getAttribute('data-root');
      var box  = document.querySelector('.cat-kids[data-root="' + root + '"]');
      if (!box) { return; }

      var nowOpen = box.hasAttribute('hidden');
      if (nowOpen) { box.removeAttribute('hidden'); } else { box.setAttribute('hidden', ''); }
      btn.setAttribute('aria-expanded', nowOpen ? 'true' : 'false');

      var list = read();
      var at   = list.indexOf(root);
      if (nowOpen && at === -1) { list.push(root); }
      if (!nowOpen && at !== -1) { list.splice(at, 1); }
      write(list);
    });
  });
})();
</script>
@endpush
@endif

<div style="flex:1;min-width:0">
@php
  // MARKER-CAT-DEPTH — firstWhere on a two-level array never matched a
  // child, so picking one showed no scope chip even when it had children
  // of its own. The flat tree finds every node, and the count is the
  // whole subtree rather than just the direct children.
  $selNode  = collect($categoryTree)->first(fn ($n) => $n['cat']->id === $category);
  $subCount = 0;
  if ($selNode) {
      $selDepth = $selNode['depth'];
      $seen = false;
      foreach ($categoryTree as $n) {
          if ($n['cat']->id === $category) { $seen = true; continue; }
          if (! $seen) continue;
          if ($n['depth'] <= $selDepth) break;   // left the subtree
          $subCount++;
      }
  }
@endphp
@if($category && $subCount)
  <div class="inv-scope">
    <span class="inv-chip-m">
      {{ $selNode['cat']->name }}@if($includeSubs) + {{ $subCount }} {{ Str::plural('subcategory', $subCount) }}@endif
    </span>
    <a href="{{ route('tenant.inventory.index', array_filter(['s'=>$search,'stock'=>$stock,'sort'=>$sort!=='name_asc'?$sort:null,'category'=>$category,'subs'=>$includeSubs?'0':null])) }}"
       style="color:var(--ia-text-muted);text-decoration:underline">
      {{ $includeSubs ? 'Show only items filed directly here' : 'Include subcategories' }}
    </a>
  </div>
@endif

{{-- MARKER-MERGE-UI --}}
@if(($canMergeItems ?? false))
  <div id="inv-merge-bar" style="display:none;align-items:center;gap:12px;padding:10px 14px;margin-bottom:12px;
       background:rgba(190,242,100,.08);border:0.5px solid rgba(190,242,100,.3);border-radius:8px;font-size:12.5px">
    <span id="inv-merge-count"></span>
    <button type="button" class="ia-btn ia-btn--sm ia-btn--primary" style="margin-left:auto"
            id="inv-merge-go" onclick="invOpenMerge()" disabled>Merge…</button>
    <button type="button" class="ia-btn ia-btn--sm" onclick="invClearPicks()">Clear</button>
  </div>

  <div id="inv-merge-scrim" onclick="invCloseMerge()"
       style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:60"></div>

  <div id="inv-merge-modal" role="dialog" aria-modal="true"
       style="display:none;position:fixed;z-index:61;top:50%;left:50%;transform:translate(-50%,-50%);
              width:560px;max-width:94vw;max-height:88vh;overflow:auto;background:var(--ia-surface);
              border:0.5px solid var(--ia-border-strong);border-radius:12px;box-shadow:0 24px 60px rgba(0,0,0,.6)">
    <div style="padding:18px 20px 2px">
      <h2 style="margin:0;font-size:17px">Merge these two items?</h2>
      <div style="color:var(--ia-text-dim);font-size:12px;margin-top:3px">
        One record will remain. This cannot be undone.
      </div>
    </div>
    <div style="padding:16px 20px" id="inv-merge-body">Working it out…</div>
    <div style="padding:14px 20px 18px;border-top:0.5px solid var(--ia-border);display:flex;gap:8px;justify-content:flex-end">
      <button type="button" class="ia-btn" onclick="invCloseMerge()">Cancel</button>
      <button type="button" class="ia-btn ia-btn--primary" id="inv-merge-commit"
              onclick="invCommitMerge()" disabled>Merge into kept item</button>
    </div>
  </div>
@endif

<div class="ia-card inv-desk-card">
  @include('tenant.inventory._partials.pager', ['pagerWhere' => 'top'])
  @if($items->isEmpty())
    {{-- MARKER-INV-EMPTY --}}
    <div class="ia-card-body">
      @include('tenant.inventory._partials.empty-state', ['emptyVariant' => 'desk'])
    </div>
  @else
<div class="ia-table-wrap">
    <table class="ia-table">
      {{-- patch-99 list redesign — column set + CSS --}}
      <thead>
        <tr>
          <th style="width:4px;padding:0"></th>
            {{-- MARKER-MERGE-UI — matches the cell added to item-card. --}}
            @if(($canMergeItems ?? false))<th style="width:30px"></th>@endif
          <th>Item</th>
          <th>UPC</th>
          {{-- MARKER-INV-LIST --}}
          @if($showColor ?? false)<th>Color</th>@endif
          @if($showSize ?? false)<th>Size</th>@endif
          <th style="text-align:right">{{ ($isMultiLocation ?? false) && ($currentLocation->name ?? null) ? 'Stock at ' . $currentLocation->name : 'Stock' }}</th>
          <th style="text-align:right">Price</th>
          <th style="text-align:right">Cost</th>
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
  @include('tenant.inventory._partials.pager', ['pagerWhere' => 'bottom'])
</div>
</div>{{-- /flex:1 --}}
</div>{{-- /inv-split MARKER-CAT-TREE --}}

{{-- Mobile card list (≤640px). Same data, different shape. --}}
<div class="inv-mobile">
  @if($items->isEmpty())
    {{-- MARKER-INV-EMPTY --}}
    <div class="inv-mobile-list">
      @include('tenant.inventory._partials.empty-state', ['emptyVariant' => 'mobile'])
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
                {{-- MARKER-CAT-TREE — full path, not a bare leaf name --}}
                <span class="inv-catpath">·
                  @if($item->category->parent)<span class="par">{{ $item->category->parent->name }} ›</span> @endif{{ $item->category->name }}
                </span>
              @endif
              @if($item->shop_bin_location)
                <span>· Bin {{ $item->shop_bin_location }}</span>
              @endif
            </div>
            @if(($isMultiLocation ?? false) && !empty($locStocks))
              <div class="inv-locs">
                @foreach($allLocations as $loc)
                  @php $lq = (int) ($locStocks[$item->id][$loc->id] ?? 0); @endphp
                  <span class="inv-loc {{ ($currentLocation && $loc->id === $currentLocation->id) ? 'here' : '' }} {{ $lq <= 0 ? 'zero' : '' }}">{{ $loc->name }} {{ $lq }}</span>
                @endforeach
              </div>
            @endif
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
  @include('tenant.inventory._partials.pager', ['pagerWhere' => 'mobile'])
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

{{-- MARKER-INV-PAGER — the pager used to live here, outside the results
     column, so it rendered below whichever flex child was taller (the
     category rail) rather than under the table. It is now included inside
     the results card and under the mobile list instead. --}}

@endif

@endsection

@push('scripts')
<script>
// MARKER-INV-AUTOFILTER — apply a filter the moment it is picked.
// The pickers fire a bubbling 'change' on their hidden input, so one
// delegated listener covers stock, brand, distributor, category and sort,
// including any picker added to this toolbar later.
(function () {
  var form = document.getElementById('inv-toolbar-form');
  if (!form) { return; }

  form.addEventListener('change', function (e) {
    var t = e.target;
    if (!t || !t.name) { return; }

    // The text box is not a filter picker: submitting here would fire on
    // blur, which is the autosave behaviour we do not want.
    if (t.name === 's') { return; }

    // Rows-per-page lives in its own form and submits itself.
    if (t.name === 'perPage') { return; }

    // Any filter change resets to page 1 — staying on page 14 of a list that
    // just became three pages long shows an empty table.
    var page = form.querySelector('[name="page"]');
    if (page) { page.value = '1'; }

    form.submit();
  });
})();
</script>
@endpush

@push('scripts')
<script>
// MARKER-MERGE-UI
(function () {
  var picks = [];      // [{id, name, sku}]
  var flipped = false; // which of the two survives
  var data = null;

  var bar   = document.getElementById('inv-merge-bar');
  var count = document.getElementById('inv-merge-count');
  var go    = document.getElementById('inv-merge-go');
  if (!bar) { return; }

  var token = document.querySelector('meta[name="csrf-token"]').content;
  var esc = function (v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };
  var money = function (c) { return c == null ? '—' : '$' + (c / 100).toFixed(2); };

  window.invPick = function (cb) {
    var entry = { id: cb.value, name: cb.dataset.name, sku: cb.dataset.sku };

    if (cb.checked) {
      // Two is the whole feature. A third tick is refused rather than
      // silently ignored, so nobody thinks they queued a batch.
      if (picks.length >= 2) {
        cb.checked = false;
        count.textContent = 'Two at a time — untick one first.';
        return;
      }
      picks.push(entry);
    } else {
      picks = picks.filter(function (p) { return p.id !== cb.value; });
    }

    bar.style.display = picks.length ? 'flex' : 'none';
    go.disabled = picks.length !== 2;
    count.textContent = picks.length === 2
      ? '2 selected'
      : picks.length + ' selected — pick one more';
  };

  window.invClearPicks = function () {
    picks = [];
    document.querySelectorAll('.inv-pick').forEach(function (c) { c.checked = false; });
    bar.style.display = 'none';
    go.disabled = true;
  };

  function pair() {
    return flipped
      ? { loser: picks[1], survivor: picks[0] }
      : { loser: picks[0], survivor: picks[1] };
  }

  window.invSwapMerge = function () { flipped = !flipped; loadPreview(); };

  window.invOpenMerge = function () {
    if (picks.length !== 2) { return; }
    flipped = false;
    document.getElementById('inv-merge-scrim').style.display = 'block';
    document.getElementById('inv-merge-modal').style.display = 'block';
    loadPreview();
  };

  window.invCloseMerge = function () {
    document.getElementById('inv-merge-scrim').style.display = 'none';
    document.getElementById('inv-merge-modal').style.display = 'none';
  };

  function loadPreview() {
    var p = pair();
    var body = document.getElementById('inv-merge-body');
    document.getElementById('inv-merge-commit').disabled = true;
    body.textContent = 'Working it out…';

    fetch('{{ route('tenant.inventory.merge.preview') }}', {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({ loser_id: p.loser.id, survivor_id: p.survivor.id })
    })
      .then(function (r) { return r.json(); })
      .then(function (d) { data = d; render(d, p); })
      .catch(function () { body.textContent = 'Could not work out what this merge would do.'; });
  }

  function render(d, p) {
    var c = d.counts || {};
    var rows = [];

    // Per location, because stock is per location — "6 + 8 = 14" is only
    // true when both sit in one place.
    if ((d.locations || []).length) {
      rows.push(['Stock', d.locations.map(function (l) {
        return esc(l.name) + ': ' + l.before + ' + ' + l.moved + ' = <strong>' + l.after + '</strong>';
      }).join('<br>')]);
    } else {
      rows.push(['Stock', 'Nothing to move — the merged-away item holds none.']);
    }

    if (c.sales)     { rows.push(['Sales history', c.sales + ' line' + (c.sales === 1 ? '' : 's') + ' reattach']); }
    if (c.receiving) { rows.push(['Receiving', c.receiving + ' row' + (c.receiving === 1 ? '' : 's') + ' move across']); }
    if (c.special)   { rows.push(['Special orders', c.special + ' move across']); }
    if (c.parts)     { rows.push(['Job parts', c.parts + ' move across']); }
    if (c.movements) { rows.push(['Change history', c.movements + ' movement' + (c.movements === 1 ? '' : 's') + ' merge in date order']); }
    if (c.vendors)   { rows.push(['Vendors', c.vendors + ' link' + (c.vendors === 1 ? '' : 's') + ', duplicates dropped']); }
    if (c.photos)    { rows.push(['Photos', c.photos + ' move over, after the kept item\'s']); }
    if ((d.adopts || []).length) { rows.push(['Adopted', d.adopts.map(esc).join(', ')]); }

    // MARKER-MERGE-COMMITMENTS — surfaced above the choices, not buried under
    // them: this is the part that should stop someone, and a warning below the
    // fold is a warning nobody read.
    var cm = d.commitments || {};
    var promised = [];
    if (cm.open_sales)     { promised.push(cm.open_sales + ' open sale' + (cm.open_sales === 1 ? '' : 's') + ' with this item on them'); }
    if (cm.special_orders) { promised.push(cm.special_orders + ' open special order' + (cm.special_orders === 1 ? '' : 's')); }
    if (cm.incoming)       { promised.push(cm.incoming + ' line on a receiving draft'); }

    var warn = '';
    if (promised.length) {
      warn =
        '<div style="margin-top:13px;background:rgba(242,119,122,.07);border:0.5px solid rgba(242,119,122,.35);'
        + 'border-radius:8px;padding:11px 13px;font-size:12px;color:var(--ia-text-muted);line-height:1.5">'
        + '<strong style="color:var(--ia-text)">Someone is waiting on this item.</strong> '
        + promised.map(esc).join(', ') + '. '
        + 'These all move to the kept item and keep working — but the name on them will change, '
        + 'so check nobody is mid-transaction before you go ahead.'
        + '</div>';
    }

    var priceOpts = '';
    if (d.price && d.price.loser !== null && d.price.loser !== d.price.survivor) {
      priceOpts =
        '<div style="margin-top:5px;display:flex;gap:6px;flex-wrap:wrap">'
        + '<button type="button" class="ia-btn ia-btn--sm mg-opt on" data-k="price" data-v="keep">Keep ' + money(d.price.survivor) + '</button>'
        + '<button type="button" class="ia-btn ia-btn--sm mg-opt" data-k="price" data-v="take">Take ' + money(d.price.loser) + '</button>'
        + '</div>';
    }

    var costOpts = '';
    if (d.cost && (d.cost.loser !== d.cost.survivor)) {
      costOpts =
        '<div style="margin-top:5px;display:flex;gap:6px;flex-wrap:wrap">'
        + '<button type="button" class="ia-btn ia-btn--sm mg-opt on" data-k="cost" data-v="keep">Keep ' + money(d.cost.survivor) + '</button>'
        + '<button type="button" class="ia-btn ia-btn--sm mg-opt" data-k="cost" data-v="take">Take ' + money(d.cost.loser) + '</button>'
        + (d.cost.weighted !== null
            ? '<button type="button" class="ia-btn ia-btn--sm mg-opt" data-k="cost" data-v="average">Weighted ' + money(d.cost.weighted) + '</button>'
            : '')
        + '</div>';
    }

    document.getElementById('inv-merge-body').innerHTML =
      '<div style="border:0.5px dashed rgba(242,119,122,.5);border-radius:8px;padding:12px 14px;position:relative">'
      + '<button type="button" class="ia-btn ia-btn--sm" style="position:absolute;right:10px;top:10px" onclick="invSwapMerge()">Swap</button>'
      + '<div style="font-size:10.5px;font-weight:700;letter-spacing:.04em;color:#f2777a;margin-bottom:6px">MERGES AWAY</div>'
      + '<div style="font-weight:650;font-size:13.5px;padding-right:64px">' + esc(p.loser.name) + '</div>'
      + '<div style="color:var(--ia-text-dim);font-size:11.5px">SKU ' + esc(p.loser.sku) + '</div>'
      + '</div>'
      + '<div style="text-align:center;margin:-8px 0;position:relative;z-index:2">'
      + '<span style="display:inline-flex;width:30px;height:30px;border-radius:50%;align-items:center;justify-content:center;'
      + 'background:var(--ia-surface);border:0.5px solid rgba(126,224,129,.5);color:#7ee081">↓</span></div>'
      + '<div style="border:0.5px solid rgba(126,224,129,.45);background:rgba(126,224,129,.05);border-radius:8px;padding:12px 14px">'
      + '<div style="font-size:10.5px;font-weight:700;letter-spacing:.04em;color:#7ee081;margin-bottom:6px">KEPT</div>'
      + '<div style="font-weight:650;font-size:13.5px">' + esc(p.survivor.name) + '</div>'
      + '<div style="color:var(--ia-text-dim);font-size:11.5px">SKU ' + esc(p.survivor.sku) + '</div>'
      + '</div>'
      + '<div style="margin-top:14px;border:0.5px solid var(--ia-border);border-radius:8px">'
      + '<div style="padding:8px 13px;font-size:10.5px;letter-spacing:.09em;color:var(--ia-text-dim);border-bottom:0.5px solid var(--ia-border)">WHAT HAPPENS</div>'
      + rows.map(function (r) {
          return '<div style="display:flex;gap:10px;padding:8px 13px;border-bottom:0.5px solid var(--ia-border);font-size:12.5px">'
            + '<span style="flex:0 0 130px;color:var(--ia-text-dim)">' + r[0] + '</span>'
            + '<span style="flex:1">' + r[1] + '</span></div>';
        }).join('')
      + '<div style="display:flex;gap:10px;padding:8px 13px;border-bottom:0.5px solid var(--ia-border);font-size:12.5px">'
      + '<span style="flex:0 0 130px;color:var(--ia-text-dim)">Sell price</span><span style="flex:1">' + money(d.price ? d.price.survivor : null) + priceOpts + '</span></div>'
      + '<div style="display:flex;gap:10px;padding:8px 13px;font-size:12.5px">'
      + '<span style="flex:0 0 130px;color:var(--ia-text-dim)">Cost</span><span style="flex:1">' + money(d.cost ? d.cost.survivor : null) + costOpts + '</span></div>'
      + '</div>'
      + warn
      + '<div style="margin-top:13px;background:rgba(245,196,81,.07);border:0.5px solid rgba(245,196,81,.35);'
      + 'border-radius:8px;padding:11px 13px;font-size:12px;color:var(--ia-text-muted);line-height:1.5">'
      + '<strong>Not yet carried over:</strong> the merged-away barcode. A shelf label printed from '
      + '<strong>' + esc(p.loser.sku) + '</strong> will stop scanning until barcode aliases are built. '
      + 'Its identifiers are adopted only where the kept item has none.'
      + '</div>'
      + '<label style="display:flex;gap:9px;align-items:flex-start;margin-top:13px;font-size:12.5px;color:var(--ia-text-muted)">'
      + '<input type="checkbox" id="inv-merge-ack" style="margin-top:3px">'
      + '<span>I understand <strong>' + esc(p.loser.name) + '</strong> will no longer exist as a separate item.</span></label>';

    document.getElementById('inv-merge-ack').addEventListener('change', function () {
      document.getElementById('inv-merge-commit').disabled = !this.checked;
    });

    document.querySelectorAll('.mg-opt').forEach(function (b) {
      b.addEventListener('click', function () {
        document.querySelectorAll('.mg-opt[data-k="' + b.dataset.k + '"]').forEach(function (o) {
          o.classList.remove('on');
        });
        b.classList.add('on');
      });
    });
  }

  window.invCommitMerge = function () {
    var p = pair();
    var btn = document.getElementById('inv-merge-commit');
    var pick = function (k) {
      var el = document.querySelector('.mg-opt.on[data-k="' + k + '"]');
      return el ? el.dataset.v : 'keep';
    };

    btn.disabled = true;
    btn.textContent = 'Merging…';

    fetch('{{ route('tenant.inventory.merge.commit') }}', {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({
        loser_id: p.loser.id,
        survivor_id: p.survivor.id,
        price_choice: pick('price'),
        cost_choice: pick('cost')
      })
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.url) { window.location = d.url; return; }
        btn.textContent = 'Merge failed';
      })
      .catch(function () { btn.textContent = 'Merge failed'; });
  };
})();
</script>
<style>
  /* MARKER-MERGE-UI */
  .mg-opt{opacity:.55}
  .mg-opt.on{opacity:1;border-color:var(--ia-accent);color:var(--ia-accent)}
</style>
@endpush
