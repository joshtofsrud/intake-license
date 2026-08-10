#!/usr/bin/env bash
set -euo pipefail
# apply-marketing-traffic-registration.sh — MARKER-MKTREG
# The admin panel lists its pages EXPLICITLY (->pages([...]) with no
# discoverPages()), so a page class has no ROUTE at all until it appears in
# that array — it isn't merely missing from navigation, the URL 404s.
#
# AdminPanelProvider already carries comments from previous times this bit:
# MARKER-REVIEW-PAGE, MARKER-MATCH-REVIEW, MARKER-CATALOG-COVERAGE,
# MARKER-CATALOG-LOOKUP-REG and MARKER-PLATFORM-MAIL all say some version of
# "this panel does not auto-discover". MarketingTraffic is the next one.
#
# Master admin lives at intake.works/admin, so the page lands at
#   intake.works/admin/marketing-traffic

PROV=app/Providers/Filament/AdminPanelProvider.php
PAGE=app/Filament/Pages/MarketingTraffic.php

[ -f "$PROV" ] || { echo "MISSING $PROV — run from the repo root"; exit 1; }
[ -f "$PAGE" ] || { echo "PRECONDITION FAILED: deploy apply-marketing-traffic-report.sh first"; exit 1; }

if grep -q "MARKER-MKTREG" "$PROV"; then
  echo "Already applied (MARKER-MKTREG present) — no-op."
  exit 0
fi

python3 - "$PROV" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """                \\App\\Filament\\Pages\\PlatformEmail::class, // MARKER-PLATFORM-MAIL — this panel lists pages explicitly; it does NOT auto-discover"""
new = """                \\App\\Filament\\Pages\\PlatformEmail::class, // MARKER-PLATFORM-MAIL — this panel lists pages explicitly; it does NOT auto-discover
                // MARKER-MKTREG — same trap again: without this line the page
                // has no route and 404s, nav or no nav. Any NEW Filament page
                // must be added here.
                \\App\\Filament\\Pages\\MarketingTraffic::class,"""

n = src.count(old)
if n != 1:
    print(f"FAIL page registration: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   MarketingTraffic registered")

open(path, 'w').write(src)
PY

php -l "$PROV"

echo ""
echo "SUCCESS — apply-marketing-traffic-registration applied."
echo "Page lands at intake.works/admin/marketing-traffic"
