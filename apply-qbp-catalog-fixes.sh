#!/usr/bin/env bash
# apply-qbp-catalog-fixes.sh
# MARKER-QBP-FIXES — two bugs from the first full sync.
#
# 1. category_id was 32 chars. QBP's are longer:
#      c1232_tubeless_system_enhancements   (34)
#      c_k1044_electronic_shift_part_sram   (34)
#    152 rows failed to write. Widened to 128 — HLC and BTI use short numeric
#    ids so nothing else is affected, and a truncating id would silently
#    collide categories rather than fail, which is worse than the error.
#
# 2. ImageFile was empty on every product with MORE THAN ONE image.
#    images.image is an object for one and a LIST for several — the same
#    SimpleXML single-child trap asList() exists to absorb, which I then did
#    not use here. The SRAM part had one image and worked; the tyre insert had
#    seven and silently got none. Nothing errored: it just wrote empty.
#
#    Fixed to collect ALL file names. ImageFiles (plural) is the array the
#    catalog's json images column wants; ImageFile stays as the first name for
#    anything already reading it.
set -e

cat <<'EOF' > database/migrations/2026_08_04_000100_widen_catalog_category_id.php
<?php

// MARKER-QBP-FIXES

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QBP category ids are descriptive slugs, not numbers:
 *   c1232_tubeless_system_enhancements
 *   c_k1044_electronic_shift_part_sram
 *
 * 32 chars truncated them, which MySQL refused in strict mode — 152 rows lost
 * on the first full sync. Truncation would have been worse than the error:
 * two categories sharing a prefix would silently become one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_distributor_catalogs', function (Blueprint $t) {
            $t->string('category_id', 128)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Deliberately not narrowing again — rows written since would fail.
    }
};
EOF
echo "created database/migrations/2026_08_04_000100_widen_catalog_category_id.php"

python3 <<'PY'
import io

# ---------------------------------------------------------------- adapter
p = 'app/Services/Distributors/QbpClient.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-QBP-FIXES' not in s, 'already applied'

old = """        $row['ImageFile']    = trim((string) ($row['images']['image']['fileName'] ?? ''));"""
assert s.count(old) == 1, 'F1 image anchor'
s = s.replace(old, """        // MARKER-QBP-FIXES — images.image is an OBJECT for one image and a
        // LIST for several. Reading ['fileName'] directly worked on
        // single-image products and returned nothing on the rest, silently.
        // Every collection here goes through asList() for exactly this.
        $files = [];
        foreach ($this->asList($row['images']['image'] ?? null) as $img) {
            $fn = is_array($img) ? ($img['fileName'] ?? '') : $img;
            $fn = trim((string) $fn);
            if ($fn !== '') {
                $files[] = $fn;
            }
        }
        $row['ImageFiles'] = $files;
        $row['ImageFile']  = $files[0] ?? '';""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- seeder
p = 'database/seeders/QbpFieldMapSeeder.php'
s = io.open(p, encoding='utf-8').read()

old = """            ['images', 'ImageFile', 'json_passthrough', null, null,
                'file name only; binary requires the CLS licence'],"""
assert s.count(old) == 1, 'F2 images map anchor'
s = s.replace(old, """            // MARKER-QBP-FIXES — ImageFiles (plural). ImageFile held only the
            // first, and before the multi-image fix it held none at all on
            // any product carrying more than one.
            ['images', 'ImageFiles', 'json_passthrough', null, null,
                'all file names; the binaries need the CLS licence'],""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- images now collected through asList ---"
grep -n "ImageFiles\|asList(\$row\['images'\]" app/Services/Distributors/QbpClient.php | head -4

echo
echo "--- multi-image extraction proven on the real shapes ---"
python3 - <<'PY'
# Mirror the PHP: asList() semantics against both payload shapes seen today.
def as_list(v):
    if v is None or v == '': return []
    if isinstance(v, list): return v
    return [v]

def files(images):
    out = []
    for img in as_list((images or {}).get('image')):
        fn = img.get('fileName') if isinstance(img, dict) else img
        fn = (fn or '').strip()
        if fn: out.append(fn)
    return out

seven = {'image': [{'fileName': 'TR00172.jpg'}, {'fileName': 'TR00172-01.jpg'},
                   {'fileName': 'TR00172-02.jpg'}, {'fileName': 'TR00172-03.jpg'},
                   {'fileName': 'TR00172-04.jpg'}, {'fileName': 'TR00172-05.jpg'},
                   {'fileName': 'TR00172-06.jpg'}]}
one   = {'image': {'fileName': 'CY4530.jpg'}}

print('  seven-image product ->', len(files(seven)), 'names (was 0)')
print('  one-image product   ->', len(files(one)), 'name  (was 1)')
print('  no images           ->', len(files(None)), 'names')
assert len(files(seven)) == 7 and len(files(one)) == 1 and files(None) == []
PY

echo
echo "--- the failing ids now fit ---"
python3 - <<'PY'
for cid in ['c1232_tubeless_system_enhancements', 'c_k1044_electronic_shift_part_sram']:
    print('  %-38s %d chars  fits 32? %s  fits 128? %s'
          % (cid, len(cid), len(cid) <= 32, len(cid) <= 128))
PY

echo
echo "--- balance ---"
python3 - <<'PY'
import io
for p in ['app/Services/Distributors/QbpClient.php',
          'database/seeders/QbpFieldMapSeeder.php',
          'database/migrations/2026_08_04_000100_widen_catalog_category_id.php']:
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
    print('%-52s braces %d parens %d brackets %d' % (p.split('/')[-1], d, par, brk))
PY

echo
echo "apply-qbp-catalog-fixes: OK"
