#!/bin/bash
# platform-email-register — the Platform email page existed but was invisible.
# AdminPanelProvider registers pages with an explicit ->pages([...]) array
# rather than discoverPages(), and the new page was never added to it — so
# the class autoloaded, the view and table were in place, and the panel
# simply did not know the page existed: no nav item, no route.
# Registered next to Email health.
set -e
cd "$(git rev-parse --show-toplevel)"
if grep -q "PlatformEmail" app/Providers/Filament/AdminPanelProvider.php; then
  echo "already registered — aborting."; exit 1
fi
cat > 'app/Providers/Filament/AdminPanelProvider.php' <<'PMREG_EOF'
<?php

namespace App\Providers\Filament;

use App\Filament\Resources\ActivationResource;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\DebugLogResource;
use App\Filament\Resources\LicenseResource;
use App\Filament\Resources\MarketingPageResource;
use App\Filament\Resources\ChangelogEntryResource;
use App\Filament\Resources\RoadmapEntryResource;
use App\Filament\Resources\PlatformNavItemResource;
use App\Filament\Resources\SectionLibraryResource;
use App\Filament\Resources\SiteSettingsResource;
use App\Filament\Resources\DistributorFieldMapResource;
use App\Filament\Resources\SalesAgencyResource; // MARKER-AGENCIES-REGISTER
use App\Filament\Resources\SalesChannelResource; // MARKER-CAMPAIGNS-REGISTER
use App\Filament\Resources\SalesProspectResource; // MARKER-SALES-REGISTER
use App\Filament\Resources\TenantResource;
use App\Filament\Resources\TenantDomainResource;  // MARKER-PATCH-119
use App\Filament\Widgets\DebugLogHeaderStats;
use App\Filament\Widgets\PlatformStatsWidget;
use App\Filament\Widgets\CustomDomainsStatsWidget;  // MARKER-PATCH-119
use App\Filament\Widgets\ServerHealthWidget;
use App\Filament\Widgets\StatsOverview;
use App\Filament\Widgets\OperationalHealthWidget;  // MARKER-PATCH-132
use App\Filament\Widgets\WpPluginStatsWidget;       // MARKER-PATCH-132
use App\Filament\Pages\ThemeEditor;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;  // MARKER-PATCH-158-G9
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;  // MARKER-PATCH-158-G9
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->domain(config('intake.domain', 'intake.works')) // MARKER-PATCH-224B
            ->login()
            ->colors(['primary' => Color::Violet])
            ->brandName('Intake')
            ->resources([
                SalesChannelResource::class, // MARKER-CAMPAIGNS-REGISTER
                SalesAgencyResource::class, // MARKER-AGENCIES-REGISTER
                SalesProspectResource::class, // MARKER-SALES-REGISTER
                TenantResource::class,
                TenantDomainResource::class,  // MARKER-PATCH-119
                CustomerResource::class,
                LicenseResource::class,
                ActivationResource::class,
                MarketingPageResource::class, // new — marketing page editor entry
                PlatformNavItemResource::class, // patch 45 — nav editor
                ChangelogEntryResource::class,
                RoadmapEntryResource::class,
                SiteSettingsResource::class, // patch 45 — global site settings
                SectionLibraryResource::class, // patch 45 — section type catalog
                DebugLogResource::class,
                DistributorFieldMapResource::class, // HLC field mapping
            ])
            ->pages([
                // MARKER-PATCH-135 — custom dashboard replaces Pages\Dashboard
                \App\Filament\Pages\PlatformDashboard::class,
                \App\Filament\Pages\Distributors::class, // HLC distributor hub
                \App\Filament\Pages\CatalogTitles::class, // MARKER-PATCH-HLCE2 title editor
                ThemeEditor::class,
                \App\Filament\Pages\BillingConfiguration::class,
                \App\Filament\Pages\ChangelogImportPreview::class,
                \App\Filament\Pages\EmailHealth::class,  // MARKER-PATCH-148
                \App\Filament\Pages\PlatformEmail::class, // MARKER-PLATFORM-MAIL — this panel lists pages explicitly; it does NOT auto-discover
            ])
            ->widgets([
                ServerHealthWidget::class,
                OperationalHealthWidget::class,  // MARKER-PATCH-132
                PlatformStatsWidget::class,
                WpPluginStatsWidget::class,       // MARKER-PATCH-132
                CustomDomainsStatsWidget::class,  // MARKER-PATCH-119
                StatsOverview::class,
                DebugLogHeaderStats::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // MARKER-PATCH-158-G9 — Restore subtle scrollbar styling on the master-admin
            // sidebar. Filament's sidebar scrolls when nav items exceed viewport height
            // (which happens once the panel has ~15+ items), and a recent change started
            // showing the OS default chunky scrollbar. This injects thin/dim styling
            // scoped to the sidebar nav so it gently scrolls without visually dominating.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => Blade::render(<<<'HTML'
                <style>
                  /* Firefox */
                  .fi-sidebar-nav {
                    scrollbar-width: thin;
                    scrollbar-color: rgba(127,127,127,0.25) transparent;
                  }
                  /* WebKit (Safari, Chrome) */
                  .fi-sidebar-nav::-webkit-scrollbar { width: 6px; }
                  .fi-sidebar-nav::-webkit-scrollbar-track { background: transparent; }
                  .fi-sidebar-nav::-webkit-scrollbar-thumb {
                    background: rgba(127,127,127,0.25);
                    border-radius: 3px;
                  }
                  .fi-sidebar-nav::-webkit-scrollbar-thumb:hover {
                    background: rgba(127,127,127,0.45);
                  }
                </style>
                HTML)
            )
            ->authGuard('web')
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
PMREG_EOF
echo "registered — server: git pull && php artisan optimize:clear && php artisan filament:cache-components"
