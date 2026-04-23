<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\BillingSettings;
use App\Models\Tenant;
use App\Models\Tenant\TenantUser;
use App\Services\StripeBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * OnboardingController — tenant signup flow.
 *
 * Three-step flow:
 *   1. GET  /signup              → show form with plan + subdomain + owner info
 *   2. POST /signup              → validate, stash in session, redirect to /signup/payment
 *   3. GET  /signup/payment      → show Stripe Payment Element + cadence toggle
 *   4. POST /signup/complete     → create Stripe customer + trialing subscription + tenant + user
 *
 * If Stripe is not configured (dev environment), steps 3 & 4 skip payment collection
 * and immediately create the tenant with no subscription. Master admin can attach
 * billing later via the billing portal.
 *
 * Session state (30 min TTL):
 *   pending_signup = [name, shop_name, phone, subdomain, email, password_hash, plan, stashed_at]
 */
class OnboardingController extends Controller
{
    public function index()
    {
        return redirect()->route('marketing.home');
    }

    public function login()
    {
        return view('platform.login');
    }

    // ==================================================================
    // Step 1: GET /signup
    // ==================================================================

    public function signup(Request $request)
    {
        // Capture quiz attribution from URL params (set by plan quiz modal).
        // Stashed in session so it survives the multi-step signup flow.
        if ($request->query('quiz_session')) {
            $request->session()->put('quiz_attribution', [
                'session_id' => substr((string) $request->query('quiz_session'), 0, 64),
                'tags'       => array_filter(array_map(
                    fn($t) => substr(trim($t), 0, 32),
                    explode(',', (string) $request->query('quiz_tags', ''))
                )),
            ]);
        }

        return view('platform.signup', [
            'plan'       => $request->query('plan', 'starter'),
            'planPrices' => config('intake.plan_prices'),
        ]);
    }

    // ==================================================================
    // Step 2: POST /signup
    // ==================================================================

    public function processSignup(Request $request)
    {
        $request->validate([
            'name'      => ['required', 'string', 'max:255'],
            'shop_name' => ['required', 'string', 'max:255'],
            'phone'     => ['nullable', 'string', 'max:32'],
            'subdomain' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9\-]{1,61}[a-z0-9]$/', 'unique:tenants,subdomain'],
            'email'     => ['required', 'email', 'max:255'],
            'password'  => ['required', 'string', 'min:8', 'confirmed'],
            'plan'      => ['required', 'in:starter,branded,scale'],
        ]);

        // Reserved-subdomain check (platform, admin, api, etc.)
        $reserved = config('intake.reserved_subdomains', []);
        if (in_array($request->input('subdomain'), $reserved)) {
            return back()->withInput()->withErrors(['subdomain' => 'That subdomain is reserved.']);
        }

        // Stash in session. Password is hashed now so we don't carry plaintext.
        $request->session()->put('pending_signup', [
            'name'            => $request->input('name'),
            'shop_name'       => $request->input('shop_name'),
            'phone'           => $request->input('phone'),
            'subdomain'       => strtolower($request->input('subdomain')),
            'email'           => strtolower($request->input('email')),
            'password_hash'   => Hash::make($request->input('password')),
            'plan'            => $request->input('plan'),
            'stashed_at'      => now()->timestamp,
        ]);

        return redirect()->route('platform.signup.payment');
    }

    // ==================================================================
    // Step 3: GET /signup/payment
    // ==================================================================

    public function paymentStep(Request $request, StripeBillingService $stripe)
    {
        $pending = $this->loadPendingSignup($request);
        if (! $pending) {
            return redirect()->route('platform.signup')
                ->withErrors(['general' => 'Your signup session expired. Please start again.']);
        }

        // If Stripe isn't configured, skip payment and create the tenant immediately.
        // This preserves the pre-Stripe dev experience.
        if (! $stripe->isConfigured()) {
            return $this->createTenantWithoutBilling($request, $pending);
        }

        $settings    = BillingSettings::current();
        $planPrices  = config('intake.plan_prices');

        return view('platform.signup-payment', [
            'pending'          => $pending,
            'planPrice'        => $planPrices[$pending['plan']] / 100,
            'publishableKey'   => $settings->activePublishableKey(),
            'isTestMode'       => ! $settings->isLive(),
        ]);
    }

    // ==================================================================
    // Step 4: POST /signup/complete
    // ==================================================================

    public function completeSignup(Request $request, StripeBillingService $stripe)
    {
        $pending = $this->loadPendingSignup($request);
        if (! $pending) {
            return redirect()->route('platform.signup')
                ->withErrors(['general' => 'Your signup session expired. Please start again.']);
        }

        $request->validate([
            'payment_method_id' => ['required', 'string', 'starts_with:pm_'],
            'cadence'           => ['required', 'in:monthly,annual'],
        ]);

        // Double-check Stripe is configured (may have changed since paymentStep).
        if (! $stripe->isConfigured()) {
            return $this->createTenantWithoutBilling($request, $pending);
        }

        // ---- 1. Create Stripe customer ----
        try {
            $customerId = $this->createPreTenantCustomer(
                $stripe,
                $pending['email'],
                $pending['name'],
                $pending['subdomain'],
                $pending['shop_name'],
            );
        } catch (\Throwable $e) {
            Log::error('Signup: Stripe customer creation failed', [
                'email' => $pending['email'],
                'error' => $e->getMessage(),
            ]);
            return back()->withErrors([
                'general' => 'We couldn\'t set up billing right now. Please try again or contact support.',
            ]);
        }

        // ---- 2. Create trialing subscription with PM attached ----
        try {
            $paymentMethodId = $request->input('payment_method_id');

            // Attach the payment method to the customer first. Stripe requires
            // the PM to be owned by the customer before it can be used as the
            // subscription's default_payment_method.
            $stripe->attachPaymentMethod($customerId, $paymentMethodId);

            $subscription = $stripe->createTrialingSubscription(
                customerId: $customerId,
                tier: $pending['plan'],
                cadence: $request->input('cadence'),
                trialDays: 14,
                paymentMethodId: $paymentMethodId,
            );
        } catch (\Throwable $e) {
            Log::error('Signup: Stripe subscription creation failed', [
                'email'       => $pending['email'],
                'customer_id' => $customerId,
                'error'       => $e->getMessage(),
            ]);
            return back()->withErrors([
                'general' => 'Your card couldn\'t be verified. Please check your details or try a different card.',
            ]);
        }

        // ---- 3. Create tenant + owner user in a transaction ----
        try {
            [$tenant, $user] = DB::transaction(function () use ($pending, $customerId, $subscription, $request) {
                return $this->createTenantWithStripe(
                    $pending,
                    $customerId,
                    $subscription,
                    $request->input('cadence'),
                );
            });
        } catch (\Throwable $e) {
            Log::error('Signup: Tenant creation failed AFTER Stripe succeeded — orphaned Stripe records', [
                'email'           => $pending['email'],
                'customer_id'     => $customerId,
                'subscription_id' => $subscription->id,
                'error'           => $e->getMessage(),
            ]);
            return redirect()->route('platform.signup')->withErrors([
                'general' => 'Your payment was processed but we hit a snag creating your account. '
                    . 'Please contact support with this reference: ' . substr($customerId, -8)
                    . '. We\'ll get you sorted.',
            ]);
        }

        // ---- 4. Clear session, issue login token, redirect ----
        $request->session()->forget('pending_signup');
        return $this->redirectToTenantAdmin($tenant, $user);
    }

    // ==================================================================
    // AJAX subdomain availability check
    // ==================================================================

    public function checkSubdomain(Request $request)
    {
        $slug     = strtolower(trim($request->input('subdomain', '')));
        $reserved = config('intake.reserved_subdomains', []);

        if (!preg_match('/^[a-z0-9][a-z0-9\-]{1,61}[a-z0-9]$/', $slug)) {
            return response()->json(['available' => false, 'reason' => 'invalid']);
        }
        if (in_array($slug, $reserved)) {
            return response()->json(['available' => false, 'reason' => 'reserved']);
        }
        $taken = Tenant::where('subdomain', $slug)->exists();
        return response()->json(['available' => !$taken, 'reason' => $taken ? 'taken' : null]);
    }

    // ==================================================================
    // Private helpers
    // ==================================================================

    /**
     * Load pending_signup from session. Returns null if missing or expired.
     * Expiry: 30 minutes from stash time.
     */
    private function loadPendingSignup(Request $request): ?array
    {
        $pending = $request->session()->get('pending_signup');
        if (! $pending) return null;

        $age = now()->timestamp - ($pending['stashed_at'] ?? 0);
        if ($age > 1800) { // 30 min
            $request->session()->forget('pending_signup');
            return null;
        }

        return $pending;
    }

    /**
     * Create a Stripe customer before the tenant exists in DB.
     * Metadata references subdomain + shop_name for reconciliation.
     */
    private function createPreTenantCustomer(
        StripeBillingService $stripe,
        string $email,
        string $name,
        string $subdomain,
        string $shopName,
    ): string {
        // Use a reflection-free direct SDK call since StripeBillingService::createCustomer
        // requires an already-created Tenant model. We bypass that for pre-tenant signup.
        $client = new \Stripe\StripeClient([
            'api_key'        => BillingSettings::current()->activeSecretKey(),
            'stripe_version' => '2024-06-20',
        ]);

        $customer = $client->customers->create(
            [
                'email' => $email,
                'name' => $name,
                'metadata' => [
                    'signup_subdomain' => $subdomain,
                    'shop_name'        => $shopName,
                    'source'           => 'intake_signup',
                ],
            ],
            [
                // Idempotency key ties to email + subdomain so a page refresh during
                // customer creation doesn't make duplicates.
                'idempotency_key' => 'signup-customer-' . hash('sha256', $email . '|' . $subdomain),
            ]
        );

        return $customer->id;
    }

    /**
     * Create Tenant + TenantUser inside a transaction and return both.
     */
    private function createTenantWithStripe(
        array $pending,
        string $customerId,
        \Stripe\Subscription $subscription,
        string $cadence,
    ): array {
        $trialEndsAt = $subscription->trial_end
            ? \Carbon\Carbon::createFromTimestamp($subscription->trial_end)
            : now()->addDays(14);

        $tenant = Tenant::create([
            'name'                        => $pending['shop_name'],
            'subdomain'                   => $pending['subdomain'],
            'plan_tier'                   => $pending['plan'],
            'stripe_customer_id'          => $customerId,
            'stripe_subscription_id'      => $subscription->id,
            'stripe_subscription_cadence' => $cadence,
            'trial_ends_at'               => $trialEndsAt,
            'subscription_status'         => $subscription->status,
            'onboarding_status'           => 'pending',
            'currency'                    => 'USD',
            'currency_symbol'             => '$',
            'accent_color'                => '#BEF264',
            'booking_window_days'         => 60,
            'min_notice_hours'            => 24,
            'booking_mode'                => 'drop_off',
            'settings'                    => ['onboarding_step' => 'branding', 'admin_theme' => 'a'],
        ]);

        $user = TenantUser::create([
            'tenant_id'  => $tenant->id,
            'name'       => $pending['name'],
            'email'      => $pending['email'],
            'phone'      => $pending['phone'],
            'password'   => $pending['password_hash'],
            'role'       => 'owner',
            'is_active'  => true,
        ]);

        return [$tenant, $user];
    }

    /**
     * Dev fallback: create tenant without billing when Stripe not configured.
     */
    private function createTenantWithoutBilling(Request $request, array $pending)
    {
        try {
            [$tenant, $user] = DB::transaction(function () use ($pending) {
                $tenant = Tenant::create([
                    'name'                => $pending['shop_name'],
                    'subdomain'           => $pending['subdomain'],
                    'plan_tier'           => $pending['plan'],
                    'onboarding_status'   => 'pending',
                    'currency'            => 'USD',
                    'currency_symbol'     => '$',
                    'accent_color'        => '#BEF264',
                    'booking_window_days' => 60,
                    'min_notice_hours'    => 24,
                    'booking_mode'        => 'drop_off',
                    'settings'            => ['onboarding_step' => 'branding', 'admin_theme' => 'a'],
                ]);

                $user = TenantUser::create([
                    'tenant_id'  => $tenant->id,
                    'name'       => $pending['name'],
                    'email'      => $pending['email'],
                    'phone'      => $pending['phone'],
                    'password'   => $pending['password_hash'],
                    'role'       => 'owner',
                    'is_active'  => true,
                ]);

                return [$tenant, $user];
            });
        } catch (\Throwable $e) {
            Log::error('Signup: Tenant creation failed (no-billing path)', [
                'email' => $pending['email'],
                'error' => $e->getMessage(),
            ]);
            return redirect()->route('platform.signup')->withErrors([
                'general' => 'We hit a snag creating your account. Please try again.',
            ]);
        }

        $request->session()->forget('pending_signup');
        return $this->redirectToTenantAdmin($tenant, $user);
    }

    /**
     * Generate one-time token and redirect to tenant's admin subdomain.
     * Also applies quiz attribution tags if the signup came from the plan quiz.
     */
    private function redirectToTenantAdmin(Tenant $tenant, TenantUser $user)
    {
        // Apply quiz attribution if this signup was quiz-sourced.
        $attribution = request()->session()->pull('quiz_attribution');
        if ($attribution && ! empty($attribution['tags'])) {
            $this->applyQuizTags($tenant, $attribution);
        }

        $token = Str::random(40);
        Cache::put(
            'onboarding_token_' . $token,
            ['user_id' => $user->id, 'tenant_id' => $tenant->id],
            now()->addMinutes(5)
        );

        $tenantUrl = 'https://' . $tenant->subdomain . '.' . config('intake.domain')
            . '/admin?token=' . $token;

        return redirect($tenantUrl);
    }

    /**
     * Apply quiz attribution tags to a freshly-created tenant and mark the
     * corresponding quiz completion as converted. Non-blocking — failures
     * log but don't interrupt signup.
     *
     * @param  Tenant  $tenant
     * @param  array   $attribution  ['session_id' => string, 'tags' => string[]]
     */
    private function applyQuizTags(Tenant $tenant, array $attribution): void
    {
        // Whitelist of tags the quiz is allowed to auto-apply.
        $allowed = ['quiz-signup', 'enterprise-quiz', 'high-volume', 'multi-location', 'needs-setup-help'];

        try {
            foreach ($attribution['tags'] as $tag) {
                if (! in_array($tag, $allowed, true)) continue;

                \DB::table('tenant_tags')->insertOrIgnore([
                    'tenant_id' => $tenant->id,
                    'tag'       => $tag,
                    'source'    => 'quiz',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Mark the matching quiz completion(s) as converted.
            if (! empty($attribution['session_id'])) {
                \App\Models\QuizCompletion::where('session_id', $attribution['session_id'])
                    ->whereNull('converted_to_signup_at')
                    ->update([
                        'converted_to_signup_at' => now(),
                        'converted_tenant_id'    => $tenant->id,
                    ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Quiz tag application failed', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
