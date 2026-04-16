<?php

namespace App\Http\Middleware;

use App\Models\Tenant\TenantUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * ConsumeOnboardingToken
 *
 * After signup on app.intake.works, the platform OnboardingController
 * generates a one-time token, stores `{user_id, tenant_id}` in the cache
 * under `onboarding_token_{token}`, and redirects the newly-created user
 * to `https://{slug}.intake.works/admin/onboarding?token={token}`.
 *
 * Cookies set on `app.intake.works` do not carry to `{slug}.intake.works`,
 * so the user arrives unauthenticated. This middleware runs BEFORE
 * RequireTenantAuth, inspects `?token=`, and if valid:
 *
 *   1. Logs the TenantUser in via the 'tenant' guard
 *   2. Deletes the cache entry (one-shot)
 *   3. Redirects to the same URL without the token, to clean the address
 *      bar and prevent back-button re-consumption
 *
 * If the token is missing, malformed, expired, or points at a user whose
 * tenant doesn't match the resolved tenant, we fall through and let
 * RequireTenantAuth handle it — sending the user to the login page.
 * That is the correct behavior: bad token ⇒ normal login flow.
 */
class ConsumeOnboardingToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->query('token');

        // No token? Nothing to do.
        if (! $token || ! is_string($token)) {
            return $next($request);
        }

        // Already authenticated on the tenant guard? Still strip the token
        // from the URL so it doesn't linger in browser history.
        if (Auth::guard('tenant')->check()) {
            return $this->redirectWithoutToken($request);
        }

        $cacheKey = 'onboarding_token_' . $token;
        $data     = Cache::get($cacheKey);

        if (! is_array($data) || empty($data['user_id']) || empty($data['tenant_id'])) {
            // Invalid or expired — fall through to normal auth handling
            return $next($request);
        }

        // The tenant must match the host-resolved tenant, otherwise a leaked
        // token could be used to log into the wrong shop's admin.
        $currentTenant = app()->bound('tenant') ? app('tenant') : null;
        if (! $currentTenant || $currentTenant->id !== $data['tenant_id']) {
            Cache::forget($cacheKey);
            return $next($request);
        }

        $user = TenantUser::where('id', $data['user_id'])
            ->where('tenant_id', $data['tenant_id'])
            ->where('is_active', true)
            ->first();

        if (! $user) {
            Cache::forget($cacheKey);
            return $next($request);
        }

        // Log in on the tenant guard. Regenerate session ID to avoid
        // fixation attacks.
        Auth::guard('tenant')->login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        // One-shot: burn the token
        Cache::forget($cacheKey);

        return $this->redirectWithoutToken($request);
    }

    /**
     * Redirect to the current URL with the `token` query param removed.
     */
    private function redirectWithoutToken(Request $request): Response
    {
        $clean = $request->url(); // path only
        $query = $request->except('token');

        if (! empty($query)) {
            $clean .= '?' . http_build_query($query);
        }

        return redirect($clean);
    }
}
