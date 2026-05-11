#!/bin/bash
# ============================================================================
# inventory-empty-card-fix.sh   (patch #39)
# ----------------------------------------------------------------------------
# Tiny follow-up to #38. On the inventory list mobile view, the empty desktop
# wrapper .ia-card was still rendering — visible as an empty rounded
# rectangle between the search bar and the first product card.
#
# Reason: in #38 the mobile CSS hides .ia-table-wrap inside the desktop
# .ia-card, but the outer .ia-card itself (with its background + border +
# padding) stays visible. With items present, the table inside hides, but
# the empty card shell remains, taking ~80px of vertical space.
#
# Fix: tag the inventory table's .ia-card with a marker class so we can
# safely hide it on mobile without affecting the page's "Get started"
# .ia-card at the top (different content). Also covers the receiving page
# which has the same shape.
#
# Files touched:
#   resources/views/tenant/inventory/index.blade.php
#   resources/views/tenant/inventory/receiving/index.blade.php
#
# Deploy:
#   git pull && php artisan view:clear && \
#   sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm
# ============================================================================

set -euo pipefail
REPO_ROOT="${REPO_ROOT:-$(pwd)}"
cd "$REPO_ROOT"

echo "==> Patch 39: hide empty desktop .ia-card on mobile inventory + receiving"

# ----------------------------------------------------------------------------
# 1. Inventory list — tag the table card + add mobile-hide CSS
# ----------------------------------------------------------------------------
python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/inventory/index.blade.php")
s = p.read_text()

if "inv-desk-card" in s:
    print("    SKIP inventory list (already patched)")
else:
    # 1a. Tag the desktop table's .ia-card with a marker class.
    #     The page also has a "Get started" .ia-card at the top — we must
    #     NOT tag that one. Anchor on the unique context (right above the
    #     @if($items->isEmpty())).
    old = """<div class="ia-card">
  @if($items->isEmpty())
    <div class="ia-card-body" style="text-align:center;padding:40px 20px;color:var(--ia-text-muted)">
      No items match your filters.
    </div>"""
    new = """<div class="ia-card inv-desk-card">
  @if($items->isEmpty())
    <div class="ia-card-body" style="text-align:center;padding:40px 20px;color:var(--ia-text-muted)">
      No items match your filters.
    </div>"""
    if s.count(old) != 1:
        raise SystemExit(f"ABORT: inventory desk-card anchor count = {s.count(old)}, expected 1")
    s = s.replace(old, new)

    # 1b. Add the hide rule to the existing @media block. The block ends
    #     with `}` followed by `</style>`. We insert one rule before that.
    old_media_close = """  .inv-sheet-overlay,
  .inv-sheet{display:block}
}
</style>"""
    new_media_close = """  .inv-sheet-overlay,
  .inv-sheet{display:block}
  /* Hide the desktop table wrapper on mobile so its empty .ia-card
     shell doesn't render between the search bar and the mobile cards. */
  .inv-desk-card{display:none}
}
</style>"""
    if s.count(old_media_close) != 1:
        raise SystemExit(f"ABORT: inventory media-close anchor not found")
    s = s.replace(old_media_close, new_media_close)

    p.write_text(s)
    print("    UPDATED inventory/index.blade.php — tagged desk card + added hide rule")
PYEOF

# ----------------------------------------------------------------------------
# 2. Receiving list — same treatment
# ----------------------------------------------------------------------------
python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/inventory/receiving/index.blade.php")
s = p.read_text()

if "recv-desk-card" in s:
    print("    SKIP receiving list (already patched)")
else:
    # The receiving page only has ONE .ia-card (the table wrapper) — no
    # "Get started" card up top to confuse things — but we still tag it
    # for clarity and to match the pattern.
    old = """<div class="ia-card">
  @if($shipments->isEmpty())"""
    new = """<div class="ia-card recv-desk-card">
  @if($shipments->isEmpty())"""
    if s.count(old) != 1:
        raise SystemExit(f"ABORT: receiving desk-card anchor count = {s.count(old)}, expected 1")
    s = s.replace(old, new)

    # Add the hide rule to the existing @media block on this page.
    old_media_close = """  .recv-tabs-m{display:flex}
  .recv-mobile{display:block}
}
</style>"""
    new_media_close = """  .recv-tabs-m{display:flex}
  .recv-mobile{display:block}
  /* Hide the desktop table wrapper on mobile so its empty .ia-card shell
     doesn't render between the tabs and the mobile shipment cards. */
  .recv-desk-card{display:none}
}
</style>"""
    if s.count(old_media_close) != 1:
        raise SystemExit(f"ABORT: receiving media-close anchor not found")
    s = s.replace(old_media_close, new_media_close)

    p.write_text(s)
    print("    UPDATED receiving/index.blade.php — tagged desk card + added hide rule")
PYEOF

cat <<EONOTE

==> Patch 39 applied locally.

Deploy:
  git add -A
  git commit -m "fix(mobile): hide empty desktop card on inventory + receiving list (#39)"
  git push

On server:
  git pull
  php artisan view:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm
EONOTE
