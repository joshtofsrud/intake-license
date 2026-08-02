#!/usr/bin/env bash
# apply-register-item-lookup-page.sh
# MARKER-CATALOG-LOOKUP-REG — the lookup page has no route until it is listed.
#
# AdminPanelProvider registers pages EXPLICITLY. It does not auto-discover, so
# a new class under app/Filament/Pages exists, compiles, caches — and is
# invisible, with no navigation entry and no route. Three comments already in
# that array say exactly this, each left by someone who had just been caught
# by it. I wrote the fourth page and skipped the step anyway.
set -e

python3 <<'PY'
import io

p = 'app/Providers/Filament/AdminPanelProvider.php'
s = io.open(p, encoding='utf-8').read()

assert 'CatalogItemLookup' not in s, 'already registered'

old = """                // MARKER-CATALOG-COVERAGE — explicit registration; this panel
                // does not auto-discover.
                \\App\\Filament\\Pages\\CatalogCoverage::class,"""
assert s.count(old) == 1, 'P1 coverage registration anchor'
s = s.replace(old, """                // MARKER-CATALOG-COVERAGE — explicit registration; this panel
                // does not auto-discover.
                \\App\\Filament\\Pages\\CatalogCoverage::class,
                // MARKER-CATALOG-LOOKUP-REG — same: no route without this line.
                \\App\\Filament\\Pages\\CatalogItemLookup::class,""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- every page class on disk is registered ---"
python3 - <<'PY'
import io, os, re
prov = io.open('app/Providers/Filament/AdminPanelProvider.php', encoding='utf-8').read()
listed = set(re.findall(r'Pages\\\\(\w+)::class', prov)) | set(re.findall(r'^\s*(\w+)::class', prov, re.M))
on_disk = {f[:-4] for f in os.listdir('app/Filament/Pages') if f.endswith('.php')}
missing = sorted(on_disk - listed)
print('registered:', len(on_disk) - len(missing), 'of', len(on_disk))
print('NOT registered:', missing or 'none')
PY

echo
echo "--- balance ---"
python3 - <<'PY'
import io
s = io.open('app/Providers/Filament/AdminPanelProvider.php', encoding='utf-8').read()
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
print('AdminPanelProvider braces', d, 'parens', par, 'brackets', brk)
PY

echo
echo "apply-register-item-lookup-page: OK"
