#!/bin/bash
# ============================================================================
# patch-65-checkout-banner-info-blue.sh
# ----------------------------------------------------------------------------
# The "N appointment(s) ready for checkout" banner on the Register page uses
# hardcoded amber (rgba(251,191,36,...)) inline styles. That reads as cream/
# yellow on the light theme and clashes with the slate palette.
#
# Swaps the amber for info-blue tint (rgba(21,112,205,...)). "Ready for
# checkout" is an informational/queued-action state, not a success — info
# blue is the semantically right token.
#
# Files touched:
#   - resources/views/tenant/register/index.blade.php  (1 inline style block)
#
# Save bar (set-savebar / set-save-btn) NOT touched. The "purple" appearance
# in screenshots is the tenant's accent_color showing through — that's
# working as designed. Tenants who customize their accent get accent-colored
# save buttons; default-accent tenants now get slate-steel after patch 63.
# ============================================================================

set -euo pipefail
cd "${REPO_ROOT:-$(pwd)}"

if [ ! -f "resources/views/tenant/register/index.blade.php" ]; then
  echo "ERROR: register/index.blade.php not found." >&2
  exit 1
fi

python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/register/index.blade.php")
s = p.read_text()

old = 'style="background:rgba(251,191,36,.10);border:0.5px solid rgba(251,191,36,.35);border-radius:var(--ia-r-md);padding:14px 18px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;gap:14px"'

new = 'style="background:rgba(21,112,205,.07);border:0.5px solid rgba(21,112,205,.30);border-radius:var(--ia-r-md);padding:14px 18px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;gap:14px"'

if "rgba(21,112,205,.07)" in s:
    print("    SKIP — checkout banner already info-blue")
elif old not in s:
    raise SystemExit("ABORT: checkout banner anchor not found")
else:
    s = s.replace(old, new, 1)
    p.write_text(s)
    print("    UPDATED — checkout banner: amber → info-blue tint")
PYEOF

cat <<EONOTE

==> Patch 65 applied locally.

Deploy:
  mv patch-65-checkout-banner-info-blue.sh _patches/
  git add resources/views/tenant/register/index.blade.php \\
          _patches/patch-65-checkout-banner-info-blue.sh
  git commit -m "fix: checkout-ready banner uses info-blue not amber (patch 65)"
  git push

On server:
  cd /var/www/intake
  git pull
  php artisan view:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

Verify on a tenant with appointments completed but not yet paid out:
  /admin/register
  Banner background should now be soft blue tint instead of cream/yellow.
EONOTE
