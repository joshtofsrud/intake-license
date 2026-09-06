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

<form method="get" action="{{ route('tenant.inventory.index') }}" class="ia-toolbar">
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
<div class="inv-split">
@if($hasCategories)
<aside class="inv-cattree">
  <div class="hd">Categories</div>
  <a href="{{ route('tenant.inventory.index', array_filter(['s'=>$search,'stock'=>$stock,'sort'=>$sort!=='name_asc'?$sort:null])) }}"
     class="{{ $category ? '' : 'sel' }}">All items</a>
  {{-- MARKER-CAT-DEPTH — one loop at any depth. Indent is capped at 3
       steps so a deep tree still fits the rail instead of sliding off. --}}
  @foreach($categoryTree as $node)
    @php $inDepth = min($node['depth'], 3); @endphp
    <a href="{{ route('tenant.inventory.index', array_filter(['s'=>$search,'stock'=>$stock,'sort'=>$sort!=='name_asc'?$sort:null,'category'=>$node['cat']->id,'subs'=>$includeSubs?null:'0'])) }}"
       class="{{ $category === $node['cat']->id ? 'sel' : '' }} {{ $node['depth'] ? 'is-child' : '' }}"
       style="padding-left:{{ 10 + $inDepth * 13 }}px"
       @if($node['depth'] > 3) title="{{ $node['cat']->name }}" @endif>
      <span>{{ $node['cat']->name }}</span><span class="cnt">{{ $node['count'] }}</span>
    </a>
  @endforeach
</aside>
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

<div class="ia-card inv-desk-card">
  @if($items->isEmpty())
    <div class="ia-card-body" style="text-align:center;padding:40px 20px;color:var(--ia-text-muted)">
      No items match your filters.
    </div>
  @else
<div class="ia-table-wrap">
    <table class="ia-table">
      {{-- patch-99 list redesign — column set + CSS --}}
      <thead>
        <tr>
          <th style="width:4px;padding:0"></th>
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
</div>
</div>{{-- /flex:1 --}}
</div>{{-- /inv-split MARKER-CAT-TREE --}}

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
      // MARKER-PAGER-FILTERS — brand and distributor were missing here, so
      // paging out of a filtered list landed on the unfiltered one. Every
      // filter the page reads lives in this one array; anything added to the
      // form belongs here too, and nowhere else.
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
