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

    // --- Fixed marketing pages ---
    Route::get('/',         [Platform\MarketingController::class, 'home'])->name('marketing.home');
    Route::get('/pricing',  [Platform\MarketingController::class, 'pricing'])->name('marketing.pricing');
    Route::get('/features', [Platform\MarketingController::class, 'features'])->name('marketing.features');
    Route::get('/docs',     [Platform\MarketingController::class, 'docs'])->name('marketing.docs');
    Route::get('/contact',  [Platform\MarketingController::class, 'contact'])->name('marketing.contact');
    Route::post('/contact', [Platform\MarketingController::class, 'contact'])->name('marketing.contact.submit');

    // --- Industry landing pages: /for/bike-shops, /for/massage-therapy, etc. ---
    Route::get('/for/{industry}', [Platform\MarketingController::class, 'forIndustry'])
        ->where('industry', '[a-z0-9-]+')
        ->name('marketing.industry');

    // --- Impersonation (admin only) ---
    Route::middleware(['auth'])->group(function () {
        Route::post('/admin/impersonate/{tenantId}', [\App\Http\Controllers\Admin\ImpersonationController::class, 'impersonate'])->name('admin.impersonate');
        Route::get('/admin/impersonate/stop',         [\App\Http\Controllers\Admin\ImpersonationController::class, 'stop'])->name('admin.impersonate.stop');
    });

    // --- Generic slug fallback: /{slug} → custom marketing pages ---
    // Must be registered last so it doesn't shadow the named routes above.
    // Excludes 'admin' (Filament) and other reserved paths.
    Route::get('/{slug}', [Platform\MarketingController::class, 'show'])
        ->where('slug', '^(?!admin|api|up|health|for)[a-z0-9][a-z0-9-]*$')
        ->name('marketing.show');

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
