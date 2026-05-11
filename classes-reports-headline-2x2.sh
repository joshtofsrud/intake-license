#!/bin/bash
# ============================================================================
# classes-reports-headline-2x2.sh   (patch #36)
# ----------------------------------------------------------------------------
# Tiny follow-up to #35. Headline strip on /admin/classes/reports collapses
# from 4 → 2 → 1 col across breakpoints. The 1-col stack at ≤480px is too
# tall — 4 cards in a row each ~120px = ~480px of vertical space just for
# the headline. Drop the 1-col rule and keep 2×2 down to phone width.
#
# Also tightens the 2-col card padding/font sizes so 2×2 stays legible
# at iPhone SE width (375px) — numbers shrink slightly, padding tightens.
#
# Files touched:
#   resources/views/tenant/classes/reports.blade.php   (~6 lines of CSS)
#
# Deploy:
#   git pull && php artisan view:clear && \
#   sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm
# ============================================================================

set -euo pipefail
REPO_ROOT="${REPO_ROOT:-$(pwd)}"
cd "$REPO_ROOT"

echo "==> Patch 36: keep classes reports headline 2×2 on narrow phones"

python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/classes/reports.blade.php")
s = p.read_text()

# 1. Remove the 480px 1-col rule.
old_480 = """
@media (max-width: 480px) {
  .rp-headline { grid-template-columns: 1fr; }
}
"""

if old_480 not in s:
    print("    SKIP — 480px 1-col rule not found (already removed or never there)")
else:
    s = s.replace(old_480, "\n")
    print("    REMOVED 480px 1-col rule from .rp-headline")

# 2. Tighten the 768px headline rules so 2×2 stays legible all the way down.
#    Replace the existing block with a slightly tighter version.
old_768 = """  /* Headline strip: 4 → 2 → 1 (mobile) */
  .rp-headline { grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 18px; }
  .rp-head-card { padding: 14px 14px; }
  .rp-head-num { font-size: 24px; }
  .rp-head-label { font-size: 12px; }
  .rp-head-sub { font-size: 11px; }"""

new_768 = """  /* Headline strip: 4 → 2x2 (stays 2-col down to phone width).
     Padding + number size tighten so 2×2 stays legible at iPhone SE width. */
  .rp-headline { grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 18px; }
  .rp-head-card { padding: 12px 12px; }
  .rp-head-card::before { left: 0; top: 12px; bottom: 12px; }
  .rp-head-num { font-size: 22px; margin-bottom: 2px; }
  .rp-head-label { font-size: 11.5px; line-height: 1.25; }
  .rp-head-sub { font-size: 10.5px; line-height: 1.3; margin-top: 2px; }"""

if old_768 not in s:
    if "rp-head-num { font-size: 22px" in s:
        print("    SKIP — 768px headline block already tightened")
    else:
        raise SystemExit("ABORT: 768px headline anchor not found")
else:
    s = s.replace(old_768, new_768)
    print("    TIGHTENED 768px headline rules for 2×2 legibility")

p.write_text(s)
PYEOF

cat <<EONOTE

==> Patch 36 applied locally.

Deploy:
  git add -A
  git commit -m "fix(classes-reports): keep headline 2×2 on narrow phones (#36)"
  git push

On server:
  git pull
  php artisan view:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm
EONOTE
