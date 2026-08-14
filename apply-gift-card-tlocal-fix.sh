#!/bin/bash
# apply-gift-card-tlocal-fix.sh
#
# MARKER-GC-TLOCAL — HOTFIX. /admin/gift-cards was throwing a 500:
#
#   Call to a member function format() on string
#   (View: resources/views/tenant/gift-cards/index.blade.php)
#
# My bug, from the gift card admin patch. tlocal() returns an already
# FORMATTED STRING (its signature is `: string`, second arg is the format),
# not a Carbon instance — so tlocal($x)->format('M j, Y') is fatal. The
# right call is tlocal_date($x) / tlocal($x, 'M j, g:i A'), which is what
# the rest of the app uses.
#
# Why it surfaced only now: the list renders an empty state until a card
# exists, so the loop containing the bad line never ran. The first real
# gift card made both admin pages unreachable. Three occurrences, all in
# the gift card views — the list, and TWO on the card detail page that
# would have fataled the moment anyone opened a card.
set -e

MARKER="MARKER-GC-TLOCAL"
LIST="resources/views/tenant/gift-cards/index.blade.php"
SHOW="resources/views/tenant/gift-cards/show.blade.php"

for f in "$LIST" "$SHOW"; do
  [ -f "$f" ] || { echo "ERROR: missing $f — run from the repo root"; exit 1; }
done
if grep -q "$MARKER" "$LIST" 2>/dev/null; then
  echo "ok: already applied ($MARKER present) — no-op"
  exit 0
fi

python3 - <<'PY'
import io

# --- list: last activity column -------------------------------------
p = 'resources/views/tenant/gift-cards/index.blade.php'
src = io.open(p, encoding='utf-8').read()
a = "{{ tlocal($r->updated_at)->format('M j, Y') }}"
assert src.count(a) == 1, 'list date not found'
src = src.replace(a, "{{ tlocal_date($r->updated_at) }}{{-- MARKER-GC-TLOCAL --}}", 1)
io.open(p, 'w', encoding='utf-8').write(src)
print('ok: list Last activity column')

# --- detail: issued date + ledger timestamps ------------------------
p2 = 'resources/views/tenant/gift-cards/show.blade.php'
s2 = io.open(p2, encoding='utf-8').read()

b = "· Issued {{ tlocal($card->created_at)->format('M j, Y') }}"
assert s2.count(b) == 1, 'issued date not found'
s2 = s2.replace(b, "· Issued {{ tlocal_date($card->created_at) }}{{-- MARKER-GC-TLOCAL --}}", 1)

c = "{{ tlocal($t->created_at)->format('M j, g:i A') }}"
assert s2.count(c) == 1, 'ledger timestamp not found'
s2 = s2.replace(c, "{{ tlocal($t->created_at, 'M j, g:i A') }}{{-- MARKER-GC-TLOCAL --}}", 1)

io.open(p2, 'w', encoding='utf-8').write(s2)
print('ok: detail issued date + ledger timestamps')
PY

# Sweep: no other view in the app makes the same mistake.
echo ""
echo "-- sweep for tlocal(...)->format( anywhere else --"
if grep -rn "tlocal([^)]*)->" resources/views/ 2>/dev/null; then
  echo "^^ REVIEW THESE — same fatal pattern"
else
  echo "clean: no other occurrences"
fi

echo ""
echo "== gift card tlocal fix applied =="
echo "Post-deploy: php artisan optimize:clear (compiled views are cached)"
