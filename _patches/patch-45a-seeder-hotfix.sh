#!/bin/bash
# ============================================================================
# patch-45a-seeder-hotfix.sh
# ----------------------------------------------------------------------------
# HOTFIX for patch #45: PlatformMarketingSeeder failed because TenantPageSection
# rows need tenant_id (not just page_id). Adds the missing field.
#
# Run this on your local Mac, commit, push, then re-run the seeder on the server.
# ============================================================================

set -euo pipefail
cd "${REPO_ROOT:-$(pwd)}"

if [ ! -f "database/seeders/PlatformMarketingSeeder.php" ]; then
  echo "ERROR: PlatformMarketingSeeder.php not found. Are you in the project root?" >&2
  exit 1
fi

python3 <<'PYEOF'
from pathlib import Path
p = Path("database/seeders/PlatformMarketingSeeder.php")
s = p.read_text()

# 1. Fix the TenantPageSection::create() call to include tenant_id
old = """            TenantPageSection::create([
                'page_id'       => $page->id,
                'section_type'  => $sec['type'],
                'content'       => $sec['content'],
                'sort_order'    => ($i + 1) * 10,
                'is_visible'    => true,
            ]);"""
new = """            TenantPageSection::create([
                'tenant_id'     => $platform->id,
                'page_id'       => $page->id,
                'section_type'  => $sec['type'],
                'content'       => $sec['content'],
                'sort_order'    => ($i + 1) * 10,
                'is_visible'    => true,
            ]);"""

if s.count(old) != 1:
    if "tenant_id'     => $platform->id" in s:
        print("    SKIP — already patched")
        raise SystemExit(0)
    raise SystemExit(f"ABORT: TenantPageSection anchor count = {s.count(old)}")

# 2. seedPage needs $platform in scope. Check signature.
if "private function seedPage(Tenant $platform" not in s:
    raise SystemExit("ABORT: seedPage signature differs from expected")

s = s.replace(old, new, 1)
p.write_text(s)
print("    UPDATED PlatformMarketingSeeder.php — added tenant_id to section inserts")
PYEOF

cat <<EONOTE

==> Patch 45a applied locally.

Deploy:
  git add database/seeders/PlatformMarketingSeeder.php
  git commit -m "fix(seeder): include tenant_id on page sections (patch 45a)"
  git push

On server:
  cd /var/www/intake
  git pull

  # Clear out the partial seed from the failed run:
  php artisan tinker --execute="
    \\\$platform = \\App\\Models\\Tenant::where('is_platform', true)->first();
    foreach (['roadmap','changelog','why-intake','contact','invest'] as \\\$slug) {
      \\\$pages = \\App\\Models\\Tenant\\TenantPage::where('tenant_id', \\\$platform->id)
        ->where('slug', \\\$slug)->get();
      foreach (\\\$pages as \\\$page) {
        \\App\\Models\\Tenant\\TenantPageSection::where('page_id', \\\$page->id)->delete();
        \\\$page->delete();
      }
    }
    echo 'Cleared partial seed.';
  "

  # Now re-run the seeder cleanly:
  php artisan db:seed --class=PlatformMarketingSeeder --force
  php artisan optimize:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm
EONOTE
