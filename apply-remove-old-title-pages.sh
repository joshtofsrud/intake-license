#!/usr/bin/env bash
# apply-remove-old-title-pages.sh
# MARKER-TITLE-CONTROL-CLEANUP — delete the two superseded title screens.
#
# Hiding them from navigation left the routes live, and the old Title
# templates page carries a real hazard: it writes size_attribute_priority
# from a form field, so saving with that box empty NULLS it for the scope and
# silently changes how {size} resolves. A page that can do that should not be
# one URL away.
#
# Verified before writing this: nothing references either class except the
# panel registration, and CatalogTitleControl depends on neither. The token
# constant that CatalogTitleReview borrowed lived on CatalogTitles, and both
# go together, so nothing is left pointing at a missing class.
#
# Deletion is git-tracked and the deploy is atomic, so recovery is a revert
# plus a redeploy — but this refuses to run unless the replacement is present,
# because removing the only title editors from a live system is not something
# to do on a half-applied tree.
set -e

python3 <<'PY'
import io, os

NEW = 'app/Filament/Pages/CatalogTitleControl.php'
assert os.path.exists(NEW), (
    'CatalogTitleControl is not present — run apply-title-control-page.sh first. '
    'Refusing to delete the only title editors.'
)

# ---------------------------------------------------------------- provider
p = 'app/Providers/Filament/AdminPanelProvider.php'
s = io.open(p, encoding='utf-8').read()
assert 'CatalogTitleControl' in s, 'the replacement is not registered — run the page patch first'

before = s
for line in [
    "                \\App\\Filament\\Pages\\CatalogTitleReview::class,\n",
    "                \\App\\Filament\\Pages\\CatalogTitles::class, // MARKER-PATCH-HLCE2 title editor\n",
    "                \\App\\Filament\\Pages\\CatalogTitles::class,\n",
]:
    s = s.replace(line, '')

assert s != before, 'no registration lines matched'
assert 'CatalogTitleReview::class' not in s, 'CatalogTitleReview still registered'
assert 'CatalogTitles::class' not in s, 'CatalogTitles still registered'

io.open(p, 'w', encoding='utf-8').write(s)
print('unregistered both from', p)

# ---------------------------------------------------------------- files
for f in [
    'app/Filament/Pages/CatalogTitles.php',
    'app/Filament/Pages/CatalogTitleReview.php',
    'resources/views/filament/pages/catalog-titles.blade.php',
    'resources/views/filament/pages/catalog-title-review.blade.php',
]:
    if os.path.exists(f):
        os.remove(f)
        print('deleted', f)
    else:
        print('already gone', f)
PY

echo
echo "--- nothing references the removed classes ---"
if grep -rn "CatalogTitles\b\|CatalogTitleReview\b" app/ resources/ routes/ config/ --include=*.php --include=*.blade.php 2>/dev/null; then
  echo "  *** still referenced — do not commit ***"
  exit 1
else
  echo "  clean"
fi

echo
echo "--- the replacement is registered ---"
grep -n "CatalogTitleControl" app/Providers/Filament/AdminPanelProvider.php

echo
echo "--- every page class on disk is registered ---"
for f in app/Filament/Pages/*.php; do
  n=$(basename "$f" .php)
  grep -q "$n::class" app/Providers/Filament/AdminPanelProvider.php \
    && printf "  ok  %s\n" "$n" || printf "  --  %s NOT REGISTERED\n" "$n"
done

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
echo "apply-remove-old-title-pages: OK"
