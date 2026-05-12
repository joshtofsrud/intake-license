#!/bin/bash
# ============================================================================
# patch-48-mobile-nav-toolbar-gap.sh
# ----------------------------------------------------------------------------
# Problem: iOS Chrome's bottom toolbar collapses on scroll, viewport expands,
# and the fixed-position bottom nav rides up the screen — leaving visible
# page background where the toolbar used to be.
#
# Conservative fix: add a "safety apron" below the nav using a ::after
# pseudo-element that extends nav's background color into the area below it.
# If the viewport expands by N pixels during toolbar collapse, the apron
# fills that gap. The nav itself stays at bottom:0 (current behavior); we're
# only masking the visible gap.
#
# This is a band-aid, not a true positioning fix — but it's CSS-only, low-risk,
# and won't regress the previous transform/will-change problem the existing
# comment warns about.
# ============================================================================

set -euo pipefail
cd "${REPO_ROOT:-$(pwd)}"

if [ ! -f "public/css/tenant/mobile-nav.css" ]; then
  echo "ERROR: not in project root" >&2
  exit 1
fi

python3 <<'PYEOF'
from pathlib import Path
p = Path("public/css/tenant/mobile-nav.css")
s = p.read_text()

if "Patch 48: toolbar gap apron" in s:
    print("    SKIP — already patched")
    raise SystemExit(0)

# Anchor: end of the .ia-mobile-nav block. Inject ::after rules after it.
anchor = """  .ia-mobile-nav {
    display: flex;
    justify-content: space-around;
    align-items: stretch;
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: #ffffff;
    border-top: 0.5px solid rgba(0, 0, 0, .1);
    padding: 6px 2px calc(6px + env(safe-area-inset-bottom, 0px));
    z-index: 100;
    box-shadow: 0 -4px 12px rgba(0, 0, 0, .06);
  }"""

addition = """

  /* Patch 48: toolbar gap apron.
     iOS Chrome's bottom toolbar collapses on scroll, the viewport expands,
     and any gap between the nav's bottom edge and the new viewport edge
     shows the page background underneath. The ::after element extends the
     nav's background color downward by 120px (well more than any toolbar
     height) so any expansion gap is filled with nav-colored background
     instead of black/page-bg showing through.
     Pure visual masking — does not change positioning, layout, or tap
     targets. Pointer-events:none so taps in the apron area don't intercept
     anything (Safari's URL bar tap-to-show, etc). */
  .ia-mobile-nav::after {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    top: 100%;
    height: 120px;
    background: inherit;
    pointer-events: none;
  }"""

if s.count(anchor) != 1:
    raise SystemExit(f"ABORT: nav block anchor count = {s.count(anchor)}")

s = s.replace(anchor, anchor + addition, 1)
p.write_text(s)
print("    UPDATED mobile-nav.css — added toolbar gap apron")
PYEOF

cat <<EONOTE

==> Patch 48 applied locally.

Deploy:
  git add public/css/tenant/mobile-nav.css
  git commit -m "fix: mask iOS Chrome toolbar-collapse gap below bottom nav (patch 48)"
  git push

On server:
  cd /var/www/intake
  git pull
  # No artisan commands needed — pure CSS change.
  # Hard-refresh on phone (or close + reopen tab) to dodge CSS cache.

Verify:
  - On iOS Chrome: scroll down a tenant page. When Chrome's bottom toolbar
    collapses, the bottom nav should still appear seated against the
    viewport edge (apron fills any expansion gap).
  - On iOS Safari, Android Chrome, desktop: no visible change.

If it doesn't help:
  The apron is masking visible bg color but the nav itself may still be
  drifting up. Real fix is reworking the layout to use 100dvh-aware
  positioning. That's morning work.
EONOTE
