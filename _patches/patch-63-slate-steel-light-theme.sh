#!/bin/bash
# ============================================================================
# patch-63-slate-steel-light-theme.sh
# ----------------------------------------------------------------------------
# Refreshes the light theme (theme-b) to use a cool slate palette and changes
# the default tenant accent color from lime (#BEF264) to slate-steel (#3B5A78).
#
# Two coordinated changes:
#
# 1. public/css/tenant/theme-b.css palette
#    - Surfaces shift from warm off-white to cool gray
#    - Borders slate-tinted (rgba(15,20,25,...)) instead of neutral black
#    - Text near-black slate (#0F1419) instead of pure black
#    - Sidebar from pure black (#0f0f0f) to deep slate (#1E2A3A) — matches the
#      same family as the new primary accent
#
# 2. resources/views/layouts/tenant/app.blade.php — three accent fallback
#    values from #BEF264 to #3B5A78. Tenants with customized accent unchanged.
#
# Combined effect:
#   - Tenants on default accent + theme-b (light): full slate-steel look
#   - Tenants on default accent + theme-a/c (dark): slate-steel where lime was
#   - Tenants with custom accent: keep their color, get the refreshed light-
#     theme neutrals if they're on theme-b
#
# Files touched:
#   - public/css/tenant/theme-b.css                      (palette rewrite)
#   - resources/views/layouts/tenant/app.blade.php       (3 accent fallbacks)
#
# Supersedes patch 62 (which only touched the accent fallback). Safe to apply
# even if patch 62 was already deployed — the accent change is idempotent.
# ============================================================================

set -euo pipefail
cd "${REPO_ROOT:-$(pwd)}"

if [ ! -f "public/css/tenant/theme-b.css" ]; then
  echo "ERROR: theme-b.css not found." >&2
  exit 1
fi
if [ ! -f "resources/views/layouts/tenant/app.blade.php" ]; then
  echo "ERROR: app.blade.php not found." >&2
  exit 1
fi

# ─── 1. theme-b.css palette refresh ─────────────────────────────────────
python3 <<'PYEOF'
from pathlib import Path
p = Path("public/css/tenant/theme-b.css")
s = p.read_text()

old_block = """body.ia-theme-b {
  --ia-bg:           #f4f4f1;
  --ia-surface:      #ffffff;
  --ia-surface-2:    #ecebe7;
  --ia-border:       rgba(0,0,0,.12);
  --ia-border-strong: rgba(0,0,0,.20);
  --ia-text:         #111111;
  --ia-text-muted:   rgba(0,0,0,.62);
  --ia-text-dim:     rgba(0,0,0,.42);
  --ia-hover:        rgba(0,0,0,.06);
  --ia-input-bg:     #ffffff;

  /* Sidebar — stays dark even in light mode */
  --ia-side-bg:      #0f0f0f;
  --ia-side-text:    rgba(255,255,255,.5);
  --ia-side-hover:   rgba(255,255,255,.05);
  --ia-side-active-bg: rgba(255,255,255,.08);
  --ia-side-active-text: #f5f5f5;
  --ia-side-border:  rgba(255,255,255,.07);
  --ia-side-section: rgba(255,255,255,.28);
}"""

new_block = """body.ia-theme-b {
  /* SLATE_STEEL_V1 — refreshed palette */
  --ia-bg:           #F7F8FA;
  --ia-surface:      #FFFFFF;
  --ia-surface-2:    #F1F3F6;
  --ia-border:       rgba(15,20,25,.10);
  --ia-border-strong: rgba(15,20,25,.20);
  --ia-text:         #0F1419;
  --ia-text-muted:   rgba(15,20,25,.62);
  --ia-text-dim:     rgba(15,20,25,.42);
  --ia-hover:        rgba(15,20,25,.06);
  --ia-input-bg:     #FFFFFF;

  /* Sidebar — deep slate, same family as new accent */
  --ia-side-bg:      #1E2A3A;
  --ia-side-text:    rgba(255,255,255,.5);
  --ia-side-hover:   rgba(255,255,255,.05);
  --ia-side-active-bg: rgba(255,255,255,.08);
  --ia-side-active-text: #f5f5f5;
  --ia-side-border:  rgba(255,255,255,.07);
  --ia-side-section: rgba(255,255,255,.28);
}"""

if "SLATE_STEEL_V1" in s:
    print("    SKIP theme-b.css — already on slate-steel palette")
elif old_block not in s:
    raise SystemExit("ABORT theme-b.css: :root block anchor not found")
else:
    s = s.replace(old_block, new_block, 1)
    p.write_text(s)
    print("    UPDATED theme-b.css — palette refreshed to slate-steel")
PYEOF

# ─── 2. app.blade.php — accent fallback values ──────────────────────────
python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/layouts/tenant/app.blade.php")
s = p.read_text()

edits = [
    (
        "--ia-accent: {{ $currentTenant->accent_color ?? '#BEF264' }};",
        "--ia-accent: {{ $currentTenant->accent_color ?? '#3B5A78' }};",
    ),
    (
        "--ia-accent-text: {{ \\App\\Support\\ColorHelper::accentTextColor($currentTenant->accent_color ?? '#BEF264') }};",
        "--ia-accent-text: {{ \\App\\Support\\ColorHelper::accentTextColor($currentTenant->accent_color ?? '#3B5A78') }};",
    ),
    (
        "--ia-accent-soft: {{ \\App\\Support\\ColorHelper::accentSoft($currentTenant->accent_color ?? '#BEF264') }};",
        "--ia-accent-soft: {{ \\App\\Support\\ColorHelper::accentSoft($currentTenant->accent_color ?? '#3B5A78') }};",
    ),
]

all_new = all(new in s for _, new in edits)
any_old = any(old in s for old, _ in edits)

if all_new and not any_old:
    print("    SKIP app.blade.php — default accent already slate-steel")
else:
    for old, new in edits:
        if old not in s:
            if new in s:
                print(f"    note: already migrated: '{old[:50]}...'")
                continue
            raise SystemExit(f"ABORT: anchor not found:\n  {old}")
        s = s.replace(old, new, 1)
    p.write_text(s)
    print("    UPDATED app.blade.php — default accent #BEF264 → #3B5A78")
PYEOF

cat <<EONOTE

==> Patch 63 applied locally.

Deploy:
  mv patch-63-slate-steel-light-theme.sh _patches/
  git add public/css/tenant/theme-b.css \\
          resources/views/layouts/tenant/app.blade.php \\
          _patches/patch-63-slate-steel-light-theme.sh
  git commit -m "feat: slate-steel light theme (patch 63)"
  git push

On server:
  cd /var/www/intake
  git pull
  php artisan view:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

Hard-refresh the browser (Cmd+Shift+R) to bust CSS cache.

Verify on a tenant with theme-b (light) AND default accent:
  - Background shifts to cool gray (#F7F8FA)
  - Surfaces stay white
  - Sidebar is now deep slate (#1E2A3A) not pure black
  - Sidebar logo mark, active nav-item dot, user avatar: slate-steel not lime
  - Text reads as slate-near-black, not pure black
  - Borders are slightly cooler

Tenants with customized accent_color: keep their color (the lime square is
gone wherever they had default lime, otherwise unchanged).
EONOTE
