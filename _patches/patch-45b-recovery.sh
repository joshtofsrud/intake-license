#!/bin/bash
# ============================================================================
# patch-45b-recovery.sh
# ----------------------------------------------------------------------------
# Comprehensive recovery for patch #45 issues:
#
#   1. Seeder still has the tenant_id bug (patch 45a never landed)
#   2. SiteSettingsResource crashes Filament when site_settings table doesn't
#      exist yet — the EditSiteSettings::mount() override is fragile
#
# This script:
#   a) Re-applies the tenant_id fix to PlatformMarketingSeeder (idempotent)
#   b) Rewrites EditSiteSettings to be lazy — only resolves the record when
#      the page is actually visited, not at boot
#   c) Wraps SiteSettings::current() to gracefully handle missing table
#
# Run on your Mac, commit, push, deploy.
# ============================================================================

set -euo pipefail
cd "${REPO_ROOT:-$(pwd)}"

if [ ! -f "database/seeders/PlatformMarketingSeeder.php" ]; then
  echo "ERROR: Run from intake-license project root." >&2
  exit 1
fi

echo "==> Patch 45b: comprehensive recovery"
echo ""

# ----------------------------------------------------------------------------
# Fix 1: Seeder tenant_id (idempotent — checks if already applied)
# ----------------------------------------------------------------------------
python3 <<'PYEOF'
from pathlib import Path
p = Path("database/seeders/PlatformMarketingSeeder.php")
s = p.read_text()

if "'tenant_id'     => $platform->id,\n                'page_id'" in s:
    print("    ✓ Seeder already has tenant_id fix")
else:
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
        raise SystemExit(f"ABORT: section create anchor count = {s.count(old)}")

    s = s.replace(old, new, 1)
    p.write_text(s)
    print("    UPDATED PlatformMarketingSeeder.php — tenant_id added")
PYEOF

# ----------------------------------------------------------------------------
# Fix 2: SiteSettings model — gracefully handle missing table
# ----------------------------------------------------------------------------
cat > app/Models/SiteSettings.php <<'MODEL'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * SiteSettings — single-row table holding global marketing/brand settings.
 * Always accessed via SiteSettings::current().
 *
 * Defensive: returns a transient instance if the table doesn't exist yet
 * (e.g. between deploy and migration). Prevents Filament from crashing at
 * boot when resources are registered before migrations run.
 */
class SiteSettings extends Model
{
    protected $table = 'site_settings';

    protected $fillable = [
        'default_page_title',
        'default_meta_description',
        'footer_tagline',
        'logo_url',
        'favicon_url',
        'og_image_url',
        'twitter_url',
        'linkedin_url',
        'github_url',
        'plausible_domain',
        'gtm_id',
    ];

    public static function current(): self
    {
        // Guard against the table not existing yet (pre-migration state).
        if (! Schema::hasTable('site_settings')) {
            return new self(); // transient, not persisted
        }

        return static::firstOrCreate(['id' => 1]);
    }
}
MODEL
echo "    UPDATED app/Models/SiteSettings.php — defensive missing-table handling"

# ----------------------------------------------------------------------------
# Fix 3: EditSiteSettings — simpler, less fragile
# ----------------------------------------------------------------------------
cat > app/Filament/Resources/SiteSettingsResource/Pages/EditSiteSettings.php <<'PAGE'
<?php

namespace App\Filament\Resources\SiteSettingsResource\Pages;

use App\Filament\Resources\SiteSettingsResource;
use App\Models\SiteSettings;
use Filament\Resources\Pages\EditRecord;

/**
 * EditSiteSettings — single-record editor for the global site_settings row.
 *
 * Uses resolveRecord() instead of overriding mount() because mount()
 * signatures vary across Filament versions and the override broke admin.
 */
class EditSiteSettings extends EditRecord
{
    protected static string $resource = SiteSettingsResource::class;

    /**
     * Filament calls this to load the record for editing.
     * We always return the singleton row regardless of any URL parameter.
     */
    protected function resolveRecord($key): \Illuminate\Database\Eloquent\Model
    {
        return SiteSettings::current();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
PAGE
echo "    REWROTE EditSiteSettings.php — resolveRecord() instead of mount() override"

# ----------------------------------------------------------------------------
# Fix 4: SiteSettingsResource — also lazy
# ----------------------------------------------------------------------------
# Make sure SiteSettingsResource doesn't try to load the record at boot.
# The current resource is mostly fine; just verify it doesn't have any
# eager loading.
python3 <<'PYEOF'
from pathlib import Path
p = Path("app/Filament/Resources/SiteSettingsResource.php")
s = p.read_text()

# Make sure shouldRegisterNavigation is conditional on table existing
if "shouldRegisterNavigation" not in s:
    # Inject a defensive check after the class declaration
    marker = "    protected static ?string $slug             = 'site-settings';"
    if marker in s:
        addition = marker + """

    /**
     * Hide from navigation if the migration hasn't run yet.
     * Prevents Filament from crashing on /admin during deploy window.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return \\Illuminate\\Support\\Facades\\Schema::hasTable('site_settings');
    }"""
        s = s.replace(marker, addition, 1)
        p.write_text(s)
        print("    UPDATED SiteSettingsResource.php — shouldRegisterNavigation guard")
    else:
        print("    SKIP SiteSettingsResource — anchor not found, leaving as-is")
else:
    print("    ✓ SiteSettingsResource already has shouldRegisterNavigation guard")
PYEOF

# Same for PlatformNavItemResource and SectionLibraryResource — defensive
python3 <<'PYEOF'
from pathlib import Path
for resfile, tablename in [
    ("app/Filament/Resources/PlatformNavItemResource.php", "tenant_nav_items"),
    ("app/Filament/Resources/SectionLibraryResource.php", "tenant_page_sections"),
]:
    p = Path(resfile)
    s = p.read_text()
    if "shouldRegisterNavigation" in s:
        print(f"    ✓ {resfile} already guarded")
        continue
    marker = "    public static function getEloquentQuery"
    if marker not in s:
        print(f"    SKIP {resfile} — anchor missing")
        continue
    # NOTE: Use \\ in source -> single \ in output. Don't use f-strings, they double-escape.
    addition = (
        "    /**\n"
        "     * Hide from navigation if the migration hasn't run yet.\n"
        "     */\n"
        "    public static function shouldRegisterNavigation(): bool\n"
        "    {\n"
        "        return \\Illuminate\\Support\\Facades\\Schema::hasTable('" + tablename + "');\n"
        "    }\n"
        "\n"
        + marker
    )
    s = s.replace(marker, addition, 1)
    p.write_text(s)
    print(f"    UPDATED {resfile} — added shouldRegisterNavigation guard")
PYEOF

cat <<EONOTE

==> Patch 45b applied locally.

Deploy:
  git add -A
  git status     # should show ~5 files modified
  git commit -m "fix: seeder tenant_id, defensive Filament resources (patch 45b)"
  git push

On server:
  cd /var/www/intake
  git pull

  # First, verify the migration table actually exists (it should from earlier deploy):
  php artisan tinker --execute="echo \\\\Illuminate\\\\Support\\\\Facades\\\\Schema::hasTable('site_settings') ? 'YES' : 'NO';"

  # Clear any partial seed state:
  php artisan tinker --execute="
\\\$platform = \\\\App\\\\Models\\\\Tenant::where('is_platform', true)->first();
\\\\App\\\\Models\\\\Tenant\\\\TenantPage::where('tenant_id', \\\$platform->id)
  ->whereIn('slug', ['roadmap','changelog','why-intake','contact','invest'])
  ->each(function(\\\$p){
    \\\\App\\\\Models\\\\Tenant\\\\TenantPageSection::where('page_id', \\\$p->id)->delete();
    \\\$p->delete();
  });
echo 'Cleared partial pages.';
"

  # Re-run the seeder:
  php artisan db:seed --class=PlatformMarketingSeeder --force

  # Restart:
  php artisan optimize:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

Smoke test:
  - https://intake.works/roadmap should render
  - https://intake.works/changelog should render
  - https://intake.works/admin should load without 500
EONOTE
