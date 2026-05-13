#!/bin/bash
# ============================================================================
# patch-45d-register-resources.sh
# ----------------------------------------------------------------------------
# Bug: patch #45 created PlatformNavItemResource, SiteSettingsResource,
# SectionLibraryResource but didn't register them in AdminPanelProvider.
# Filament uses an explicit ->resources() array, not auto-discovery, so the
# resources existed as files but Filament never loaded them. Result: no
# /admin/navigation route, no Navigation menu item.
#
# This patch adds them to the provider's resources array.
# ============================================================================

set -euo pipefail
cd "${REPO_ROOT:-$(pwd)}"

if [ ! -f "app/Providers/Filament/AdminPanelProvider.php" ]; then
  echo "ERROR: not in project root" >&2
  exit 1
fi

python3 <<'PYEOF'
from pathlib import Path
p = Path("app/Providers/Filament/AdminPanelProvider.php")
s = p.read_text()

if "PlatformNavItemResource" in s:
    print("    ✓ AdminPanelProvider already registers new resources")
    raise SystemExit(0)

# Add imports
old_imports = """use App\\Filament\\Resources\\MarketingPageResource;
use App\\Filament\\Resources\\ChangelogEntryResource;
use App\\Filament\\Resources\\RoadmapEntryResource;
use App\\Filament\\Resources\\TenantResource;"""

new_imports = """use App\\Filament\\Resources\\MarketingPageResource;
use App\\Filament\\Resources\\ChangelogEntryResource;
use App\\Filament\\Resources\\RoadmapEntryResource;
use App\\Filament\\Resources\\PlatformNavItemResource;
use App\\Filament\\Resources\\SectionLibraryResource;
use App\\Filament\\Resources\\SiteSettingsResource;
use App\\Filament\\Resources\\TenantResource;"""

if s.count(old_imports) != 1:
    raise SystemExit(f"ABORT: imports anchor count = {s.count(old_imports)}")
s = s.replace(old_imports, new_imports, 1)

# Add to resources array
old_resources = """                MarketingPageResource::class, // new — marketing page editor entry
                ChangelogEntryResource::class,
                RoadmapEntryResource::class,
                DebugLogResource::class,"""

new_resources = """                MarketingPageResource::class, // new — marketing page editor entry
                PlatformNavItemResource::class, // patch 45 — nav editor
                ChangelogEntryResource::class,
                RoadmapEntryResource::class,
                SiteSettingsResource::class, // patch 45 — global site settings
                SectionLibraryResource::class, // patch 45 — section type catalog
                DebugLogResource::class,"""

if s.count(old_resources) != 1:
    raise SystemExit(f"ABORT: resources array anchor count = {s.count(old_resources)}")
s = s.replace(old_resources, new_resources, 1)

p.write_text(s)
print("    UPDATED AdminPanelProvider.php — registered 3 new resources")
PYEOF

cat <<EONOTE

==> Patch 45d applied locally.

Deploy:
  git add app/Providers/Filament/AdminPanelProvider.php
  git commit -m "fix: register new Filament resources in AdminPanelProvider (patch 45d)"
  git push

On server:
  cd /var/www/intake
  git pull
  php artisan optimize:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

Verify:
  php artisan route:list 2>&1 | grep -i "navigation\\|site-settings\\|section-library"

  Should now show new routes for /admin/navigation, /admin/site-settings, /admin/section-library

Then visit intake.works/admin and check the Platform group in the sidebar.
EONOTE
