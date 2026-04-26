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

    // --- Stripe subscription webhooks (addon framework + plan billing) ---
    // Separate from tenant-scoped /webhooks/stripe which handles booking deposits.
    // Stripe signs the request; CSRF exempted via VerifyCsrfToken::$except.
    Route::post('/webhooks/stripe/subscriptions',
        [\App\Http\Controllers\Webhooks\StripeWebhookController::class, 'handle']
    )->name('webhooks.stripe.subscriptions');

    // --- Plan quiz analytics (marketing funnel) ---
    // Client-side quiz POSTs here on completion. CSRF exempted so the quiz
    // can run on any cached marketing page without needing a fresh token.
    Route::post('/api/plan-quiz/complete',
        [Platform\PlanQuizController::class, 'complete']
    )->name('platform.plan-quiz.complete');

    // --- Fixed marketing pages (backed by platform tenant's TenantPages) ---
    Route::get('/',         [Platform\MarketingController::class, 'home'])->name('marketing.home');
    Route::get('/pricing',  [Platform\MarketingController::class, 'pricing'])->name('marketing.pricing');
    Route::get('/changelog', [Platform\MarketingController::class, 'changelog'])->name('marketing.changelog');
    Route::get('/roadmap',   [Platform\MarketingController::class, 'roadmap'])->name('marketing.roadmap');
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

    // --- Marketing page editor bridge (admin only) ---
    // GET hands off to the tenant page builder view with platform tenant bound.
    // POST handles auto-save (section content, nav, page meta).
    Route::middleware(['auth'])->group(function () {
        Route::get('/admin/marketing-pages/{pageId}/edit-content',
            [\App\Http\Controllers\Admin\MarketingPageController::class, 'editContent']
        )->name('admin.marketing-pages.edit-content');

        Route::post('/admin/marketing-pages/store',
            [\App\Http\Controllers\Admin\MarketingPageController::class, 'store']
        )->name('admin.marketing-pages.store');
    });

    // --- Generic slug fallback: /{slug} → custom marketing pages ---
    // Must be registered last so it doesn't shadow the named routes above.
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
    Route::get('/signup/payment',  [Platform\OnboardingController::class, 'paymentStep'])->name('platform.signup.payment');
    Route::post('/signup/complete', [Platform\OnboardingController::class, 'completeSignup'])->name('platform.signup.complete');
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

    Route::get('/',        [TenantControllers\PublicController::class, 'home'])->name('tenant.home');
    Route::get('/confirm', [TenantControllers\PublicController::class, 'confirm'])->name('tenant.confirm');
    Route::get('/contact', [TenantControllers\PublicController::class, 'contact'])->name('tenant.contact');

    Route::get('/book',                  [TenantControllers\BookingController::class, 'index'])->name('tenant.booking');
    Route::get('/book/availability',     [TenantControllers\BookingController::class, 'availability'])->name('tenant.booking.availability');
    Route::post('/book/submit',          [TenantControllers\BookingController::class, 'submit'])->name('tenant.booking.submit');

    Route::get('/waitlist/join',               [TenantControllers\WaitlistPublicController::class, 'join'])->name('tenant.waitlist.join');
    Route::post('/waitlist/join',              [TenantControllers\WaitlistPublicController::class, 'submitJoin'])->name('tenant.waitlist.submit');
    Route::get('/waitlist/my',                 [TenantControllers\WaitlistPublicController::class, 'myEntries'])->name('tenant.waitlist.my');
    Route::post('/waitlist/remove',            [TenantControllers\WaitlistPublicController::class, 'removeEntry'])->name('tenant.waitlist.remove');
    Route::get('/waitlist/offer/{token}',      [TenantControllers\WaitlistOfferController::class, 'show'])->name('tenant.waitlist.offer.show');
    Route::post('/waitlist/offer/{token}/accept', [TenantControllers\WaitlistOfferController::class, 'accept'])->name('tenant.waitlist.offer.accept');
    Route::get('/waitlist/offer/{token}/confirmed', [TenantControllers\WaitlistOfferController::class, 'confirmed'])->name('tenant.waitlist.offer.confirmed');
    Route::get('/book/paypal/return',    [TenantControllers\BookingController::class, 'paypalReturn'])->name('tenant.paypal.return');

    Route::post('/webhooks/stripe',  [TenantControllers\BookingController::class, 'stripeWebhook'])->name('tenant.webhook.stripe');
    Route::post('/webhooks/paypal',  [TenantControllers\BookingController::class, 'paypalWebhook'])->name('tenant.webhook.paypal');

    Route::post('/contact',  [TenantControllers\PublicController::class, 'contact'])->name('tenant.contact.submit');

    Route::prefix('admin')
        ->name('tenant.')
        ->group(function () {

        Route::get('/login',            [TenantControllers\AuthController::class, 'showLogin'])->name('login');
        Route::post('/login',           [TenantControllers\AuthController::class, 'login'])->name('login.submit');
        Route::get('/forgot-password',  [TenantControllers\AuthController::class, 'showForgot'])->name('forgot');
        Route::post('/forgot-password', [TenantControllers\AuthController::class, 'sendReset'])->name('forgot.submit');
        Route::get('/reset-password',   [TenantControllers\AuthController::class, 'showReset'])->name('reset');
        Route::post('/reset-password',  [TenantControllers\AuthController::class, 'resetPassword'])->name('reset.submit');
        Route::post('/logout',          [TenantControllers\AuthController::class, 'logout'])->name('logout');

        Route::middleware([
            'App\Http\Middleware\ConsumeOnboardingToken',
            'App\Http\Middleware\RequireTenantAuth',
            'App\Http\Middleware\ApplyTenantTheme',
        ])->group(function () {

            Route::get('/',                 [TenantControllers\DashboardController::class, 'index'])->name('dashboard');

            Route::post('/onboarding/branding', [TenantControllers\OnboardingModalController::class, 'saveBranding'])->name('onboarding.branding');
            Route::post('/onboarding/services', [TenantControllers\OnboardingModalController::class, 'saveServices'])->name('onboarding.services');
            Route::post('/onboarding/hours',    [TenantControllers\OnboardingModalController::class, 'saveHours'])->name('onboarding.hours');
            Route::post('/onboarding/dismiss',  [TenantControllers\OnboardingModalController::class, 'dismiss'])->name('onboarding.dismiss');
            Route::post('/onboarding/complete', [TenantControllers\OnboardingModalController::class, 'complete'])->name('onboarding.complete');

            // Calendar (admin) — day/week/month views of the tenant's schedule.
            Route::get('/calendar',             [TenantControllers\CalendarController::class, 'index'])->name('calendar.index');
            Route::get('/calendar/quick-book',  [TenantControllers\QuickBookController::class, 'picker'])->name('calendar.quick-book.picker');
            Route::post('/calendar/quick-book', [TenantControllers\QuickBookController::class, 'store'])->name('calendar.quick-book.store');
            Route::post('/calendar/breaks',     [TenantControllers\QuickBookController::class, 'storeBreak'])->name('calendar.breaks.store');

            // Resources (staff / benches / spaces) — calendar's column source
            Route::get('/resources',            [TenantControllers\ResourceController::class, 'index'])->name('resources.index');
            Route::post('/resources',           [TenantControllers\ResourceController::class, 'store'])->name('resources.store');
            Route::patch('/resources/{id}',     [TenantControllers\ResourceController::class, 'update'])->name('resources.update');
            Route::delete('/resources/{id}',    [TenantControllers\ResourceController::class, 'destroy'])->name('resources.destroy');
            Route::post('/resources/reorder',   [TenantControllers\ResourceController::class, 'reorder'])->name('resources.reorder');

            Route::get('/appointments',         [TenantControllers\AppointmentController::class, 'index'])->name('appointments.index');
            Route::post('/appointments',        [TenantControllers\AppointmentController::class, 'store'])->name('appointments.store');
            Route::get('/appointments/{id}',    [TenantControllers\AppointmentController::class, 'show'])->name('appointments.show');
            Route::patch('/appointments/{id}',  [TenantControllers\AppointmentController::class, 'update'])->name('appointments.update');
            Route::get('/appointments/{id}/drawer', [TenantControllers\AppointmentController::class, 'drawer'])->name('appointments.drawer');

            Route::get('/customers',            [TenantControllers\CustomerController::class, 'index'])->name('customers.index');
            Route::get('/customers/{id}',       [TenantControllers\CustomerController::class, 'show'])->name('customers.show');
            Route::post('/customers',           [TenantControllers\CustomerController::class, 'store'])->name('customers.store');
            Route::patch('/customers/{id}',     [TenantControllers\CustomerController::class, 'update'])->name('customers.update');

            Route::get('/waitlist',                    [TenantControllers\WaitlistAdminController::class, 'index'])->name('waitlist.index');
            Route::get('/waitlist/settings',           [TenantControllers\WaitlistAdminController::class, 'settings'])->name('waitlist.settings');
            Route::patch('/waitlist/settings',         [TenantControllers\WaitlistAdminController::class, 'updateSettings'])->name('waitlist.settings.update');
            Route::post('/waitlist/similar',           [TenantControllers\WaitlistAdminController::class, 'addSimilarMapping'])->name('waitlist.similar.add');
            Route::delete('/waitlist/similar/{id}',    [TenantControllers\WaitlistAdminController::class, 'removeSimilarMapping'])->name('waitlist.similar.remove');
            Route::delete('/waitlist/entries/{id}',    [TenantControllers\WaitlistAdminController::class, 'cancelEntry'])->name('waitlist.cancel');
            // Feature-addon catalog (tenant-facing purchase + manage).
            // Note: 'feature-addons' path avoids collision with existing service-addon routes below.
            Route::get('/feature-addons',             [TenantControllers\AddonCatalogController::class, 'index'])->name('feature_addons.index');
            Route::post('/feature-addons/activate',   [TenantControllers\AddonCatalogController::class, 'activate'])->name('feature_addons.activate');
            Route::post('/feature-addons/cancel',     [TenantControllers\AddonCatalogController::class, 'cancel'])->name('feature_addons.cancel');
            Route::get('/services',             [TenantControllers\ServiceController::class, 'index'])->name('services.index');
            Route::post('/services',            [TenantControllers\ServiceController::class, 'store'])->name('services.store');
            Route::patch('/services/{id}',      [TenantControllers\ServiceController::class, 'update'])->name('services.update');
            Route::delete('/services/{id}',     [TenantControllers\ServiceController::class, 'destroy'])->name('services.destroy');
            Route::get('/work-order-fields',             [TenantControllers\WorkOrderFieldsController::class, 'index'])->name('work-order-fields.index');
            Route::post('/work-order-fields',            [TenantControllers\WorkOrderFieldsController::class, 'store'])->name('work-order-fields.store');
            Route::patch('/work-order-fields/{id}',      [TenantControllers\WorkOrderFieldsController::class, 'update'])->name('work-order-fields.update');
            Route::delete('/work-order-fields/{id}',     [TenantControllers\WorkOrderFieldsController::class, 'destroy'])->name('work-order-fields.destroy');
            Route::post('/dashboard/wof-banner/dismiss', [TenantControllers\DashboardController::class, 'dismissWorkOrderBanner'])->name('dashboard.wof-banner.dismiss');
            Route::get('/dashboard/day.json', [TenantControllers\DashboardController::class, 'dayJson'])->name('dashboard.day');

            Route::post('/addons',              [TenantControllers\AddonController::class, 'store'])->name('addons.store');
            Route::patch('/addons/{id}',        [TenantControllers\AddonController::class, 'update'])->name('addons.update');
            Route::delete('/addons/{id}',       [TenantControllers\AddonController::class, 'destroy'])->name('addons.destroy');

            Route::get('/capacity',             [TenantControllers\CapacityController::class, 'index'])->name('capacity.index');
            Route::post('/capacity',            [TenantControllers\CapacityController::class, 'store'])->name('capacity.store');

            Route::get('/booking-editor',       [TenantControllers\BookingEditorController::class, 'index'])->name('booking-editor.index');
            Route::post('/booking-editor',      [TenantControllers\BookingEditorController::class, 'store'])->name('booking-editor.store');

            Route::post('/uploads', [TenantControllers\UploadController::class, 'store'])->name('uploads.store');

            Route::get('/help', [TenantControllers\HelpController::class, 'index'])->name('help.index');

            Route::get('/whats-new', [TenantControllers\WhatsNewController::class, 'changelog'])->name('whats_new.changelog');
            Route::get('/whats-coming', [TenantControllers\WhatsNewController::class, 'roadmap'])->name('whats_new.roadmap');

            Route::get('/pages',                [TenantControllers\PageBuilderController::class, 'index'])->name('pages.index');
            Route::get('/pages/{id}',           [TenantControllers\PageBuilderController::class, 'edit'])->name('pages.edit');
            Route::post('/pages',               [TenantControllers\PageBuilderController::class, 'store'])->name('pages.store');
            Route::patch('/pages/{id}',         [TenantControllers\PageBuilderController::class, 'update'])->name('pages.update');
            Route::delete('/pages/{id}',        [TenantControllers\PageBuilderController::class, 'destroy'])->name('pages.destroy');
            Route::post('/pages/{id}/sections',           [TenantControllers\PageBuilderController::class, 'addSection'])->name('pages.sections.add');
            Route::patch('/pages/{id}/sections/{sid}',    [TenantControllers\PageBuilderController::class, 'updateSection'])->name('pages.sections.update');
            Route::delete('/pages/{id}/sections/{sid}',   [TenantControllers\PageBuilderController::class, 'deleteSection'])->name('pages.sections.delete');
            Route::post('/pages/{id}/sections/reorder',   [TenantControllers\PageBuilderController::class, 'reorderSections'])->name('pages.sections.reorder');

            Route::get('/emails',               [TenantControllers\EmailController::class, 'index'])->name('emails.index');
            Route::patch('/emails/{type}',      [TenantControllers\EmailController::class, 'update'])->name('emails.update');
            Route::get('/campaigns',            [TenantControllers\CampaignController::class, 'index'])->name('campaigns.index');
            Route::get('/campaigns/{id}',       [TenantControllers\CampaignController::class, 'show'])->name('campaigns.show');
            Route::post('/campaigns',           [TenantControllers\CampaignController::class, 'store'])->name('campaigns.store');
            Route::patch('/campaigns/{id}',     [TenantControllers\CampaignController::class, 'update'])->name('campaigns.update');
            Route::post('/campaigns/{id}/send', [TenantControllers\CampaignController::class, 'send'])->name('campaigns.send');
            Route::post('/campaigns/{id}/preview', [TenantControllers\CampaignController::class, 'preview'])->name('campaigns.preview');

            // Campaign image library
            Route::get('/campaign-images',           [TenantControllers\CampaignImageController::class, 'index'])->name('campaign-images.index');
            Route::get('/campaign-images/usage',     [TenantControllers\CampaignImageController::class, 'usage'])->name('campaign-images.usage');
            Route::post('/campaign-images',          [TenantControllers\CampaignImageController::class, 'upload'])->name('campaign-images.upload');
            Route::delete('/campaign-images/{id}',   [TenantControllers\CampaignImageController::class, 'destroy'])->name('campaign-images.destroy');

            Route::get('/branding',             [TenantControllers\BrandingController::class, 'index'])->name('branding.index');
            Route::patch('/branding',           [TenantControllers\BrandingController::class, 'update'])->name('branding.update');
            Route::get('/settings',             [TenantControllers\SettingsController::class, 'index'])->name('settings.index');
            Route::patch('/settings',           [TenantControllers\SettingsController::class, 'update'])->name('settings.update');

            Route::get('/team',                 [TenantControllers\TeamController::class, 'index'])->name('team.index');
            Route::post('/team',                [TenantControllers\TeamController::class, 'store'])->name('team.store');
            Route::patch('/team/{id}',          [TenantControllers\TeamController::class, 'update'])->name('team.update');
            Route::delete('/team/{id}',         [TenantControllers\TeamController::class, 'destroy'])->name('team.destroy');

            // Stripe billing portal (card update, invoices, cancel).
            // Plan changes happen in-app, not via the portal.
            Route::get('/billing/portal',       [TenantControllers\BillingController::class, 'portal'])->name('billing.portal');

        });

    });

    Route::get('/{slug}', [TenantControllers\PublicController::class, 'page'])->name('tenant.page');

});
