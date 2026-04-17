<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Platform;
use App\Http\Controllers\Tenant as TenantControllers;

$domain     = config('intake.domain', 'intake.works');
$tenantHost = '{subdomain}.' . $domain;

// =========================================================================
// Platform routes — intake.works
// =========================================================================

Route::domain($domain)->group(function () {

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

    Route::get('/',         [Platform\MarketingController::class, 'home'])->name('marketing.home');
    Route::get('/pricing',  [Platform\MarketingController::class, 'pricing'])->name('marketing.pricing');
    Route::get('/features', [Platform\MarketingController::class, 'features'])->name('marketing.features');
    Route::get('/docs',     [Platform\MarketingController::class, 'docs'])->name('marketing.docs');
    Route::get('/contact',  [Platform\MarketingController::class, 'contact'])->name('marketing.contact');
    Route::post('/contact', [Platform\MarketingController::class, 'contact'])->name('marketing.contact.submit');

    Route::middleware(['auth'])->group(function () {
        Route::post('/admin/impersonate/{tenantId}', [\App\Http\Controllers\Admin\ImpersonationController::class, 'impersonate'])->name('admin.impersonate');
        Route::get('/admin/impersonate/stop',         [\App\Http\Controllers\Admin\ImpersonationController::class, 'stop'])->name('admin.impersonate.stop');
    });

});

// =========================================================================
// Platform routes — app.intake.works
// =========================================================================

Route::domain('app.' . $domain)->group(function () {

    Route::get('/',         [Platform\OnboardingController::class, 'index'])->name('platform.home');
    Route::get('/signup',   [Platform\OnboardingController::class, 'signup'])->name('platform.signup');
    Route::post('/signup',  [Platform\OnboardingController::class, 'processSignup'])->name('platform.signup.process');
    Route::get('/checkout', [Platform\OnboardingController::class, 'checkout'])->name('platform.checkout');
    Route::post('/subdomain/check', [Platform\OnboardingController::class, 'checkSubdomain'])->name('platform.subdomain.check');

    Route::get('/login',    [Platform\OnboardingController::class, 'login'])->name('platform.login');

});

// =========================================================================
// Tenant routes — {slug}.intake.works
// =========================================================================

Route::middleware(['App\Http\Middleware\ResolveTenant'])
    ->domain($tenantHost)
    ->group(function () {

    // ----------------------------------------------------------------
    // Public pages — no auth required
    // ----------------------------------------------------------------
    Route::get('/',        [TenantControllers\PublicController::class, 'home'])->name('tenant.home');
    Route::get('/confirm', [TenantControllers\PublicController::class, 'confirm'])->name('tenant.confirm');
    Route::get('/contact', [TenantControllers\PublicController::class, 'contact'])->name('tenant.contact');

    Route::get('/book',                  [TenantControllers\BookingController::class, 'index'])->name('tenant.booking');
    Route::get('/book/availability',     [TenantControllers\BookingController::class, 'availability'])->name('tenant.booking.availability');
    Route::post('/book/submit',          [TenantControllers\BookingController::class, 'submit'])->name('tenant.booking.submit');
    Route::get('/book/paypal/return',    [TenantControllers\BookingController::class, 'paypalReturn'])->name('tenant.paypal.return');

    Route::post('/webhooks/stripe',  [TenantControllers\BookingController::class, 'stripeWebhook'])->name('tenant.webhook.stripe');
    Route::post('/webhooks/paypal',  [TenantControllers\BookingController::class, 'paypalWebhook'])->name('tenant.webhook.paypal');

    Route::post('/contact',  [TenantControllers\PublicController::class, 'contact'])->name('tenant.contact.submit');

    // ----------------------------------------------------------------
    // Tenant admin — authenticated
    // ----------------------------------------------------------------
    Route::prefix('admin')
        ->name('tenant.')
        ->group(function () {

        // Auth routes
        Route::get('/login',            [TenantControllers\AuthController::class, 'showLogin'])->name('login');
        Route::post('/login',           [TenantControllers\AuthController::class, 'login'])->name('login.submit');
        Route::get('/forgot-password',  [TenantControllers\AuthController::class, 'showForgot'])->name('forgot');
        Route::post('/forgot-password', [TenantControllers\AuthController::class, 'sendReset'])->name('forgot.submit');
        Route::get('/reset-password',   [TenantControllers\AuthController::class, 'showReset'])->name('reset');
        Route::post('/reset-password',  [TenantControllers\AuthController::class, 'resetPassword'])->name('reset.submit');
        Route::post('/logout',          [TenantControllers\AuthController::class, 'logout'])->name('logout');

        // Authenticated tenant routes
        Route::middleware([
            'App\Http\Middleware\ConsumeOnboardingToken',
            'App\Http\Middleware\RequireTenantAuth',
            'App\Http\Middleware\ApplyTenantTheme',
        ])->group(function () {

            Route::get('/',                 [TenantControllers\DashboardController::class, 'index'])->name('dashboard');

            // Onboarding modal endpoints
            Route::post('/onboarding/branding', [TenantControllers\OnboardingModalController::class, 'saveBranding'])->name('onboarding.branding');
            Route::post('/onboarding/services', [TenantControllers\OnboardingModalController::class, 'saveServices'])->name('onboarding.services');
            Route::post('/onboarding/hours',    [TenantControllers\OnboardingModalController::class, 'saveHours'])->name('onboarding.hours');
            Route::post('/onboarding/dismiss',  [TenantControllers\OnboardingModalController::class, 'dismiss'])->name('onboarding.dismiss');
            Route::post('/onboarding/complete', [TenantControllers\OnboardingModalController::class, 'complete'])->name('onboarding.complete');

            // Appointments
            Route::get('/appointments',         [TenantControllers\AppointmentController::class, 'index'])->name('appointments.index');
            Route::post('/appointments',        [TenantControllers\AppointmentController::class, 'store'])->name('appointments.store');
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

            // Booking form editor
            Route::get('/booking-editor',       [TenantControllers\BookingEditorController::class, 'index'])->name('booking-editor.index');
            Route::post('/booking-editor',      [TenantControllers\BookingEditorController::class, 'store'])->name('booking-editor.store');

            // Page builder
            Route::get('/pages',                [TenantControllers\PageBuilderController::class, 'index'])->name('pages.index');
            Route::get('/pages/{id}',           [TenantControllers\PageBuilderController::class, 'edit'])->name('pages.edit');
            Route::post('/pages',               [TenantControllers\PageBuilderController::class, 'store'])->name('pages.store');
            Route::patch('/pages/{id}',         [TenantControllers\PageBuilderController::class, 'update'])->name('pages.update');
            Route::delete('/pages/{id}',        [TenantControllers\PageBuilderController::class, 'destroy'])->name('pages.destroy');
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

    // Public page catch-all — MUST be last (catches /{slug} for tenant pages)
    Route::get('/{slug}', [TenantControllers\PublicController::class, 'page'])->name('tenant.page');

});
