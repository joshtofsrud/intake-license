#!/bin/bash
# register-display-logo-setting — per-register welcome-screen logo choice
# (Auto / Light / Main / None) on the Registers page.
# NOTE: full-file writes below include the fullscreen-button and idle-logo
# edits made manually on July 14 — safe to apply over them.
set -e
cd "$(git rev-parse --show-toplevel)"
if grep -q "display_logo" app/Models/Tenant/TenantRegister.php; then
  echo "register-display-logo-setting already applied — aborting."; exit 1
fi
if ! grep -q "fsBtn" resources/views/tenant/register/display.blade.php; then
  echo "WARNING: display.blade.php is missing the manual fullscreen edit this script expects."
  echo "Aborting rather than overwriting an unknown version. Tell Claude."
  exit 1
fi

cat > 'database/migrations/2026_07_14_000002_add_display_logo_to_tenant_registers.php' <<'RDL_0_EOF'
<?php

// MARKER-REGISTER-RECON-DISPLAY — per-register display logo choice.
// auto = light logo, falling back to main; main/light force one; none hides it.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_registers', function (Blueprint $t) {
            $t->string('display_logo', 10)->default('auto')->after('display_token');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_registers', function (Blueprint $t) {
            $t->dropColumn('display_logo');
        });
    }
};
RDL_0_EOF

cat > 'app/Models/Tenant/TenantRegister.php' <<'RDL_1_EOF'
<?php

namespace App\Models\Tenant;

// MARKER-REGISTER-RECON-DISPLAY

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TenantRegister extends Model
{
    protected $table = 'tenant_registers';

    protected $fillable = [
        'tenant_id', 'location_id', 'number', 'name',
        'display_token', 'display_logo', 'display_cart', 'cart_updated_at', 'is_active',
    ];

    protected $casts = [
        'display_cart'    => 'array',
        'cart_updated_at' => 'datetime',
        'is_active'       => 'boolean',
    ];

    public function tenant(): BelongsTo   { return $this->belongsTo(Tenant::class); }
    public function location(): BelongsTo { return $this->belongsTo(TenantLocation::class, 'location_id'); }

    public static function freshToken(): string
    {
        return Str::random(48);
    }

    /** Next register number for a tenant (1, 2, 3, …). */
    public static function nextNumber(string $tenantId): int // tenant ids are UUIDs
    {
        return (int) static::where('tenant_id', $tenantId)->max('number') + 1;
    }
}
RDL_1_EOF

cat > 'app/Http/Controllers/Tenant/RegisterDisplayController.php' <<'RDL_2_EOF'
<?php

namespace App\Http\Controllers\Tenant;

// MARKER-REGISTER-RECON-DISPLAY — register management + customer-facing pay displays.
//
// Admin side (authed, register-guarded):
//   registers()        — manage page: list, pairing QR per register
//   storeRegister()    — create a register (number auto-assigned)
//   regenerateToken()  — rotate a register's display token (unpairs screens)
//   selectRegister()   — bind this staff session to a register (current_register_id)
//   displayState()     — receive debounced cart snapshots from the POS page
//
// Public side (tenant-resolved by host, token is the credential):
//   display()          — full-screen customer display bound to one register
//   displayPoll()      — JSON snapshot the display polls (~1.5s)

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantRegister;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RegisterDisplayController extends Controller
{
    // ---------------------------------------------------------------- admin

    public function registers(Request $request)
    {
        $tenant = app('tenant');

        return view('tenant.register.registers', [
            'tenant'    => $tenant,
            'registers' => TenantRegister::where('tenant_id', $tenant->id)
                             ->orderBy('number')->get(),
            'currentRegisterId' => (int) $request->session()->get('current_register_id', 0),
        ]);
    }

    public function storeRegister(Request $request): RedirectResponse
    {
        $tenant = app('tenant');
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);

        TenantRegister::create([
            'tenant_id'     => $tenant->id,
            'number'        => TenantRegister::nextNumber($tenant->id),
            'name'          => $data['name'],
            'display_token' => TenantRegister::freshToken(),
        ]);

        return back()->with('status', 'Register added.');
    }

    public function updateRegister(Request $request, int $id): RedirectResponse
    {
        $tenant = app('tenant');
        $register = TenantRegister::where('tenant_id', $tenant->id)->findOrFail($id);
        $data = $request->validate([
            'display_logo' => ['required', 'in:auto,main,light,none'],
        ]);
        $register->update($data);

        return back()->with('status', 'Register updated.');
    }

    public function regenerateToken(Request $request, int $id): RedirectResponse
    {
        $tenant = app('tenant');
        $register = TenantRegister::where('tenant_id', $tenant->id)->findOrFail($id);
        $register->update([
            'display_token' => TenantRegister::freshToken(),
            'display_cart'  => null,
        ]);

        return back()->with('status', 'Pairing link regenerated — previously paired screens are disconnected.');
    }

    public function selectRegister(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $data = $request->validate(['register_id' => ['required', 'integer']]);

        // 0 = no register (clears the binding)
        if ((int) $data['register_id'] === 0) {
            $request->session()->forget('current_register_id');
            return response()->json(['ok' => true, 'register_id' => null]);
        }

        $register = TenantRegister::where('tenant_id', $tenant->id)
                      ->where('is_active', true)
                      ->findOrFail((int) $data['register_id']);

        $request->session()->put('current_register_id', $register->id);

        return response()->json(['ok' => true, 'register_id' => $register->id]);
    }

    public function displayState(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $registerId = (int) $request->session()->get('current_register_id', 0);
        if ($registerId === 0) {
            return response()->json(['ok' => false, 'reason' => 'no_register'], 200);
        }

        $register = TenantRegister::where('tenant_id', $tenant->id)->find($registerId);
        if (! $register) {
            $request->session()->forget('current_register_id');
            return response()->json(['ok' => false, 'reason' => 'gone'], 200);
        }

        // Snapshot is display-only data; whitelist the shape rather than
        // trusting the client blob wholesale.
        $snap = $request->validate([
            'state'                 => ['required', 'in:idle,cart,pay'],
            'items'                 => ['array', 'max:200'],
            'items.*.name'          => ['required_with:items', 'string', 'max:160'],
            'items.*.qty'           => ['required_with:items', 'numeric'],
            'items.*.line_cents'    => ['required_with:items', 'integer'],
            'items.*.refund'        => ['sometimes', 'boolean'],
            'subtotal_cents'        => ['integer'],
            'discount_cents'        => ['integer'],
            'tax_cents'             => ['integer'],
            'tax_label'             => ['nullable', 'string', 'max:40'],
            'surcharge_cents'       => ['integer'],
            'tip_cents'             => ['integer'],
            'total_cents'           => ['integer'],
            'pay_url'               => ['nullable', 'string', 'max:500'],
        ]);

        $register->update([
            'display_cart'    => $snap,
            'cart_updated_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    // --------------------------------------------------------------- public

    public function display(string $token)
    {
        $tenant = app('tenant');
        $register = TenantRegister::where('tenant_id', $tenant->id)
                      ->where('display_token', $token)
                      ->where('is_active', true)
                      ->firstOrFail();

        return view('tenant.register.display', [
            'tenant'   => $tenant,
            'register' => $register,
        ]);
    }

    public function displayPoll(string $token): JsonResponse
    {
        $tenant = app('tenant');
        $register = TenantRegister::where('tenant_id', $tenant->id)
                      ->where('display_token', $token)
                      ->where('is_active', true)
                      ->firstOrFail();

        $snap = $register->display_cart;

        // A snapshot older than 90s means the POS page is gone — fall back
        // to idle instead of showing a stale cart to the next customer.
        $stale = $register->cart_updated_at === null
              || $register->cart_updated_at->lt(now()->subSeconds(90));

        return response()->json([
            'state' => $stale ? 'idle' : ($snap['state'] ?? 'idle'),
            'snap'  => $stale ? null : $snap,
        ]);
    }
}
RDL_2_EOF

cat > 'routes/web.php' <<'RDL_3_EOF'
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Platform;
use App\Http\Controllers\Tenant as TenantControllers;

$domain = config('intake.domain', 'intake.works');
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
    Route::get('/why-intake', [Platform\MarketingController::class, 'whyIntake'])->name('marketing.why-intake');
    Route::get('/docs',     [Platform\MarketingController::class, 'docs'])->name('marketing.docs');
    Route::get('/contact',  [Platform\MarketingController::class, 'contact'])->name('marketing.contact');
    Route::get('/invest',   [Platform\MarketingController::class, 'invest'])->name('marketing.invest');
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
// Tenant routes (MARKER-PATCH-123)
//
// Tenant-facing routes live inside the $tenantRoutes closure and are
// registered once under the ResolveTenant middleware. The middleware
// identifies the tenant from the request host:
//
//   - {slug}.intake.works  → tenants.subdomain lookup
//   - any other host       → tenant_domains.hostname lookup (active rows)
//
// Unknown hosts 404 via middleware abort. No {subdomain} placeholder is
// declared on the routes themselves, so route() URLs render relative;
// in a tenant request the current host is used naturally.
// =========================================================================

$tenantRoutes = function () {

    Route::get('/',        [TenantControllers\PublicController::class, 'home'])->name('tenant.home');
    Route::get('/confirm', [TenantControllers\PublicController::class, 'confirm'])->name('tenant.confirm');
    Route::get('/contact', [TenantControllers\PublicController::class, 'contact'])->name('tenant.contact');

    Route::get('/book',                  [TenantControllers\BookingController::class, 'index'])->name('tenant.booking');
    // MARKER-PATCH-528 — public delivery-window confirm page (token is the credential)
    Route::get('/d/{token}',             [TenantControllers\DeliveryConfirmController::class, 'show'])->name('tenant.delivery_confirm.show');
    Route::post('/d/{token}',            [TenantControllers\DeliveryConfirmController::class, 'confirm'])->name('tenant.delivery_confirm.save');
    // MARKER-PATCH-149 — anonymous funnel event tracking from public pages
    Route::post('/funnel/track',         [TenantControllers\FunnelTrackController::class, 'store'])->name('tenant.funnel.track');
    // MARKER-REGISTER-RECON-DISPLAY — customer pay display (token is the credential)
    Route::get('/pay-display/{token}',            [TenantControllers\RegisterDisplayController::class, 'display'])->name('tenant.pay_display.show');
    Route::get('/pay-display/{token}/state.json', [TenantControllers\RegisterDisplayController::class, 'displayPoll'])->name('tenant.pay_display.poll');
    Route::post('/booking/abandon',      [TenantControllers\AbandonedBookingController::class, 'store'])->name('tenant.booking.abandon'); // MARKER-RECOVERY
    Route::get('/book/availability',     [TenantControllers\BookingController::class, 'availability'])->name('tenant.booking.availability');
    Route::post('/book/submit',          [TenantControllers\BookingController::class, 'submit'])->name('tenant.booking.submit');
    Route::post('/book/finalize',        [TenantControllers\BookingController::class, 'finalize'])->name('tenant.booking.finalize'); // MARKER-PATCH-385
    // MARKER-PATCH-213 — returning-customer lookup (pre-fills the Bikes step)
    Route::post('/book/customer-lookup', [TenantControllers\BookingLookupController::class, 'lookup'])->name('tenant.booking.customer-lookup');
    // MARKER-PATCH-239 — public rental availability browse.
    // MARKER-PATCH-561 — Online Retail Wave 2: read-only storefront
    Route::get('/shop',                  [TenantControllers\StorefrontController::class, 'index'])->name('tenant.shop.index');
    Route::get('/shop/search.json',      [TenantControllers\StorefrontController::class, 'searchJson'])->name('tenant.shop.search'); // MARKER-PATCH-582
    Route::get('/shop/{id}',             [TenantControllers\StorefrontController::class, 'show'])->name('tenant.shop.show');
    // MARKER-PATCH-564 — Online Retail Wave 3: cart
    Route::get('/cart',                  [TenantControllers\CartController::class, 'show'])->name('tenant.cart.show');
    Route::post('/cart/items',           [TenantControllers\CartController::class, 'add'])->name('tenant.cart.add');
    Route::patch('/cart/items/{lineId}', [TenantControllers\CartController::class, 'update'])->name('tenant.cart.update');
    Route::delete('/cart/items/{lineId}',[TenantControllers\CartController::class, 'remove'])->name('tenant.cart.remove');
    // MARKER-PATCH-566 — Online Retail Wave 4: checkout + confirmation
    Route::get('/checkout',              [TenantControllers\CheckoutController::class, 'show'])->name('tenant.checkout.show');
    Route::post('/checkout/place',       [TenantControllers\CheckoutController::class, 'place'])->name('tenant.checkout.place');
    Route::get('/checkout/return',       [TenantControllers\CheckoutController::class, 'returnLeg'])->name('tenant.checkout.return');
    Route::get('/order/{token}',         [TenantControllers\CheckoutController::class, 'confirmation'])->name('tenant.order.confirmation');
    Route::get('/rentals',               [TenantControllers\RentalBrowseController::class, 'index'])->name('tenant.rentals.browse');
    // MARKER-PATCH-240 — public reservation checkout.
    Route::get( '/rentals/reserve',          [TenantControllers\RentalReserveController::class, 'show'])->name('tenant.rentals.reserve');
    Route::post('/rentals/reserve',          [TenantControllers\RentalReserveController::class, 'store'])->name('tenant.rentals.reserve.store');
    Route::post('/rentals/reserve/confirm',  [TenantControllers\RentalReserveController::class, 'confirm'])->name('tenant.rentals.reserve.confirm');
    Route::get( '/rentals/reserved',         [TenantControllers\RentalReserveController::class, 'confirmation'])->name('tenant.rentals.reserved');

    Route::get('/waitlist/join',               [TenantControllers\WaitlistPublicController::class, 'join'])->name('tenant.waitlist.join');
    Route::post('/waitlist/join',              [TenantControllers\WaitlistPublicController::class, 'submitJoin'])->name('tenant.waitlist.submit');
    Route::get('/waitlist/my',                 [TenantControllers\WaitlistPublicController::class, 'myEntries'])->name('tenant.waitlist.my');
    Route::post('/waitlist/remove',            [TenantControllers\WaitlistPublicController::class, 'removeEntry'])->name('tenant.waitlist.remove');
    Route::get('/waitlist/offer/{token}',      [TenantControllers\WaitlistOfferController::class, 'show'])->name('tenant.waitlist.offer.show');
    Route::post('/waitlist/offer/{token}/accept', [TenantControllers\WaitlistOfferController::class, 'accept'])->name('tenant.waitlist.offer.accept');
    Route::get('/waitlist/offer/{token}/confirmed', [TenantControllers\WaitlistOfferController::class, 'confirmed'])->name('tenant.waitlist.offer.confirmed');
    Route::get('/book/paypal/return',    [TenantControllers\BookingController::class, 'paypalReturn'])->name('tenant.paypal.return');

    // Customer account — register, login, logout, forgot, reset, portal
    Route::get('/account/register',      [TenantControllers\CustomerAccountController::class, 'showRegister'])->name('tenant.customer.register');
    Route::post('/account/register',     [TenantControllers\CustomerAccountController::class, 'register'])->name('tenant.customer.register.submit');
    Route::get('/account/login',         [TenantControllers\CustomerAccountController::class, 'showLogin'])->name('tenant.customer.login');
    Route::post('/account/login',        [TenantControllers\CustomerAccountController::class, 'login'])->name('tenant.customer.login.submit');
    Route::post('/account/logout',       [TenantControllers\CustomerAccountController::class, 'logout'])->name('tenant.customer.logout');
    Route::get('/account/forgot',        [TenantControllers\CustomerAccountController::class, 'showForgot'])->name('tenant.customer.forgot');
    Route::post('/account/forgot',       [TenantControllers\CustomerAccountController::class, 'sendReset'])->name('tenant.customer.forgot.submit');
    Route::get('/account/reset',         [TenantControllers\CustomerAccountController::class, 'showReset'])->name('tenant.customer.reset');
    Route::post('/account/reset',        [TenantControllers\CustomerAccountController::class, 'resetPassword'])->name('tenant.customer.reset.submit');
    Route::get('/account',               [TenantControllers\CustomerAccountController::class, 'portal'])->name('tenant.customer.portal');

    // Customer-facing class booking
    Route::get('/classes',                          [TenantControllers\CustomerClassController::class, 'index'])->name('tenant.customer.classes');
    Route::get('/classes/{id}',                     [TenantControllers\CustomerClassController::class, 'show'])->name('tenant.customer.classes.show');
    Route::post('/classes/{id}/register',           [TenantControllers\CustomerClassController::class, 'register'])->name('tenant.customer.classes.register');
    Route::get('/classes/confirm/{id}',             [TenantControllers\CustomerClassController::class, 'confirm'])->name('tenant.customer.classes.confirm');
    Route::post('/classes/registrations/{id}/cancel', [TenantControllers\CustomerClassController::class, 'cancelRegistration'])->name('tenant.customer.classes.cancel');

// MARKER-PATCH-118 - Cloudflare custom-hostname webhook
Route::post('webhooks/cloudflare', [\App\Http\Controllers\Webhooks\CloudflareWebhookController::class, 'handle'])
    ->name('webhooks.cloudflare');

// MARKER-PATCH-168 - Stripe Connect events (account.updated, etc.). Separate
// from platform-billing webhooks; different signing secret.
Route::post('webhooks/stripe-connect',
    [\App\Http\Controllers\Webhooks\StripeConnectWebhookController::class, 'handle']
)->name('webhooks.stripe-connect');

// MARKER-PATCH-170 - Direct Payments webhook (per-tenant, path-scoped).
// Each tenant has their own Stripe account so we route by tenant_id in the
// URL and verify against that tenant\'s webhook signing secret.
Route::post('webhooks/stripe-direct/{tenantId}',
    [\App\Http\Controllers\Webhooks\DirectPaymentsWebhookController::class, 'handle']
)->name('webhooks.stripe-direct');

// MARKER-PATCH-146 — SES bounce/complaint webhook (signature-verified, public)
Route::post('webhooks/ses-bounce', [\App\Http\Controllers\Webhooks\SesBounceController::class, 'handle'])
    ->name('webhooks.ses-bounce');

// MARKER-PATCH-201 — Postmark bounce / spam-complaint webhook (replaces SES path).
Route::post('webhooks/postmark', [\App\Http\Controllers\Webhooks\PostmarkWebhookController::class, 'handle'])
    ->name('webhooks.postmark');

// MARKER-PATCH-403 — Postmark inbound email -> unified inbox. Routes by the
// per-thread token carried in MailboxHash. Fail-open posture (always 2xx);
// unroutable mail is logged and dropped. CSRF-exempt (external POST).
Route::post('webhooks/postmark/inbound', [\App\Http\Controllers\Webhooks\PostmarkInboundController::class, 'handle'])
    ->name('webhooks.postmark.inbound');

// MARKER-PATCH-221 — Twilio inbound SMS (unified inbox). Signature-validated,
// always answers 2xx (fail-open posture; unprocessable requests are skipped).
Route::post('webhooks/twilio/inbound', [\App\Http\Controllers\Webhooks\TwilioInboundController::class, 'handle'])
    ->name('webhooks.twilio.inbound');

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
        // MARKER-PATCH-478 — team-member invite setup (public, tenant-resolved, token-gated)
        Route::get('/team/setup',       [TenantControllers\TeamController::class, 'setupForm'])->name('team.setup');
        Route::post('/team/setup',      [TenantControllers\TeamController::class, 'completeSetup'])->name('team.setup.complete');
        Route::post('/logout',          [TenantControllers\AuthController::class, 'logout'])->name('logout');

        // MARKER-PATCH-173 — Customer-facing return pages for the send-payment-
        // link (Stripe Checkout) flow. PUBLIC: the paying customer is anonymous
        // on their own phone, so these must sit OUTSIDE the auth middleware
        // sub-group below. Paths match the success_url/cancel_url baked into
        // DirectPaymentsService so links already issued also resolve.
        Route::get('/register/checkout-success', [TenantControllers\RegisterController::class, 'checkoutSuccess'])->name('register.checkout_success');
        Route::get('/register/checkout-cancel',  [TenantControllers\RegisterController::class, 'checkoutCancel'])->name('register.checkout_cancel');

        // Staff switcher tier — requires trusted device, not signed-in user.
        // Lives between device auth (Layer 1) and user auth (Layer 2 PIN).
        Route::middleware([
            'App\Http\Middleware\EnsureTrustedDevice',
            'App\Http\Middleware\ApplyTenantTheme',
        ])->group(function () {
            Route::get('/switch',             [TenantControllers\StaffSwitchController::class, 'index'])->name('switch');
            Route::post('/pin/verify',        [TenantControllers\StaffSwitchController::class, 'verifyPin'])->name('pin.verify');
            Route::post('/pin/set',           [TenantControllers\StaffSwitchController::class, 'setInitialPin'])->name('pin.set');
            Route::post('/pin/reset-request', [TenantControllers\StaffSwitchController::class, 'requestReset'])->name('pin.reset-request');
        });

        Route::middleware([
            'App\Http\Middleware\ConsumeOnboardingToken',
            'App\Http\Middleware\EnsureTrustedDevice',
            'App\Http\Middleware\RequireTenantAuth',
            // MARKER-PATCH-492 — per-section role enforcement (Roles & access)
            'App\Http\Middleware\EnforceSectionAccess',
            'App\Http\Middleware\EnsurePinFresh',
            'App\Http\Middleware\ApplyTenantTheme',
        ])->group(function () {

            // Multi-location: picker + switch (no RequireCurrentLocation gate; chicken/egg)
            Route::get('/select-location',   [TenantControllers\AuthController::class, 'showLocationPicker'])->name('select-location');
            Route::post('/select-location',  [TenantControllers\AuthController::class, 'selectLocation'])->name('select-location.store');
            Route::post('/switch-location',  [TenantControllers\AuthController::class, 'switchLocation'])->name('switch-location');

            // PIN gate endpoints (chunk 6) - whitelisted by EnsurePinFresh
            // so they work even when the lock overlay is pending.
            Route::post('/pin/heartbeat',    [TenantControllers\PinGateController::class, 'heartbeat'])->name('pin.heartbeat');
            Route::post('/pin/unlock',       [TenantControllers\PinGateController::class, 'unlock'])->name('pin.unlock');
            Route::get('/pin/context',       [TenantControllers\PinGateController::class, 'context'])->name('pin.context'); // MARKER-PATCH-545
            // MARKER-PATCH-480 — first-time PIN setup from the lock overlay
            Route::post('/pin/setup',        [TenantControllers\PinGateController::class, 'setupPin'])->name('pin.setup');

            // Everything below requires a current_location_id set in session.
            // Picker routes above are exempt (chicken/egg).
            Route::middleware([\App\Http\Middleware\RequireCurrentLocation::class])->group(function () {

            Route::get('/',                 [TenantControllers\DashboardController::class, 'index'])->name('dashboard');

            // Walk-in screen — quick-actions launcher; open to all tiers.
            // The 'Ring up sale' option inside is gated separately in the view.
            Route::get('/register/walk-in',          [TenantControllers\WalkInController::class, 'index'])->name('register.walk-in');

            Route::middleware([\App\Http\Middleware\RequireRetailCapability::class])->group(function () {
                    // Register (POS) — walk-in retail + service jobs
                Route::get('/register',                  [TenantControllers\RegisterController::class, 'index'])->name('register.index');
                Route::get('/register/appointment-tray', [TenantControllers\RegisterController::class, 'appointmentTray'])->name('register.appointment-tray');
                // MARKER-PATCH-180 — dismiss a parked appointment draft from the tray
                Route::post('/register/appointment-tray/dismiss', [TenantControllers\RegisterController::class, 'dismissTraySale'])->name('register.appointment-tray.dismiss');
                Route::get('/register/search',           [TenantControllers\RegisterController::class, 'search'])->name('register.search');
                Route::get('/register/item/{id}/info',   [TenantControllers\RegisterController::class, 'itemInfo'])->name('register.item_info'); // MARKER-PATCH-552
                // MARKER-REGISTER-RECON-DISPLAY — register management + display mirroring
                Route::get('/register/registers',                  [TenantControllers\RegisterDisplayController::class, 'registers'])->name('register.registers');
                Route::post('/register/registers',                 [TenantControllers\RegisterDisplayController::class, 'storeRegister'])->name('register.registers.store');
                Route::post('/register/registers/{id}/regenerate', [TenantControllers\RegisterDisplayController::class, 'regenerateToken'])->name('register.registers.regenerate');
                Route::post('/register/registers/{id}/update',     [TenantControllers\RegisterDisplayController::class, 'updateRegister'])->name('register.registers.update');
                Route::post('/register/select',                    [TenantControllers\RegisterDisplayController::class, 'selectRegister'])->name('register.select');
                Route::post('/register/display-state',             [TenantControllers\RegisterDisplayController::class, 'displayState'])->name('register.display_state');

                // MARKER-PATCH-567 — Online Retail Wave 5a: orders queue
                Route::get('/orders',            [TenantControllers\OrdersController::class, 'index'])->name('orders.index');
                Route::get('/orders/{id}',       [TenantControllers\OrdersController::class, 'show'])->name('orders.show');
                Route::post('/orders/{id}',      [TenantControllers\OrdersController::class, 'update'])->name('orders.update');

                // MARKER-PATCH-569 — Online Retail Wave 5b: storefront settings
                Route::get('/storefront',        [TenantControllers\StorefrontSettingsController::class, 'show'])->name('storefront.settings');
                Route::post('/storefront',       [TenantControllers\StorefrontSettingsController::class, 'update'])->name('storefront.settings.update');
                Route::post('/storefront/bulk',  [TenantControllers\StorefrontSettingsController::class, 'bulk'])->name('storefront.settings.bulk');
                Route::post('/storefront/item/{id}', [TenantControllers\StorefrontSettingsController::class, 'toggleItem'])->name('storefront.item.toggle'); // MARKER-PATCH-569
                Route::post('/register/sales',           [TenantControllers\RegisterController::class, 'storeSale'])->name('register.sales.store');
                // MARKER-PATCH-170 — Direct Payments hand-keyed card endpoints
                Route::post('/register/payment-intent',   [TenantControllers\RegisterController::class, 'createPaymentIntent'])->name('register.payment_intent.create');
                Route::post('/register/payment-intent/confirm', [TenantControllers\RegisterController::class, 'confirmPaymentIntent'])->name('register.payment_intent.confirm');
                // MARKER-PATCH-170B — auto-refund when commit fails after charge succeeds
                Route::post('/register/payment-intent/auto-refund', [TenantControllers\RegisterController::class, 'autoRefundPaymentIntent'])->name('register.payment_intent.auto_refund');
                // MARKER-PATCH-172 — send-payment-link (Stripe Checkout)
                Route::post('/register/checkout-session',         [TenantControllers\RegisterController::class, 'createCheckoutSession'])->name('register.checkout_session.create');
                Route::post('/register/checkout-session/check',   [TenantControllers\RegisterController::class, 'checkCheckoutSession'])->name('register.checkout_session.check');
                Route::post('/register/checkout-session/cancel',  [TenantControllers\RegisterController::class, 'cancelCheckoutSession'])->name('register.checkout_session.cancel');
                Route::get('/register/drafts',            [TenantControllers\RegisterController::class, 'listDrafts'])->name('register.drafts.index');
                Route::post('/register/drafts',           [TenantControllers\RegisterController::class, 'storeDraft'])->name('register.drafts.store');
                Route::get('/register/drafts/{id}',        [TenantControllers\RegisterController::class, 'showDraft'])->name('register.drafts.show');
                Route::delete('/register/drafts/{id}',     [TenantControllers\RegisterController::class, 'discardDraft'])->name('register.drafts.destroy');
                Route::post('/register/drafts/{id}/commit',[TenantControllers\RegisterController::class, 'commitDraft'])->name('register.drafts.commit');
                Route::get('/register/quotes',           [TenantControllers\RegisterController::class, 'quotesIndex'])->name('register.quotes.index');
                Route::post('/register/quotes',          [TenantControllers\RegisterController::class, 'storeQuote'])->name('register.quotes.store');
                Route::get('/register/lookup-sale',       [TenantControllers\RegisterController::class, 'lookupSaleForRefund'])->name('register.lookup-sale');
                Route::post('/register/transactions',     [TenantControllers\RegisterController::class, 'storeTransaction'])->name('register.transactions.store');
                Route::get('/register/history',          [TenantControllers\RegisterController::class, 'historyIndex'])->name('register.history.index');
                Route::get('/register/sales/{id}/json',  [TenantControllers\RegisterController::class, 'showSaleJson'])->name('register.sales.show');
                // MARKER-PATCH-319 — printable 80mm sales receipt
                Route::get('/register/sales/{id}/receipt', [TenantControllers\RegisterController::class, 'printReceipt'])->name('register.sales.receipt');
                Route::get('/register/sales/{id}/view',  [TenantControllers\RegisterController::class, 'showSalePage'])->name('register.sales.page'); // MARKER-PATCH-231A
                // MARKER-PATCH-197 — Stripe-vs-ledger reconciliation.
                Route::get('/register/reconciliation',   [TenantControllers\RegisterController::class, 'reconciliation'])->name('register.reconciliation');
                Route::post('/register/reconciliation/record', [TenantControllers\RegisterController::class, 'reconcilePayment'])->name('register.reconciliation.record');
                // MARKER-PATCH-198 — delete a single ledger payment (data correction).
                Route::post('/register/sales/payment/delete', [TenantControllers\RegisterController::class, 'deleteSalePayment'])->name('register.sales.payment.delete');
                // MARKER-PATCH-199 — delete an empty sale (data correction).
                Route::post('/register/sales/delete', [TenantControllers\RegisterController::class, 'deleteSale'])->name('register.sales.delete');
                Route::get('/register/refunds/search',   [TenantControllers\RegisterController::class, 'searchRefundables'])->name('register.refunds.search');
                Route::post('/register/refunds',         [TenantControllers\RegisterController::class, 'storeRefund'])->name('register.refunds.store');
                // MARKER-PATCH-177 — standalone refund (customer + amount, no sale)
                Route::post('/register/refunds/standalone', [TenantControllers\RegisterController::class, 'storeStandaloneRefund'])->name('register.refunds.standalone');

                // MARKER-PATCH-461 — record an appointment overage refund (overage_refund ledger row + paid-cache cascade)
                Route::post('/register/appointment-overage-refund', [TenantControllers\RegisterController::class, 'recordAppointmentOverageRefund'])->name('register.appointment-overage-refund');

                // patch-100a oversell actions — register cart buttons that
                // create a transfer request or a special order when staff
                // rings up a line that exceeds available stock at the
                // current location.
                Route::post('/register/oversell/transfer-request', [TenantControllers\RegisterController::class, 'storeOversellTransferRequest'])->name('register.oversell.transfer-request');
                Route::post('/register/oversell/special-order',    [TenantControllers\RegisterController::class, 'storeOversellSpecialOrder'])->name('register.oversell.special-order');

                // patch-100b transfer-requests — admin UI for browsing and acting on
                // transfer requests created by the register cart (patch-100a).
                Route::get( '/transfer-requests',                   [TenantControllers\TransferRequestController::class, 'index'])->name('transfer-requests.index');
                Route::get( '/transfer-requests/{id}',              [TenantControllers\TransferRequestController::class, 'show'])->name('transfer-requests.show');
                Route::post('/transfer-requests/{id}/fulfill',      [TenantControllers\TransferRequestController::class, 'fulfill'])->name('transfer-requests.fulfill');
                // patch-102 transfer send/receive — three-stage flow
                Route::post('/transfer-requests/{id}/send',          [TenantControllers\TransferRequestController::class, 'send'])->name('transfer-requests.send');
                Route::post('/transfer-requests/{id}/receive',       [TenantControllers\TransferRequestController::class, 'receive'])->name('transfer-requests.receive');
                Route::post('/transfer-requests/{id}/cancel',       [TenantControllers\TransferRequestController::class, 'cancel'])->name('transfer-requests.cancel');
            }); // close RequireRetailCapability group

            // MARKER-PATCH-217 — Rentals. Always a la carte (never tier-
            // included), tier floor branded. Group-level gate: every rental
            // route added inside inherits it by construction.
            Route::middleware([\App\Http\Middleware\RequireRentalCapability::class])->group(function () {
                Route::get('/rentals', [TenantControllers\RentalDeskController::class, 'index'])->name('rentals.desk');

                // MARKER-PATCH-218 — Fleet admin (categories, units,
                // condition templates). Inline-edit protocol: PATCH with
                // JSON {field, value}; archives, never hard-deletes.
                Route::get('/rentals/fleet',                            [TenantControllers\RentalFleetController::class, 'index'])->name('rentals.fleet');
                Route::post('/rentals/fleet/categories',                [TenantControllers\RentalFleetController::class, 'storeCategory'])->name('rentals.fleet.categories.store');
                Route::patch('/rentals/fleet/categories/{id}',          [TenantControllers\RentalFleetController::class, 'updateCategory'])->name('rentals.fleet.categories.update');
                Route::delete('/rentals/fleet/categories/{id}',         [TenantControllers\RentalFleetController::class, 'destroyCategory'])->name('rentals.fleet.categories.destroy');
                Route::post('/rentals/fleet/units',                     [TenantControllers\RentalFleetController::class, 'storeUnit'])->name('rentals.fleet.units.store');
                Route::patch('/rentals/fleet/units/{id}',               [TenantControllers\RentalFleetController::class, 'updateUnit'])->name('rentals.fleet.units.update');
                Route::delete('/rentals/fleet/units/{id}',              [TenantControllers\RentalFleetController::class, 'destroyUnit'])->name('rentals.fleet.units.destroy');
                // MARKER-PATCH-235 — unit detail page ('/detail' keeps clear of the inline-edit PATCH/DELETE URLs).
                Route::get('/rentals/fleet/units/{id}/detail',          [TenantControllers\RentalFleetController::class, 'showUnit'])->name('rentals.fleet.units.show');
                Route::post('/rentals/fleet/condition-templates',       [TenantControllers\RentalFleetController::class, 'storeConditionTemplate'])->name('rentals.fleet.ct.store');
                Route::patch('/rentals/fleet/condition-templates/{id}', [TenantControllers\RentalFleetController::class, 'updateConditionTemplate'])->name('rentals.fleet.ct.update');
                Route::delete('/rentals/fleet/condition-templates/{id}',[TenantControllers\RentalFleetController::class, 'destroyConditionTemplate'])->name('rentals.fleet.ct.destroy');
                // MARKER-PATCH-227 — model layer + bulk add.
                Route::post('/rentals/fleet/models',           [TenantControllers\RentalFleetController::class, 'storeModel'])->name('rentals.fleet.models.store');
                Route::patch('/rentals/fleet/models/{id}',     [TenantControllers\RentalFleetController::class, 'updateModel'])->name('rentals.fleet.models.update');
                Route::delete('/rentals/fleet/models/{id}',    [TenantControllers\RentalFleetController::class, 'destroyModel'])->name('rentals.fleet.models.destroy');
                Route::post('/rentals/fleet/units/bulk',       [TenantControllers\RentalFleetController::class, 'bulkAddUnits'])->name('rentals.fleet.units.bulk');

                // MARKER-PATCH-219 — rental bookings. store/check-out/
                // check-in/cancel mutate under the tenant rental write lock.
                // MARKER-PATCH-228 — rentals settings (season window + leasing toggle).
                Route::get('/rentals/settings',  [TenantControllers\RentalSettingsController::class, 'index'])->name('rentals.settings');
                Route::post('/rentals/settings', [TenantControllers\RentalSettingsController::class, 'save'])->name('rentals.settings.save');
                // MARKER-PATCH-237 — versioned agreement templates (publish-only).
                Route::post('/rentals/settings/agreement-templates', [TenantControllers\RentalSettingsController::class, 'storeAgreementTemplate'])->name('rentals.settings.agreements.store');

                // MARKER-PATCH-229 — lease packages (the tier builder). Gated
                // in-controller on leases_enabled.
                Route::get( '/rentals/leases/packages',                 [TenantControllers\LeasePackageController::class, 'index'])->name('rentals.leases.packages');
                Route::post('/rentals/leases/packages',                 [TenantControllers\LeasePackageController::class, 'store'])->name('rentals.leases.packages.store');
                Route::patch('/rentals/leases/packages/{id}',           [TenantControllers\LeasePackageController::class, 'update'])->name('rentals.leases.packages.update');
                Route::delete('/rentals/leases/packages/{id}',          [TenantControllers\LeasePackageController::class, 'destroy'])->name('rentals.leases.packages.destroy');
                Route::post('/rentals/leases/packages/{id}/slots',      [TenantControllers\LeasePackageController::class, 'addSlot'])->name('rentals.leases.packages.slots.add');
                Route::delete('/rentals/leases/packages/{id}/slots/{slotId}', [TenantControllers\LeasePackageController::class, 'removeSlot'])->name('rentals.leases.packages.slots.remove');

                // MARKER-PATCH-230 — lease transactions + fulfillment.
                Route::get( '/rentals/leases',            [TenantControllers\LeaseController::class, 'index'])->name('rentals.leases.index');
                Route::get( '/rentals/leases/new',        [TenantControllers\LeaseController::class, 'create'])->name('rentals.leases.create');
                Route::post('/rentals/leases',            [TenantControllers\LeaseController::class, 'store'])->name('rentals.leases.store');
                Route::get( '/rentals/leases/{id}',       [TenantControllers\LeaseController::class, 'show'])->name('rentals.leases.show');

                // MARKER-PATCH-223 — fleet-wide availability timeline.
                Route::get('/rentals/availability-timeline',     [TenantControllers\RentalAvailabilityTimelineController::class, 'index'])->name('rentals.availability.timeline');
                Route::get('/rentals/bookings',                  [TenantControllers\RentalBookingController::class, 'index'])->name('rentals.bookings.index');
                Route::get('/rentals/bookings/new',              [TenantControllers\RentalBookingController::class, 'create'])->name('rentals.bookings.create');
                Route::post('/rentals/bookings',                 [TenantControllers\RentalBookingController::class, 'store'])->name('rentals.bookings.store');
                Route::get('/rentals/availability-check',        [TenantControllers\RentalBookingController::class, 'availability'])->name('rentals.availability');
                Route::get('/rentals/bookings/{id}',             [TenantControllers\RentalBookingController::class, 'show'])->name('rentals.bookings.show');
                Route::post('/rentals/bookings/{id}/check-out',  [TenantControllers\RentalBookingController::class, 'checkOut'])->name('rentals.bookings.checkout');
                // MARKER-PATCH-232 — guided check-out flow.
                Route::get( '/rentals/bookings/{id}/check-out-flow',   [TenantControllers\RentalBookingController::class, 'checkOutFlow'])->name('rentals.bookings.checkout.flow');
                Route::post('/rentals/bookings/{id}/agreement/sign',   [TenantControllers\RentalBookingController::class, 'signAgreement'])->name('rentals.bookings.agreement.sign');
                Route::post('/rentals/bookings/{id}/condition-check',  [TenantControllers\RentalBookingController::class, 'storeConditionCheck'])->name('rentals.bookings.condition.store');
                Route::post('/rentals/bookings/{id}/check-out-complete', [TenantControllers\RentalBookingController::class, 'completeCheckOut'])->name('rentals.bookings.checkout.complete');
                // MARKER-PATCH-233 — guided return flow.
                Route::get( '/rentals/bookings/{id}/return-flow',     [TenantControllers\RentalBookingController::class, 'returnFlow'])->name('rentals.bookings.return.flow');
                Route::post('/rentals/bookings/{id}/return-charges',  [TenantControllers\RentalBookingController::class, 'addReturnCharges'])->name('rentals.bookings.return.charges');
                Route::post('/rentals/bookings/{id}/return-complete', [TenantControllers\RentalBookingController::class, 'completeReturn'])->name('rentals.bookings.return.complete');
                Route::post('/rentals/bookings/{id}/check-in',   [TenantControllers\RentalBookingController::class, 'checkIn'])->name('rentals.bookings.checkin');
                Route::post('/rentals/bookings/{id}/cancel',     [TenantControllers\RentalBookingController::class, 'cancel'])->name('rentals.bookings.cancel');
                Route::post('/rentals/bookings/{id}/collect-payment', [TenantControllers\RentalBookingController::class, 'collectPayment'])->name('rentals.bookings.collect');
                // MARKER-PATCH-220 — deposit holds (manual-capture intents).
                Route::post('/rentals/bookings/{id}/deposit/intent',  [TenantControllers\RentalBookingController::class, 'createDepositIntent'])->name('rentals.bookings.deposit.intent');
                Route::post('/rentals/bookings/{id}/deposit/confirm', [TenantControllers\RentalBookingController::class, 'confirmDepositIntent'])->name('rentals.bookings.deposit.confirm');
                Route::post('/rentals/bookings/{id}/deposit/release', [TenantControllers\RentalBookingController::class, 'releaseDeposit'])->name('rentals.bookings.deposit.release');
                Route::post('/rentals/bookings/{id}/deposit/capture', [TenantControllers\RentalBookingController::class, 'captureDeposit'])->name('rentals.bookings.deposit.capture');
            }); // close RequireRentalCapability group

            // MARKER-PATCH-231 — global search.
            Route::get('/search', [TenantControllers\GlobalSearchController::class, 'search'])->name('search');

            // MARKER-PATCH-231 — notifications full page.
            Route::get('/notifications', [TenantControllers\StaffAlertController::class, 'page'])->name('notifications');

            // MARKER-PATCH-225 — staff alerts: bell feed + per-user prefs.
            Route::get('/alerts/feed',           [TenantControllers\StaffAlertController::class, 'feed'])->name('alerts.feed');
            Route::post('/alerts/{id}/read',     [TenantControllers\StaffAlertController::class, 'markRead'])->name('alerts.read');
            Route::post('/alerts/read-all',      [TenantControllers\StaffAlertController::class, 'markAllRead'])->name('alerts.read-all');
            // MARKER-PATCH-280 — shop-wide announcement send.
            Route::post('/alerts/broadcasts',    [TenantControllers\StaffAlertController::class, 'storeBroadcast'])->name('alerts.broadcasts.store');
            Route::post('/alerts/broadcasts/{id}/dismiss', [TenantControllers\StaffAlertController::class, 'dismissBroadcast'])->name('alerts.broadcasts.dismiss');
            Route::get('/settings/alerts',       [TenantControllers\StaffAlertController::class, 'prefs'])->name('alerts.prefs');
            Route::post('/settings/alerts',      [TenantControllers\StaffAlertController::class, 'savePrefs'])->name('alerts.prefs.save');

            // MARKER-PATCH-221 — unified inbox. Gated in the controller on
            // the unified_inbox addon (403 + nav hidden when absent).
            Route::get('/inbox',                        [TenantControllers\InboxController::class, 'index'])->name('inbox.index');
            Route::post('/inbox/start',                 [TenantControllers\InboxController::class, 'start'])->name('inbox.start');
            Route::post('/inbox/threads/{id}/messages', [TenantControllers\InboxController::class, 'send'])->name('inbox.send');
            Route::post('/inbox/threads/{id}/status',   [TenantControllers\InboxController::class, 'toggleStatus'])->name('inbox.status');
            Route::post('/inbox/messages/{id}/delete',  [TenantControllers\InboxController::class, 'deleteMessage'])->name('inbox.message.delete'); // MARKER-PATCH-401

            Route::post('/onboarding/branding', [TenantControllers\OnboardingModalController::class, 'saveBranding'])->name('onboarding.branding');
            Route::post('/onboarding/services', [TenantControllers\OnboardingModalController::class, 'saveServices'])->name('onboarding.services');
            Route::post('/onboarding/hours',    [TenantControllers\OnboardingModalController::class, 'saveHours'])->name('onboarding.hours');
            Route::post('/onboarding/dismiss',  [TenantControllers\OnboardingModalController::class, 'dismiss'])->name('onboarding.dismiss');
            Route::post('/onboarding/complete', [TenantControllers\OnboardingModalController::class, 'complete'])->name('onboarding.complete');

            // 8-step onboarding wizard (replaces the modal for new tenants).
            // Per-step submit: GET shows the screen, POST saves + bumps
            // tenant.onboarding_step + returns JSON with next_url.
            // Gated by RequireOnboardingIncomplete: completed tenants get
            // bounced to the dashboard so they can't re-run the wizard and
            // clobber their data via idempotent saves.
            Route::middleware('App\Http\Middleware\RequireOnboardingIncomplete')
                ->prefix('onboarding/wizard')
                ->name('onboarding.wizard.')
                ->group(function () {
                Route::get('/industry',    [TenantControllers\OnboardingWizardController::class, 'showIndustry'])->name('industry');
                Route::post('/industry',   [TenantControllers\OnboardingWizardController::class, 'saveIndustry'])->name('industry.save');
                Route::get('/identity',    [TenantControllers\OnboardingWizardController::class, 'showIdentity'])->name('identity');
                Route::post('/identity',   [TenantControllers\OnboardingWizardController::class, 'saveIdentity'])->name('identity.save');
                Route::get('/booking',     [TenantControllers\OnboardingWizardController::class, 'showBooking'])->name('booking');
                Route::post('/booking',    [TenantControllers\OnboardingWizardController::class, 'saveBooking'])->name('booking.save');
                Route::get('/hours',       [TenantControllers\OnboardingWizardController::class, 'showHours'])->name('hours');
                Route::post('/hours',      [TenantControllers\OnboardingWizardController::class, 'saveHours'])->name('hours.save');
                Route::get('/services',    [TenantControllers\OnboardingWizardController::class, 'showServices'])->name('services');
                Route::post('/services',   [TenantControllers\OnboardingWizardController::class, 'saveServices'])->name('services.save');
                Route::get('/team',        [TenantControllers\OnboardingWizardController::class, 'showTeam'])->name('team');
                Route::post('/team',       [TenantControllers\OnboardingWizardController::class, 'saveTeam'])->name('team.save');
                Route::get('/payment',     [TenantControllers\OnboardingWizardController::class, 'showPayment'])->name('payment');
                Route::post('/payment',    [TenantControllers\OnboardingWizardController::class, 'savePayment'])->name('payment.save');
                Route::get('/done',        [TenantControllers\OnboardingWizardController::class, 'showDone'])->name('done');
                Route::post('/done',       [TenantControllers\OnboardingWizardController::class, 'complete'])->name('complete');
                Route::post('/ai-prefill', [TenantControllers\OnboardingWizardController::class, 'saveAiPrefill'])->name('ai-prefill');
            });

            // Calendar (admin) — day/week/month views of the tenant's schedule.
            Route::get('/calendar',             [TenantControllers\CalendarController::class, 'index'])->name('calendar.index');

            // MARKER-PATCH-152A — Deliveries (internal pickup/dropoff schedule)
            Route::get('/deliveries',                           [TenantControllers\DeliveriesController::class, 'index'])->name('deliveries.index');
            // MARKER-PATCH-321 — printable 80mm pickup/delivery slips for a day
            Route::get('/deliveries/slips',                     [TenantControllers\DeliveriesController::class, 'printSlips'])->name('deliveries.slips');
            Route::get('/deliveries/customer-assets',           [TenantControllers\DeliveriesController::class, 'customerAssets'])->name('deliveries.customer-assets'); // MARKER-PATCH-427
            // MARKER-PATCH-329 — single delivery receipt
            Route::get('/deliveries/{id}/slip',                 [TenantControllers\DeliveriesController::class, 'printSlip'])->name('deliveries.slip');
            // MARKER-PATCH-152B — create + edit + complete + cancel
            Route::post('/deliveries',                           [TenantControllers\DeliveriesController::class, 'store'])->name('deliveries.store');
            Route::patch('/deliveries/{id}',                     [TenantControllers\DeliveriesController::class, 'update'])->name('deliveries.update');
            Route::patch('/deliveries/{id}/complete',            [TenantControllers\DeliveriesController::class, 'complete'])->name('deliveries.complete');
            // MARKER-PATCH-515 — schedule the return leg from the appointment
            Route::post('/deliveries/schedule-return/{appointmentId}', [TenantControllers\DeliveriesController::class, 'scheduleReturn'])->name('deliveries.schedule_return');
            Route::patch('/deliveries/{id}/cancel',              [TenantControllers\DeliveriesController::class, 'cancel'])->name('deliveries.cancel');
            Route::get('/deliveries/resources',                 [TenantControllers\DeliveryResourcesController::class, 'index'])->name('deliveries.resources.index');
            Route::post('/deliveries/resources',                [TenantControllers\DeliveryResourcesController::class, 'store'])->name('deliveries.resources.store');
            Route::patch('/deliveries/resources/{id}',          [TenantControllers\DeliveryResourcesController::class, 'update'])->name('deliveries.resources.update');
            Route::delete('/deliveries/resources/{id}',         [TenantControllers\DeliveryResourcesController::class, 'destroy'])->name('deliveries.resources.destroy');

            Route::get('/reports',              [TenantControllers\ReportsController::class, 'index'])->name('reports.index');
            Route::get('/reports/customers',    [TenantControllers\ReportsController::class, 'customers'])->name('reports.customers');
            Route::get('/reports/services',     [TenantControllers\ReportsController::class, 'services'])->name('reports.services');
            Route::get('/reports/retail',       [TenantControllers\ReportsController::class, 'retail'])->name('reports.retail');
            Route::get('/reports/money',        [TenantControllers\ReportsController::class, 'money'])->name('reports.money');
            Route::get('/reports/staff',        [TenantControllers\ReportsController::class, 'staff'])->name('reports.staff');
            // MARKER-PATCH-151A — Traffic tab
            Route::get('/reports/traffic',      [TenantControllers\ReportsController::class, 'traffic'])->name('reports.traffic');
            Route::post('/reports/search-rules',            [TenantControllers\SearchRulesController::class, 'store'])->name('reports.search-rules.store'); // MARKER-PATCH-622
            Route::post('/reports/search-rules/{ruleId}/delete', [TenantControllers\SearchRulesController::class, 'destroy'])->name('reports.search-rules.delete');
            Route::post('/calendar/dropoff/reschedule', [TenantControllers\CalendarController::class, 'dropOffReschedule'])->name('calendar.dropoff.reschedule');
            Route::get('/calendar/quick-book',  [TenantControllers\QuickBookController::class, 'picker'])->name('calendar.quick-book.picker');
            Route::post('/calendar/quick-book', [TenantControllers\QuickBookController::class, 'store'])->name('calendar.quick-book.store');
            Route::post('/calendar/breaks',     [TenantControllers\QuickBookController::class, 'storeBreak'])->name('calendar.breaks.store');
            Route::delete('/calendar/breaks/{id}', [TenantControllers\QuickBookController::class, 'destroyBreak'])->name('calendar.breaks.destroy');
            Route::delete('/calendar/holds/{id}',  [TenantControllers\QuickBookController::class, 'destroyHold'])->name('calendar.holds.destroy');

            // Resources (staff / benches / spaces) — calendar's column source
            Route::get('/resources',            [TenantControllers\ResourceController::class, 'index'])->name('resources.index');
            Route::post('/resources',           [TenantControllers\ResourceController::class, 'store'])->name('resources.store');
            Route::patch('/resources/{id}',     [TenantControllers\ResourceController::class, 'update'])->name('resources.update');
            Route::delete('/resources/{id}',    [TenantControllers\ResourceController::class, 'destroy'])->name('resources.destroy');
            Route::post('/resources/reorder',   [TenantControllers\ResourceController::class, 'reorder'])->name('resources.reorder');

            Route::get('/receiving-methods',          [TenantControllers\ReceivingMethodController::class, 'index'])->name('receiving-methods.index');
            Route::post('/receiving-methods',         [TenantControllers\ReceivingMethodController::class, 'store'])->name('receiving-methods.store');
            Route::patch('/receiving-methods/{id}',   [TenantControllers\ReceivingMethodController::class, 'update'])->name('receiving-methods.update');
            Route::delete('/receiving-methods/{id}',  [TenantControllers\ReceivingMethodController::class, 'destroy'])->name('receiving-methods.destroy');
            Route::post('/receiving-methods/reorder', [TenantControllers\ReceivingMethodController::class, 'reorder'])->name('receiving-methods.reorder');

            Route::get('/appointments',         [TenantControllers\AppointmentController::class, 'index'])->name('appointments.index');
            Route::get('/appointments/picker-data', [TenantControllers\AppointmentController::class, 'pickerData'])->name('appointments.picker-data');
            Route::get('/appointments/day-strip',   [TenantControllers\AppointmentController::class, 'dayStrip'])->name('appointments.day-strip');
            // SEQUENTIAL-PICKER-ROUTES v1
            Route::get('/appointments/eligible-resources', [TenantControllers\AppointmentController::class, 'eligibleResources'])->name('appointments.eligible-resources');
            Route::get('/appointments/week-times',         [TenantControllers\AppointmentController::class, 'weekTimes'])->name('appointments.week-times');
            Route::get('/appointments/day-times',   [TenantControllers\AppointmentController::class, 'dayTimes'])->name('appointments.day-times');
            Route::get('/appointments/resolve-resource', [TenantControllers\AppointmentController::class, 'resolveResource'])->name('appointments.resolve-resource');
            Route::post('/appointments',        [TenantControllers\AppointmentController::class, 'store'])->name('appointments.store');
            Route::get('/appointments/{id}',    [TenantControllers\AppointmentController::class, 'show'])->name('appointments.show');
            Route::patch('/appointments/{id}',  [TenantControllers\AppointmentController::class, 'update'])->name('appointments.update');
            Route::get('/appointments/{id}/drawer', [TenantControllers\AppointmentController::class, 'drawer'])->name('appointments.drawer');
            // MARKER-PATCH-313 — printable work-order service tag (80mm thermal)
            Route::get('/appointments/{id}/tag', [TenantControllers\AppointmentController::class, 'printTag'])->name('appointments.tag');
            // MARKER-PATCH-336 — unified print (parallel path)
            Route::get('/print/appointment/{id}', [TenantControllers\PrintController::class, 'appointment'])->name('print.appointment');
            Route::get('/print/sale/{id}',        [TenantControllers\PrintController::class, 'sale'])->name('print.sale');
            Route::get('/print/{source}/{id}/meta', [TenantControllers\PrintController::class, 'meta'])->name('print.meta');
            Route::post('/print/{source}/{id}/email', [TenantControllers\PrintController::class, 'email'])->name('print.email');
            // MARKER-PATCH-204 — work-order invoice export (PDF print + email)
            Route::match(['get','post'], '/appointments/{id}/invoice/preview',  [TenantControllers\InvoiceExportController::class, 'preview'])->name('appointments.invoice.preview');
            Route::match(['get','post'], '/appointments/{id}/invoice/download', [TenantControllers\InvoiceExportController::class, 'download'])->name('appointments.invoice.download');
            Route::post('/appointments/{id}/invoice/email',                     [TenantControllers\InvoiceExportController::class, 'email'])->name('appointments.invoice.email');
            // MARKER-PATCH-206 — live HTML preview for the composer pane (no PDF, no DB write)
            Route::match(['get','post'], '/appointments/{id}/invoice/preview-html', [TenantControllers\InvoiceExportController::class, 'previewHtml'])->name('appointments.invoice.preview-html');
            Route::get('/appointments-inventory-search', [TenantControllers\AppointmentController::class, 'searchInventoryItems'])->name('appointments.inventory-search');

            Route::get('/customers',            [TenantControllers\CustomerController::class, 'index'])->name('customers.index');
            Route::get('/customers/search',     [TenantControllers\CustomerController::class, 'search'])->name('customers.search');
            Route::get('/customers/{id}',       [TenantControllers\CustomerController::class, 'show'])->name('customers.show');
            Route::post('/customers',           [TenantControllers\CustomerController::class, 'store'])->name('customers.store');
            Route::patch('/customers/{id}',     [TenantControllers\CustomerController::class, 'update'])->name('customers.update');

            // MARKER-PATCH-158-C — customer asset CRUD (gated by multi_asset_enabled in controller)
            Route::post('/customers/{customerId}/assets',                  [TenantControllers\CustomerAssetsController::class, 'store'])->name('customers.assets.store');
            Route::patch('/customers/{customerId}/assets/{id}',            [TenantControllers\CustomerAssetsController::class, 'update'])->name('customers.assets.update');
            Route::post('/customers/{customerId}/assets/{id}/archive',     [TenantControllers\CustomerAssetsController::class, 'archive'])->name('customers.assets.archive');
            Route::post('/customers/{customerId}/assets/{id}/unarchive',   [TenantControllers\CustomerAssetsController::class, 'unarchive'])->name('customers.assets.unarchive');

            // Inventory (POS Phase 1) — gated by `retail` capability via FeatureAccessService
            Route::prefix('inventory')->name('inventory.')->group(function () {
                Route::get('/',                  [TenantControllers\InventoryController::class, 'index'])->name('index');
                Route::get('/create',            [TenantControllers\InventoryController::class, 'create'])->name('create');
                Route::post('/',                 [TenantControllers\InventoryController::class, 'store'])->name('store');
                Route::get('/categories',        [TenantControllers\InventoryCategoryController::class, 'index'])->name('categories.index');
                Route::post('/categories',       [TenantControllers\InventoryCategoryController::class, 'store'])->name('categories.store');
                Route::post('/categories/quick',        [TenantControllers\InventoryCategoryController::class, 'quickStore'])->name('categories.quick');
                Route::patch('/categories/{id}/parent', [TenantControllers\InventoryCategoryController::class, 'reparent'])->name('categories.reparent');
                Route::get('/uncategorized',         [TenantControllers\InventoryController::class, 'uncategorized'])->name('uncategorized');
                Route::post('/uncategorized/assign', [TenantControllers\InventoryController::class, 'uncategorizedAssign'])->name('uncategorized.assign');
// Receiving — POS Phase 1, gated by retail capability
                Route::prefix('receiving')->name('receiving.')->group(function () {
                    Route::get('/',                              [TenantControllers\ReceiveShipmentController::class, 'index'])->name('index');
                    Route::post('/create',                       [TenantControllers\ReceiveShipmentController::class, 'create'])->name('create');
                    Route::post('/',                             [TenantControllers\ReceiveShipmentController::class, 'store'])->name('store');
                    Route::get('/{id}',                          [TenantControllers\ReceiveShipmentController::class, 'show'])->name('show');
                    Route::get('/{id}/edit',                     [TenantControllers\ReceiveShipmentController::class, 'edit'])->name('edit');
                    Route::patch('/{id}',                        [TenantControllers\ReceiveShipmentController::class, 'update'])->name('update');
                    Route::delete('/{id}',                       [TenantControllers\ReceiveShipmentController::class, 'destroy'])->name('destroy');
                    Route::post('/{id}/items',                   [TenantControllers\ReceiveShipmentController::class, 'addItem'])->name('items.store');
                    Route::patch('/{id}/items/{itemId}',         [TenantControllers\ReceiveShipmentController::class, 'updateItem'])->name('items.update');
                    Route::delete('/{id}/items/{itemId}',        [TenantControllers\ReceiveShipmentController::class, 'removeItem'])->name('items.destroy');
                    Route::post('/{id}/commit',                  [TenantControllers\ReceiveShipmentController::class, 'commit'])->name('commit');
                    Route::post('/{id}/items/new-inventory-item', [TenantControllers\ReceiveShipmentController::class, 'quickCreateItem'])->name('items.quick.create');
                    Route::get('/items/{id}/quick',     [TenantControllers\ReceiveShipmentController::class, 'quickShowItem'])->name('items.quick.show');
                    Route::patch('/items/{id}/quick',   [TenantControllers\ReceiveShipmentController::class, 'quickUpdateItem'])->name('items.quick.update');
                    Route::get('/categories/list',      [TenantControllers\ReceiveShipmentController::class, 'categoriesForModal'])->name('categories.list');
                });

                // Item search (used by receiving line-add modal, will be reused by register UI)
                Route::get('/items/search', [TenantControllers\ReceiveShipmentController::class, 'searchItems'])->name('items.search');


                Route::get('/{id}',              [TenantControllers\InventoryController::class, 'show'])->name('show');
                Route::get('/{id}/edit',         [TenantControllers\InventoryController::class, 'edit'])->name('edit');
                Route::patch('/{id}',            [TenantControllers\InventoryController::class, 'update'])->name('update');
                Route::post('/{id}/stock',       [TenantControllers\InventoryController::class, 'adjustStock'])->name('stock');
                Route::delete('/{id}',           [TenantControllers\InventoryController::class, 'destroy'])->name('destroy');
            });

            // Vendors — added in patch 86 (Special Orders Stage 4a).
            // Tenant-scoped vendor catalog. Distinct from
            // platform_distributor_catalogs which is the global sync source.
            Route::prefix('vendors')->name('vendors.')->group(function () {
                Route::get('/',           [TenantControllers\VendorController::class, 'index'])->name('index');
                Route::post('/',          [TenantControllers\VendorController::class, 'store'])->name('store');
                Route::get('/{id}',       [TenantControllers\VendorController::class, 'show'])->name('show');
                Route::get('/{id}/edit',  [TenantControllers\VendorController::class, 'edit'])->name('edit');
                Route::patch('/{id}',     [TenantControllers\VendorController::class, 'update'])->name('update');
                Route::delete('/{id}',    [TenantControllers\VendorController::class, 'destroy'])->name('destroy');
            });

            // Special Orders — added in patch 87 (Stage 4b).
            // Reads + writes scoped to tenant. State transitions go
            // through SpecialOrderService for validation + audit notes.
            Route::prefix('special-orders')->name('special-orders.')->group(function () {
                Route::get('/',                                    [TenantControllers\SpecialOrderController::class, 'index'])->name('index');
                Route::post('/',                                   [TenantControllers\SpecialOrderController::class, 'store'])->name('store');
                Route::get('/appointments-for-customer',           [TenantControllers\SpecialOrderController::class, 'appointmentsForCustomer'])->name('appointments-for-customer');
                Route::get('/{id}',                                [TenantControllers\SpecialOrderController::class, 'show'])->name('show');
                Route::post('/{id}/mark-ordered',                  [TenantControllers\SpecialOrderController::class, 'markOrdered'])->name('mark-ordered');
                Route::post('/{id}/mark-arrived',                  [TenantControllers\SpecialOrderController::class, 'markArrived'])->name('mark-arrived');
                Route::post('/{id}/mark-pulled',                   [TenantControllers\SpecialOrderController::class, 'markPulled'])->name('mark-pulled');
                Route::post('/{id}/cancel',                        [TenantControllers\SpecialOrderController::class, 'cancel'])->name('cancel');
                Route::post('/{id}/notes',                         [TenantControllers\SpecialOrderController::class, 'addNote'])->name('notes.store');
            });

            Route::get('/waitlist',                    [TenantControllers\WaitlistAdminController::class, 'index'])->name('waitlist.index');
            Route::get('/waitlist/settings',           [TenantControllers\WaitlistAdminController::class, 'settings'])->name('waitlist.settings');
            Route::patch('/waitlist/settings',         [TenantControllers\WaitlistAdminController::class, 'updateSettings'])->name('waitlist.settings.update');
            Route::post('/waitlist/similar',           [TenantControllers\WaitlistAdminController::class, 'addSimilarMapping'])->name('waitlist.similar.add');
            Route::delete('/waitlist/similar/{id}',    [TenantControllers\WaitlistAdminController::class, 'removeSimilarMapping'])->name('waitlist.similar.remove');
            Route::delete('/waitlist/entries/{id}',    [TenantControllers\WaitlistAdminController::class, 'cancelEntry'])->name('waitlist.cancel');
            // Feature-addon catalog (tenant-facing purchase + manage).
            // Classes — templates, sessions, registrations, memberships, packs
            Route::get('/classes/templates',                    [TenantControllers\ClassController::class, 'templates'])->name('classes.templates');
            Route::post('/classes/templates',                   [TenantControllers\ClassController::class, 'storeTemplate'])->name('classes.templates.store');
            Route::patch('/classes/templates/{id}',             [TenantControllers\ClassController::class, 'updateTemplate'])->name('classes.templates.update');
            Route::delete('/classes/templates/{id}',            [TenantControllers\ClassController::class, 'destroyTemplate'])->name('classes.templates.destroy');

            Route::get('/classes/sessions',                     [TenantControllers\ClassController::class, 'sessions'])->name('classes.sessions');
            Route::post('/classes/sessions',                    [TenantControllers\ClassController::class, 'storeSession'])->name('classes.sessions.store');
            Route::get('/classes/sessions/{id}',                [TenantControllers\ClassController::class, 'showSession'])->name('classes.sessions.show');
            Route::patch('/classes/sessions/{id}',              [TenantControllers\ClassController::class, 'updateSession'])->name('classes.sessions.update');
            Route::delete('/classes/sessions/{id}',             [TenantControllers\ClassController::class, 'destroySession'])->name('classes.sessions.destroy');

            Route::post('/classes/sessions/{id}/register',      [TenantControllers\ClassController::class, 'registerCustomer'])->name('classes.sessions.register');
            Route::post('/classes/registrations/{id}/cancel',   [TenantControllers\ClassController::class, 'cancelRegistration'])->name('classes.registrations.cancel');
            Route::post('/classes/registrations/{id}/checkin',  [TenantControllers\ClassController::class, 'checkIn'])->name('classes.registrations.checkin');
            Route::post('/classes/registrations/{id}/noshow',   [TenantControllers\ClassController::class, 'markNoShow'])->name('classes.registrations.noshow');

            Route::get('/classes/memberships',                  [TenantControllers\ClassController::class, 'membershipProducts'])->name('classes.memberships');
            Route::post('/classes/memberships',                 [TenantControllers\ClassController::class, 'storeMembershipProduct'])->name('classes.memberships.store');
            Route::patch('/classes/memberships/{id}',           [TenantControllers\ClassController::class, 'updateMembershipProduct'])->name('classes.memberships.update');

            Route::get('/classes/packs',                        [TenantControllers\ClassController::class, 'packProducts'])->name('classes.packs');
            Route::post('/classes/packs',                       [TenantControllers\ClassController::class, 'storePackProduct'])->name('classes.packs.store');
            Route::patch('/classes/packs/{id}',                 [TenantControllers\ClassController::class, 'updatePackProduct'])->name('classes.packs.update');

            // Customer-side grant/revoke for memberships and packs. Comp/manual
            // for v1 — Stripe purchase flow comes later. Endpoints are POST
            // (create) and DELETE (cancel). Routed under /customers/{id}/...
            // so they appear naturally on the customer detail page.
            Route::post('/customers/{customerId}/memberships',                [TenantControllers\ClassController::class, 'grantCustomerMembership'])->name('customers.memberships.grant');
            Route::delete('/customers/{customerId}/memberships/{id}',         [TenantControllers\ClassController::class, 'revokeCustomerMembership'])->name('customers.memberships.revoke');
            Route::post('/customers/{customerId}/packs',                      [TenantControllers\ClassController::class, 'grantCustomerPack'])->name('customers.packs.grant');
            Route::delete('/customers/{customerId}/packs/{id}',               [TenantControllers\ClassController::class, 'revokeCustomerPack'])->name('customers.packs.revoke');

            // Classes reports — panel page + CSV exports per panel
            Route::get('/classes/reports',                                    [TenantControllers\ClassController::class, 'reports'])->name('classes.reports');
            Route::get('/classes/reports/export/{panel}',                     [TenantControllers\ClassController::class, 'reportExport'])->name('classes.reports.export');

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

            // MARKER-PATCH-610 — time clock
            Route::get('/timeclock',            [TenantControllers\TimeClockController::class, 'index'])->name('timeclock.index');
            Route::post('/timeclock/in',        [TenantControllers\TimeClockController::class, 'punchIn'])->name('timeclock.in');
            Route::post('/timeclock/out',       [TenantControllers\TimeClockController::class, 'punchOut'])->name('timeclock.out');
            Route::get('/timeclock/timesheet',  [TenantControllers\TimeClockController::class, 'timesheet'])->name('timeclock.timesheet'); // MARKER-PATCH-613
            Route::post('/timeclock/timesheet/email', [TenantControllers\TimeClockController::class, 'emailTimesheet'])->name('timeclock.timesheet.email');
            Route::get('/timeclock/team',       [TenantControllers\TimeClockController::class, 'team'])->name('timeclock.team'); // MARKER-PATCH-614
            Route::post('/timeclock/punch',     [TenantControllers\TimeClockController::class, 'createPunch'])->name('timeclock.punch.create');
            Route::post('/timeclock/punch/{punchId}', [TenantControllers\TimeClockController::class, 'editPunch'])->name('timeclock.punch.edit');
            Route::get('/timeclock/reports',       [TenantControllers\TimeClockController::class, 'reports'])->name('timeclock.reports'); // MARKER-PATCH-615
            Route::get('/timeclock/reports/csv',   [TenantControllers\TimeClockController::class, 'reportsCsv'])->name('timeclock.reports.csv');
            Route::get('/timeclock/reports/print', [TenantControllers\TimeClockController::class, 'reportPrint'])->name('timeclock.reports.print');
            Route::post('/timeclock/reports/email',[TenantControllers\TimeClockController::class, 'reportEmail'])->name('timeclock.reports.email');
            Route::get('/timeclock/approvals',     [TenantControllers\TimeClockController::class, 'approvals'])->name('timeclock.approvals'); // MARKER-PATCH-616
            Route::post('/timeclock/approve',      [TenantControllers\TimeClockController::class, 'approvePerson'])->name('timeclock.approve');
            Route::post('/timeclock/period/lock',  [TenantControllers\TimeClockController::class, 'lockPeriod'])->name('timeclock.period.lock');
            Route::post('/timeclock/period/reopen',[TenantControllers\TimeClockController::class, 'reopenPeriod'])->name('timeclock.period.reopen');
            Route::post('/timeclock/settings',     [TenantControllers\TimeClockController::class, 'saveSettings'])->name('timeclock.settings');

            // MARKER-PATCH-623 — staff scheduling phase 1
            Route::get('/scheduling',                    [TenantControllers\SchedulingController::class, 'index'])->name('scheduling.index');
            Route::post('/scheduling/shift',             [TenantControllers\SchedulingController::class, 'storeShift'])->name('scheduling.shift.store');
            Route::post('/scheduling/shift/{shiftId}/delete', [TenantControllers\SchedulingController::class, 'deleteShift'])->name('scheduling.shift.delete');
            Route::post('/scheduling/copy-week',         [TenantControllers\SchedulingController::class, 'copyLastWeek'])->name('scheduling.copy-week');
            Route::post('/scheduling/publish',           [TenantControllers\SchedulingController::class, 'publish'])->name('scheduling.publish');
            Route::get('/scheduling/timeoff',            [TenantControllers\SchedulingController::class, 'timeOff'])->name('scheduling.timeoff');
            Route::post('/scheduling/timeoff',           [TenantControllers\SchedulingController::class, 'timeOffStore'])->name('scheduling.timeoff.store');
            Route::post('/scheduling/timeoff/{requestId}/review', [TenantControllers\SchedulingController::class, 'timeOffReview'])->name('scheduling.timeoff.review');
            Route::get('/scheduling/mine',               [TenantControllers\SchedulingController::class, 'mine'])->name('scheduling.mine');
            Route::get('/scheduling/availability',       [TenantControllers\SchedulingController::class, 'availability'])->name('scheduling.availability'); // MARKER-PATCH-624
            Route::post('/scheduling/availability',      [TenantControllers\SchedulingController::class, 'availabilityStore'])->name('scheduling.availability.store');
            Route::get('/scheduling/settings',           [TenantControllers\SchedulingController::class, 'settingsPage'])->name('scheduling.settings');
            Route::post('/scheduling/settings',          [TenantControllers\SchedulingController::class, 'saveSettings'])->name('scheduling.settings.save');
            Route::post('/scheduling/template',          [TenantControllers\SchedulingController::class, 'saveTemplate'])->name('scheduling.template.save');
            Route::post('/scheduling/template/{templateId}/apply', [TenantControllers\SchedulingController::class, 'applyTemplate'])->name('scheduling.template.apply');

            Route::post('/uploads', [TenantControllers\UploadController::class, 'store'])->name('uploads.store');

            // MARKER-PATCH-258 — media library
            Route::get('/media',            [TenantControllers\MediaLibraryController::class, 'index'])->name('media.index');
            Route::get('/media/feed',       [TenantControllers\MediaLibraryController::class, 'feed'])->name('media.feed');
            Route::post('/media/{id}/archive', [TenantControllers\MediaLibraryController::class, 'archive'])->name('media.archive');

            Route::get('/help', [TenantControllers\HelpController::class, 'index'])->name('help.index');

            Route::get('/whats-new', [TenantControllers\WhatsNewController::class, 'changelog'])->name('whats_new.changelog');
            Route::get('/whats-coming', [TenantControllers\WhatsNewController::class, 'roadmap'])->name('whats_new.roadmap');

            Route::get('/pages',                [TenantControllers\PageBuilderController::class, 'index'])->name('pages.index');
            Route::get('/pages/{id}',           [TenantControllers\PageBuilderController::class, 'edit'])->name('pages.edit');
            Route::get('/pages/{id}/preview',   [TenantControllers\PageBuilderController::class, 'preview'])->name('pages.preview'); // MARKER-PATCH-267
            Route::post('/pages',               [TenantControllers\PageBuilderController::class, 'store'])->name('pages.store');
            Route::post('/pages/brand-kit',     [TenantControllers\PageBuilderController::class, 'saveBrandKit'])->name('pages.brand-kit.save'); // MARKER-PATCH-302
            Route::patch('/pages/{id}',         [TenantControllers\PageBuilderController::class, 'update'])->name('pages.update');
            Route::delete('/pages/{id}',        [TenantControllers\PageBuilderController::class, 'destroy'])->name('pages.destroy');
            Route::post('/pages/{id}/sections',           [TenantControllers\PageBuilderController::class, 'addSection'])->name('pages.sections.add');
            Route::patch('/pages/{id}/sections/{sid}',    [TenantControllers\PageBuilderController::class, 'updateSection'])->name('pages.sections.update');
            Route::delete('/pages/{id}/sections/{sid}',   [TenantControllers\PageBuilderController::class, 'deleteSection'])->name('pages.sections.delete');
            Route::post('/pages/{id}/sections/reorder',   [TenantControllers\PageBuilderController::class, 'reorderSections'])->name('pages.sections.reorder');

            // MARKER-PATCH-261 — site template gallery
            Route::get('/website/templates',               [TenantControllers\SiteTemplateController::class, 'index'])->name('templates.index');
            Route::post('/website/templates/revert',       [TenantControllers\SiteTemplateController::class, 'revert'])->name('templates.revert');
            Route::post('/website/templates/{key}/apply',  [TenantControllers\SiteTemplateController::class, 'apply'])->where('key', '[a-z]+')->name('templates.apply');

            Route::get('/emails',               [TenantControllers\EmailController::class, 'index'])->name('emails.index');
            // MARKER-PATCH-404 — Communication Center (unified comms surface)
            Route::get('/communication',        [TenantControllers\CommunicationController::class, 'index'])->name('communication.index');
            Route::patch('/communication',      [TenantControllers\CommunicationController::class, 'updateToggles'])->name('communication.toggles');
            Route::patch('/communication/template/{type}', [TenantControllers\CommunicationController::class, 'saveTemplate'])->name('communication.template'); // MARKER-PATCH-405
            Route::post('/communication/test/{type}', [TenantControllers\CommunicationController::class, 'sendTest'])->name('communication.test'); // MARKER-PATCH-409
            Route::patch('/emails/{type}',      [TenantControllers\EmailController::class, 'update'])->name('emails.update');
            // MARKER-PATCH-160 — re-send a receipt from sale-detail (also accepts ?email= for "send to another")
            Route::post('/sales/{id}/resend-receipt',
                [TenantControllers\RegisterController::class, 'resendReceipt'])
                ->name('sales.resend_receipt');
            // MARKER-PATCH-407 — emails.settings.update route removed (receipt options in Communication Center)
            Route::get('/campaigns',            [TenantControllers\CampaignController::class, 'index'])->name('campaigns.index');
            Route::get('/campaigns/{id}',       [TenantControllers\CampaignController::class, 'show'])->name('campaigns.show');
            Route::post('/campaigns',           [TenantControllers\CampaignController::class, 'store'])->name('campaigns.store');
            Route::patch('/campaigns/{id}',     [TenantControllers\CampaignController::class, 'update'])->name('campaigns.update');
            Route::post('/campaigns/{id}/send', [TenantControllers\CampaignController::class, 'send'])->name('campaigns.send');
            Route::post('/campaigns/{id}/preview', [TenantControllers\CampaignController::class, 'preview'])->name('campaigns.preview');

            // MARKER-PATCH-450 — Engage -> Recovery (abandoned-booking worklist + funnel)
            Route::get('/recovery',         [TenantControllers\RecoveryController::class, 'index'])->name('recovery.index');
            // MARKER-PATCH-486 — settings PATCH before the {id} PATCH so it matches first.
            Route::patch('/recovery/settings', [TenantControllers\RecoveryController::class, 'updateSettings'])->name('recovery.settings.update');
            Route::patch('/recovery/{id}',  [TenantControllers\RecoveryController::class, 'updateStatus'])->name('recovery.update');

            // MARKER-FLOW-6 — Booking Mode admin (flow mode + Simple-menu curation)
            Route::get('/booking-modes',  [TenantControllers\BookingModesController::class, 'index'])->name('booking_modes.index');
            Route::post('/booking-modes', [TenantControllers\BookingModesController::class, 'save'])->name('booking_modes.save');
            // MARKER-PATCH-510 — Pickup & delivery: route windows + knobs
            Route::post('/booking-modes/route-windows',              [TenantControllers\RouteWindowsController::class, 'store'])->name('route_windows.store');
            Route::patch('/booking-modes/route-windows/settings',    [TenantControllers\RouteWindowsController::class, 'saveSettings'])->name('route_windows.settings');
            Route::patch('/booking-modes/route-windows/{id}',        [TenantControllers\RouteWindowsController::class, 'update'])->name('route_windows.update');
            Route::delete('/booking-modes/route-windows/{id}',       [TenantControllers\RouteWindowsController::class, 'destroy'])->name('route_windows.destroy');

            // Campaign image library
            Route::get('/campaign-images',           [TenantControllers\CampaignImageController::class, 'index'])->name('campaign-images.index');
            Route::get('/campaign-images/usage',     [TenantControllers\CampaignImageController::class, 'usage'])->name('campaign-images.usage');
            Route::post('/campaign-images',          [TenantControllers\CampaignImageController::class, 'upload'])->name('campaign-images.upload');
            Route::delete('/campaign-images/{id}',   [TenantControllers\CampaignImageController::class, 'destroy'])->name('campaign-images.destroy');

            // MARKER-PATCH-HLC7A — tenant distributor surface
            Route::prefix('distributors')->name('distributors.')->group(function () {
                Route::get('/import',             [TenantControllers\DistributorController::class, 'import'])->name('import');
                Route::get('/attention',          [TenantControllers\DistributorController::class, 'attention'])->name('attention');
                Route::post('/attention/resolve', [TenantControllers\DistributorController::class, 'attentionResolve'])->name('attention.resolve');
                Route::post('/attention/sync',    [TenantControllers\DistributorController::class, 'attentionSync'])->name('attention.sync'); // MARKER-PATCH-555
                Route::post('/import/run',        [TenantControllers\DistributorController::class, 'importRun'])->name('import.run');
                Route::get('/connection',         [TenantControllers\DistributorController::class, 'connection'])->name('connection');
                Route::post('/connection/key',    [TenantControllers\DistributorController::class, 'saveKey'])->name('connection.key');
                Route::post('/connection/test',   [TenantControllers\DistributorController::class, 'testConnection'])->name('connection.test');
                Route::post('/connection/refresh',[TenantControllers\DistributorController::class, 'refreshSync'])->name('connection.refresh');
            });

            Route::get('/settings',             [TenantControllers\SettingsController::class, 'index'])->name('settings.index');
            Route::patch('/settings',           [TenantControllers\SettingsController::class, 'update'])->name('settings.update');
            // MARKER-PATCH-629 — unified payment methods
            Route::post('/settings/payment-methods',                    [TenantControllers\PaymentMethodsController::class, 'storeCustom'])->name('settings.payment-methods.store');
            Route::post('/settings/payment-methods/{methodId}',         [TenantControllers\PaymentMethodsController::class, 'update'])->name('settings.payment-methods.update');
            Route::post('/settings/payment-methods/{methodId}/delete',  [TenantControllers\PaymentMethodsController::class, 'destroyCustom'])->name('settings.payment-methods.delete');

            // MARKER-PATCH-473 — verify a tenant's Square connection
            Route::post('/settings/square/verify', [TenantControllers\SettingsController::class, 'verifySquareConnection'])->name('settings.square.verify');

            // MARKER-PATCH-468 — toggle asset tracking from the Services-page banner
            Route::patch('/services/asset-tracking', [TenantControllers\SettingsController::class, 'toggleAssetTracking'])->name('services.asset-tracking.toggle');

            // MARKER-PATCH-168 — Stripe Connect Session A: tenant payments settings
            // MARKER-PATCH-224 — Settings -> Messaging (owns all tenant SMS config).
            Route::get( '/settings/messaging',               [TenantControllers\Settings\MessagingController::class, 'index'])->name('settings.messaging');
            Route::post('/settings/messaging/search',        [TenantControllers\Settings\MessagingController::class, 'search'])->name('settings.messaging.search');
            Route::post('/settings/messaging/claim',         [TenantControllers\Settings\MessagingController::class, 'claim'])->name('settings.messaging.claim');
            Route::post('/settings/messaging/byo',           [TenantControllers\Settings\MessagingController::class, 'saveByo'])->name('settings.messaging.byo');
            Route::post('/settings/messaging/sync-webhook',  [TenantControllers\Settings\MessagingController::class, 'syncWebhook'])->name('settings.messaging.sync');
            Route::get( '/settings/payments',            [TenantControllers\Settings\PaymentsController::class, 'index'])->name('settings.payments.index');
            Route::post('/settings/payments/connect',    [TenantControllers\Settings\PaymentsController::class, 'connect'])->name('settings.payments.connect');
            Route::post('/settings/payments/resume',     [TenantControllers\Settings\PaymentsController::class, 'resume'])->name('settings.payments.resume');
            Route::post('/settings/payments/disconnect', [TenantControllers\Settings\PaymentsController::class, 'disconnect'])->name('settings.payments.disconnect');

            // MARKER-PATCH-120 - Custom domain management
            Route::get('/settings/domains',                [TenantControllers\DomainController::class, 'index'])->name('domains.index');
            Route::post('/settings/domains',               [TenantControllers\DomainController::class, 'store'])->name('domains.store');
            Route::get('/settings/domains/{id}',           [TenantControllers\DomainController::class, 'show'])->name('domains.show');
            Route::delete('/settings/domains/{id}',        [TenantControllers\DomainController::class, 'destroy'])->name('domains.destroy');
            Route::post('/settings/domains/{id}/sync',     [TenantControllers\DomainController::class, 'sync'])->name('domains.sync');

            // Sign-in security admin (chunk 8) — owner-only enforced in the controller.
            // Locations admin (patch 109) — owner-only enforced in controller.
            Route::get('/locations',                      [TenantControllers\LocationController::class, 'index'])->name('locations.index');
            Route::post('/locations',                     [TenantControllers\LocationController::class, 'store'])->name('locations.store');
            Route::patch('/locations/{id}',               [TenantControllers\LocationController::class, 'update'])->name('locations.update');
            Route::post('/locations/{id}/set-default',    [TenantControllers\LocationController::class, 'setDefault'])->name('locations.set-default');
            Route::post('/locations/{id}/toggle-active',  [TenantControllers\LocationController::class, 'toggleActive'])->name('locations.toggle-active');
            Route::delete('/locations/{id}',              [TenantControllers\LocationController::class, 'destroy'])->name('locations.destroy');

            // MARKER-PATCH-129 — old /admin/security/* URLs redirect to /admin/team/*
            Route::get('/security',                  fn() => redirect()->route('tenant.team.index'))->name('security.index');
            Route::get('/security/devices',          fn() => redirect()->route('tenant.team.devices'));
            Route::get('/security/policy',           fn() => redirect()->route('tenant.team.policy'));
            Route::post('/settings/test-sms',   [TenantControllers\SettingsController::class, 'sendTestSms'])->name('settings.test-sms');

            // MARKER-PATCH-129 — consolidated Team & Access
            Route::get('/team',                            [TenantControllers\TeamController::class, 'index'])->name('team.index');
            Route::post('/team',                           [TenantControllers\TeamController::class, 'store'])->name('team.store');
            Route::get('/team/devices',                    [TenantControllers\TeamController::class, 'devices'])->name('team.devices');
            Route::post('/team/devices/{id}/revoke',       [TenantControllers\TeamController::class, 'revokeDevice'])->name('team.devices.revoke');
            Route::post('/team/devices/revoke-all',        [TenantControllers\TeamController::class, 'revokeAllDevices'])->name('team.devices.revoke-all');
            Route::get('/team/policy',                     [TenantControllers\TeamController::class, 'policy'])->name('team.policy');
            Route::patch('/team/policy',                   [TenantControllers\TeamController::class, 'updatePolicy'])->name('team.policy.update');
            // MARKER-PATCH-494 — Roles & access (custom named roles). Must sit
            // BEFORE /team/{id} or 'roles' gets swallowed as a member id.
            Route::get('/team/roles',                      [TenantControllers\TeamController::class, 'rolesIndex'])->name('team.roles');
            Route::post('/team/roles',                     [TenantControllers\TeamController::class, 'storeRole'])->name('team.roles.store');
            Route::patch('/team/roles/{roleId}',           [TenantControllers\TeamController::class, 'updateRole'])->name('team.roles.update');
            Route::delete('/team/roles/{roleId}',          [TenantControllers\TeamController::class, 'destroyRole'])->name('team.roles.destroy');
            Route::get('/team/{id}',                       [TenantControllers\TeamController::class, 'show'])->name('team.show');
            Route::patch('/team/{id}',                     [TenantControllers\TeamController::class, 'update'])->name('team.update');
            Route::delete('/team/{id}',                    [TenantControllers\TeamController::class, 'destroy'])->name('team.destroy');

            // MARKER-PATCH-143 — Test email send endpoint (settings card)
            Route::post('/settings/email/test', [TenantControllers\TestEmailController::class, 'sendSettingsTest'])->name('settings.email.test');
            // MARKER-PATCH-150 — Web analytics settings (GA-4 etc)
            Route::post('/settings/analytics', [TenantControllers\AnalyticsSettingsController::class, 'update'])->name('settings.analytics.update');

            // MARKER-PATCH-147 — Tenant suppression list
            Route::get('/email/suppressions',         [TenantControllers\SuppressionController::class, 'index'])->name('suppressions.index');
            Route::post('/email/suppressions',        [TenantControllers\SuppressionController::class, 'store'])->name('suppressions.store');
            Route::delete('/email/suppressions/{id}', [TenantControllers\SuppressionController::class, 'destroy'])->name('suppressions.destroy');

            // Self-service account surfaces (current user only)
            Route::get('/account',                         [TenantControllers\AccountController::class, 'index'])->name('account.index');
            Route::patch('/account/name',                  [TenantControllers\AccountController::class, 'updateName'])->name('account.name');
            Route::patch('/account/password',              [TenantControllers\AccountController::class, 'updatePassword'])->name('account.password');
            Route::patch('/account/pin',                   [TenantControllers\AccountController::class, 'setPin'])->name('account.pin');
            Route::patch('/account/pin/clear',             [TenantControllers\AccountController::class, 'clearPin'])->name('account.pin.clear');
            // MARKER-PATCH-130 — per-user device + sign-out-everywhere routes removed

            // Stripe billing portal (card update, invoices, cancel).
            // Plan changes happen in-app, not via the portal.
            Route::get('/billing/portal',       [TenantControllers\BillingController::class, 'portal'])->name('billing.portal');

            }); // close RequireCurrentLocation group

        });

    });

    Route::get('/{slug}', [TenantControllers\PublicController::class, 'page'])->name('tenant.page');

};

// ─────────────────────────────────────────────────────────────────────
// MARKER-PATCH-123 — Single tenant route registration. ResolveTenant
// middleware identifies the tenant from the request host, supporting
// both {slug}.intake.works subdomains and custom domains (Cloudflare
// for SaaS). Routes carry no {subdomain} placeholder; controllers
// resolve the current tenant via the tenant() helper / app('tenant').
// ─────────────────────────────────────────────────────────────────────
Route::middleware(['App\Http\Middleware\ResolveTenant'])
    ->group($tenantRoutes);

RDL_3_EOF

cat > 'resources/views/tenant/register/registers.blade.php' <<'RDL_4_EOF'
@extends('layouts.tenant.app')

{{-- MARKER-REGISTER-RECON-DISPLAY — manage physical registers + pair customer displays --}}

@php $pageTitle = 'Registers'; @endphp

@section('content')
<div style="max-width:860px">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
    <h1 style="font-size:22px;font-weight:800;letter-spacing:-.02em">Registers &amp; pay displays</h1>
    <a href="{{ route('tenant.register.index') }}" class="ia-btn ia-btn-ghost">← Back to register</a>
  </div>
  <p style="color:var(--ia-muted);font-size:13.5px;margin-bottom:20px">
    Each register is a physical pay station. Pair an iPad or phone once by scanning its QR code —
    the screen then mirrors that register's cart automatically for every sale.
  </p>

  @if (session('status'))
    <div class="ia-alert ia-alert-success" style="margin-bottom:16px">{{ session('status') }}</div>
  @endif

  @foreach ($registers as $r)
    <div style="background:var(--ia-panel);border:1px solid var(--ia-border);border-radius:12px;padding:18px;margin-bottom:12px;display:flex;gap:20px;align-items:flex-start">
      <div style="flex:1">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
          <span style="font-weight:800;font-size:16px">#{{ $r->number }} — {{ $r->name }}</span>
          @if ($currentRegisterId === $r->id)
            <span style="font-size:10.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;background:var(--ia-accent);color:#0B0B0B;border-radius:100px;padding:3px 9px">This device</span>
          @endif
        </div>
        <div style="font-size:12.5px;color:var(--ia-muted);margin-bottom:12px;word-break:break-all">
          Display link: {{ url('/pay-display/' . $r->display_token) }}
        </div>
        {{-- MARKER-REGISTER-RECON-DISPLAY — welcome-screen logo choice --}}
        <form method="POST" action="{{ route('tenant.register.registers.update', ['id' => $r->id]) }}"
              style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
          @csrf
          <label style="font-size:12.5px;color:var(--ia-muted)">Welcome-screen logo</label>
          <select name="display_logo" class="ia-input" style="max-width:210px;font-size:13px"
                  onchange="this.form.submit()">
            <option value="auto"  @selected($r->display_logo === 'auto')>Auto (light, then main)</option>
            <option value="light" @selected($r->display_logo === 'light')>Light logo</option>
            <option value="main"  @selected($r->display_logo === 'main')>Main logo</option>
            <option value="none"  @selected($r->display_logo === 'none')>No logo</option>
          </select>
        </form>
        <div style="display:flex;gap:8px">
          <button class="ia-btn ia-btn-ghost" onclick="toggleQr({{ $r->id }})">Show pairing QR</button>
          <form method="POST" action="{{ route('tenant.register.registers.regenerate', ['id' => $r->id]) }}"
                onsubmit="return confirm('Regenerate the pairing link? All screens paired to this register will disconnect.');">
            @csrf
            <button class="ia-btn ia-btn-ghost" type="submit">Regenerate link</button>
          </form>
        </div>
      </div>
      <div id="qr-{{ $r->id }}" data-url="{{ url('/pay-display/' . $r->display_token) }}"
           style="display:none;background:#fff;border-radius:10px;padding:12px;width:170px;height:170px"></div>
    </div>
  @endforeach

  <form method="POST" action="{{ route('tenant.register.registers.store') }}"
        style="display:flex;gap:10px;margin-top:18px">
    @csrf
    <input name="name" required maxlength="80" placeholder="Register name — e.g. Front Counter"
           class="ia-input" style="flex:1">
    <button class="ia-btn ia-btn-primary" type="submit">Add register</button>
  </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
<script>
function toggleQr(id) {
  const el = document.getElementById('qr-' + id);
  if (el.style.display === 'none') {
    if (!el.dataset.done && typeof qrcode === 'function') {
      const qr = qrcode(0, 'M');
      qr.addData(el.dataset.url);
      qr.make();
      el.innerHTML = qr.createSvgTag({ scalable: true, margin: 0 });
      el.querySelector('svg').style.cssText = 'width:100%;height:100%';
      el.dataset.done = '1';
    }
    el.style.display = 'block';
  } else {
    el.style.display = 'none';
  }
}
</script>
@endsection
RDL_4_EOF

cat > 'resources/views/tenant/register/display.blade.php' <<'RDL_5_EOF'
<!DOCTYPE html>
{{-- MARKER-REGISTER-RECON-DISPLAY — full-screen customer display for one register.
     Token in the URL is the credential; page is read-only and polls for state. --}}
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>{{ $tenant->business_name }} — Register {{ $register->number }}</title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; -webkit-user-select:none; user-select:none }
  html,body { height:100% }
  body {
    font-family: -apple-system, 'Inter', system-ui, sans-serif;
    background:#0B0B0B; color:#EDEDED; display:flex; flex-direction:column;
    overflow:hidden;
  }
  .top { display:flex; justify-content:space-between; align-items:center; padding:22px 30px; border-bottom:1px solid #1E1E1E }
  .biz { font-size:19px; font-weight:800; letter-spacing:-.02em }
  .reg { font-size:12px; font-weight:700; letter-spacing:.12em; text-transform:uppercase; color:#8A8A8A }
  .main { flex:1; display:flex; flex-direction:column; overflow:hidden }

  /* idle */
  .idle { flex:1; display:none; align-items:center; justify-content:center; flex-direction:column; gap:14px; text-align:center; padding:30px }
  .idle .hello { font-size:clamp(30px,5vw,52px); font-weight:800; letter-spacing:-.03em }
  .idle .sub { color:#8A8A8A; font-size:16px }

  /* cart */
  .cart { flex:1; display:none; flex-direction:column; overflow:hidden }
  .lines { flex:1; overflow-y:auto; padding:18px 30px }
  .line { display:flex; justify-content:space-between; gap:16px; padding:13px 0; border-bottom:1px solid #181818; font-size:19px }
  .line .n { flex:1 }
  .line .q { color:#8A8A8A; font-size:15px }
  .line.refund { color:#F09595 }
  .totals { border-top:1px solid #242424; padding:16px 30px 22px; background:#101010 }
  .trow { display:flex; justify-content:space-between; padding:4px 0; font-size:16px; color:#B9B9B9 }
  .trow.grand { font-size:clamp(26px,4vw,38px); font-weight:800; color:#fff; padding-top:10px }
  .trow.grand .v { color:#BEF264 }

  /* pay */
  .pay { flex:1; display:none; align-items:center; justify-content:center; flex-direction:column; gap:20px; padding:30px; text-align:center }
  .pay .amt { font-size:clamp(34px,6vw,56px); font-weight:800; letter-spacing:-.03em }
  .pay .amt span { color:#BEF264 }
  #payQr { background:#fff; border-radius:16px; padding:16px; width:min(46vh,300px); height:min(46vh,300px) }
  .pay .hint { color:#8A8A8A; font-size:15px }
</style>
</head>
<body>
  <div class="top">
    <div class="biz">{{ $tenant->business_name }}</div>
    <div class="reg">Register {{ $register->number }} · {{ $register->name }}</div>
  </div>

  <div class="main">
    <div class="idle" id="vIdle" style="display:flex">
      {{-- MARKER-REGISTER-RECON-DISPLAY — Brand Kit logo (light variant for dark screen) --}}
      @php
        $displayLogo = match ($register->display_logo ?? 'auto') {
            'none'  => null,
            'main'  => $tenant->logo_url,
            'light' => $tenant->logo_light_url ?: $tenant->logo_url,
            default => $tenant->logo_light_url ?: $tenant->logo_url,
        };
      @endphp
      @if ($displayLogo)
        <img src="{{ $displayLogo }}" alt="{{ $tenant->business_name }}"
             style="max-width:min(60vw,420px);max-height:26vh;object-fit:contain;margin-bottom:10px">
      @endif
      <div class="hello">Welcome to {{ $tenant->business_name }}</div>
      <div class="sub">Your order will appear here.</div>
    </div>

    <div class="cart" id="vCart">
      <div class="lines" id="cartLines"></div>
      <div class="totals" id="cartTotals"></div>
    </div>

    <div class="pay" id="vPay">
      <div class="amt">Total due <span id="payAmt"></span></div>
      <div id="payQr"></div>
      <div class="hint">Scan with your phone camera to pay</div>
    </div>
  </div>

{{-- MARKER-REGISTER-RECON-DISPLAY — fullscreen toggle --}}
<button id="fsBtn" style="position:fixed;bottom:18px;right:18px;z-index:10;background:#1E1E1E;color:#BEF264;border:1px solid #333;border-radius:10px;padding:10px 16px;font:600 14px -apple-system,'Inter',sans-serif;cursor:pointer">&#x26F6; Full screen</button>
<script>
(function () {
  const btn = document.getElementById('fsBtn');
  const el = document.documentElement;
  btn.addEventListener('click', () => {
    (el.requestFullscreen || el.webkitRequestFullscreen).call(el);
  });
  const sync = () => {
    btn.style.display = (document.fullscreenElement || document.webkitFullscreenElement) ? 'none' : 'block';
  };
  document.addEventListener('fullscreenchange', sync);
  document.addEventListener('webkitfullscreenchange', sync);
})();
</script>

<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
<script>
const POLL_URL = @json(route('tenant.pay_display.poll', ['token' => $register->display_token]));
const fmt = c => '$' + ((c || 0) / 100).toFixed(2);
let lastPayUrl = null;

function show(which) {
  for (const id of ['vIdle', 'vCart', 'vPay']) {
    document.getElementById(id).style.display = (id === which) ? 'flex' : 'none';
  }
}

function render(data) {
  const snap = data.snap;
  if (data.state === 'idle' || !snap || !(snap.items || []).length) { show('vIdle'); return; }

  if (data.state === 'pay' && snap.pay_url) {
    document.getElementById('payAmt').textContent = fmt(snap.total_cents);
    if (snap.pay_url !== lastPayUrl && typeof qrcode === 'function') {
      const qr = qrcode(0, 'M');
      qr.addData(snap.pay_url); qr.make();
      const el = document.getElementById('payQr');
      el.innerHTML = qr.createSvgTag({ scalable: true, margin: 0 });
      el.querySelector('svg').style.cssText = 'width:100%;height:100%';
      lastPayUrl = snap.pay_url;
    }
    show('vPay'); return;
  }

  let html = '';
  for (const i of snap.items) {
    html += '<div class="line' + (i.refund ? ' refund' : '') + '">'
          + '<div class="n">' + esc(i.name) + ' <span class="q">× ' + i.qty + '</span></div>'
          + '<div>' + (i.refund ? '-' : '') + fmt(Math.abs(i.line_cents)) + '</div></div>';
  }
  document.getElementById('cartLines').innerHTML = html;

  let t = '';
  t += trow('Subtotal', fmt(snap.subtotal_cents));
  if (snap.discount_cents)  t += trow('Discount', '-' + fmt(snap.discount_cents));
  if (snap.tax_cents)       t += trow(snap.tax_label || 'Tax', fmt(snap.tax_cents));
  if (snap.surcharge_cents) t += trow('Card surcharge', fmt(snap.surcharge_cents));
  if (snap.tip_cents)       t += trow('Tip', fmt(snap.tip_cents));
  t += '<div class="trow grand"><div>Total</div><div class="v">' + fmt(snap.total_cents) + '</div></div>';
  document.getElementById('cartTotals').innerHTML = t;
  show('vCart');
}

const trow = (l, v) => '<div class="trow"><div>' + l + '</div><div>' + v + '</div></div>';
const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

async function poll() {
  try {
    const r = await fetch(POLL_URL, { cache: 'no-store' });
    if (r.ok) render(await r.json());
  } catch (e) { /* keep last state; retry next tick */ }
}
poll();
setInterval(poll, 1500);
// Keep the screen awake where supported
if ('wakeLock' in navigator) {
  const lock = () => navigator.wakeLock.request('screen').catch(() => {});
  lock();
  document.addEventListener('visibilitychange', () => { if (!document.hidden) lock(); });
}
</script>
</body>
</html>
RDL_5_EOF

echo "register-display-logo-setting applied — run migrate + view:clear + route:clear on the server"
