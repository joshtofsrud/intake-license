#!/bin/bash
# ============================================================================
# patch-59-elig-chip-visual-treatment.sh
# ----------------------------------------------------------------------------
# Replaces the strikethrough treatment on excluded staff-eligibility chips
# with a dimmed/dashed treatment, fades the staff color dot to neutral gray
# when excluded, and adds a small "N of M selected" count when in specific
# (restricted) mode.
#
# Why: strikethrough reads as "deleted from system" rather than "available
# but not selected." Most users seeing 4 of 5 staff struck through assume
# they accidentally archived staff members. Dimmed + dashed is the standard
# metaphor for "this is an option, not currently chosen."
#
# Files touched:
#   - resources/views/tenant/services/index.blade.php  (CSS: chip.is-off + dot)
#   - public/js/tenant/services.js  (markup: add count indicator)
#   - resources/views/tenant/help/index.blade.php  (docs: "struck through" → "dimmed")
#
# Helper-text wording in JS already covers both states correctly and reads
# fine with the new visual treatment — left as-is.
# ============================================================================

set -euo pipefail
cd "${REPO_ROOT:-$(pwd)}"

if [ ! -f "resources/views/tenant/services/index.blade.php" ]; then
  echo "ERROR: services/index.blade.php not found." >&2
  exit 1
fi
if [ ! -f "public/js/tenant/services.js" ]; then
  echo "ERROR: public/js/tenant/services.js not found." >&2
  exit 1
fi

# ─── 1. CSS: replace strikethrough with dimmed/dashed treatment ────────
python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/services/index.blade.php")
s = p.read_text()

old_off = ".sv-elig-chip.is-off{background:transparent;border-color:var(--ia-border);color:var(--ia-text-3);text-decoration:line-through;opacity:.7}"
new_off = ".sv-elig-chip.is-off{background:transparent;border-style:dashed;border-color:var(--ia-border);color:var(--ia-text-3);opacity:.42}"

if "border-style:dashed;border-color:var(--ia-border);color:var(--ia-text-3);opacity:.42" in s:
    print("    SKIP chip.is-off — already converted to dashed/dimmed")
elif old_off not in s:
    raise SystemExit("ABORT chip.is-off: anchor not found")
else:
    s = s.replace(old_off, new_off, 1)
    print("    UPDATED — .sv-elig-chip.is-off: strikethrough → dashed border + opacity .42")

old_off_dot = ".sv-elig-chip.is-off > span:first-child{filter:saturate(.2) brightness(.7);opacity:.5}"
new_off_dot = ".sv-elig-chip.is-off > span:first-child{background:var(--ia-text-3) !important;filter:saturate(0);opacity:.6}"

if "background:var(--ia-text-3) !important;filter:saturate(0)" in s:
    print("    SKIP chip.is-off dot — already neutralized")
elif old_off_dot not in s:
    raise SystemExit("ABORT chip.is-off dot: anchor not found")
else:
    s = s.replace(old_off_dot, new_off_dot, 1)
    print("    UPDATED — excluded chip dot now neutral gray (was just desaturated)")

old_off_hover = ".sv-elig-chip.is-off:hover{opacity:1;color:var(--ia-text-2);border-color:var(--ia-border-strong)}"
new_off_hover = ".sv-elig-chip.is-off:hover{opacity:.85;color:var(--ia-text-2);border-color:var(--ia-border-strong)}"

if "is-off:hover{opacity:.85" in s:
    print("    SKIP chip.is-off:hover — already toned down")
elif old_off_hover not in s:
    raise SystemExit("ABORT chip.is-off:hover: anchor not found")
else:
    s = s.replace(old_off_hover, new_off_hover, 1)
    print("    UPDATED — .sv-elig-chip.is-off:hover: opacity 1 → 0.85 (less jarring jump)")

# Add count indicator CSS just after the hint rule.
old_hint = ".sv-elig-hint{font-size:11.5px;color:var(--ia-text-3);margin-top:8px;line-height:1.5}"
new_hint = """.sv-elig-hint{font-size:11.5px;color:var(--ia-text-3);margin-top:8px;line-height:1.5}
.sv-elig-count{display:inline-block;font-size:11px;color:var(--ia-text-3);align-self:center;margin-left:4px;font-variant-numeric:tabular-nums}"""

if ".sv-elig-count{" in s:
    print("    SKIP count CSS — already present")
elif old_hint not in s:
    raise SystemExit("ABORT count CSS: hint anchor not found")
else:
    s = s.replace(old_hint, new_hint, 1)
    print("    UPDATED — added .sv-elig-count CSS class")

p.write_text(s)
PYEOF

# ─── 2. JS: render the count indicator when in specific mode ───────────
python3 <<'PYEOF'
from pathlib import Path
p = Path("public/js/tenant/services.js")
s = p.read_text()

# The chip-builder ends with the join, then defines `hint` and returns the
# wrapper div. We inject a `countMarkup` variable computed in the same scope
# and append it inside the chip container.
old_return = """    var hint = allEligible
      ? 'Anyone can perform this service. Click a chip to limit eligibility to specific staff.'
      : 'Click an excluded chip to add. Click a selected chip to remove. Deselect all to allow anyone again.';

    return '<div class="sv-drawer-field">'
      + '<label class="sv-drawer-label">Available with</label>'
      + '<div class="sv-elig-chips">' + chips + '</div>'
      + '<div class="sv-elig-hint">' + hint + '</div>'
    + '</div>';
  }"""

new_return = """    var hint = allEligible
      ? 'Anyone can perform this service. Click a chip to limit eligibility to specific staff.'
      : 'Click an excluded chip to add. Click a selected chip to remove. Deselect all to allow anyone again.';

    // When in specific (restricted) mode, show a small "N of M selected"
    // count after the chips so the eligibility state is glanceable.
    var countMarkup = '';
    if (!allEligible) {
      countMarkup = '<span class="sv-elig-count">'
        + ids.length + ' of ' + state.resources.length + ' selected'
        + '</span>';
    }

    return '<div class="sv-drawer-field">'
      + '<label class="sv-drawer-label">Available with</label>'
      + '<div class="sv-elig-chips">' + chips + countMarkup + '</div>'
      + '<div class="sv-elig-hint">' + hint + '</div>'
    + '</div>';
  }"""

if "sv-elig-count" in s:
    print("    SKIP JS — count indicator already wired")
elif old_return not in s:
    raise SystemExit("ABORT JS: chip-builder return anchor not found")
else:
    s = s.replace(old_return, new_return, 1)
    p.write_text(s)
    print("    UPDATED — JS now renders 'N of M selected' count when restricted")
PYEOF

# ─── 3. Help text: update "struck through" wording ─────────────────────
python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/help/index.blade.php")
s = p.read_text()

old_help1 = "<strong>Some chips lime, others struck through</strong>"
new_help1 = "<strong>Some chips lime, others dimmed</strong>"

if "Some chips lime, others dimmed" in s:
    print("    SKIP help text 1 — already updated")
elif old_help1 not in s:
    raise SystemExit("ABORT help text 1: anchor not found")
else:
    s = s.replace(old_help1, new_help1, 1)
    print("    UPDATED — help text: 'struck through' → 'dimmed'")

old_help2 = "<strong>Click a struck-through chip</strong> to add it back."
new_help2 = "<strong>Click a dimmed chip</strong> to add it back."

if "Click a dimmed chip" in s:
    print("    SKIP help text 2 — already updated")
elif old_help2 not in s:
    raise SystemExit("ABORT help text 2: anchor not found")
else:
    s = s.replace(old_help2, new_help2, 1)
    print("    UPDATED — help text: 'struck-through chip' → 'dimmed chip'")

p.write_text(s)
PYEOF

cat <<EONOTE

==> Patch 59 applied locally.

Deploy:
  git add resources/views/tenant/services/index.blade.php \\
          public/js/tenant/services.js \\
          resources/views/tenant/help/index.blade.php \\
          patch-59-elig-chip-visual-treatment.sh
  git commit -m "fix: excluded staff chips use dimmed/dashed instead of strikethrough (patch 59)"
  git push

On server:
  cd /var/www/intake
  git pull
  php artisan view:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

Verify on a tenant with multiple staff (Mountainview Fitness):
  1. /admin/services, open Personal Training (60 min) drawer
  2. Click any staff chip to enter restricted mode
  3. Excluded chips are now dashed-border + dimmed (no strikethrough)
  4. Their staff color dots fade to neutral gray
  5. "1 of 5 selected" appears next to the chips
  6. Click the selected chip again to deselect — all chips return to blue "is-all" state
  7. Hover an excluded chip — opacity bumps to .85, not jarring jump

JS edits ship as a static asset (public/js/tenant/services.js). After git
pull the new file is in place; no asset compilation needed. Browser may
cache the old JS — hard-refresh (Cmd+Shift+R) to verify.
EONOTE
