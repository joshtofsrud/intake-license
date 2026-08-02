#!/usr/bin/env bash
# apply-item-form-category-tree.sh
# MARKER-ITEM-CAT-TREE — the item forms show categories as a flat alphabetical
# list, so parents and children interleave and the structure disappears.
#
# On Ground Control that dropdown currently reads:
#
#     29"
#     700c / 29"
#     Brake Pads
#     Brakes
#     Chain
#     Chainrings
#     Cranks / Chainrings
#     Disc Brake Pads
#     ...
#
# "Brake Pads" and "Disc Brake Pads" are children of "Brakes"; "Chainrings"
# belongs under "Cranks / Chainrings"; the wheel sizes belong under a wheel
# or tyre parent. Sorted flat by name, a child can appear several rows above
# its own parent — you cannot tell what is nested under what, and two
# similarly named categories are impossible to tell apart.
#
# The inventory INDEX already solved this (MARKER-CAT-TREE): it builds a
# parent/children structure and renders children indented under their parent
# with a └ prefix. The item create and edit forms just never got it.
#
# This reuses that shape rather than inventing a second one, so the picker
# matches what the filter dropdown already looks like.
#
# Categories with a parent that is itself missing or inactive would vanish
# from a strict two-level render, so they are appended at the end rather
# than silently dropped — better a category in the wrong place than an item
# you cannot file at all.
#
# View + controller: deploy with /root/intake-deploy.sh.
set -e

python3 <<'PY'
import io

# ---------------------------------------------------------------- controller
p = 'app/Http/Controllers/Tenant/InventoryController.php'
s = io.open(p, encoding='utf-8').read()

old = """        $categories = TenantInventoryCategory::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();"""
assert s.count(old) == 2, 'C1 expected the flat fetch in BOTH create() and edit(), got %d' % s.count(old)
s = s.replace(old, """        // MARKER-ITEM-CAT-TREE — same parent/children shape the index filter
        // uses, so the picker reads like the rest of inventory.
        $categories = self::categoryOptions($tenant->id);""")

# helper, placed before the descendant helper it sits next to
old = """    public function uncategorized(Request $request): View"""
assert s.count(old) == 1, 'C2 helper insert anchor'
s = s.replace(old, """    /**
     * MARKER-ITEM-CAT-TREE — categories as roots with their children, for a
     * select that shows nesting instead of a flat alphabetical jumble.
     *
     * Returns a flat list of ['cat' => model, 'depth' => 0|1] so the view
     * stays simple. Orphans — a category whose parent is missing or
     * inactive — are appended at depth 0 rather than dropped; an item you
     * cannot file is worse than a category shown in the wrong place.
     *
     * @return array<int, array{cat: TenantInventoryCategory, depth: int}>
     */
    public static function categoryOptions(string $tenantId): array
    {
        $all = TenantInventoryCategory::where('tenant_id', $tenantId)
            ->orderBy('sort_order')->orderBy('name')->get();

        $out  = [];
        $seen = [];

        foreach ($all->whereNull('parent_id') as $root) {
            $out[]        = ['cat' => $root, 'depth' => 0];
            $seen[$root->id] = true;

            foreach ($all->where('parent_id', $root->id) as $child) {
                $out[]         = ['cat' => $child, 'depth' => 1];
                $seen[$child->id] = true;
            }
        }

        foreach ($all as $cat) {
            if (! isset($seen[$cat->id])) {
                $out[] = ['cat' => $cat, 'depth' => 0];
            }
        }

        return $out;
    }

    public function uncategorized(Request $request): View""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- views
old_create = """          <option value=\"\">Select category…</option>
          @foreach($categories as $cat)
            <option value=\"{{ $cat->id }}\" @selected(old('category_id') === $cat->id)>{{ $cat->name }}</option>
          @endforeach"""

new_create = """          <option value=\"\">Select category…</option>
          {{-- MARKER-ITEM-CAT-TREE — children indented under their parent, matching
               the index filter. A flat A-Z list put children rows above their own
               parents. --}}
          @foreach($categories as $opt)
            <option value=\"{{ $opt['cat']->id }}\" @selected(old('category_id') === $opt['cat']->id)>{{ $opt['depth'] ? '   └ ' : '' }}{{ $opt['cat']->name }}</option>
          @endforeach"""

p = 'resources/views/tenant/inventory/create.blade.php'
s = io.open(p, encoding='utf-8').read()
assert s.count(old_create) == 1, 'V1 create category loop anchor'
s = s.replace(old_create, new_create)
io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

p = 'resources/views/tenant/inventory/edit.blade.php'
s = io.open(p, encoding='utf-8').read()
import re
m = re.search(r'[ \t]*@foreach\(\$categories as \$cat\).*?@endforeach', s, re.S)
assert m, 'V2 edit category loop not found'
block = m.group(0)
indent = re.match(r'[ \t]*', block).group(0)
sel = 'old(\'category_id\', $item->category_id)'
s = s.replace(block, indent + "{{-- MARKER-ITEM-CAT-TREE --}}\n"
    + indent + "@foreach($categories as $opt)\n"
    + indent + "  <option value=\"{{ $opt['cat']->id }}\" @selected(" + sel + " === $opt['cat']->id)>{{ $opt['depth'] ? '   └ ' : '' }}{{ $opt['cat']->name }}</option>\n"
    + indent + "@endforeach")
io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo "--- rendered loops ---"
grep -n -A3 "MARKER-ITEM-CAT-TREE" resources/views/tenant/inventory/create.blade.php resources/views/tenant/inventory/edit.blade.php | head -16

echo
echo "--- blade sweep ---"
python3 - <<'PY'
import io, re
for f in ['resources/views/tenant/inventory/create.blade.php',
          'resources/views/tenant/inventory/edit.blade.php']:
    s = re.sub(r'\{\{--.*?--\}\}', '', io.open(f, encoding='utf-8').read(), flags=re.S)
    print(f, 'glued:', len(re.findall(r'\w@(?:if|endif|foreach|endforeach|elseif|else|unless|php|endphp)\b', s)))
    for a, b in [('@if','@endif'), ('@foreach','@endforeach'), ('@section','@endsection')]:
        o = len(re.findall(r'\B'+a+r'\b', s)); c = len(re.findall(r'\B'+b+r'\b', s))
        print('   ', a, o, b, c, 'OK' if o == c else 'MISMATCH')
PY

echo "--- balance ---"
python3 - <<'PY'
import io
s = io.open('app/Http/Controllers/Tenant/InventoryController.php', encoding='utf-8').read()
i, n, d, par = 0, len(s), 0, 0
while i < n:
    c = s[i]
    if c == '#' or (c == '/' and i+1 < n and s[i+1] == '/'):
        while i < n and s[i] != '\n': i += 1
    elif c == '/' and i+1 < n and s[i+1] == '*':
        i += 2
        while i+1 < n and not (s[i] == '*' and s[i+1] == '/'): i += 1
        i += 2
    elif c in '"\'':
        q = c; i += 1
        while i < n and s[i] != q:
            if s[i] == '\\': i += 1
            i += 1
        i += 1
    else:
        if c == '{': d += 1
        elif c == '}': d -= 1
        elif c == '(': par += 1
        elif c == ')': par -= 1
        i += 1
print('InventoryController braces', d, 'parens', par)
PY

echo
echo "apply-item-form-category-tree: OK"
