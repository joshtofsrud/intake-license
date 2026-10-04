<?php
// MARKER-REPPANEL-PANEL — the /rep panel. Same users table, same 'web' guard;
// isolation comes from canAccessPanel (panel-aware) + this panel only
// registering rep-scoped resources. Reps physically cannot reach Tenants,
// Licensing, or Distribution because those resources don't exist here.

namespace App\Providers\Filament;

use App\Filament\Rep\Resources\RepProspectResource;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class RepPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('rep')
            ->path('rep')
            ->login()
            ->brandName('Intake · Rep')
            // MARKER-PANEL-BRAND — the Brand page's logos and favicon, not hand-set text.
            // Closures, so the Brand row is read per request rather than at boot.
            ->brandLogo(fn () => \App\Support\Brand::url('logo_light'))
            ->darkModeBrandLogo(fn () => \App\Support\Brand::url('logo'))
            ->brandLogoHeight('1.75rem')
            ->favicon(fn () => \App\Support\Brand::url('favicon'))
            ->colors(['primary' => Color::Sky])
            ->darkMode(true)
            ->resources([
                RepProspectResource::class,
            ])
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                \App\Filament\Rep\Widgets\RepBookWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->authGuard('web');
    }
}
