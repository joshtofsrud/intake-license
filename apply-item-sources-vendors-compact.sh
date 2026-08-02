#!/usr/bin/env bash
# apply-item-sources-vendors-compact.sh
# MARKER-ITEM-SOURCES-COMPACT — pass $vendors to the views.
#
# The sources patch created $vendors in create() and edit() but never added
# it to the compact() list, so the variable existed in the controller and
# never reached the Blade partial. _sources.blade.php loops $vendors, so
# both pages 500 with an undefined variable the moment the include renders.
#
# Two separate mistakes stacked: the original patch aborted before applying
# anything (same-line collision with the category-tree patch), and the
# repair that fixed the anchor carried this one through untouched. Building
# a variable and forgetting to hand it over is exactly the failure a
# smoke test on the actual page would have caught, and I asked for a grep
# count instead.
set -e

python3 <<'PY'
import io

p = 'app/Http/Controllers/Tenant/InventoryController.php'
s = io.open(p, encoding='utf-8').read()

assert 'syncItemSources' in s, 'run apply-item-vendor-sources-repair.sh first'

old = """        return view('tenant.inventory.create', compact('categories'));"""
assert s.count(old) == 1, 'V1 create view anchor'
s = s.replace(old, """        // MARKER-ITEM-SOURCES-COMPACT — _sources.blade.php loops $vendors.
        return view('tenant.inventory.create', compact('categories', 'vendors'));""")

old = """        return view('tenant.inventory.edit', compact('item', 'categories'));"""
assert s.count(old) == 1, 'V2 edit view anchor'
s = s.replace(old, """        // MARKER-ITEM-SOURCES-COMPACT
        return view('tenant.inventory.edit', compact('item', 'categories', 'vendors'));""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- every variable the partial needs is now passed ---"
python3 - <<'PY'
import io, re
ctl = io.open('app/Http/Controllers/Tenant/InventoryController.php', encoding='utf-8').read()
part = io.open('resources/views/tenant/inventory/_sources.blade.php', encoding='utf-8').read()

# variables the partial reads but does not define itself
defined = set(re.findall(r'\$(\w+)\s*=', part))
used    = set(re.findall(r'\$(\w+)', part)) - defined - {'v','s','m','i','r','q','e','row','btn','wrap','html','next','tpl','rows','add'}

for view, fn in [('create', r"return view\('tenant\.inventory\.create', compact\(([^)]*)\)\)"),
                 ('edit',   r"return view\('tenant\.inventory\.edit', compact\(([^)]*)\)\)")]:
    m = re.search(fn, ctl)
    passed = set(re.findall(r"'(\w+)'", m.group(1))) if m else set()
    missing = sorted(u for u in used if u not in passed and u not in ('item',) or (u == 'item' and view == 'edit' and 'item' not in passed))
    print('%-8s passes: %-28s partial needs: %-22s %s'
          % (view, ','.join(sorted(passed)), ','.join(sorted(used)),
             'OK' if not missing else 'MISSING ' + ','.join(missing)))
PY

echo
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
echo "apply-item-sources-vendors-compact: OK"
