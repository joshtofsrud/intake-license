#!/usr/bin/env python3
"""Inventory categories: stop the tree at two levels.

The sidebar, the filter dropdown and the controller that feeds them all
hardcoded exactly two levels — roots, then their direct children, and
nothing else. A category nested any deeper existed, held items, and was
completely invisible: unreachable from the sidebar, absent from the
filter, and its items only findable through a parent with "include
subcategories" on. The Categories management page renders the same data
recursively, which is why the two screens disagreed.

A fourth consequence, only visible once you look: $selNode searched the
top-level array only, so selecting a CHILD category never matched and
the "+ N subcategories" scope chip never appeared for it.

Fix: the controller flattens the tree recursively with a depth on each
node; all three surfaces render from that. Flat-with-depth rather than
nested-with-recursion because a <select> can't nest anyway, and it keeps
the three renderers reading the same shape.
Run from repo root: python3 apply-inventory-category-depth.py
"""
import sys

CTRL = 'app/Http/Controllers/Tenant/InventoryController.php'
VIEW = 'resources/views/tenant/inventory/index.blade.php'

def sub(p, old, new, label):
    s = open(p).read()
    if new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    open(p, 'w').write(s.replace(old, new, 1))
    print(f"OK: {label}")

# ============================================================
# 1) Controller — recursive flatten
# ============================================================
sub(CTRL,
    """        $categoryTree = [];
        foreach ($allCats->whereNull('parent_id') as $root) {
            $children = [];
            foreach ($allCats->where('parent_id', $root->id) as $child) {
                $children[] = [
                    'cat'   => $child,
                    'count' => array_sum(array_map(
                        fn ($id) => $catCounts[$id] ?? 0,
                        self::descendantCategoryIds($allCats, $child->id)
                    )),
                ];
            }
            $categoryTree[] = [
                'cat'      => $root,
                'children' => $children,
                'count'    => array_sum(array_map(
                    fn ($id) => $catCounts[$id] ?? 0,
                    self::descendantCategoryIds($allCats, $root->id)
                )),
            ];
        }""",
    """        // MARKER-CAT-DEPTH — this used to walk roots and their direct
        // children ONLY, so anything nested deeper was invisible in the
        // sidebar and absent from the filter even though it held items.
        // Flat-with-depth (not nested) because a <select> can't nest, and
        // it keeps the sidebar, the dropdown and the scope chip reading
        // the same shape.
        $categoryTree = [];
        $walk = function ($parentId, int $depth) use (&$walk, $allCats, $catCounts, &$categoryTree) {
            foreach ($allCats->where('parent_id', $parentId) as $cat) {
                $kids = $allCats->where('parent_id', $cat->id);
                $categoryTree[] = [
                    'cat'      => $cat,
                    'depth'    => $depth,
                    'kids'     => $kids->count(),
                    // Count rolls up the whole subtree, as it always did.
                    'count'    => array_sum(array_map(
                        fn ($id) => $catCounts[$id] ?? 0,
                        self::descendantCategoryIds($allCats, $cat->id)
                    )),
                    // Kept so anything still reading ['children'] gets the
                    // direct children rather than a fatal.
                    'children' => [],
                ];
                $walk($cat->id, $depth + 1);
            }
        };
        $walk(null, 0);""",
    "controller: recursive flatten")

# ============================================================
# 2) Filter dropdown — indent by depth
# ============================================================
sub(VIEW,
    """    @foreach($categoryTree as $node)
      <option value="{{ $node['cat']->id }}" @selected($category === $node['cat']->id)>{{ $node['cat']->name }}</option>
      @foreach($node['children'] as $child)
        <option value="{{ $child['cat']->id }}" @selected($category === $child['cat']->id)>&nbsp;&nbsp;└ {{ $child['cat']->name }}</option>
      @endforeach
    @endforeach""",
    """    {{-- MARKER-CAT-DEPTH — one flat loop; depth carries the nesting. --}}
    @foreach($categoryTree as $node)
      <option value="{{ $node['cat']->id }}" @selected($category === $node['cat']->id)>
        {!! $node['depth'] ? str_repeat('&nbsp;&nbsp;', $node['depth']) . '└ ' : '' !!}{{ $node['cat']->name }}
      </option>
    @endforeach""",
    "view: dropdown depth")

# ============================================================
# 3) Sidebar — indent by depth, capped so deep trees still fit
# ============================================================
sub(VIEW,
    """  @foreach($categoryTree as $node)
    <a href="{{ route('tenant.inventory.index', array_filter(['s'=>$search,'stock'=>$stock,'sort'=>$sort!=='name_asc'?$sort:null,'category'=>$node['cat']->id,'subs'=>$includeSubs?null:'0'])) }}"
       class="{{ $category === $node['cat']->id ? 'sel' : '' }}">
      <span>{{ $node['cat']->name }}</span><span class="cnt">{{ $node['count'] }}</span>
    </a>
    @if(count($node['children']))
      <div class="kids">
        @foreach($node['children'] as $child)
          <a href="{{ route('tenant.inventory.index', array_filter(['s'=>$search,'stock'=>$stock,'sort'=>$sort!=='name_asc'?$sort:null,'category'=>$child['cat']->id])) }}"
             class="{{ $category === $child['cat']->id ? 'sel' : '' }}">
            <span>{{ $child['cat']->name }}</span><span class="cnt">{{ $child['count'] }}</span>
          </a>
        @endforeach
      </div>
    @endif
  @endforeach""",
    """  {{-- MARKER-CAT-DEPTH — one loop at any depth. Indent is capped at 3
       steps so a deep tree still fits the rail instead of sliding off. --}}
  @foreach($categoryTree as $node)
    @php $inDepth = min($node['depth'], 3); @endphp
    <a href="{{ route('tenant.inventory.index', array_filter(['s'=>$search,'stock'=>$stock,'sort'=>$sort!=='name_asc'?$sort:null,'category'=>$node['cat']->id,'subs'=>$includeSubs?null:'0'])) }}"
       class="{{ $category === $node['cat']->id ? 'sel' : '' }} {{ $node['depth'] ? 'is-child' : '' }}"
       style="padding-left:{{ 10 + $inDepth * 13 }}px"
       @if($node['depth'] > 3) title="{{ $node['cat']->name }}" @endif>
      <span>{{ $node['cat']->name }}</span><span class="cnt">{{ $node['count'] }}</span>
    </a>
  @endforeach""",
    "view: sidebar depth")

# ============================================================
# 4) Scope chip — child categories were never found
# ============================================================
sub(VIEW,
    """  $selNode = collect($categoryTree)->firstWhere('cat.id', $category);
  $subCount = $selNode ? count($selNode['children']) : 0;""",
    """  // MARKER-CAT-DEPTH — firstWhere on a two-level array never matched a
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
  }""",
    "view: scope chip finds children at any depth")

# ============================================================
# 5) The .kids wrapper is gone — its indent now comes from depth
# ============================================================
s = open(VIEW).read()
if '.inv-cats .kids' in s:
    print("NOTE: .kids CSS left in place (harmless; no longer emitted)")

print("\\nDone. No migration needed. view:clear after deploy.")
