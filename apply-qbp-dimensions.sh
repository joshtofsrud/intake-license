#!/usr/bin/env bash
# apply-qbp-dimensions.sh
# MARKER-QBP-DIMS — flatten freight L/W/H onto the variant row.
#
# For trees where apply-qbp-adapter-build.sh already ran WITHOUT the
# Dimensions addition. The field map's `dimensions` row reads a flattened
# `Dimensions` key; without this, that row resolves null on every product —
# silently, which is the BTI-cost failure shape.
#
# Why the adapter and not the map: a dotted path cannot assemble three
# elements into one JSON column, and the resolver's zip_pipe zips pipe
# STRINGS — verified by reading it, not assumed.
set -e

python3 <<'PY'
import io

p = 'app/Services/Distributors/QbpClient.php'
s = io.open(p, encoding='utf-8').read()

if 'MARKER-QBP-DIMS' in s or "row['Dimensions']" in s:
    print('already present — nothing to do')
    raise SystemExit(0)

old = """        $row['ImageFile']    = trim((string) ($row['images']['image']['fileName'] ?? ''));"""
assert s.count(old) == 1, 'D1 variant anchor'
s = s.replace(old, """        $row['ImageFile']    = trim((string) ($row['images']['image']['fileName'] ?? ''));

        // MARKER-QBP-DIMS — flattened here because a dotted path cannot
        // assemble three elements into one JSON column, and zip_pipe zips
        // pipe strings, not element triples — checked, not assumed.
        $dims = [];
        foreach (['Length', 'Width', 'Height'] as $d) {
            $v = $row['freight'][$d]['value'] ?? null;
            if ($v !== null && trim((string) $v) !== '') {
                $dims[$d] = (float) $v;
            }
        }
        $row['Dimensions'] = $dims ?: null;""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- Dimensions emitted by variant() ---"
grep -n "row\['Dimensions'\]" app/Services/Distributors/QbpClient.php

echo
echo "--- balance ---"
python3 - <<'PY'
import io
s = io.open('app/Services/Distributors/QbpClient.php', encoding='utf-8').read()
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
print('QbpClient braces', d, 'parens', par, 'brackets', brk)
PY

echo
echo "apply-qbp-dimensions: OK"
