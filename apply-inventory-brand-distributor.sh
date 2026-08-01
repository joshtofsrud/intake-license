#!/bin/bash
# inventory-brand-distributor — filter and sort inventory by brand and vendor.
#
#   Neither lives on tenant_inventory_items: brand is `manufacturer` on the
#   linked platform catalog row, and a distributor is a SOURCE on
#   tenant_item_vendors. So this joins rather than sorting a column.
#
#   The distributor filter deliberately asks "is this item available from
#   X", not "did it come from X". Since matching landed, an item can carry
#   HLC and BTI at once, and a shop asking "what can I get from BTI" means
#   everything BTI can supply — not only the rows BTI happened to create.
#   Answered with a whereExists on the vendor pivot, so an item with two
#   sources appears under both and is never listed twice.
#
#   Brand sorting joins the catalog row. That join is LEFT and the select is
#   pinned back to the items table, because a hand-created item has no
#   catalog row and must not vanish from the list when someone sorts by
#   brand — those sort last under "(no brand)" rather than disappearing.
#
#   The brand list is built from what this tenant actually carries, not the
#   whole 39k-row shared catalog, so it stays a usable dropdown.
# NO MIGRATION. Server: optimize:clear && view:clear
set -e
if grep -q "MARKER-INV-BRAND-DIST" app/Http/Controllers/Tenant/InventoryController.php; then
  echo "inventory-brand-distributor already applied — aborting."; exit 1
fi

# ------------------------------------------------------------------ controller
python3 - <<'IBD_0_EOF'
import io
p = 'app/Http/Controllers/Tenant/InventoryController.php'
s = io.open(p, encoding='utf-8').read()

# --- inputs -------------------------------------------------------------
old = """        $sort     = $request->input('sort', 'name_asc');"""
assert s.count(old) == 1, s.count(old)
new = """        $sort     = $request->input('sort', 'name_asc');
        // MARKER-INV-BRAND-DIST
        $brand       = trim((string) $request->input('brand', ''));
        $distributor = trim((string) $request->input('distributor', ''));"""
s = s.replace(old, new)

# --- filters, applied just before the sort block ------------------------
old = """        // patch-98 sort: stock_asc / stock_desc now order by current"""
assert s.count(old) == 1, s.count(old)
new = """        // MARKER-INV-BRAND-DIST — brand lives on the linked catalog row.
        if ($brand !== '') {
            $q->whereHas('distributorCatalog', fn ($w) => $w->where('manufacturer', $brand));
        }

        // MARKER-INV-BRAND-DIST — "available from", not "created by". An item
        // matched across distributors carries several sources, and a shop
        // asking what BTI can supply means all of it. whereExists keeps the
        // row unique — a join would list a two-source item twice.
        if ($distributor !== '') {
            $q->whereExists(function ($w) use ($distributor) {
                $w->selectRaw('1')
                  ->from('tenant_inventory_item_vendors as iv_f')
                  ->whereColumn('iv_f.inventory_item_id', 'tenant_inventory_items.id')
                  ->where('iv_f.distributor_code', $distributor);
            });
        }

        // patch-98 sort: stock_asc / stock_desc now order by current"""
s = s.replace(old, new)

# --- brand sort ---------------------------------------------------------
old = """        } else {
            switch ($sort) {
                case 'name_desc': $q->orderBy('name', 'desc'); break;"""
assert s.count(old) == 1, s.count(old)
new = """        } elseif (in_array($sort, ['brand_asc', 'brand_desc'], true)) {
            // MARKER-INV-BRAND-DIST — LEFT join, and the select is pinned back
            // to the items table: a hand-created item has no catalog row and
            // must not disappear from the list because someone sorted by
            // brand. Those sort last instead.
            $dir = $sort === 'brand_asc' ? 'asc' : 'desc';
            $q->leftJoin('platform_distributor_catalogs as pdc_sort', 'pdc_sort.id', '=', 'tenant_inventory_items.distributor_catalog_id')
              ->orderByRaw("COALESCE(NULLIF(pdc_sort.manufacturer, ''), 'zzzz') {$dir}")
              ->orderBy('tenant_inventory_items.name')
              ->select('tenant_inventory_items.*');
        } else {
            switch ($sort) {
                case 'name_desc': $q->orderBy('name', 'desc'); break;"""
s = s.replace(old, new)

# --- options for the dropdowns ------------------------------------------
old = """        $categories    = $allCats;"""
assert s.count(old) == 1, s.count(old)
new = """        // MARKER-INV-BRAND-DIST — built from what this tenant carries, not the
        // whole shared catalog, so the dropdown stays usable.
        $brandOptions = \\App\\Models\\PlatformDistributorCatalog::query()
            ->whereIn('id', function ($w) {
                $w->select('distributor_catalog_id')
                  ->from('tenant_inventory_items')
                  ->where('tenant_id', tenant()->id)
                  ->whereNotNull('distributor_catalog_id');
            })
            ->whereNotNull('manufacturer')->where('manufacturer', '!=', '')
            ->distinct()->orderBy('manufacturer')->pluck('manufacturer');

        $distributorOptions = \\Illuminate\\Support\\Facades\\DB::table('tenant_inventory_item_vendors as iv')
            ->join('tenant_inventory_items as it', 'it.id', '=', 'iv.inventory_item_id')
            ->where('it.tenant_id', tenant()->id)
            ->whereNotNull('iv.distributor_code')->where('iv.distributor_code', '!=', '')
            ->distinct()->orderBy('iv.distributor_code')
            ->pluck('iv.distributor_code');

        $categories    = $allCats;"""
s = s.replace(old, new)

# --- pass to the view ---------------------------------------------------
old = """            'total', 'search', 'category', 'stock', 'sort', 'page', 'perPage',"""
assert s.count(old) == 1, s.count(old)
new = """            'total', 'search', 'category', 'stock', 'sort', 'page', 'perPage',
            'brand', 'distributor', 'brandOptions', 'distributorOptions', // MARKER-INV-BRAND-DIST"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('controller ok')
IBD_0_EOF

# ------------------------------------------------------------------ view
python3 - <<'IBD_1_EOF'
import io
p = 'resources/views/tenant/inventory/index.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """    'stock_asc'  => 'Stock low → high',
    'stock_desc' => 'Stock high → low',
  ];"""
assert s.count(old) == 1, s.count(old)
new = """    'stock_asc'  => 'Stock low → high',
    'stock_desc' => 'Stock high → low',
    'brand_asc'  => 'Brand A–Z',
    'brand_desc' => 'Brand Z–A',
  ];"""
s = s.replace(old, new)

old = """  <select name="stock" class="ia-input" style="width:auto">"""
assert s.count(old) == 1, s.count(old)
new = """  {{-- MARKER-INV-BRAND-DIST --}}
  @if($brandOptions->isNotEmpty())
    <select name="brand" class="ia-input" style="width:auto">
      <option value="">All brands</option>
      @foreach($brandOptions as $b)
        <option value="{{ $b }}" @selected($brand === $b)>{{ $b }}</option>
      @endforeach
    </select>
  @endif

  @if($distributorOptions->count() > 1)
    <select name="distributor" class="ia-input" style="width:auto">
      <option value="">All distributors</option>
      @foreach($distributorOptions as $d)
        <option value="{{ $d }}" @selected($distributor === $d)>Available from {{ $d }}</option>
      @endforeach
    </select>
  @endif

  <select name="stock" class="ia-input" style="width:auto">"""
s = s.replace(old, new)

# keep the filters across the pager and the mobile sheet
old = """  <input type="hidden" name="category" value="{{ $category }}">
  <input type="hidden" name="stock" value="{{ $stock }}">
  <input type="hidden" name="sort" value="{{ $sort }}">"""
assert s.count(old) == 1, s.count(old)
new = old + """
  <input type="hidden" name="brand" value="{{ $brand }}">
  <input type="hidden" name="distributor" value="{{ $distributor }}">"""
s = s.replace(old, new)

old = """  @if($search || $category || $stock || $sort !== 'name_asc')"""
assert s.count(old) == 1, s.count(old)
new = """  @if($search || $category || $stock || $brand || $distributor || $sort !== 'name_asc')"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('view ok')
IBD_1_EOF

echo
echo "inventory-brand-distributor applied."
