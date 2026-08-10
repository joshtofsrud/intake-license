#!/usr/bin/env bash
set -euo pipefail
# apply-features-grid-all-categories.sh — MARKER-FGCATS
# Add-ons in an unlisted category are INVISIBLE in master admin, so they can't
# be granted or revoked from the Features tab at all.
#
# features-grid.blade.php builds $grouped = $features->groupBy('category') and
# then loops a HARDCODED list of four:
#     communication / operations / feature / onboarding
# Anything else is silently skipped. `online_store` has category 'retail', so
# the Online Store card is never drawn — which is exactly why the ecommerce
# chip isn't on that screen.
#
# It is not just ecommerce. Categories present in the addon migrations but NOT
# in that list: 'retail' (4 addons — retail, pos, multi_location_pos,
# online_store) and 'team'. Five add-ons unmanageable from master admin.
#
# FIX: derive the sections from what actually exists. Known categories keep
# their curated labels and ordering; anything else is appended with a
# humanised label instead of vanishing. A new category added by a future
# migration can never silently disappear again.

VIEW=resources/views/filament/relations/features-grid.blade.php
[ -f "$VIEW" ] || { echo "MISSING $VIEW — run from the repo root"; exit 1; }

if grep -q "MARKER-FGCATS" "$VIEW"; then
  echo "Already applied (MARKER-FGCATS present) — no-op."
  exit 0
fi

python3 - "$VIEW" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """        @php
            $categoryLabels = [
                'communication' => 'Communication',
                'operations'    => 'Operations',
                'feature'       => 'Tier features',
                'onboarding'    => 'Onboarding',
            ];
        @endphp"""

new = """        @php
            /* MARKER-FGCATS — this list used to be the ONLY thing rendered, so
               any addon in another category (e.g. 'retail', which is where
               online_store lives) was invisible here and could not be granted
               or revoked at all. Curated labels and order are kept for the
               categories we know; everything else is appended rather than
               dropped, so a category added by a future migration can't
               silently disappear. */
            $knownLabels = [
                'communication' => 'Communication',
                'operations'    => 'Operations',
                'retail'        => 'Retail & ecommerce',
                'team'          => 'Team',
                'feature'       => 'Tier features',
                'onboarding'    => 'Onboarding',
            ];

            $categoryLabels = [];
            foreach ($knownLabels as $ckey => $clabel) {
                if (isset($grouped[$ckey]) && $grouped[$ckey]->count()) {
                    $categoryLabels[$ckey] = $clabel;
                }
            }
            foreach ($grouped as $ckey => $rows) {
                if (! isset($categoryLabels[$ckey]) && $rows->count()) {
                    $categoryLabels[$ckey] = ucfirst(str_replace('_', ' ', (string) $ckey));
                }
            }
        @endphp"""

n = src.count(old)
if n != 1:
    print(f"FAIL category list: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   grid renders every category present")

open(path, 'w').write(src)
PY

echo ""
echo "SUCCESS — apply-features-grid-all-categories applied."
echo "Online Store now appears under 'Retail & ecommerce' with its Activate button."
