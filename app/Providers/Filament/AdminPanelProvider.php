<?php

namespace App\Providers\Filament;

use App\Filament\Resources\ActivationResource;
use App\Filament\Resources\AddonResource;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\DebugLogResource;
use App\Filament\Resources\LicenseResource;
use App\Filament\Resources\MarketingPageResource;
use App\Filament\Resources\ChangelogEntryResource;
use App\Filament\Resources\BillingNoticeTemplateResource;
use App\Filament\Resources\TenantBillingDiscountResource;
use App\Filament\Resources\RoadmapEntryResource;
use App\Filament\Resources\SectionLibraryResource;
use App\Filament\Resources\SiteSettingsResource;
use App\Filament\Resources\DistributorFieldMapResource;
use App\Filament\Resources\SalesAgencyResource;
use App\Filament\Resources\SalesChannelResource;
use App\Filament\Resources\SalesProspectResource;
use App\Filament\Resources\TenantResource;
use App\Filament\Resources\TenantDomainResource;
use App\Filament\Widgets\DebugLogHeaderStats;
use App\Filament\Widgets\PlatformStatsWidget;
use App\Filament\Widgets\CustomDomainsStatsWidget;
use App\Filament\Widgets\ServerHealthWidget;
use App\Filament\Widgets\StatsOverview;
use App\Filament\Widgets\OperationalHealthWidget;
use App\Filament\Widgets\WpPluginStatsWidget;
use App\Filament\Pages\ThemeEditor;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->domain(config('intake.domain', 'intake.works'))
            ->login()
            ->colors(['primary' => Color::Violet])
            ->brandName('Intake')
            // the Brand page's logos and favicon, not hand-set text.
            // Closures, so the Brand row is read per request rather than at boot.
            ->brandLogo(fn () => \App\Support\Brand::url('logo_light'))
            ->darkModeBrandLogo(fn () => \App\Support\Brand::url('logo'))
            ->brandLogoHeight('1.75rem')
            ->favicon(fn () => \App\Support\Brand::url('favicon'))
            // group order comes from the database when it has
            // an opinion. An empty array means Filament keeps its own order, so
            // an empty table changes nothing.
            ->navigationGroups(\App\Support\AdminNav::groupOrder())
            ->resources([
                AddonResource::class,
                SalesChannelResource::class,
                SalesAgencyResource::class,
                SalesProspectResource::class,
                \App\Filament\Resources\SalesTerritoryResource::class,
                TenantResource::class,
                TenantDomainResource::class,
                CustomerResource::class,
                LicenseResource::class,
                ActivationResource::class,
                MarketingPageResource::class, // new — marketing page editor entry
                ChangelogEntryResource::class,
                TenantBillingDiscountResource::class,
                BillingNoticeTemplateResource::class,
                RoadmapEntryResource::class,
                SiteSettingsResource::class, // patch 45 — global site settings
                SectionLibraryResource::class, // patch 45 — section type catalog
                DebugLogResource::class,
                DistributorFieldMapResource::class, // HLC field mapping
            ])
            ->pages([
                \App\Filament\Pages\PlatformCommunication::class,
                \App\Filament\Pages\Brand::class,
                // explicit registration; this panel does not auto-discover.
                \App\Filament\Pages\SiteNavigation::class,
                // explicit registration; this panel does not
                // auto-discover, and an unregistered page has no route.
                \App\Filament\Pages\PlatformInbox::class,
                // this panel lists pages EXPLICITLY and does
                // not auto-discover, so an unregistered page class has no route
                // at all. This has bitten at least five times; do not remove.
                \App\Filament\Pages\HelpArticles::class,
                // fully qualified
                // on purpose: there is no `use App\Filament\Pages` in this file, so a
                // bare Pages\ prefix resolves into Filament's own namespace and kills
                // artisan at boot.
                \App\Filament\Pages\Contributions::class,
                \App\Filament\Pages\TaskHealth::class,
                // custom dashboard replaces Pages\Dashboard
                \App\Filament\Pages\PlatformDashboard::class,
                \App\Filament\Pages\Distributors::class, // HLC distributor hub
                // list-first Catalog Titles page. This panel
                // lists pages explicitly, so a new page class is invisible until
                // it appears here, cache or no cache.
                // the merged surface. The two below stay
                // registered so their URLs keep working and reverting is one
                // line, but they are hidden from navigation.
                \App\Filament\Pages\CatalogTitleControl::class,
                // explicit registration; this panel does
                // not auto-discover, so the page has no route until listed.
                \App\Filament\Pages\CatalogMatchReview::class,
                // explicit registration; this panel
                // does not auto-discover.
                \App\Filament\Pages\CatalogCoverage::class,
                // same: no route without this line.
                \App\Filament\Pages\CatalogItemLookup::class,
                ThemeEditor::class,
                \App\Filament\Pages\BillingConfiguration::class,
                \App\Filament\Pages\PasswordEditor::class,
                \App\Filament\Pages\ChangelogImportPreview::class,
                \App\Filament\Pages\EmailHealth::class,
                \App\Filament\Pages\PlatformEmail::class, // this panel lists pages explicitly; it does NOT auto-discover
                // same trap again: without this line the page
                // has no route and 404s, nav or no nav. Any NEW Filament page
                // must be added here.
                \App\Filament\Pages\MarketingTraffic::class,
                \App\Filament\Pages\Raise::class, // panel lists pages explicitly, no auto-discovery
                \App\Filament\Pages\InvestorRecord::class,
                \App\Filament\Pages\RaiseSetup::class,
                \App\Filament\Pages\TeamRoles::class, // pages are EXPLICIT here, no auto-discovery
                \App\Filament\Pages\RolesAccess::class,
                \App\Filament\Pages\Scheduling::class,             // explicit, no auto-discovery
                \App\Filament\Pages\SchedulingAvailability::class,
                \App\Filament\Pages\SchedulingTypes::class,
                \App\Filament\Pages\Demo::class,
                \App\Filament\Pages\NavArrange::class,
                \App\Filament\Pages\CustomerCleanup::class,
                \App\Filament\Pages\TenantBilling::class,
                \App\Filament\Pages\SalesRouteDay::class,     // explicit registration; this panel does NOT auto-discover
                \App\Filament\Pages\SalesPipeline::class,     // explicit registration; this panel does NOT auto-discover
                \App\Filament\Pages\SalesFindShops::class, \App\Filament\Pages\SalesPlacesSettings::class,     // explicit registration; this panel does NOT auto-discover
            ])
            ->widgets([
                ServerHealthWidget::class,
                OperationalHealthWidget::class,
                PlatformStatsWidget::class,
                WpPluginStatsWidget::class,
                CustomDomainsStatsWidget::class,
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
            // Restore subtle scrollbar styling on the master-admin
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
            // one sheet that makes every custom page
            // usable on a phone. loaded at BODY_END,
            // not HEAD_END — each page's <style> sits inside the body, so the
            // head is BEFORE it and the page was winning every un-!important
            // rule. Versioned by filemtime so a change is picked up without
            // a cache clear.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => '<link rel="stylesheet" href="'
                    . asset('css/admin/mobile.css') . '?v='
                    . (file_exists(public_path('css/admin/mobile.css')) ? filemtime(public_path('css/admin/mobile.css')) : '1')
                    . '">'
            )
            // in-app confirm for every [data-confirm].
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
                \App\Http\Middleware\EnforceAdminArea::class,
            ]);
    }
}
