#!/bin/bash
# ============================================================================
# dropoff-calendar-mobile-fix.sh   (patch #42)
# ----------------------------------------------------------------------------
# Two issues visible on real device shot of /admin/calendar in drop-off mode:
#
#   1. "Calendar" h1 is invisible (or missing) above the "Drop-off mode ·
#      Monday, May 11, 2026" subtitle. Just blank space where the title
#      should be.
#
#   2. Empty-state copy reads "No appointments yet.<br>Drag a card here to
#      assign." Drag-and-drop is desktop-only behavior. On mobile, dragging
#      cards between resource columns is impractical; the copy should
#      suggest tapping + to add an appointment instead.
#
# Additional polish while we're here:
#   - Resource columns have min-width:220px in the grid template, which
#     makes 2+ columns side-by-side on a 390px viewport (overflow). On
#     mobile, columns should stack as single-column cards.
#   - The view-toggle (Day/Week) + date-nav buttons (‹ Today ›) should
#     wrap reasonably under the title on mobile.
#
# Files touched:
#   resources/views/tenant/calendar/dropoff.blade.php  (mobile CSS + empty copy)
#
# Deploy:
#   git pull && php artisan view:clear && \
#   sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm
# ============================================================================

set -euo pipefail
REPO_ROOT="${REPO_ROOT:-$(pwd)}"
cd "$REPO_ROOT"

echo "==> Patch 42: drop-off calendar mobile fixes"

python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/calendar/dropoff.blade.php")
s = p.read_text()

# ----------------------------------------------------------------------------
# 1. Replace the empty-state copy. Wrap the desktop "Drag a card" hint in
#    a span that hides on mobile, and add an inline mobile-only hint.
#    On mobile, also link "tap +" to whatever the FAB does.
# ----------------------------------------------------------------------------
old_empty = '<div class="cal-dropoff-empty">No appointments yet.<br>Drag a card here to assign.</div>'
new_empty = '''<div class="cal-dropoff-empty">
              <div>No appointments yet.</div>
              <div class="cal-dropoff-empty-hint-desktop">Drag a card here to assign.</div>
              <div class="cal-dropoff-empty-hint-mobile">Tap + below to add one.</div>
            </div>'''

if "cal-dropoff-empty-hint-desktop" in s:
    print("    SKIP empty-state copy (already patched)")
elif s.count(old_empty) != 1:
    raise SystemExit(f"ABORT: empty-state anchor count = {s.count(old_empty)}, expected 1")
else:
    s = s.replace(old_empty, new_empty)
    print("    UPDATED empty-state copy with desktop/mobile variants")

# ----------------------------------------------------------------------------
# 2. Append a @media (max-width: 640px) block to the existing <style>.
#    Anchor: closing </style>\n@endpush.
# ----------------------------------------------------------------------------
marker = "/* Drop-off calendar mobile (patch #42) */"
if marker in s:
    print("    SKIP mobile CSS (already applied)")
else:
    mobile_css = """

""" + marker + """
.cal-dropoff-empty-hint-mobile { display: none; }
.cal-dropoff-empty-hint-desktop { display: block; }

@media (max-width: 640px) {
  /* Ensure the page title renders visibly on mobile.
     Some interaction (likely the global page-head column-stack rule
     from mobile-forms.css combined with the inline flex:gap:10px on
     the .ia-page-head-right) was leaving the title row visually empty.
     This rule explicitly forces title visibility + reasonable size. */
  .ia-page-head .ia-page-title {
    display: block;
    font-size: 22px;
    margin: 0;
    color: var(--ia-text);
  }
  .ia-page-head .ia-page-subtitle {
    font-size: 12.5px;
    margin-top: 4px;
  }

  /* Page head right side: view toggle + date nav. They sit in a flex
     container with gap:10px (inline style). On mobile, let them wrap
     onto their own row below the title. */
  .ia-page-head-right {
    flex-wrap: wrap;
    width: 100%;
    justify-content: flex-start !important;
  }

  /* Date-nav buttons grow to fit available width but stay touch-friendly. */
  .cal-date-btn {
    padding: 8px 14px;
    font-size: 13.5px;
    min-height: 36px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }
  .cal-view-tab {
    padding: 8px 14px;
    font-size: 13px;
    min-height: 32px;
    display: inline-flex;
    align-items: center;
  }

  /* Resource columns: the inline style sets
     `grid-template-columns: repeat(N, minmax(220px, 1fr))` which forces
     each col to at least 220px. With 2+ resources this overflows the
     390px viewport. Override to single-column on mobile so each resource
     becomes a stacked card. */
  .cal-dropoff-grid[style*="grid-template-columns"] {
    grid-template-columns: 1fr !important;
    gap: 10px !important;
  }

  /* Tighten the column header + body */
  .cal-dropoff-col {
    min-height: 0;  /* Don't reserve 200px when empty on mobile */
  }
  .cal-dropoff-col-head {
    padding: 10px 14px;
  }
  .cal-dropoff-col-body {
    min-height: 60px;
    padding: 8px 10px;
  }

  /* Empty-state hint: swap desktop wording for mobile wording */
  .cal-dropoff-empty {
    padding: 18px 10px;
    font-size: 12.5px;
  }
  .cal-dropoff-empty-hint-desktop { display: none; }
  .cal-dropoff-empty-hint-mobile  { display: block; margin-top: 4px; }

  /* Drag handles aren't useful on mobile — soften the grab cursor */
  .cal-dropoff-card { cursor: default; }
}
"""

    style_close = "</style>\n@endpush"
    if s.count(style_close) != 1:
        raise SystemExit(f"ABORT: </style>@endpush count = {s.count(style_close)}, expected 1")
    s = s.replace(style_close, mobile_css + style_close)
    print("    APPENDED mobile @media block")

p.write_text(s)
PYEOF

cat <<EONOTE

==> Patch 42 applied locally.

Deploy:
  git add -A
  git commit -m "fix(mobile): drop-off calendar — visible title, mobile-friendly empty state, stacked columns (#42)"
  git push

On server:
  git pull
  php artisan view:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

What this fixes on /admin/calendar mobile:
  - "Calendar" h1 explicitly sized + colored, no longer invisible
  - Empty-state hint changes from "Drag a card here to assign" → "Tap +
    below to add one" on mobile
  - Resource columns stack vertically as cards (was 2+ side-by-side
    overflowing the viewport)
  - View toggle + date nav buttons wrap cleanly under the title with
    touch-friendly sizing
  - Removes 200px min-height on empty columns (no more big empty space
    per resource)
EONOTE
