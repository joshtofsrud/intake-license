<?php

namespace App\Providers\Filament;

use App\Filament\Resources\ActivationResource;
use App\Filament\Resources\AddonResource; // MARKER-ADDON-CATALOG
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\DebugLogResource;
use App\Filament\Resources\LicenseResource;
use App\Filament\Resources\MarketingPageResource;
use App\Filament\Resources\ChangelogEntryResource;
use App\Filament\Resources\BillingNoticeTemplateResource; // MARKER-BILLING-NOTICES
use App\Filament\Resources\TenantBillingDiscountResource; // MARKER-BILLING-DISCOUNTS
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
            // MARKER-NAV-ORDER — group order comes from the database when it has
            // an opinion. An empty array means Filament keeps its own order, so
            // an empty table changes nothing.
            ->navigationGroups(\App\Support\AdminNav::groupOrder())
            ->resources([
                AddonResource::class, // MARKER-ADDON-CATALOG
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
                TenantBillingDiscountResource::class, // MARKER-BILLING-DISCOUNTS
                BillingNoticeTemplateResource::class, // MARKER-BILLING-NOTICES
                RoadmapEntryResource::class,
                SiteSettingsResource::class, // patch 45 — global site settings
                SectionLibraryResource::class, // patch 45 — section type catalog
                DebugLogResource::class,
                DistributorFieldMapResource::class, // HLC field mapping
            ])
            ->pages([
                // MARKER-CONTRIBUTIONS · MARKER-CONTRIBUTIONS-FQN — fully qualified
                // on purpose: there is no `use App\Filament\Pages` in this file, so a
                // bare Pages\ prefix resolves into Filament's own namespace and kills
                // artisan at boot.
                \App\Filament\Pages\Contributions::class,
                \App\Filament\Pages\TaskHealth::class,      // MARKER-TASK-HEALTH
                // MARKER-PATCH-135 — custom dashboard replaces Pages\Dashboard
                \App\Filament\Pages\PlatformDashboard::class,
                \App\Filament\Pages\Distributors::class, // HLC distributor hub
                // MARKER-REVIEW-PAGE — list-first Catalog Titles page. This panel
                // lists pages explicitly, so a new page class is invisible until
                // it appears here, cache or no cache.
                // MARKER-TITLE-CONTROL — the merged surface. The two below stay
                // registered so their URLs keep working and reverting is one
                // line, but they are hidden from navigation.
                \App\Filament\Pages\CatalogTitleControl::class,
                // MARKER-MATCH-REVIEW — explicit registration; this panel does
                // not auto-discover, so the page has no route until listed.
                \App\Filament\Pages\CatalogMatchReview::class,
                // MARKER-CATALOG-COVERAGE — explicit registration; this panel
                // does not auto-discover.
                \App\Filament\Pages\CatalogCoverage::class,
                // MARKER-CATALOG-LOOKUP-REG — same: no route without this line.
                \App\Filament\Pages\CatalogItemLookup::class,
                ThemeEditor::class,
                \App\Filament\Pages\BillingConfiguration::class,
                \App\Filament\Pages\PasswordEditor::class,
                \App\Filament\Pages\ChangelogImportPreview::class,
                \App\Filament\Pages\EmailHealth::class,  // MARKER-PATCH-148
                \App\Filament\Pages\PlatformEmail::class, // MARKER-PLATFORM-MAIL — this panel lists pages explicitly; it does NOT auto-discover
                // MARKER-MKTREG — same trap again: without this line the page
                // has no route and 404s, nav or no nav. Any NEW Filament page
                // must be added here.
                \App\Filament\Pages\MarketingTraffic::class,
                \App\Filament\Pages\Raise::class, // MARKER-RAISE-ADMIN — panel lists pages explicitly, no auto-discovery
                \App\Filament\Pages\InvestorRecord::class, // MARKER-RAISE-RECORDS
                \App\Filament\Pages\RaiseSetup::class, // MARKER-RAISE-SETUP
                \App\Filament\Pages\TeamRoles::class, // MARKER-TEAM-ROLES — pages are EXPLICIT here, no auto-discovery
                \App\Filament\Pages\RolesAccess::class, // MARKER-ADMIN-NAV-GATE
                \App\Filament\Pages\Scheduling::class,             // MARKER-SCHED-ADMIN — explicit, no auto-discovery
                \App\Filament\Pages\SchedulingAvailability::class, // MARKER-SCHED-ADMIN
                \App\Filament\Pages\SchedulingTypes::class,        // MARKER-SCHED-ADMIN
                \App\Filament\Pages\Demo::class,
                \App\Filament\Pages\NavArrange::class,             // MARKER-NAV-ARRANGE
                \App\Filament\Pages\CustomerCleanup::class,   // MARKER-CUST-CLEANUP
                \App\Filament\Pages\TenantBilling::class,     // MARKER-TENANT-BILLING                   // MARKER-DEMO-ENTRY
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
            // MARKER-ADMIN-CONFIRM — in-app confirm for every [data-confirm].
            // Replaces wire:confirm, which uses the browser's native confirm()
            // and fails closed and silently when that is suppressed. Capture
            // phase, so the click is intercepted before Livewire's own
            // listener runs; on Yes the button is flagged and the click is
            // re-dispatched, and Livewire handles it exactly as before.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => Blade::render(<<<'HTML'
                <div id="ia-confirm" style="display:none;position:fixed;inset:0;z-index:100;
                     background:rgba(0,0,0,.6);align-items:center;justify-content:center">
                  <div role="dialog" aria-modal="true" aria-labelledby="ia-confirm-msg"
                       style="background:rgb(24 24 27);border:1px solid rgba(255,255,255,.14);
                              border-radius:12px;width:420px;max-width:92vw;padding:20px 22px;
                              box-shadow:0 24px 60px rgba(0,0,0,.6);color:#f4f4f5;font-size:14px;line-height:1.5">
                    <div id="ia-confirm-msg" style="white-space:pre-line"></div>
                    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:18px">
                      <button type="button" id="ia-confirm-no"
                              style="padding:7px 14px;border-radius:8px;border:1px solid rgba(255,255,255,.18);
                                     background:transparent;color:inherit;cursor:pointer;font:inherit">Cancel</button>
                      <button type="button" id="ia-confirm-yes"
                              style="padding:7px 14px;border-radius:8px;border:0;background:#bef264;
                                     color:#0b0b0b;font-weight:600;cursor:pointer;font:inherit">Yes, go ahead</button>
                    </div>
                  </div>
                </div>
                <script>
                (function () {
                  var wrap = document.getElementById('ia-confirm');
                  var msg  = document.getElementById('ia-confirm-msg');
                  var yes  = document.getElementById('ia-confirm-yes');
                  var no   = document.getElementById('ia-confirm-no');
                  var pending = null;

                  function close() { wrap.style.display = 'none'; pending = null; }

                  document.addEventListener('click', function (e) {
                    var btn = e.target.closest ? e.target.closest('[data-confirm]') : null;
                    if (!btn) { return; }

                    // Second pass after Yes: let it through to Livewire.
                    if (btn.dataset.confirmed === '1') {
                      delete btn.dataset.confirmed;
                      return;
                    }

                    e.preventDefault();
                    e.stopImmediatePropagation();

                    pending = btn;
                    msg.textContent = btn.getAttribute('data-confirm') || 'Are you sure?';
                    wrap.style.display = 'flex';
                    yes.focus();
                  }, true);

                  yes.addEventListener('click', function () {
                    var btn = pending;
                    close();
                    if (!btn) { return; }
                    btn.dataset.confirmed = '1';
                    btn.click();
                  });

                  no.addEventListener('click', close);
                  wrap.addEventListener('click', function (e) { if (e.target === wrap) { close(); } });
                  document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape' && wrap.style.display === 'flex') { close(); }
                  });
                })();
                </script>
                HTML)
            )
            ->authGuard('web')
            ->authMiddleware([
                Authenticate::class,
                \App\Http\Middleware\EnforceAdminArea::class, // MARKER-ADMIN-ROLES
            ]);
    }
}
