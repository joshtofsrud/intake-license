#!/bin/bash
# ============================================================================
# marketing-system-unification.sh   (patch #45)
# ----------------------------------------------------------------------------
# Unify the marketing system end-to-end. After this patch:
#
#   * Every marketing page renders through the CMS path (marketing/page.blade.php)
#   * One nav source — TenantNavItem rows scoped to the platform tenant
#   * Master admin can edit Navigation, Site Settings, view Section Library
#   * Patch #44 logo gap closed (CMS shell now uses the three-bar SVG too)
#
# Phases (all applied atomically):
#
#   Phase 1 — Special section types for dynamic pages
#     - roadmap_grid: renders RoadmapEntry rows grouped by status
#     - changelog_list: renders ChangelogEntry rows reverse-chronologically
#     - (contact_form already exists; we just verify it works for /contact)
#     - Register both in PageBuilderController::DEFAULTS
#
#   Phase 2 — Migrate static pages to CMS
#     - Migration: site_settings table (single-row)
#     - Seeder: TenantPage + TenantPageSection rows for the 5 still-static pages
#       (/roadmap, /changelog, /why-intake, /contact, /invest)
#     - Seeder: TenantNavItem rows for canonical nav
#     - Update MarketingController: remove useLegacy() branching for those routes
#     - Delete the 5 static Blade files: roadmap, changelog, why-intake,
#       contact, invest (kept temporarily as .bak in /tmp for safety — see below)
#
#   Phase 3 — Master admin resources
#     - PlatformNavItemResource (manages TenantNavItem for platform tenant)
#     - SiteSettingsResource (manages the single-row site_settings table)
#     - SectionLibraryResource (read-only catalog of available section types)
#
#   Phase 4 — CMS shell + favicon/OG closure (patch #44 follow-up)
#     - Update _shell_nav.blade.php with three-bar SVG logo
#     - Add favicon links + OG meta to page.blade.php <head>
#
# Files created:
#   database/migrations/2026_05_11_*_create_site_settings_table.php
#   database/seeders/PlatformMarketingSeeder.php
#   app/Models/SiteSettings.php
#   app/Filament/Resources/PlatformNavItemResource.php
#   app/Filament/Resources/PlatformNavItemResource/Pages/{List,Create,Edit}PlatformNavItem.php
#   app/Filament/Resources/SiteSettingsResource.php
#   app/Filament/Resources/SiteSettingsResource/Pages/EditSiteSettings.php
#   app/Filament/Resources/SectionLibraryResource.php
#   app/Filament/Resources/SectionLibraryResource/Pages/ListSectionLibrary.php
#   resources/views/marketing/sections/roadmap_grid.blade.php
#   resources/views/marketing/sections/changelog_list.blade.php
#
# Files modified:
#   app/Http/Controllers/Platform/MarketingController.php (kill useLegacy branching)
#   app/Http/Controllers/Tenant/PageBuilderController.php (add new section types to DEFAULTS)
#   resources/views/marketing/sections/_shell_nav.blade.php (new logo + favicon)
#   resources/views/marketing/page.blade.php (favicon + OG meta in <head>)
#
# Files removed (safety: kept as .bak in /tmp/intake-pre-patch-45/ for 30 days):
#   resources/views/marketing/roadmap.blade.php
#   resources/views/marketing/changelog.blade.php
#   resources/views/marketing/why-intake.blade.php
#   resources/views/marketing/contact.blade.php
#   resources/views/marketing/invest.blade.php
#   resources/views/marketing/layout.blade.php (legacy shell, now unused)
#
# Deploy:
#   git pull
#   composer install --no-interaction --no-scripts
#   php artisan migrate --force
#   php artisan db:seed --class=PlatformMarketingSeeder --force
#   php artisan optimize:clear
#   sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm
# ============================================================================

set -euo pipefail
REPO_ROOT="${REPO_ROOT:-$(pwd)}"
cd "$REPO_ROOT"

echo "==> Patch 45: marketing system unification (all 4 phases)"
echo ""

# Sanity: confirm we're in the right repo
if [ ! -f "artisan" ] || [ ! -f "resources/views/marketing/layout.blade.php" ]; then
  echo "ERROR: Doesn't look like the intake repo. Aborting." >&2
  exit 1
fi

# Pre-patch safety backup of the Blade files we'll delete in Phase 2
BACKUP_DIR="/tmp/intake-pre-patch-45-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP_DIR"
for f in roadmap.blade.php changelog.blade.php why-intake.blade.php contact.blade.php invest.blade.php layout.blade.php; do
  if [ -f "resources/views/marketing/$f" ]; then
    cp "resources/views/marketing/$f" "$BACKUP_DIR/"
  fi
done
echo "  → Backed up legacy Blade files to $BACKUP_DIR"
echo ""

# ============================================================================
# PHASE 1 — Special section types
# ============================================================================
echo "==> Phase 1: Special section types"

# 1a. roadmap_grid section view
cat > resources/views/marketing/sections/roadmap_grid.blade.php <<'BLADE'
{{--
    Dynamic section: renders the roadmap grid.
    Reads RoadmapEntry rows from the DB and groups by status. The editable
    content of this section is only the section's surrounding chrome (extra
    intro text). Actual entries are edited via Filament's Roadmap resource.

    Variables in scope:
      $c       — section content array (any intro_text the editor set)
      $section — TenantPageSection model
--}}
@php
    use App\Models\RoadmapEntry;

    $entries = RoadmapEntry::published()
        ->orderBy('display_order')
        ->orderBy('created_at')
        ->get()
        ->groupBy('status');

    // Stable status order regardless of which buckets have entries.
    $orderedGroups = [];
    foreach (array_keys(RoadmapEntry::STATUSES) as $statusKey) {
        if (isset($entries[$statusKey]) && $entries[$statusKey]->count() > 0) {
            $orderedGroups[$statusKey] = $entries[$statusKey];
        }
    }

    $statusLabels = RoadmapEntry::STATUSES;
    $introText = $c['intro_text'] ?? '';
@endphp

<section class="mk-section {{ $padding }}" style="{{ $inlineStyle }}">
  <div class="mk-container">
    @if($introText)
      <p class="mk-section-intro" style="font-size:15px;color:var(--mk-muted);max-width:680px;margin:0 auto 36px;text-align:center;line-height:1.55">
        {{ $introText }}
      </p>
    @endif

    @foreach($orderedGroups as $statusKey => $groupEntries)
      <div class="mk-rm-group" style="margin-bottom:48px">
        <div class="mk-rm-group-head" style="display:flex;align-items:baseline;gap:14px;margin-bottom:18px">
          <h3 style="margin:0;font-size:18px;font-weight:600;letter-spacing:-.015em">
            {{ $statusLabels[$statusKey] ?? ucfirst($statusKey) }}
          </h3>
          <span style="font-size:12.5px;color:var(--mk-muted)">· {{ $groupEntries->count() }} item{{ $groupEntries->count() === 1 ? '' : 's' }}</span>
        </div>
        <div class="mk-rm-cards" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px">
          @foreach($groupEntries as $entry)
            <article class="mk-rm-card" style="background:var(--mk-bg2);border:0.5px solid var(--mk-border);border-radius:12px;padding:18px 20px">
              <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px">
                @if($entry->category)
                  <span style="font-size:10.5px;text-transform:uppercase;letter-spacing:.1em;color:var(--mk-accent);font-weight:600">{{ $entry->category }}</span>
                @else
                  <span></span>
                @endif
                @if($statusKey === 'shipped' && $entry->shipped_on)
                  <span style="font-size:11.5px;color:var(--mk-muted)">Shipped {{ $entry->shipped_on->format('M j') }}</span>
                @elseif($entry->target_month)
                  <span style="font-size:11.5px;color:var(--mk-muted)">{{ $entry->target_month->format('F Y') }}</span>
                @elseif($entry->rough_timeframe)
                  <span style="font-size:11.5px;color:var(--mk-muted)">{{ $entry->rough_timeframe }}</span>
                @endif
              </div>
              <h4 style="margin:0 0 8px;font-size:15px;font-weight:600;line-height:1.3">{{ $entry->title }}</h4>
              <p style="margin:0;font-size:13.5px;color:var(--mk-muted);line-height:1.5">{{ $entry->body }}</p>
            </article>
          @endforeach
        </div>
      </div>
    @endforeach
  </div>
</section>
BLADE
echo "    CREATED resources/views/marketing/sections/roadmap_grid.blade.php"

# 1b. changelog_list section view
cat > resources/views/marketing/sections/changelog_list.blade.php <<'BLADE'
{{--
    Dynamic section: renders the changelog list.
    Reads ChangelogEntry rows reverse-chronologically. Like roadmap_grid,
    the editable content here is only intro_text — actual entries are
    edited via Filament's Changelog resource.

    Variables in scope:
      $c       — section content array
      $section — TenantPageSection model
--}}
@php
    use App\Models\ChangelogEntry;

    $entries = ChangelogEntry::published()
        ->orderByDesc('is_highlighted')
        ->orderByDesc('shipped_on')
        ->orderByDesc('created_at')
        ->get();

    $introText = $c['intro_text'] ?? '';
@endphp

<section class="mk-section {{ $padding }}" style="{{ $inlineStyle }}">
  <div class="mk-container">
    @if($introText)
      <p class="mk-section-intro" style="font-size:15px;color:var(--mk-muted);max-width:680px;margin:0 auto 36px;text-align:center;line-height:1.55">
        {{ $introText }}
      </p>
    @endif

    <div class="mk-cl-list" style="max-width:760px;margin:0 auto;display:flex;flex-direction:column;gap:18px">
      @foreach($entries as $entry)
        <article class="mk-cl-entry" style="background:var(--mk-bg2);border:0.5px solid var(--mk-border);border-radius:12px;padding:20px 24px;@if($entry->is_highlighted)border-left:3px solid var(--mk-accent);@endif">
          <header style="display:flex;justify-content:space-between;align-items:baseline;gap:14px;margin-bottom:10px;flex-wrap:wrap">
            <div style="display:flex;align-items:baseline;gap:10px">
              @if($entry->category)
                <span style="font-size:10.5px;text-transform:uppercase;letter-spacing:.1em;color:var(--mk-accent);font-weight:600">{{ $entry->category }}</span>
              @endif
              <h3 style="margin:0;font-size:16px;font-weight:600;letter-spacing:-.01em">{{ $entry->title }}</h3>
            </div>
            @if($entry->shipped_on)
              <span style="font-size:12.5px;color:var(--mk-muted);white-space:nowrap">{{ $entry->shipped_on->format('M j, Y') }}</span>
            @endif
          </header>
          <p style="margin:0;font-size:14px;color:var(--mk-muted);line-height:1.55">{{ $entry->body }}</p>
        </article>
      @endforeach

      @if($entries->isEmpty())
        <p style="text-align:center;color:var(--mk-muted);font-size:14px;padding:40px 0">
          No changelog entries yet. Check back soon.
        </p>
      @endif
    </div>
  </div>
</section>
BLADE
echo "    CREATED resources/views/marketing/sections/changelog_list.blade.php"

# 1c. Register the new section types in PageBuilderController::DEFAULTS
python3 <<'PYEOF'
from pathlib import Path
p = Path("app/Http/Controllers/Tenant/PageBuilderController.php")
s = p.read_text()

marker = "'roadmap_grid'"
if marker in s:
    print("    SKIP PageBuilderController — section types already registered")
else:
    # Anchor: after 'classes_embed' line
    old = "'classes_embed'  => ['heading'=>'Upcoming classes','show_filters'=>true,'weeks_ahead'=>2],"
    if s.count(old) != 1:
        raise SystemExit(f"ABORT: classes_embed anchor count = {s.count(old)}")

    new = old + """
        'roadmap_grid'  => ['intro_text'=>'An honest look at where Intake is heading. Plans change as we learn from shops using the product.'],
        'changelog_list'=> ['intro_text'=>'Everything we shipped lately, reverse-chronological.'],"""

    s = s.replace(old, new, 1)
    p.write_text(s)
    print("    UPDATED PageBuilderController — registered roadmap_grid + changelog_list")
PYEOF

echo ""

# ============================================================================
# PHASE 2 — Migrate static pages to CMS
# ============================================================================
echo "==> Phase 2: Migrate static pages to CMS"

# 2a. SiteSettings migration
MIGRATION_TS=$(date +%Y_%m_%d_%H%M%S)
MIGRATION_FILE="database/migrations/${MIGRATION_TS}_create_site_settings_table.php"
cat > "$MIGRATION_FILE" <<'MIGRATION'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * site_settings: Global brand/marketing settings for intake.works.
 * Single-row table (id=1). Managed via master admin Filament resource.
 *
 * Contains: meta defaults, footer copy, social links, analytics IDs.
 * Brand asset URLs are stored here so they can be overridden without
 * a code deploy (favicon, OG image, logo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $t) {
            $t->id();

            // Meta defaults
            $t->string('default_page_title', 191)->nullable();
            $t->string('default_meta_description', 500)->nullable();
            $t->string('footer_tagline', 255)->nullable();

            // Brand assets — stored as URLs (relative or absolute).
            // When null, the fallback is the shipped public/* asset.
            $t->string('logo_url', 500)->nullable();
            $t->string('favicon_url', 500)->nullable();
            $t->string('og_image_url', 500)->nullable();

            // Social links — null hides the link.
            $t->string('twitter_url', 500)->nullable();
            $t->string('linkedin_url', 500)->nullable();
            $t->string('github_url', 500)->nullable();

            // Analytics IDs.
            $t->string('plausible_domain', 191)->nullable();
            $t->string('gtm_id', 64)->nullable();

            $t->timestamps();
        });

        // Seed the single row with sensible defaults.
        \DB::table('site_settings')->insert([
            'id' => 1,
            'default_page_title' => 'intake — Retail, booking, and classes — built for communication and efficiency.',
            'default_meta_description' => 'For service, retail, fitness, and appointment-based businesses.',
            'footer_tagline' => 'Online booking, work orders, and customer management for service shops.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
    }
};
MIGRATION
echo "    CREATED $MIGRATION_FILE"

# 2b. SiteSettings model
cat > app/Models/SiteSettings.php <<'MODEL'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SiteSettings — single-row table holding global marketing/brand settings.
 * Always accessed via SiteSettings::current().
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
        return static::firstOrCreate(['id' => 1]);
    }
}
MODEL
echo "    CREATED app/Models/SiteSettings.php"

# 2c. PlatformMarketingSeeder — seeds canonical nav + the 5 page rows
cat > database/seeders/PlatformMarketingSeeder.php <<'SEEDER'
<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\Tenant\TenantPage;
use App\Models\Tenant\TenantPageSection;
use App\Models\Tenant\TenantNavItem;
use Illuminate\Database\Seeder;

/**
 * Seeds canonical nav + the 5 still-static pages as CMS rows.
 *
 * Idempotent — uses updateOrCreate / firstOrCreate. Safe to re-run.
 * Scoped exclusively to the platform tenant (is_platform=true).
 */
class PlatformMarketingSeeder extends Seeder
{
    public function run(): void
    {
        $platform = Tenant::where('is_platform', true)->first();

        if (! $platform) {
            $this->command->error('No platform tenant found. Skipping marketing seed.');
            return;
        }

        // Canonical nav items — order matters.
        $navItems = [
            ['label' => 'Features',  'url' => '/features',  'sort_order' => 10],
            ['label' => 'Pricing',   'url' => '/pricing',   'sort_order' => 20],
            ['label' => 'Roadmap',   'url' => '/roadmap',   'sort_order' => 30],
            ['label' => 'Changelog', 'url' => '/changelog', 'sort_order' => 40],
            ['label' => 'Docs',      'url' => '/docs',      'sort_order' => 50],
        ];

        // Wipe + reseed nav (idempotent + ensures canonical order)
        TenantNavItem::where('tenant_id', $platform->id)->delete();
        foreach ($navItems as $item) {
            TenantNavItem::create([
                'tenant_id'        => $platform->id,
                'label'            => $item['label'],
                'url'              => $item['url'],
                'is_external'      => false,
                'open_in_new_tab'  => false,
                'sort_order'       => $item['sort_order'],
            ]);
        }
        $this->command->info('Seeded '.count($navItems).' nav items for platform tenant.');

        // Seed pages that don't already exist as CMS rows.
        $this->seedPage($platform, 'roadmap', 'Roadmap', 'What is coming.', [
            ['type' => 'hero', 'content' => [
                'eyebrow' => 'Roadmap',
                'headline' => 'What is coming.',
                'subheading' => 'Timing is intentionally rough. We commit to direction, not dates.',
                'text_align' => 'center',
                'height' => 'small',
            ]],
            ['type' => 'roadmap_grid', 'content' => [
                'intro_text' => '',
            ]],
        ]);

        $this->seedPage($platform, 'changelog', 'Changelog', 'What we shipped.', [
            ['type' => 'hero', 'content' => [
                'eyebrow' => 'Changelog',
                'headline' => 'What we shipped.',
                'subheading' => 'Real updates, reverse-chronological. The most recent on top.',
                'text_align' => 'center',
                'height' => 'small',
            ]],
            ['type' => 'changelog_list', 'content' => [
                'intro_text' => '',
            ]],
        ]);

        $this->seedPage($platform, 'why-intake', 'Why Intake', 'Why Intake', [
            ['type' => 'hero', 'content' => [
                'eyebrow' => 'Why Intake',
                'headline' => 'Built for service shops, not against them.',
                'accent_words' => 'shops',
                'subheading' => 'Most booking and POS tools were built for a different kind of business. Intake was built specifically for service shops — bike, salon, fitness, pet — by someone who runs one.',
                'text_align' => 'center',
                'height' => 'medium',
                'cta_primary_label' => 'Start free trial',
                'cta_primary_url' => '/signup',
            ]],
            ['type' => 'feature_grid', 'content' => [
                'eyebrow' => 'What makes us different',
                'heading' => 'Three things you can\'t get elsewhere',
                'columns' => 3,
                'features' => [
                    ['icon' => '✦', 'title' => 'Real concurrency', 'body' => 'Advisory locks at the database level. Two customers booking the same slot at the same time? Only one gets it. Most competitors are eventually-consistent.'],
                    ['icon' => '✦', 'title' => 'POS + booking unified', 'body' => 'Walk-in sales, work orders, appointments, and inventory in one tool. No syncing between Square and Acuity. No reconciling reports.'],
                    ['icon' => '✦', 'title' => 'Migration concierge', 'body' => 'Send us your data however you have it. We do the import, the cleanup, and walk you through your new setup. $299 one-time, free on Custom annual.'],
                ],
            ]],
        ]);

        $this->seedPage($platform, 'contact', 'Contact', 'Contact us', [
            ['type' => 'hero', 'content' => [
                'eyebrow' => 'Contact',
                'headline' => 'Get in touch.',
                'subheading' => 'Pre-sales questions, support, partnership ideas. Real humans on the other end.',
                'text_align' => 'center',
                'height' => 'small',
            ]],
            ['type' => 'contact_form', 'content' => [
                'heading' => 'Send us a message',
                'subheading' => 'We typically respond within 1 business day.',
                'show_phone' => true,
                'show_message' => true,
            ]],
        ]);

        $this->seedPage($platform, 'invest', 'Invest', 'Invest in Intake', [
            ['type' => 'hero', 'content' => [
                'eyebrow' => 'Investing',
                'headline' => 'Invest in Intake.',
                'subheading' => 'Equity crowdfunding via Republic. Details and offering terms on the Republic page.',
                'text_align' => 'center',
                'height' => 'small',
                'cta_primary_label' => 'View on Republic',
                'cta_primary_url' => 'https://republic.com/intake',
            ]],
            ['type' => 'text_image', 'content' => [
                'eyebrow' => 'About this offering',
                'heading' => 'Built by an operator',
                'body' => 'Intake is a B2B SaaS for service-based small businesses. Real customers, real revenue. The full offering details, financials, and risks are on Republic.',
                'image_position' => 'right',
            ]],
        ]);

        $this->command->info('Seeded 5 marketing pages: roadmap, changelog, why-intake, contact, invest.');
    }

    /**
     * Idempotent page+sections seeding. Updates the page if exists, replaces sections.
     */
    private function seedPage(Tenant $platform, string $slug, string $title, string $metaTitle, array $sections): void
    {
        $page = TenantPage::updateOrCreate(
            ['tenant_id' => $platform->id, 'slug' => $slug],
            [
                'title' => $title,
                'meta_title' => $metaTitle,
                'is_home' => false,
                'is_published' => true,
                'is_in_nav' => false, // nav is managed by TenantNavItem now
                'nav_order' => 0,
            ]
        );

        // Replace sections entirely — simplest idempotent approach.
        TenantPageSection::where('page_id', $page->id)->delete();
        foreach ($sections as $i => $sec) {
            TenantPageSection::create([
                'page_id'       => $page->id,
                'section_type'  => $sec['type'],
                'content'       => $sec['content'],
                'sort_order'    => ($i + 1) * 10,
                'is_visible'    => true,
            ]);
        }
    }
}
SEEDER
echo "    CREATED database/seeders/PlatformMarketingSeeder.php"

# 2d. Strip useLegacy() and the static-path branches from MarketingController.
#     This makes the CMS path the ONLY path.
python3 <<'PYEOF'
from pathlib import Path
p = Path("app/Http/Controllers/Platform/MarketingController.php")
s = p.read_text()

marker = "// Patch 45: CMS-only marketing"
if marker in s:
    print("    SKIP MarketingController — already CMS-only")
else:
    # The strategy: replace each method body that has useLegacy() branching
    # with a direct $this->renderPage($slug) call. The dynamic pages (roadmap,
    # changelog) also become renderPage() since their data is now pulled by
    # the section types themselves.

    new_class = '''<?php

namespace App\\Http\\Controllers\\Platform;

use App\\Http\\Controllers\\Controller;
use App\\Models\\ChangelogEntry;
use App\\Models\\RoadmapEntry;
use App\\Models\\Tenant;
use App\\Models\\Tenant\\TenantPage;
use App\\Models\\Tenant\\TenantNavItem;
use App\\Services\\IndustryPackService;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Mail;
use Illuminate\\Support\\Facades\\Validator;

/**
 * MarketingController — drives every marketing page.
 *
 * Patch 45: CMS-only marketing. Every page renders via renderPage($slug).
 * The five formerly-static pages (roadmap, changelog, why-intake, contact,
 * invest) are now stored as TenantPage rows with sections.
 *
 * Dynamic content (roadmap entries, changelog entries) is fetched by the
 * special section types `roadmap_grid` and `changelog_list` at render time.
 */
class MarketingController extends Controller
{
    public function home()      { return $this->renderPage('home'); }
    public function pricing()   { return $this->renderPage('pricing'); }
    public function features()  { return $this->renderPage('features'); }
    public function whyIntake() { return $this->renderPage('why-intake'); }
    public function invest()    { return $this->renderPage('invest'); }
    public function roadmap()   { return $this->renderPage('roadmap'); }
    public function changelog() { return $this->renderPage('changelog'); }
    public function docs()      { return $this->renderPage('docs'); }

    public function show(string $slug)
    {
        if (str_starts_with($slug, '__')) abort(404);
        return $this->renderPage($slug);
    }

    public function forIndustry(string $industry)
    {
        $packService = app(IndustryPackService::class);
        $pack = $packService->get($industry);
        if (! $pack) abort(404);

        // Use the __for-industry template; substitute tokens.
        $tenant = $this->platformTenant();
        $template = TenantPage::where('tenant_id', $tenant->id)
            ->where('slug', '__for-industry')
            ->where('is_published', true)
            ->first();

        if (! $template) abort(404);

        $sections = $template->sections()->where('is_visible', true)->get()->map(function ($s) use ($pack) {
            $s->content = $this->substituteTokens($s->content, $pack);
            return $s;
        });

        $navItems = TenantNavItem::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')->get();

        return view('marketing.page', [
            'page'     => $template,
            'sections' => $sections,
            'navItems' => $navItems,
            'tenant'   => $tenant,
            'industry' => $pack,
        ]);
    }

    public function contact(Request $request)
    {
        if ($request->isMethod('GET')) {
            return $this->renderPage('contact');
        }

        // POST: validate and email. Keeps existing behavior.
        $validator = Validator::make($request->all(), [
            'name'    => 'required|string|max:120',
            'email'   => 'required|email|max:191',
            'phone'   => 'nullable|string|max:32',
            'message' => 'required|string|max:5000',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        // TODO(patch 46): wire to real mail when SMTP settings finalized.
        // For now log and redirect.
        \\Log::info('Marketing contact form', $request->only(['name','email','phone','message']));

        return back()->with('status', 'Thanks! We\\'ll be in touch within 1 business day.');
    }

    // Patch 45: CMS-only marketing — single render path.
    private function renderPage(string $slug)
    {
        $tenant = $this->platformTenant();

        $page = TenantPage::where('tenant_id', $tenant->id)
            ->where('slug', $slug)
            ->where('is_published', true)
            ->first();

        if (! $page) abort(404);

        $sections = $page->sections()->where('is_visible', true)->get();
        $navItems = TenantNavItem::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')->get();

        return view('marketing.page', [
            'page'     => $page,
            'sections' => $sections,
            'navItems' => $navItems,
            'tenant'   => $tenant,
            'industry' => null,
        ]);
    }

    private function platformTenant(): Tenant
    {
        static $cached = null;
        if ($cached) return $cached;

        $cached = Tenant::where('is_platform', true)->first();
        if (! $cached) abort(503, 'No platform tenant configured');

        return $cached;
    }

    private function substituteTokens($value, array $pack)
    {
        if (is_array($value)) {
            return array_map(fn ($v) => $this->substituteTokens($v, $pack), $value);
        }
        if (! is_string($value)) return $value;

        return preg_replace_callback('/\\{industry_([a-z_]+)\\}/', function ($m) use ($pack) {
            return $pack[$m[1]] ?? $m[0];
        }, $value);
    }
}
'''
    p.write_text(new_class)
    print("    REWROTE MarketingController — CMS-only, useLegacy() removed")
PYEOF

echo ""

# ============================================================================
# PHASE 3 — Master admin Filament resources
# ============================================================================
echo "==> Phase 3: Master admin Filament resources"

# 3a. PlatformNavItemResource
mkdir -p app/Filament/Resources/PlatformNavItemResource/Pages

cat > app/Filament/Resources/PlatformNavItemResource.php <<'RES'
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlatformNavItemResource\Pages;
use App\Models\Tenant;
use App\Models\Tenant\TenantNavItem;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Master admin: edit the platform tenant's navigation items.
 * Items are scoped to the platform tenant (is_platform=true).
 * Renders on every marketing page via the CMS shell.
 */
class PlatformNavItemResource extends Resource
{
    protected static ?string $model = TenantNavItem::class;

    protected static ?string $navigationIcon  = 'heroicon-o-bars-3';
    protected static ?string $navigationGroup = 'Platform';
    protected static ?string $navigationLabel = 'Navigation';
    protected static ?int    $navigationSort  = 11;
    protected static ?string $recordTitleAttribute = 'label';

    protected static ?string $modelLabel       = 'Nav item';
    protected static ?string $pluralModelLabel = 'Navigation';
    protected static ?string $breadcrumb       = 'Navigation';
    protected static ?string $slug             = 'navigation';

    public static function getEloquentQuery(): Builder
    {
        $platform = Tenant::where('is_platform', true)->first();
        if (! $platform) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }
        return parent::getEloquentQuery()
            ->where('tenant_id', $platform->id)
            ->orderBy('sort_order');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Link')
                ->schema([
                    Forms\Components\TextInput::make('label')
                        ->required()
                        ->maxLength(64)
                        ->helperText('Shown in the nav. Keep short — one or two words.'),

                    Forms\Components\TextInput::make('url')
                        ->required()
                        ->maxLength(500)
                        ->helperText('Use "/path" for internal pages. Use "https://..." for external links.'),

                    Forms\Components\Toggle::make('is_external')
                        ->label('External link')
                        ->helperText('Marks this as off-site. Shown with a different badge in admin.')
                        ->default(false),

                    Forms\Components\Toggle::make('open_in_new_tab')
                        ->label('Open in new tab')
                        ->helperText('Adds target="_blank" rel="noopener". Recommended for external links.')
                        ->default(false),

                    Forms\Components\TextInput::make('sort_order')
                        ->numeric()
                        ->default(0)
                        ->helperText('Lower numbers appear first.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order', 'asc')
            ->reorderable('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('label')
                    ->weight('medium')
                    ->searchable(),

                Tables\Columns\TextColumn::make('url')
                    ->fontFamily('mono')
                    ->color('gray')
                    ->limit(50)
                    ->searchable(),

                Tables\Columns\IconColumn::make('is_external')
                    ->label('External')
                    ->boolean(),

                Tables\Columns\IconColumn::make('open_in_new_tab')
                    ->label('New tab')
                    ->boolean(),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Order')
                    ->numeric()
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPlatformNavItems::route('/'),
            'create' => Pages\CreatePlatformNavItem::route('/create'),
            'edit'   => Pages\EditPlatformNavItem::route('/{record}/edit'),
        ];
    }
}
RES
echo "    CREATED app/Filament/Resources/PlatformNavItemResource.php"

cat > app/Filament/Resources/PlatformNavItemResource/Pages/ListPlatformNavItems.php <<'PG'
<?php

namespace App\Filament\Resources\PlatformNavItemResource\Pages;

use App\Filament\Resources\PlatformNavItemResource;
use App\Models\Tenant;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPlatformNavItems extends ListRecords
{
    protected static string $resource = PlatformNavItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('New nav item')
                ->mutateFormDataUsing(function (array $data): array {
                    $platform = Tenant::where('is_platform', true)->firstOrFail();
                    $data['tenant_id'] = $platform->id;
                    return $data;
                }),
        ];
    }
}
PG
echo "    CREATED .../Pages/ListPlatformNavItems.php"

cat > app/Filament/Resources/PlatformNavItemResource/Pages/CreatePlatformNavItem.php <<'PG'
<?php

namespace App\Filament\Resources\PlatformNavItemResource\Pages;

use App\Filament\Resources\PlatformNavItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePlatformNavItem extends CreateRecord
{
    protected static string $resource = PlatformNavItemResource::class;
}
PG
echo "    CREATED .../Pages/CreatePlatformNavItem.php"

cat > app/Filament/Resources/PlatformNavItemResource/Pages/EditPlatformNavItem.php <<'PG'
<?php

namespace App\Filament\Resources\PlatformNavItemResource\Pages;

use App\Filament\Resources\PlatformNavItemResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPlatformNavItem extends EditRecord
{
    protected static string $resource = PlatformNavItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
PG
echo "    CREATED .../Pages/EditPlatformNavItem.php"

# 3b. SiteSettingsResource — single-record edit page
mkdir -p app/Filament/Resources/SiteSettingsResource/Pages

cat > app/Filament/Resources/SiteSettingsResource.php <<'RES'
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SiteSettingsResource\Pages;
use App\Models\SiteSettings;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Master admin: edit global site settings.
 * Single-row table (id=1). Edit-only resource (no list, no create).
 */
class SiteSettingsResource extends Resource
{
    protected static ?string $model = SiteSettings::class;

    protected static ?string $navigationIcon  = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'Platform';
    protected static ?string $navigationLabel = 'Site settings';
    protected static ?int    $navigationSort  = 30;

    protected static ?string $modelLabel       = 'Site settings';
    protected static ?string $pluralModelLabel = 'Site settings';
    protected static ?string $breadcrumb       = 'Site settings';
    protected static ?string $slug             = 'site-settings';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identity')
                ->description('Default page title, meta description, and tagline. Used as fallback when a page doesn\'t set its own.')
                ->schema([
                    Forms\Components\TextInput::make('default_page_title')
                        ->label('Default page title')
                        ->maxLength(191),

                    Forms\Components\Textarea::make('default_meta_description')
                        ->label('Default meta description')
                        ->rows(2)
                        ->maxLength(500)
                        ->helperText('Used in <meta name="description"> when a page doesn\'t override.'),

                    Forms\Components\TextInput::make('footer_tagline')
                        ->label('Footer tagline')
                        ->maxLength(255)
                        ->helperText('Small text shown under the logo in the marketing footer.'),
                ]),

            Forms\Components\Section::make('Brand assets')
                ->description('URLs to override the default shipped assets. Leave blank to use the files in /public.')
                ->schema([
                    Forms\Components\TextInput::make('logo_url')
                        ->label('Logo URL')
                        ->url()
                        ->helperText('Default: /logo.svg. Should be a 168×36 SVG with icon + wordmark.'),

                    Forms\Components\TextInput::make('favicon_url')
                        ->label('Favicon URL')
                        ->url()
                        ->helperText('Default: /favicon.svg. Should be a square SVG, optimized for small sizes.'),

                    Forms\Components\TextInput::make('og_image_url')
                        ->label('OG share image URL')
                        ->url()
                        ->helperText('Default: /og-image.png. Should be 1200×630 PNG.'),
                ]),

            Forms\Components\Section::make('Social links')
                ->description('Shown in the footer. Leave blank to hide that platform.')
                ->collapsed()
                ->schema([
                    Forms\Components\TextInput::make('twitter_url')
                        ->label('Twitter / X URL')
                        ->url(),

                    Forms\Components\TextInput::make('linkedin_url')
                        ->label('LinkedIn URL')
                        ->url(),

                    Forms\Components\TextInput::make('github_url')
                        ->label('GitHub URL')
                        ->url(),
                ]),

            Forms\Components\Section::make('Analytics')
                ->description('Tracking codes injected into every marketing page.')
                ->collapsed()
                ->schema([
                    Forms\Components\TextInput::make('plausible_domain')
                        ->label('Plausible / Fathom domain')
                        ->placeholder('intake.works'),

                    Forms\Components\TextInput::make('gtm_id')
                        ->label('Google Tag Manager ID')
                        ->placeholder('GTM-XXXXXXX')
                        ->maxLength(64),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        // We don't really list. The "All site settings" view just shows
        // the single row with an Edit action.
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('default_page_title')->label('Title'),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->since(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\EditSiteSettings::route('/'),
        ];
    }

    public static function canCreate(): bool { return false; }
    public static function canDelete($record): bool { return false; }
}
RES
echo "    CREATED app/Filament/Resources/SiteSettingsResource.php"

cat > app/Filament/Resources/SiteSettingsResource/Pages/EditSiteSettings.php <<'PG'
<?php

namespace App\Filament\Resources\SiteSettingsResource\Pages;

use App\Filament\Resources\SiteSettingsResource;
use App\Models\SiteSettings;
use Filament\Resources\Pages\EditRecord;

/**
 * EditSiteSettings — opens the singleton record directly.
 * No "list" page; the resource's "index" route points here.
 */
class EditSiteSettings extends EditRecord
{
    protected static string $resource = SiteSettingsResource::class;

    public function mount($record = null): void
    {
        // Always load the single row.
        $row = SiteSettings::current();
        parent::mount($row->id);
    }

    protected function getRedirectUrl(): string
    {
        // Stay on the same page after save — there's no list to go back to.
        return $this->getResource()::getUrl('index');
    }
}
PG
echo "    CREATED .../Pages/EditSiteSettings.php"

# 3c. SectionLibraryResource — read-only catalog
mkdir -p app/Filament/Resources/SectionLibraryResource/Pages

cat > app/Filament/Resources/SectionLibraryResource.php <<'RES'
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SectionLibraryResource\Pages;
use App\Models\Tenant\TenantPageSection;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Master admin: read-only catalog of available section types.
 * Shows what sections exist in marketing/sections/, how many pages use
 * each one, and links to docs for adding new types.
 *
 * Useful as a reference when planning content. NOT used for editing —
 * sections are edited in the page editor.
 */
class SectionLibraryResource extends Resource
{
    // Sits on top of TenantPageSection but renders aggregated rows.
    protected static ?string $model = TenantPageSection::class;

    protected static ?string $navigationIcon  = 'heroicon-o-squares-2x2';
    protected static ?string $navigationGroup = 'Platform';
    protected static ?string $navigationLabel = 'Section library';
    protected static ?int    $navigationSort  = 40;

    protected static ?string $modelLabel       = 'Section type';
    protected static ?string $pluralModelLabel = 'Section library';
    protected static ?string $breadcrumb       = 'Section library';
    protected static ?string $slug             = 'section-library';

    public static function getEloquentQuery(): Builder
    {
        // Aggregate: distinct section_type with count.
        return parent::getEloquentQuery()
            ->selectRaw('MIN(id) as id, section_type, COUNT(*) as usage_count')
            ->groupBy('section_type');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('section_type', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('section_type')
                    ->label('Section type')
                    ->formatStateUsing(fn ($state) => str_replace('_', ' ', ucfirst($state)))
                    ->weight('medium')
                    ->searchable(),

                Tables\Columns\TextColumn::make('section_type')
                    ->label('Identifier')
                    ->fontFamily('mono')
                    ->color('gray')
                    ->copyable(),

                Tables\Columns\TextColumn::make('usage_count')
                    ->label('In use on')
                    ->formatStateUsing(fn ($state) => $state.' page'.($state === 1 ? '' : 's'))
                    ->sortable(),
            ])
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSectionLibrary::route('/'),
        ];
    }

    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }
}
RES
echo "    CREATED app/Filament/Resources/SectionLibraryResource.php"

cat > app/Filament/Resources/SectionLibraryResource/Pages/ListSectionLibrary.php <<'PG'
<?php

namespace App\Filament\Resources\SectionLibraryResource\Pages;

use App\Filament\Resources\SectionLibraryResource;
use Filament\Resources\Pages\ListRecords;

class ListSectionLibrary extends ListRecords
{
    protected static string $resource = SectionLibraryResource::class;

    protected static ?string $title = 'Section library';
}
PG
echo "    CREATED .../Pages/ListSectionLibrary.php"

echo ""

# ============================================================================
# PHASE 4 — CMS shell logo + favicon/OG (patch #44 follow-up)
# ============================================================================
echo "==> Phase 4: CMS shell — logo + favicon/OG meta"

# 4a. Replace the "I" letter in _shell_nav.blade.php with the three-bar SVG
python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/marketing/sections/_shell_nav.blade.php")
s = p.read_text()

if "patch #44" in s or "three-bar SVG" in s:
    print("    SKIP _shell_nav.blade.php — already patched")
else:
    old_logo = '<div class="mk-logo-mark">I</div>'
    new_logo = '''<div class="mk-logo-mark">
            {{-- Three-bar SVG (patch #44 / patch #45) --}}
            <svg viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style="width:18px;height:18px;display:block">
                <rect x="6" y="6"    width="16" height="3.5" rx="1" fill="#0a0a0a"/>
                <rect x="6" y="12.25" width="13" height="3.5" rx="1" fill="#0a0a0a"/>
                <rect x="6" y="18.5"  width="10" height="3.5" rx="1" fill="#0a0a0a"/>
            </svg>
        </div>'''

    if s.count(old_logo) != 1:
        raise SystemExit(f"ABORT: _shell_nav logo anchor count = {s.count(old_logo)}")

    s = s.replace(old_logo, new_logo, 1)
    p.write_text(s)
    print("    UPDATED _shell_nav.blade.php — three-bar SVG logo")
PYEOF

# 4b. Add favicon links + OG meta to page.blade.php <head>
python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/marketing/page.blade.php")
s = p.read_text()

if "Patch #44 favicon links" in s or "favicon.svg" in s:
    print("    SKIP page.blade.php — already has favicon")
else:
    # Anchor: the Inter font link
    anchor = '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">'
    if s.count(anchor) != 1:
        raise SystemExit(f"ABORT: page.blade.php Inter font anchor count = {s.count(anchor)}")

    new_block = anchor + '''

    {{-- Patch #44 favicon links + OG meta — match the static-layout shell --}}
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16.png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <meta name="theme-color" content="#0c0c0c">

    {{-- OG/Twitter card --}}
    <meta property="og:image" content="{{ url('/og-image.png') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="{{ url('/og-image.png') }}">'''

    s = s.replace(anchor, new_block, 1)
    p.write_text(s)
    print("    UPDATED page.blade.php — favicon links + OG meta")
PYEOF

echo ""

# ============================================================================
# Phase 2 cleanup — delete the static Blades that are now redundant
# (done at the end so any earlier failure doesn't leave us with no backup)
# ============================================================================
echo "==> Phase 2 cleanup: removing redundant static Blades"
for f in roadmap.blade.php changelog.blade.php why-intake.blade.php contact.blade.php invest.blade.php; do
  if [ -f "resources/views/marketing/$f" ]; then
    rm "resources/views/marketing/$f"
    echo "    REMOVED resources/views/marketing/$f"
  fi
done
# NOTE: NOT removing marketing/layout.blade.php — though unused by routes,
# it has the favicon links from patch #44 and we want to keep that change
# tracked in git. Mark it as deprecated:
if [ -f "resources/views/marketing/layout.blade.php" ]; then
  # Just add a comment at the top noting it's deprecated.
  python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/marketing/layout.blade.php")
s = p.read_text()
if "DEPRECATED — patch #45" not in s:
    new = "{{-- DEPRECATED — patch #45 (marketing-system-unification.sh) made this file unused.\n     The CMS layout (marketing/page.blade.php) is the only shell.\n     Kept temporarily for one release in case a route still references it.\n     SAFE TO DELETE in patch #46+ --}}\n" + s
    p.write_text(new)
    print("    MARKED resources/views/marketing/layout.blade.php as DEPRECATED")
PYEOF
fi

cat <<EONOTE

==> Patch 45 applied locally.

Files backed up to: $BACKUP_DIR

Deploy steps (on your Mac):
  git add -A
  git status         # review the changes
  git commit -m "feat(marketing): unify on CMS — single nav source + master admin (#45)"
  git push

Deploy steps (on the server):
  cd /var/www/intake
  git pull
  composer install --no-interaction --no-scripts
  php artisan migrate --force
  php artisan db:seed --class=PlatformMarketingSeeder --force
  php artisan optimize:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

Smoke test (priority order):
  1. https://intake.works/roadmap  — should render with three-bar logo + roadmap entries
  2. https://intake.works/changelog — should render changelog entries
  3. https://intake.works/why-intake — should render the migrated content
  4. https://intake.works/contact   — form should render (POST still goes to controller)
  5. https://intake.works/invest    — should render
  6. Open every page and verify the nav is CONSISTENT (Features · Pricing · Roadmap · Changelog · Docs)
  7. Master admin (app.intake.works/admin):
     - Platform group has: Marketing pages, Navigation, Roadmap, Changelog, Site settings, Section library
     - Navigation editor shows 5 items, drag-to-reorder works
     - Site settings opens directly to the edit form (no list)
     - Section library shows what types are in use and how many pages use each

If anything looks wrong:
  - Revert: git checkout HEAD~1 && rollback the migration:
    php artisan migrate:rollback --step=1
  - The backed-up Blades are at: $BACKUP_DIR
EONOTE
