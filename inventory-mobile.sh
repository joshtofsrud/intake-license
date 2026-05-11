#!/bin/bash
# ============================================================================
# inventory-mobile.sh   (patch #38)
# ----------------------------------------------------------------------------
# Last major Tier 1 mobile page. Two list pages get the parallel desktop+mobile
# render treatment:
#
#   /admin/inventory              — product list (desktop table + mobile cards)
#   /admin/inventory/receiving    — shipment list (desktop table + mobile cards)
#
# Plus a "Best on desktop" notice on the three receiving-form pages
# (create/edit/show) since real receiving on a phone is a v1.1 design problem.
#
# Inventory list cards:
#   - Stock state communicated via colored dot (left) + colored count (right)
#   - Bin location + category + SKU live in meta line under the name
#   - Tap row → item detail page
#   - Search bar + filter sheet trigger (dot lights up when filters active)
#   - Active filters surface as removable chips
#   - Sheet has Category / Stock level / Sort by groups
#
# Receiving list cards:
#   - Scrollable pill tabs with row counts (Drafts 2 / Committed 14 / Voided 1)
#   - Each shipment: number + distributor/date + status pill + Lines/Units meta
#   - Tap row → shipment editor (draft) or viewer (committed/voided)
#
# Files touched:
#   resources/views/tenant/inventory/index.blade.php             (mobile section)
#   resources/views/tenant/inventory/receiving/index.blade.php   (mobile section)
#   resources/views/tenant/inventory/receiving/create.blade.php  (mobile notice)
#   resources/views/tenant/inventory/receiving/edit.blade.php    (mobile notice)
#   resources/views/tenant/inventory/receiving/show.blade.php    (mobile notice)
#
# Deploy:
#   git pull && php artisan view:clear && \
#   sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm
# ============================================================================

set -euo pipefail
REPO_ROOT="${REPO_ROOT:-$(pwd)}"
cd "$REPO_ROOT"

echo "==> Patch 38: inventory + receiving mobile parallel render"

# ----------------------------------------------------------------------------
# 1. Inventory list (index.blade.php)
# ----------------------------------------------------------------------------
python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/inventory/index.blade.php")
s = p.read_text()

if "inv-mobile" in s:
    print("    SKIP inventory list (already patched)")
else:
    # 1a. Append a @push('styles') block at the top of the file, just before
    #     @section('content'). The index page currently has NO style block,
    #     so we add one.
    css_block = """
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
}
</style>
@endpush
"""
    anchor = "@section('content')"
    if s.count(anchor) != 1:
        raise SystemExit(f"ABORT: @section('content') count = {s.count(anchor)}, expected 1")
    s = s.replace(anchor, css_block + "\n" + anchor)
    print("    INSERTED inventory mobile CSS at top of view")

    # 1b. Add mobile page-head actions immediately under the existing
    #     .ia-page-actions desktop row. They'll show only on mobile via CSS.
    desk_actions_anchor = """    @if($hasCategories)
      <a href="{{ route('tenant.inventory.create') }}" class="ia-btn ia-btn--primary">+ New item</a>
    @else
      <a href="{{ route('tenant.inventory.categories.index') }}" class="ia-btn ia-btn--primary">Set up categories</a>
    @endif
  </div>
</div>"""
    new_desk_actions = """    @if($hasCategories)
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
</div>"""
    if s.count(desk_actions_anchor) != 1:
        raise SystemExit(f"ABORT: page-actions anchor count = {s.count(desk_actions_anchor)}, expected 1")
    s = s.replace(desk_actions_anchor, new_desk_actions)
    print("    INSERTED mobile page-head action row")

    # 1c. Insert mobile toolbar + chip strip + card list + sheet right after
    #     the closing </form> of the desktop toolbar. Order: mobile toolbar,
    #     chip strip, then both ia-card desktop + .inv-mobile share the same
    #     position in DOM (CSS toggles which is visible).
    desk_form_close = """  @if($search || $category || $stock || $sort !== 'name_asc')
    <a href="{{ route('tenant.inventory.index') }}" class="ia-btn ia-btn--ghost">Reset</a>
  @endif
</form>"""
    new_with_mobile_tb = """  @if($search || $category || $stock || $sort !== 'name_asc')
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
@endif"""
    if s.count(desk_form_close) != 1:
        raise SystemExit(f"ABORT: desktop form close anchor count = {s.count(desk_form_close)}, expected 1")
    s = s.replace(desk_form_close, new_with_mobile_tb)
    print("    INSERTED mobile toolbar + chip strip")

    # 1d. Insert mobile card list right after the desktop table .ia-card closes.
    #     The desktop .ia-card ends with `</div>` just before the pagination
    #     `@if($total > $perPage)`. Anchor on that boundary.
    desk_table_close = """      </tbody>
    </table>
</div>
  @endif
</div>

@if($total > $perPage)"""
    new_with_mobile_list = """      </tbody>
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
          $stockCount = (int) $item->computed_stock_count;
          $threshold  = $item->shop_reorder_threshold;
          $isLow  = $threshold !== null && $stockCount > 0 && $stockCount <= $threshold;
          $isOut  = $stockCount <= 0;
          $dotCls = $isOut ? 'out' : ($isLow ? 'low' : '');
          $sellPrice = $item->effectiveSellPriceCents();
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

@if($total > $perPage)"""
    if s.count(desk_table_close) != 1:
        raise SystemExit(f"ABORT: desktop table-close anchor count = {s.count(desk_table_close)}, expected 1")
    s = s.replace(desk_table_close, new_with_mobile_list)
    print("    INSERTED mobile card list + filter sheet + JS")

    p.write_text(s)
    print("    DONE inventory/index.blade.php")
PYEOF

# ----------------------------------------------------------------------------
# 2. Receiving list (receiving/index.blade.php)
# ----------------------------------------------------------------------------
python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/inventory/receiving/index.blade.php")
s = p.read_text()

if "recv-mobile" in s:
    print("    SKIP receiving list (already patched)")
else:
    css_block = """
@push('styles')
<style>
/* Receiving mobile list (patch #38) — scoped via .recv- prefix.
   Desktop table stays. Mobile cards + pill tabs. */
.recv-mobile{display:none}
.recv-mobile-list{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);overflow:hidden}
.recv-card-m{padding:14px 16px;border-bottom:0.5px solid var(--ia-border);display:flex;flex-direction:column;gap:8px;text-decoration:none;color:inherit;transition:background var(--ia-t)}
.recv-card-m:last-child{border-bottom:none}
.recv-card-m:active{background:var(--ia-hover)}
.recv-top-m{display:flex;justify-content:space-between;align-items:flex-start;gap:10px}
.recv-num-m{font-size:14.5px;font-weight:600;color:var(--ia-text);font-variant-numeric:tabular-nums}
.recv-dist-m{font-size:12px;color:var(--ia-text-muted);margin-top:2px}
.recv-status-m{display:inline-flex;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:500;flex-shrink:0;text-transform:capitalize}
.recv-status-m.draft{background:rgba(250,180,106,.15);color:#FAB46A}
.recv-status-m.committed{background:var(--ia-accent-soft);color:var(--ia-accent)}
.recv-status-m.voided{background:var(--ia-surface-2);color:var(--ia-text-muted)}
.recv-meta-row-m{display:flex;gap:14px;flex-wrap:wrap;font-size:12px;color:var(--ia-text-muted);font-variant-numeric:tabular-nums;align-items:center}
.recv-meta-item-m{display:inline-flex;align-items:center;gap:4px;white-space:nowrap}
.recv-meta-label-m{font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim,rgba(255,255,255,.38));font-weight:500}
.recv-meta-value-m{color:var(--ia-text);font-weight:500}
.recv-unexpected-m{color:#FAB46A}

/* Pill tabs */
.recv-tabs-m{display:none;gap:6px;margin-bottom:14px;overflow-x:auto;scrollbar-width:none;padding-bottom:2px}
.recv-tabs-m::-webkit-scrollbar{display:none}
.recv-tab-m{flex-shrink:0;padding:7px 14px;border-radius:999px;background:var(--ia-surface);border:0.5px solid var(--ia-border);color:var(--ia-text-muted);font-size:13px;display:inline-flex;align-items:center;gap:5px;text-decoration:none;font-family:inherit}
.recv-tab-m.active{background:var(--ia-accent);color:#000;border-color:var(--ia-accent)}
.recv-tab-count-m{font-size:11px;opacity:.6;font-variant-numeric:tabular-nums}

/* Mobile head action row */
.recv-head-m{display:none}
.recv-icon-btn-m{width:36px;height:36px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;background:var(--ia-surface);border:0.5px solid var(--ia-border);color:var(--ia-text-muted);text-decoration:none;font-family:inherit;font-size:16px;cursor:pointer;border-width:0;padding:0}
.recv-icon-btn-m.primary{background:var(--ia-accent);color:#000;border-color:var(--ia-accent);font-weight:600}

@media(max-width:640px){
  .ia-tabs,
  .ia-toolbar,
  .ia-table-wrap{display:none !important}
  .ia-page-head .ia-page-actions{display:none}
  .recv-head-m{display:flex;gap:6px;align-items:center;margin-left:auto}
  .recv-tabs-m{display:flex}
  .recv-mobile{display:block}
}
</style>
@endpush
"""
    anchor = "@section('content')"
    if s.count(anchor) != 1:
        raise SystemExit(f"ABORT: @section('content') count = {s.count(anchor)}, expected 1")
    s = s.replace(anchor, css_block + "\n" + anchor)

    # Add mobile head action row inside the .ia-page-head (icon-only New shipment).
    desk_actions_anchor = """    <form method="POST" action="{{ route('tenant.inventory.receiving.create') }}" style="display:inline">
      @csrf
      <button type="submit" class="ia-btn ia-btn--primary">+ New shipment</button>
    </form>
  </div>
</div>"""
    new_desk_actions = """    <form method="POST" action="{{ route('tenant.inventory.receiving.create') }}" style="display:inline">
      @csrf
      <button type="submit" class="ia-btn ia-btn--primary">+ New shipment</button>
    </form>
  </div>
  {{-- Mobile-only icon button: New shipment (POST via form below). --}}
  <div class="recv-head-m">
    <form method="POST" action="{{ route('tenant.inventory.receiving.create') }}" style="display:inline">
      @csrf
      <button type="submit" class="recv-icon-btn-m primary" title="New shipment" aria-label="New shipment">+</button>
    </form>
  </div>
</div>"""
    if s.count(desk_actions_anchor) != 1:
        raise SystemExit(f"ABORT: receiving desktop actions anchor not unique")
    s = s.replace(desk_actions_anchor, new_desk_actions)

    # Insert mobile pill tabs after the existing flash banner area, before
    # the desktop tabs. The desktop .ia-tabs is what we're replacing on mobile,
    # so we add .recv-tabs-m as a sibling and hide the desktop one on small.
    desk_tabs_anchor = '<div class="ia-tabs"'
    if s.count(desk_tabs_anchor) != 1:
        raise SystemExit(f"ABORT: desktop tabs anchor not unique")
    insert_idx = s.index(desk_tabs_anchor)
    mobile_tabs = """{{-- Mobile pill tabs (≤640px). Same hrefs as desktop tabs. --}}
<div class="recv-tabs-m">
  @foreach($tabs as $key => $label)
    @php $count = $counts[$key] ?? 0; @endphp
    <a href="{{ route('tenant.inventory.receiving.index', ['tab' => $key]) }}" class="recv-tab-m {{ $tab === $key ? 'active' : '' }}">
      {{ $label }} <span class="recv-tab-count-m">{{ $count }}</span>
    </a>
  @endforeach
</div>

"""
    s = s[:insert_idx] + mobile_tabs + s[insert_idx:]

    # Insert mobile card list right after the closing of the desktop table .ia-card.
    desk_table_close = """      </tbody>
    </table>
</div>
  @endif
</div>

@if($total > $perPage)"""
    new_with_mobile_list = """      </tbody>
    </table>
</div>
  @endif
</div>

{{-- Mobile card list (≤640px) --}}
<div class="recv-mobile">
  @if($shipments->isEmpty())
    <div class="recv-mobile-list" style="padding:40px 20px;text-align:center;color:var(--ia-text-muted);font-size:13px">
      @if($tab === 'draft')
        No draft shipments. Tap + above to start one.
      @else
        No {{ $tab }} shipments yet.
      @endif
    </div>
  @else
    <div class="recv-mobile-list">
      @foreach($shipments as $s)
        @php
          $linkRoute = $s->status === 'draft'
            ? route('tenant.inventory.receiving.edit', ['id' => $s->id])
            : route('tenant.inventory.receiving.show', ['id' => $s->id]);
          $totalLines = $s->expected_count + $s->received_count + $s->unexpected_count;
        @endphp
        <a href="{{ $linkRoute }}" class="recv-card-m">
          <div class="recv-top-m">
            <div style="min-width:0">
              <div class="recv-num-m">{{ $s->shipment_number }}</div>
              <div class="recv-dist-m">
                {{ $s->distributor_name ?? 'No distributor' }}@if($s->received_date) · {{ $s->received_date->format('D, M j') }}@endif
              </div>
            </div>
            <span class="recv-status-m {{ $s->status }}">{{ $s->status }}</span>
          </div>
          <div class="recv-meta-row-m">
            <span class="recv-meta-item-m"><span class="recv-meta-label-m">Lines</span> <span class="recv-meta-value-m">{{ $totalLines }}</span></span>
            <span class="recv-meta-item-m"><span class="recv-meta-label-m">Units</span> <span class="recv-meta-value-m">{{ $s->received_count }}</span></span>
            @if($s->unexpected_count > 0)
              <span class="recv-meta-item-m recv-unexpected-m" style="margin-left:auto">{{ $s->unexpected_count }} unexpected</span>
            @endif
          </div>
        </a>
      @endforeach
    </div>
  @endif
</div>

@if($total > $perPage)"""
    if s.count(desk_table_close) != 1:
        raise SystemExit(f"ABORT: receiving table-close anchor count = {s.count(desk_table_close)}, expected 1")
    s = s.replace(desk_table_close, new_with_mobile_list)

    p.write_text(s)
    print("    UPDATED receiving/index.blade.php — mobile pills + card list")
PYEOF

# ----------------------------------------------------------------------------
# 3. Receiving form pages — "Best on desktop" mobile notice.
#    Three pages share the same need: line-by-line shipment entry which is
#    a v1.1 mobile design problem (barcode scanning, etc).
# ----------------------------------------------------------------------------
python3 <<'PYEOF'
from pathlib import Path

NOTICE_CSS_AND_BLOCK = """
@push('styles')
<style>
/* "Best on desktop" mobile notice (patch #38). Hidden on >640px. */
.recv-mobile-notice{display:none;background:rgba(250,180,106,.08);border:0.5px solid rgba(250,180,106,.25);border-radius:var(--ia-r-lg);padding:14px 16px;margin-bottom:16px}
.recv-mobile-notice-title{font-size:13px;font-weight:600;color:#FAB46A;margin-bottom:4px;display:flex;align-items:center;gap:6px}
.recv-mobile-notice-body{font-size:12px;color:var(--ia-text-muted);line-height:1.5}
@media(max-width:640px){
  .recv-mobile-notice{display:block}
}
</style>
@endpush
"""

NOTICE_HTML = """{{-- Mobile "best on desktop" notice (patch #38). Receiving is line-by-line
     entry that doesn't fit a phone — v1.1 will likely add barcode scanning
     and a different mobile flow. For now we surface the limitation rather
     than rebuild the form. --}}
<div class="recv-mobile-notice">
  <div class="recv-mobile-notice-title">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    Best on desktop
  </div>
  <div class="recv-mobile-notice-body">
    Receiving works on mobile, but line-by-line entry is faster on a larger screen. Mobile-optimized receiving (with barcode scanning) is on the roadmap.
  </div>
</div>
"""

targets = [
    "resources/views/tenant/inventory/receiving/create.blade.php",
    "resources/views/tenant/inventory/receiving/edit.blade.php",
    "resources/views/tenant/inventory/receiving/show.blade.php",
]

for tgt in targets:
    p = Path(tgt)
    if not p.exists():
        print(f"    SKIP {tgt} (not found)")
        continue
    s = p.read_text()
    if "recv-mobile-notice" in s:
        print(f"    SKIP {tgt} (already patched)")
        continue

    # Insert CSS @push before @section('content')
    anchor1 = "@section('content')"
    if s.count(anchor1) != 1:
        print(f"    SKIP {tgt} (@section('content') not unique)")
        continue
    s = s.replace(anchor1, NOTICE_CSS_AND_BLOCK + "\n" + anchor1)

    # Insert the notice div as the first thing inside @section('content'),
    # which has just been bumped down. Find the first occurrence again.
    anchor2 = "@section('content')\n"
    if s.count(anchor2) != 1:
        # Look for variant without newline
        anchor2 = "@section('content')"
        if s.count(anchor2) != 1:
            print(f"    SKIP {tgt} (content section not unique after CSS insert)")
            continue
    idx = s.index(anchor2) + len(anchor2)
    s = s[:idx] + "\n\n" + NOTICE_HTML + s[idx:]

    p.write_text(s)
    print(f"    UPDATED {tgt} — added best-on-desktop notice")
PYEOF

cat <<EONOTE

==> Patch 38 applied locally.

To deploy:
  git add -A
  git commit -m "feat(mobile): inventory list + receiving list + best-on-desktop notice (#38)"
  git push

On server:
  git pull
  php artisan view:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

  (No migration, no composer — pure view/CSS/JS.)

What this adds:
  - Inventory list: search bar + filter sheet + active-filter chips + card list
  - Stock state via colored dot + colored count (no badge ribbons)
  - Receiving list: pill tabs with counts + shipment cards with status pill
  - Receiving create/edit/show: "Best on desktop" amber notice (≤640px)

Final mobile Tier 1 page done. After this, only Tier 1 audit pass remains
before pre-launch hygiene + features.
EONOTE
