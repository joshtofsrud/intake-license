#!/usr/bin/env bash
# apply-images-overwrite-fix.sh
# MARKER-IMAGES-OVERWRITE — the field map's images were thrown away.
#
# Traced end to end on a live QBP product (RM9022):
#
#   raw images: {"image":[{"fileName":"RM9022.jpg"},{"fileName":"RM9022-01.jpg"}]}
#   ImageFiles: ["RM9022.jpg","RM9022-01.jpg"]
#   mapped:     ["RM9022.jpg","RM9022-01.jpg"]        <- the field map worked
#
# and then, two lines before the write:
#
#   $canonical['images'] = $variant['Images'] ?? [];
#
# 'Images' with a capital I is HLC's key. QBP emits ImageFiles, BTI emits
# image_paths — so for both of those the lookup misses and an empty array is
# written STRAIGHT OVER the value the field map had just resolved correctly.
#
# The whole point of the field map is that a distributor's shape can change
# without a deploy. A hardcoded key downstream of it silently cancels that,
# and does so for every distributor except the one it was written for.
#
# The map now wins whenever it produced anything. The hardcoded read stays as
# a fallback for a distributor whose map has no images row at all, so HLC
# keeps working whether or not its map covers the field.
set -e

python3 <<'PY'
import io

p = 'app/Services/Distributors/DistributorCatalogSyncService.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-IMAGES-OVERWRITE' not in s, 'already applied'

old = """        // MARKER-PATCH-372 — capture distributor product images. Public CDN URLs
        // ({Format,Url,Hash}) already embedded per-variant in the Products payload.
        $canonical['images'] = $variant['Images'] ?? [];"""
assert s.count(old) == 1, 'I1 images anchor'
s = s.replace(old, """        // MARKER-PATCH-372 — capture distributor product images. Public CDN URLs
        // ({Format,Url,Hash}) already embedded per-variant in the Products payload.
        //
        // MARKER-IMAGES-OVERWRITE — but ONLY when the field map produced
        // nothing. This line used to run unconditionally and wrote
        // $variant['Images'] over whatever the map had resolved. 'Images' is
        // HLC's key: QBP emits ImageFiles and BTI emits image_paths, so for
        // both of those it missed and overwrote good data with an empty array.
        //
        // Verified on QBP RM9022 — the map resolved two file names and this
        // line discarded them, which is why every QBP product showed no image
        // while the mapping page reported images <- ImageFiles, correctly.
        $mappedImages = $canonical['images'] ?? null;
        if ($mappedImages === null || $mappedImages === [] || $mappedImages === '') {
            $canonical['images'] = $variant['Images'] ?? [];
        }""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- the overwrite is now conditional ---"
grep -n "MARKER-IMAGES-OVERWRITE" -A 3 app/Services/Distributors/DistributorCatalogSyncService.php | tail -4
grep -n "mappedImages" app/Services/Distributors/DistributorCatalogSyncService.php

echo
echo "--- behaviour per distributor ---"
python3 - <<'PY'
def resolve(mapped, variant_images_key):
    """Mirror the patched logic."""
    images = mapped
    if images is None or images == [] or images == '':
        images = variant_images_key if variant_images_key is not None else []
    return images

cases = [
    ('QBP  map resolved 2 files',      ['RM9022.jpg', 'RM9022-01.jpg'], None,
                                       ['RM9022.jpg', 'RM9022-01.jpg']),
    ('QBP  product genuinely has none', [],                              None, []),
    ('HLC  map has no images row',      None,                            [{'Url': 'https://x/y.jpg'}],
                                       [{'Url': 'https://x/y.jpg'}]),
    ('HLC  map row present and filled', [{'Url': 'https://a/b.jpg'}],    [{'Url': 'https://x/y.jpg'}],
                                       [{'Url': 'https://a/b.jpg'}]),
    ('BTI  map resolved paths',         ['a.jpg', 'b.jpg'],              None, ['a.jpg', 'b.jpg']),
]
for label, mapped, fallback, want in cases:
    got = resolve(mapped, fallback)
    ok = got == want
    print('  %-34s -> %-30s %s' % (label, str(got)[:30], 'OK' if ok else '*** WRONG ***'))
    assert ok
print('  the map wins when it produced anything; fallback only when it did not')
PY

echo
echo "--- nothing else overwrites a mapped canonical field ---"
python3 - <<'PY'
import io, re
s = io.open('app/Services/Distributors/DistributorCatalogSyncService.php', encoding='utf-8').read()
# Assignments to $canonical[...] that read straight from $variant/$product and
# are NOT guarded — the same trap as images.
hits = []
for m in re.finditer(r"\$canonical\['(\w+)'\]\s*=\s*\$(?:variant|product)\[", s):
    line = s[:m.start()].count('\n') + 1
    ctx = s[max(0, m.start() - 260):m.start()]
    guarded = 'if (' in ctx.split('\n')[-3] if ctx else False
    hits.append((line, m.group(1), guarded))
for line, field, guarded in hits:
    print('  line %-5d %-12s %s' % (line, field, 'guarded' if guarded else 'UNGUARDED — same pattern'))
print('  total:', len(hits))
PY

echo
echo "--- balance ---"
python3 - <<'PY'
import io
s = io.open('app/Services/Distributors/DistributorCatalogSyncService.php', encoding='utf-8').read()
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
print('SyncService braces', d, 'parens', par, 'brackets', brk)
PY

echo
echo "apply-images-overwrite-fix: OK"
