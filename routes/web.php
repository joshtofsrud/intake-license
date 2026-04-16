<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Platform;
use App\Http\Controllers\Tenant as TenantControllers;

/*
|--------------------------------------------------------------------------
| Intake — Web Routes
|--------------------------------------------------------------------------
|
| Four distinct route groups, separated by domain pattern:
|
|   1. Platform routes     — intake.works, app.intake.works
|   2. License API         — license.intake.works (see routes/api.php)
|   3. Tenant public       — {slug}.intake.works or custom domain
|   4. Tenant admin        — {slug}.intake.works/admin (authenticated)
|
| The Filament master admin lives at intake.works/admin (handled by
| AdminPanelProvider — no route definition needed here).
|
| Tenant subdomain routing uses Laravel's domain() constraint.
| Custom domains are handled via the ResolveTenant middleware fallback.
|
*/

$domain     = config('intake.domain', 'intake.works');
$tenantHost = '{subdomain}.' . $domain;

// =========================================================================
// Platform routes — intake.works + app.intake.works
// =========================================================================

Route::domain($domain)->group(function () {

    // Health check (used by uptime monitors)
    Route::get('/health', function () {
        try {
            \Illuminate\Support\Facades\DB::select('SELECT 1');
            $db = 'ok';
        } catch (\Exception $e) {
            $db = 'error';
        }
        return response()->json([
            'status'   => $db === 'ok' ? 'ok' : 'degraded',
            'database' => $db,
        ], $db === 'ok' ? 200 : 503);
    });

    // Marketing site
    Route::get('/',         [Platform\MarketingController::class, 'home'])->name('marketing.home');
    Route::get('/pricing',  [Platform\MarketingController::class, 'pricing'])->name('marketing.pricing');
    Route::get('/features', [Platform\MarketingController::class, 'features'])->name('marketing.features');
    Route::get('/docs',     [Platform\MarketingController::class, 'docs'])->name('marketing.docs');
    Route::get('/contact',  [Platform\MarketingController::class, 'contact'])->name('marketing.contact');
    Route::post('/contact', [Platform\MarketingController::class, 'contact'])->name('marketing.contact.submit');

    // Master admin impersonation (Filament auth guards this via middleware)
    Route::middleware(['auth'])->group(function () {
        Route::post('/admin/impersonate/{tenantId}', [\App\Http\Controllers\Admin\ImpersonationController::class, 'impersonate'])->name('admin.impersonate');
        Route::get('/admin/impersonate/stop',         [\App\Http\Controllers\Admin\ImpersonationController::class, 'stop'])->name('admin.impersonate.stop');
    });

});

Route::domain('app.' . $domain)->group(function () {

    // Tenant onboarding + signup
    Route::get('/',         [Platform\OnboardingController::class, 'index'])->name('platform.home');
    Route::get('/signup',   [Platform\OnboardingController::class, 'signup'])->name('platform.signup');
    Route::post('/signup',  [Platform\OnboardingController::class, 'processSignup'])->name('platform.signup.process');
    Route::get('/checkout', [Platform\OnboardingController::class, 'checkout'])->name('platform.checkout');
    Route::post('/subdomain/check', [Platform\OnboardingController::class, 'checkSubdomain'])->name('platform.subdomain.check');

    // Tenant login (redirects to their subdomain after auth)
    Route::get('/login',    [Platform\OnboardingController::class, 'login'])->name('platform.login');

});

// =========================================================================
// Tenant routes — {slug}.intake.works  (and custom domains via middleware)
// =========================================================================

Route::middleware(['App\Http\Middleware\ResolveTenant'])
    ->group(function () use ($tenantHost, $domain) {

    // ------------------------------------------------------------------
    // Public routes — no auth required
    // ------------------------------------------------------------------
    Route::domain($tenantHost)->group(function () {

        // Public website pages (served by page builder)
        Route::get('/',        [TenantControllers\PublicController::class, 'home'])->name('tenant.home');
        Route::get('/confirm', [TenantControllers\PublicController::class, 'confirm'])->name('tenant.confirm');
        Route::get('/contact', [TenantControllers\PublicController::class, 'contact'])->name('tenant.contact');

        // Booking form
        Route::get('/book',                  [TenantControllers\BookingController::class, 'index'])->name('tenant.booking');
        Route::get('/book/availability',     [TenantControllers\BookingController::class, 'availability'])->name('tenant.booking.availability');
        Route::post('/book/submit',          [TenantControllers\BookingController::class, 'submit'])->name('tenant.booking.submit');
        Route::get('/book/paypal/return',    [TenantControllers\BookingController::class, 'paypalReturn'])->name('tenant.paypal.return');

        // Payment webhooks
        Route::post('/webhooks/stripe',  [TenantControllers\BookingController::class, 'stripeWebhook'])->name('tenant.webhook.stripe');
        Route::post('/webhooks/paypal',  [TenantControllers\BookingController::class, 'paypalWebhook'])->name('tenant.webhook.paypal');

        // Dynamic page builder pages
        Route::get('/{slug}',    [TenantControllers\PublicController::class, 'page'])->name('tenant.page');
        Route::post('/contact',  [TenantControllers\PublicController::class, 'contact'])->name('tenant.contact.submit');

    });

    // Also handle custom domains (no subdomain constraint — ResolveTenant
    // already resolved the tenant from the custom domain)
    Route::get('/',         [TenantControllers\PublicController::class, 'home'])->name('tenant.home.custom');
    Route::get('/book',     [TenantControllers\PublicController::class, 'booking'])->name('tenant.booking.custom');
    Route::get('/contact',  [TenantControllers\PublicController::class, 'contact'])->name('tenant.contact.custom');
    Route::get('/{slug}',   [TenantControllers\PublicController::class, 'page'])->name('tenant.page.custom');

    // ------------------------------------------------------------------
    // Tenant admin routes — authenticated + onboarded
    // ------------------------------------------------------------------
    Route::domain($tenantHost)
        ->prefix('admin')
        ->name('tenant.')
        ->group(function () {

        // Auth (no tenant auth guard needed for these)
        Route::get('/login', function () {
            $request = request();
            if ($request->query('forgot')) return app(\App\Http\Controllers\Tenant\AuthController::class)->showForgot();
            if ($request->query('reset')) return app(\App\Http\Controllers\Tenant\AuthController::class)->showReset($request);
            return app(\App\Http\Controllers\Tenant\AuthController::class)->showLogin();
        })->name('login');
        Route::post('/login', function (\Illuminate\Http\Request $request) {
            $op = $request->query('forgot') ? 'sendReset'
                : ($request->query('reset') ? 'resetPassword' : 'login');
            return app(\App\Http\Controllers\Tenant\AuthController::class)->$op($request);
        })->name('login.submit');
        Route::post('/logout', [TenantControllers\AuthController::class, 'logout'])->name('logout');

        // Onboarding wizard — auth required but not onboarded check
        // ConsumeOnboardingToken runs first so a `?token=` from signup
        // auto-logs the user in before RequireTenantAuth checks them.
        Route::middleware([
            'App\Http\Middleware\ConsumeOnboardingToken',
            'App\Http\Middleware\RequireTenantAuth',
        ])
            ->prefix('onboarding')
            ->name('onboarding.')
            ->group(function () {
                Route::get('/',         [TenantControllers\OnboardingController::class, 'index'])->name('index');
                Route::get('/branding', [TenantControllers\OnboardingController::class, 'branding'])->name('branding');
                Route::post('/branding',[TenantControllers\OnboardingController::class, 'saveBranding'])->name('branding.save');
                Route::get('/services', [TenantControllers\OnboardingController::class, 'services'])->name('services');
                Route::post('/services',[TenantControllers\OnboardingController::class, 'saveServices'])->name('services.save');
                Route::get('/complete', [TenantControllers\OnboardingController::class, 'complete'])->name('complete');
            });

        // Main admin — auth + onboarded required
        Route::middleware([
            'App\Http\Middleware\RequireTenantAuth',
            'App\Http\Middleware\RequireOnboarded',
            'App\Http\Middleware\ApplyTenantTheme',
        ])->group(function () {

            Route::get('/',                 [TenantControllers\DashboardController::class, 'index'])->name('dashboard');

            // Appointments
            Route::get('/appointments',         [TenantControllers\AppointmentController::class, 'index'])->name('appointments.index');
            Route::get('/appointments/{id}',    [TenantControllers\AppointmentController::class, 'show'])->name('appointments.show');
            Route::patch('/appointments/{id}',  [TenantControllers\AppointmentController::class, 'update'])->name('appointments.update');

            // Customers
            Route::get('/customers',            [TenantControllers\CustomerController::class, 'index'])->name('customers.index');
            Route::get('/customers/{id}',       [TenantControllers\CustomerController::class, 'show'])->name('customers.show');
            Route::post('/customers',           [TenantControllers\CustomerController::class, 'store'])->name('customers.store');
            Route::patch('/customers/{id}',     [TenantControllers\CustomerController::class, 'update'])->name('customers.update');

            // Services
            Route::get('/services',             [TenantControllers\ServiceController::class, 'index'])->name('services.index');
            Route::post('/services',            [TenantControllers\ServiceController::class, 'store'])->name('services.store');
            Route::patch('/services/{id}',      [TenantControllers\ServiceController::class, 'update'])->name('services.update');
            Route::delete('/services/{id}',     [TenantControllers\ServiceController::class, 'destroy'])->name('services.destroy');

            // Capacity
            Route::get('/capacity',             [TenantControllers\CapacityController::class, 'index'])->name('capacity.index');
            Route::post('/capacity',            [TenantControllers\CapacityController::class, 'store'])->name('capacity.store');

            // Page builder
            Route::get('/pages',                [TenantControllers\PageBuilderController::class, 'index'])->name('pages.index');
            Route::get('/pages/{id}',           [TenantControllers\PageBuilderController::class, 'edit'])->name('pages.edit');
            Route::post('/pages',               [TenantControllers\PageBuilderController::class, 'store'])->name('pages.store');
            Route::patch('/pages/{id}',         [TenantControllers\PageBuilderController::class, 'update'])->name('pages.update');
            Route::delete('/pages/{id}',        [TenantControllers\PageBuilderController::class, 'destroy'])->name('pages.destroy');

            // Page sections (AJAX)
            Route::post('/pages/{id}/sections',           [TenantControllers\PageBuilderController::class, 'addSection'])->name('pages.sections.add');
            Route::patch('/pages/{id}/sections/{sid}',    [TenantControllers\PageBuilderController::class, 'updateSection'])->name('pages.sections.update');
            Route::delete('/pages/{id}/sections/{sid}',   [TenantControllers\PageBuilderController::class, 'deleteSection'])->name('pages.sections.delete');
            Route::post('/pages/{id}/sections/reorder',   [TenantControllers\PageBuilderController::class, 'reorderSections'])->name('pages.sections.reorder');

            // Communications
            Route::get('/emails',               [TenantControllers\EmailController::class, 'index'])->name('emails.index');
            Route::patch('/emails/{type}',      [TenantControllers\EmailController::class, 'update'])->name('emails.update');
            Route::get('/campaigns',            [TenantControllers\CampaignController::class, 'index'])->name('campaigns.index');
            Route::get('/campaigns/{id}',       [TenantControllers\CampaignController::class, 'show'])->name('campaigns.show');
            Route::post('/campaigns',           [TenantControllers\CampaignController::class, 'store'])->name('campaigns.store');
            Route::patch('/campaigns/{id}',     [TenantControllers\CampaignController::class, 'update'])->name('campaigns.update');
            Route::post('/campaigns/{id}/send', [TenantControllers\CampaignController::class, 'send'])->name('campaigns.send');

            // Branding + settings
            Route::get('/branding',             [TenantControllers\BrandingController::class, 'index'])->name('branding.index');
            Route::patch('/branding',           [TenantControllers\BrandingController::class, 'update'])->name('branding.update');
            Route::get('/settings',             [TenantControllers\SettingsController::class, 'index'])->name('settings.index');
            Route::patch('/settings',           [TenantControllers\SettingsController::class, 'update'])->name('settings.update');

            // Team
            Route::get('/team',                 [TenantControllers\TeamController::class, 'index'])->name('team.index');
            Route::post('/team',                [TenantControllers\TeamController::class, 'store'])->name('team.store');
            Route::patch('/team/{id}',          [TenantControllers\TeamController::class, 'update'])->name('team.update');
            Route::delete('/team/{id}',         [TenantControllers\TeamController::class, 'destroy'])->name('team.destroy');

        });

    });

});
