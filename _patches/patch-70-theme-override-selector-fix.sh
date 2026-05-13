#!/bin/bash
# ============================================================================
# patch-70-theme-override-selector-fix.sh
# ----------------------------------------------------------------------------
# Bug: theme editor publish writes to DB correctly, helper emits correct
# <style> block, but tenant pages still show old colors.
#
# Root cause: CSS variable resolution walks UP the DOM tree to the nearest
# ancestor that defines the variable. The static theme-b.css defines
# variables on `body.ia-theme-b`. The injected <style> defines them on
# `html.ia-theme-b`. For elements inside <body>, the body-level definition
# is the closer ancestor and wins — even though the injected style is
# parsed later.
#
# Fix: change the injected selector from `html.ia-theme-b` to
# `body.ia-theme-b`. Same element as the static rule, later in the HTML,
# so the cascade tie goes to the injected value.
#
# Files touched:
#   - app/Support/ThemeOverrideHelper.php  (one selector change)
# ============================================================================

set -euo pipefail
cd "${REPO_ROOT:-$(pwd)}"

if [ ! -f "app/Support/ThemeOverrideHelper.php" ]; then
  echo "ERROR: ThemeOverrideHelper.php not found." >&2
  exit 1
fi

python3 <<'PYEOF'
from pathlib import Path
p = Path("app/Support/ThemeOverrideHelper.php")
s = p.read_text()

old = 'html.ia-theme-{$theme} {'
new = 'body.ia-theme-{$theme} {'

if 'body.ia-theme-{$theme} {' in s and 'html.ia-theme-{$theme} {' not in s:
    print("    SKIP — selector already on body.ia-theme-X")
elif old not in s:
    raise SystemExit("ABORT: selector anchor not found")
else:
    s = s.replace(old, new, 1)
    p.write_text(s)
    print("    UPDATED — selector changed: html.ia-theme-X → body.ia-theme-X")
PYEOF

cat <<EONOTE

==> Patch 70 applied locally.

Deploy:
  mv patch-70-theme-override-selector-fix.sh _patches/
  git add app/Support/ThemeOverrideHelper.php \\
          _patches/patch-70-theme-override-selector-fix.sh
  git commit -m "fix: theme overrides on body selector to win cascade (patch 70)"
  git push

On server (also bust caches since the helper is called per request):
  cd /var/www/intake
  git pull
  php artisan tinker --execute="app(\\App\\Services\\ThemeSettingsService::class)->bustCaches();"
  php artisan optimize:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

Then hard-refresh the tenant admin page (Cmd+Shift+R) to bust browser cache.
Background should now reflect whatever you published last (#7786a6 in this
case). Set it back to #F7F8FA in the editor when you're done testing.
EONOTE
