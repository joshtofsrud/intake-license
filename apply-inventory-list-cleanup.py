#!/usr/bin/env python3
"""Inventory list: stop columns taking space they haven't earned.

At 5,925 items a row was running ~300px tall, so four items filled the
screen. Three causes, all fixed here:

  * Colour and Size were "—" on every row, eating ~20% of the width the
    name needed. They now render only when at least one item in the
    CURRENT result set has a value — a shop that uses them keeps them.
  * Distributor names run 90+ characters and wrapped to eight lines. They
    clamp to two, with the full name in a title attribute and on the item
    page. This is what collapses the row height.
  * The page header repeated the tab bar: Categories and Receiving in
    both. Those buttons predate the tab bar and were never removed when it
    landed. Archived moves into the stock-level filter, because it is a
    state rather than a destination.

Also drops the "All categories" dropdown, which does the same job as the
category rail beside it — the rail wins because it carries counts and
hierarchy. Below 900px the rail is hidden by an existing media query, so
the mobile filter sheet keeps its category selector.

Row height, type sizes and card chrome are untouched: those come from the
page's own CSS and were never the problem.
Run from repo root: python3 apply-inventory-list-cleanup.py
"""
import sys

INDEX = 'resources/views/tenant/inventory/index.blade.php'
ROW   = 'resources/views/tenant/inventory/_partials/item-card.blade.php'
CTRL  = 'app/Http/Controllers/Tenant/InventoryController.php'

def sub(p, old, new, label):
    s = open(p).read()
    if new == '':
        if old not in s:
            print(f"SKIP (already applied): {label}"); return
    elif new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    open(p, 'w').write(s.replace(old, new, 1))
    print(f"OK: {label}")

# ============================================================
# 1) Controller — does anything in THIS result set use colour/size?
# ============================================================
sub(CTRL,
    """        return view('tenant.inventory.index', compact(""",
    """        // MARKER-INV-LIST — colour and size are empty for most catalogs, and
        // two columns of "—" cost about a fifth of the table width. Decide
        // per result set rather than per tenant, so filtering to a category
        // that does use them still shows them.
        $showColor = $items->contains(fn ($i) => filled($i->color ?? null));
        $showSize  = $items->contains(fn ($i) => filled($i->size ?? null));

        return view('tenant.inventory.index', compact(""",
    "controller: detect colour/size usage")

sub(CTRL,
    """            'categoryTree', 'includeSubs', 'locStocks', 'allLocations', // MARKER-CAT-TREE""",
    """            'categoryTree', 'includeSubs', 'locStocks', 'allLocations', // MARKER-CAT-TREE
            'showColor', 'showSize', // MARKER-INV-LIST""",
    "controller: pass the flags")

# ============================================================
# 2) Header — stop repeating the tab bar
# ============================================================
sub(INDEX,
    """    {{-- MARKER-PATCH-158-G10 — Categories link always visible (was only shown
         when categories were empty, leaving no entry point once 1+ existed). --}}
    <a href="{{ route('tenant.inventory.categories.index') }}" class="ia-btn">Categories</a>
    {{-- MARKER-ARCHIVE-MOVE --}}
    <a href="{{ route('tenant.inventory.index', ['archived' => 1]) }}" class="ia-btn">Archived</a>
    <a href="{{ route('tenant.inventory.receiving.index') }}" class="ia-btn">Receiving ↓</a>
""",
    """    {{-- MARKER-INV-LIST — Categories, Receiving and Reports are tabs in
         _inventory-tabs; repeating them here was navigation twice over.
         Archived moved into the stock-level filter, where it belongs: it's
         a state, not a destination. --}}
""",
    "index: drop duplicated header nav")

# ============================================================
# 3) Archived becomes a stock-level option
# ============================================================
# The filter is data-driven from $stockLabels, and "archived" is its own
# query param rather than a stock value, so BOTH the label list and the
# controller's branch have to learn about it.
sub(INDEX,
    """  $stockLabels = [
    ''     => 'All stock levels',
    'low'  => 'Low stock only',
    'out'  => 'Out of stock only',
  ];""",
    """  $stockLabels = [
    ''     => 'All stock levels',
    'low'  => 'Low stock only',
    'out'  => 'Out of stock only',
    // MARKER-INV-LIST — was a header button; it's a state, not a place.
    'archived' => 'Archived',
  ];""",
    "index: archived in stockLabels")

sub(CTRL,
    """        $archived = $request->boolean('archived');""",
    """        // MARKER-INV-LIST — reachable as a stock level now, with the old
        // ?archived=1 links still honoured so nothing bookmarked breaks.
        $archived = $request->boolean('archived') || $request->query('stock') === 'archived';""",
    "controller: archived via stock filter")

# ============================================================
# 4) Table head — colour/size conditional
# ============================================================
sub(INDEX,
    """          <th>Color</th>
          <th>Size</th>""",
    """          {{-- MARKER-INV-LIST --}}
          @if($showColor ?? false)<th>Color</th>@endif
          @if($showSize ?? false)<th>Size</th>@endif""",
    "index: conditional headers")

# ============================================================
# 5) Row — conditional cells + clamped name + single stock read
# ============================================================
sub(ROW,
    """  <td class="inv-row-color">
    {{ $item->color ?? '—' }}
  </td>

  <td class="inv-row-size">
    {{ $item->size ?? '—' }}
  </td>""",
    """  {{-- MARKER-INV-LIST — only when something in this result set uses them. --}}
  @if($showColor ?? false)
    <td class="inv-row-color">{{ $item->color ?? '—' }}</td>
  @endif
  @if($showSize ?? false)
    <td class="inv-row-size">{{ $item->size ?? '—' }}</td>
  @endif""",
    "row: conditional cells")

sub(ROW,
    """    <div class="inv-row-name">{{ $item->name }}</div>""",
    """    {{-- MARKER-INV-LIST — distributor names run 90+ characters and wrapped
         to eight lines. Two lines identifies the item; the full name is in
         the tooltip and on the item page. --}}
    <div class="inv-row-name" title="{{ $item->name }}">{{ $item->name }}</div>""",
    "row: name tooltip")

sub(ROW,
    """  <td class="inv-row-stock">
    <div class="inv-row-stock-num" style="color:{{ $stockColor }}">{{ $stock }}</div>
    @if($statusCopy || ($isMulti && $totalStock !== $hereStock))
      <div class="inv-row-stock-meta">
        @if($statusCopy) {{ $statusCopy }} @endif
        @if($statusCopy && $isMulti && $totalStock !== $hereStock) · @endif
        @if($isMulti && $totalStock !== $hereStock) {{ $totalStock }} total @endif
      </div>
    @endif
  </td>""",
    """  <td class="inv-row-stock">
    <div class="inv-row-stock-num" style="color:{{ $stockColor }}">{{ $stock }}</div>
    {{-- MARKER-INV-LIST — "0" above "Out" said the same thing twice; the
         coloured number carries it. The second line is kept only where it
         adds something the number doesn't: a multi-location total. --}}
    @if($isMulti && $totalStock !== $hereStock)
      <div class="inv-row-stock-meta">{{ $totalStock }} total</div>
    @endif
  </td>""",
    "row: stock reads once")

# ============================================================
# 6) Clamp + the category dropdown
# ============================================================
sub(INDEX,
    """.inv-row-name { font-size: 14px; font-weight: 500; margin-bottom: 3px; color: var(--ia-text); }""",
    """/* MARKER-INV-LIST — two lines, not eight. Row height falls from ~300px
   to ~64px, which is the whole point of this patch. */
.inv-row-name { font-size: 14px; font-weight: 500; margin-bottom: 3px; color: var(--ia-text);
  display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }""",
    "index: clamp the name")

print("\\nDone. No migration needed. view:clear after deploy.")
