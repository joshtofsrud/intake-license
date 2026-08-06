#!/usr/bin/env bash
# apply-bti-image-base.sh
# MARKER-BTI-IMAGE-BASE — BTI images have never loaded.
#
# The base was https://www.bti-usa.com/images, which 404s on every product.
# It appears in two places (a config default and the field-map seeder) and was
# evidently never checked against a real image — BTI's images only became
# visible at all today, when the sync stopped overwriting mapped values, and
# they arrived broken.
#
# The real path, from a live BTI product page:
#
#   https://bti-usa.com/images/pictures/ma/ma3512a.jpg
#                             ^^^^^^^^^
#
# BTI sends image_paths as "/1k/1k506009.jpg", so the base needs /pictures and
# their path slots in after it:
#
#   https://bti-usa.com/images/pictures  +  /1k/1k506009.jpg
#
# The host also drops the www: their own pages serve images from bti-usa.com
# without it. Both forms resolve, but matching what they publish avoids a
# redirect on every image request.
#
# Fixed in BOTH places, because one without the other leaves whichever path
# runs second still wrong — the config default feeds BtiClient::images(), the
# seeder arg feeds the catalog sync.
set -e

python3 <<'PY'
import io

OLD = 'https://www.bti-usa.com/images'
NEW = 'https://bti-usa.com/images/pictures'

# ---------------------------------------------------------------- config
p = 'config/distributors.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-BTI-IMAGE-BASE' not in s, 'already applied'
assert OLD in s, 'config no longer holds the old base'

old = """        'image_base'     => env('BTI_IMAGE_BASE', 'https://www.bti-usa.com/images'),"""
assert s.count(old) == 1, 'C1 image_base anchor'
s = s.replace(old, """        // MARKER-BTI-IMAGE-BASE — product photos live under /images/pictures,
        // not /images. Confirmed against a live BTI page:
        //   https://bti-usa.com/images/pictures/ma/ma3512a.jpg
        // BTI sends image_paths as "/1k/1k506009.jpg", which appends to this.
        'image_base'     => env('BTI_IMAGE_BASE', 'https://bti-usa.com/images/pictures'),""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- seeder
p = 'database/seeders/BtiFieldMapSeeder.php'
s = io.open(p, encoding='utf-8').read()

old = """                'prefix' => 'https://www.bti-usa.com/images',"""
assert s.count(old) == 1, 'S1 seeder prefix anchor'
s = s.replace(old, """                // MARKER-BTI-IMAGE-BASE — /images 404s; photos are under
                // /images/pictures. Verified: bti-usa.com/images/pictures/ma/ma3512a.jpg
                'prefix' => 'https://bti-usa.com/images/pictures',""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- the old base is gone from both places ---"
grep -rn "www.bti-usa.com/images" config/distributors.php database/seeders/BtiFieldMapSeeder.php || echo "  none remaining"

echo
echo "--- and the new one is in both ---"
grep -n "images/pictures" config/distributors.php database/seeders/BtiFieldMapSeeder.php

echo
echo "--- the URL this produces for a real BTI path ---"
python3 - <<'PY'
def split_pipe(raw, prefix, sep='|'):
    out = []
    for part in str(raw).split(sep):
        part = part.strip()
        if not part:
            continue
        out.append(part if part.startswith('http')
                   else prefix.rstrip('/') + '/' + part.lstrip('/'))
    return out or None

pre = 'https://bti-usa.com/images/pictures'

# The real stored value from source_raw, and the known-good URL to match.
got  = split_pipe('/1k/1k506009.jpg', pre)[0]
want_shape = 'https://bti-usa.com/images/pictures/1k/1k506009.jpg'
print('  stored path : /1k/1k506009.jpg')
print('  built URL   :', got)
print('  matches the shape of the verified one:', got == want_shape)
assert got == want_shape

# Sanity against the URL we know loads.
verified = 'https://bti-usa.com/images/pictures/ma/ma3512a.jpg'
print('  verified URL:', verified)
print('  same prefix :', verified.startswith(pre))
assert verified.startswith(pre)

# Multiple paths, and an absolute one, still behave.
multi = split_pipe('/1k/a.jpg|/1k/b.jpg|https://cdn.example/c.jpg', pre)
print('  three paths ->', len(multi), 'urls, absolute preserved:', multi[2].startswith('https://cdn.example'))
assert len(multi) == 3
PY

echo
echo "--- balance ---"
python3 - <<'PY'
import io
for p in ['config/distributors.php', 'database/seeders/BtiFieldMapSeeder.php']:
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
    print('%-32s braces %d parens %d brackets %d' % (p.split('/')[-1], d, par, brk))
PY

echo
echo "apply-bti-image-base: OK"
