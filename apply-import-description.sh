#!/usr/bin/env bash
# apply-import-description.sh
# MARKER-IMPORT-DESC — imported items inherit the catalog description.
#
# createItem() copied name, subtitle, prices, upc and case quantity — and
# nothing else. description was never carried across, so no imported item has
# ever had one. That was invisible while HLC and BTI were the only sources:
# neither supplies real copy. QBP does, and 1,516 rows carry it.
#
# Only set on CREATE, never on merge. A shop that has written its own
# description for a product must not have it replaced the next time another
# distributor is added as a source — their words beat the vendor's.
#
# IMAGES ARE NOT INCLUDED, deliberately. tenant_inventory_items has no image
# column, and QBP's images are FILE NAMES: the binaries need the CLS fetch,
# which does not exist yet. Copying names into a new column now would leave a
# column full of strings that render nothing. That is its own piece of work.
set -e

python3 <<'PY'
import io

p = 'app/Services/Distributors/DistributorCatalogImportService.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-IMPORT-DESC' not in s, 'already applied'

old = """            'display_subtitle'       => $cat->display_subtitle,"""
assert s.count(old) == 1, 'D1 createItem anchor'
s = s.replace(old, """            'display_subtitle'       => $cat->display_subtitle,
            // MARKER-IMPORT-DESC — vendor copy, on create only. HLC and BTI
            // rarely supply it; QBP's bullet points do. Never written on a
            // merge: a shop's own description outranks a distributor's.
            'description'            => $cat->description ?: null,""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- description copied on create ---"
grep -n "MARKER-IMPORT-DESC" -A 1 app/Services/Distributors/DistributorCatalogImportService.php | head -4

echo
echo "--- and NOT written anywhere a merge could overwrite ---"
python3 - <<'PY'
import io, re
s = io.open('app/Services/Distributors/DistributorCatalogImportService.php', encoding='utf-8').read()

create = re.search(r'private function createItem.*?\n    \}', s, re.S).group(0)
print('  createItem sets description :', "'description'" in create)

# addSource is the merge path — it must not touch description.
m = re.search(r'private function addSource.*?\n    \}', s, re.S)
if m:
    print('  addSource sets description  :', "'description'" in m.group(0), '(must be False)')
    assert "'description'" not in m.group(0)
else:
    print('  addSource not found — check merge path manually')

# No update() anywhere carrying description.
upd = re.findall(r"update\(\[[^\]]*'description'", s, re.S)
print('  update() writes description :', len(upd), '(must be 0)')
assert not upd
PY

echo
echo "--- balance ---"
python3 - <<'PY'
import io
s = io.open('app/Services/Distributors/DistributorCatalogImportService.php', encoding='utf-8').read()
i, n, d, par, brk = 0, len(s), 0, 0, 0
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
        elif c == '[': brk += 1
        elif c == ']': brk -= 1
        i += 1
print('ImportService braces', d, 'parens', par, 'brackets', brk)
PY

echo
echo "apply-import-description: OK"
