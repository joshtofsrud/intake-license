#!/usr/bin/env bash
# apply-qbp-bullets.sh
# MARKER-QBP-BULLETS — QBP's bullet points become the description.
#
# The feed probe showed bulletPoints unmapped while carrying real copy:
#   "Standard Presta Valve w/ Insert"
#   "The maximum allowable rim thickness at the valve hole is 18mm or 0.70""
#
# The catalog's description column was empty on every QBP row. The text was
# already being fetched and stored in source_raw — just never mapped out.
#
# bulletPoints.bulletPoint is an OBJECT for one bullet and a LIST for several
# — the same single-child trap that silently emptied images on multi-image
# products. asList() from the start this time.
#
# Two keys are emitted: Description (newline-joined, what the text column
# wants) and BulletPoints (the array, for anything that later wants to render
# them as a list rather than a paragraph).
set -e

python3 <<'PY'
import io

# ---------------------------------------------------------------- adapter
p = 'app/Services/Distributors/QbpClient.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-QBP-BULLETS' not in s, 'already applied'

old = """        $row['ImageFiles'] = $files;
        $row['ImageFile']  = $files[0] ?? '';"""
assert s.count(old) == 1, 'B1 image block anchor'
s = s.replace(old, """        $row['ImageFiles'] = $files;
        $row['ImageFile']  = $files[0] ?? '';

        // MARKER-QBP-BULLETS — one bullet is an object, several are a list.
        // Kept as both a joined string (the description column is text) and
        // the raw array, so a storefront can render them as bullets later
        // without re-parsing a paragraph.
        $bullets = [];
        foreach ($this->asList($row['bulletPoints']['bulletPoint'] ?? null) as $bp) {
            $text = is_array($bp) ? ($bp['text'] ?? '') : $bp;
            $text = trim((string) $text);
            if ($text !== '') {
                $bullets[] = $text;
            }
        }
        $row['BulletPoints'] = $bullets;
        $row['Description']  = $bullets ? implode("\\n", $bullets) : null;""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- seeder
p = 'database/seeders/QbpFieldMapSeeder.php'
s = io.open(p, encoding='utf-8').read()

old = """            ['manufacturer', 'brand.description', 'direct', null, null,"""
assert s.count(old) == 1, 'B2 seeder anchor'
s = s.replace(old, """            // MARKER-QBP-BULLETS — real copy, e.g. "The maximum allowable rim
            // thickness at the valve hole is 18mm". Adapter joins the bullets
            // with newlines; BulletPoints holds the array if a list render is
            // wanted later.
            ['description', 'Description', 'direct', null, null,
                'QBP bulletPoints, newline-joined by the adapter'],

            ['manufacturer', 'brand.description', 'direct', null, null,""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- bullets collected through asList, both keys emitted ---"
grep -n "BulletPoints\|row\['Description'\]" app/Services/Distributors/QbpClient.php | head -4

echo
echo "--- description mapped in the seeder ---"
grep -n "'description', 'Description'" database/seeders/QbpFieldMapSeeder.php

echo
echo "--- proven against both real shapes from the probe ---"
python3 - <<'PY'
def as_list(v):
    if v is None or v == '': return []
    if isinstance(v, list): return v
    return [v]

def bullets(bp):
    out = []
    for b in as_list((bp or {}).get('bulletPoint')):
        t = (b.get('text') if isinstance(b, dict) else b) or ''
        t = t.strip()
        if t: out.append(t)
    return out

many = {'bulletPoint': [
    {'text': 'Standard Presta Valve w/ Insert'},
    {'text': 'The maximum allowable rim thickness at the valve hole is 18mm or 0.70\u201d'}]}
one  = {'bulletPoint': {'text': 'Tube Insert Only'}}

print('  two bullets ->', len(bullets(many)))
print('  one bullet  ->', len(bullets(one)), '(the single-child trap)')
print('  none        ->', len(bullets(None)))
print('  joined      ->', repr(chr(10).join(bullets(one))))
assert len(bullets(many)) == 2 and len(bullets(one)) == 1 and bullets(None) == []
PY

echo
echo "--- every seeded path still exists on the variant row ---"
python3 - <<'PY'
import io, re
seeder = io.open('database/seeders/QbpFieldMapSeeder.php', encoding='utf-8').read()
flattened = {'Attributes','CategoryName','CategoryId','ImageFile','ImageFiles','BarcodeList',
             'FirstBarcode','IsOfferable','Dimensions','BulletPoints','Description'}
raw = {'sku','modelCode','manufacturerPartNumber','name','unit','discontinued','hazmat','ormd',
       'blocked','brand','msrp','mapPrice','basePrice','freight','modifiedTime','markets','retailSales'}
paths = re.findall(r"\['[a-z_]+', '([A-Za-z.]+)'", seeder)
missing = [p for p in paths if p.split('.')[0] not in flattened and p.split('.')[0] not in raw]
print('  paths checked :', len(paths))
print('  unknown heads :', missing or 'none')
assert not missing
PY

echo
echo "--- balance ---"
python3 - <<'PY'
import io
for p in ['app/Services/Distributors/QbpClient.php', 'database/seeders/QbpFieldMapSeeder.php']:
    s = io.open(p, encoding='utf-8').read()
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
    print('%-34s braces %d parens %d brackets %d' % (p.split('/')[-1], d, par, brk))
PY

echo
echo "apply-qbp-bullets: OK"
