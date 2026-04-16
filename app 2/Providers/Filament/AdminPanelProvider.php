<?php

namespace App\Providers\Filament;

use App\Filament\Resources\ActivationResource;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\LicenseResource;
use App\Filament\Resources\TenantResource;
use App\Filament\Widgets\PlatformStatsWidget;
use App\Filament\Widgets\StatsOverview;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->domain(env('APP_DOMAIN', 'intake.works')) // Scope Filament to root domain only
            ->login()
            ->colors(['primary' => Color::Violet])
            ->brandName('Intake')
            ->resources([
                TenantResource::class,
                CustomerResource::class,
                LicenseResource::class,
                ActivationResource::class,
            ])
            ->pages([
                Pages\Dashboard::class,
            ])
            ->widgets([
                PlatformStatsWidget::class,
                StatsOverview::class,
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
            ->authGuard('web')
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
