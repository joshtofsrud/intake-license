<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * ResolveTenant
 *
 * Reads the incoming host and resolves it to a Tenant model.
 * Supports two patterns:
 *
 *   1. Subdomain:     {slug}.intake.works  → match on tenants.subdomain
 *   2. Custom domain: any other host        → match on tenants.custom_domain
 *
 * Platform domains (intake.works, app.intake.works, license.intake.works, etc.)
 * are skipped — those routes don't need a tenant in scope.
 *
 * On success: binds the tenant to the IoC container as app('tenant') and
 * shares it with all views as $currentTenant.
 *
 * On failure: aborts with 404.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $host       = strtolower($request->getHost());
        $rootDomain = strtolower(config('intake.domain', 'intake.works'));
        $reserved   = config('intake.reserved_subdomains', []);

        // ----------------------------------------------------------------
        // Determine if this is a platform domain — skip tenant resolution
        // ----------------------------------------------------------------
        if ($this->isPlatformHost($host, $rootDomain, $reserved)) {
            // a customer-account link with no shop in it.
            // Emails built before carry the platform address,
            // and they sit in inboxes for months. These pages need a shop to
            // render, so they 500'd. Explain instead — and when a marketing page
            // with the slug "account-help" is published, show that, so the
            // wording is editable in master admin.
            if ($request->is('account', 'account/*')) {
                $platformId = \App\Models\Tenant::where('is_platform', true)->value('id');
                $page = $platformId
                    ? \App\Models\Tenant\TenantPage::where('tenant_id', $platformId)
                        ->where('slug', 'account-help')->where('is_published', true)->first()
                    : null;
                if ($page) {
                    return redirect('/account-help');
                }

                return response()->view('marketing.account-elsewhere', [], 404);
            }

            // MARKER-PLATFORM-HOST-404
            // Shop-site routes have no shop to serve on the platform's own
            // hosts (intake.works, www, app. and the other reserved names).
            // Anything the marketing and signup routes didn't claim used to
            // fall through to a shop controller with tenant() === null and
            // 500 (/shop, /cart, /rentals ... on app.intake.works). Two kinds
            // of request legitimately arrive here and still pass:
            //   - inbound webhooks (Stripe Connect, Postmark, Twilio,
            //     Cloudflare, SES, Dropbox Sign ...), which post to the
            //     platform host and find their shop from the payload;
            //   - the staff sign-in pages, which already redirect to the
            //     platform sign-in when there is no shop.
            if ($request->is('webhooks/*')) {
                return $next($request);
            }

            $action = (string) optional($request->route())->getActionName();
            $auth   = \App\Http\Controllers\Tenant\AuthController::class;
            if (in_array($action, [$auth . '@showLogin', $auth . '@showForgot', $auth . '@showReset'], true)) {
                return $next($request);
            }

            // www has no routes of its own; send visitors to the bare domain.
            if ($host === 'www.' . $rootDomain && in_array($request->method(), ['GET', 'HEAD'], true)) {
                return redirect()->to('https://' . $rootDomain . $request->getRequestUri(), 301);
            }

            abort(404);
        }

        // ----------------------------------------------------------------
        // Try subdomain match first
        // ----------------------------------------------------------------
        $tenant = null;

        if (str_ends_with($host, '.' . $rootDomain)) {
            $subdomain = substr($host, 0, strlen($host) - strlen('.' . $rootDomain));

            // Reject reserved subdomains that somehow slip through
            if (in_array($subdomain, $reserved, true)) {
                abort(404);
            }

            $tenant = Tenant::where('subdomain', $subdomain)
                ->where('is_active', true)
                ->first();
        }

        // ----------------------------------------------------------------
        // Fall back to custom domain match
        // query tenant_domains first; legacy column second
        // ----------------------------------------------------------------
        if (! $tenant) {
            // New path: tenant_domains table. Only matches if the domain
            // is in a status that should be serving traffic.
            $domainRow = \App\Models\Tenant\TenantDomain::where('hostname', $host)
                ->whereIn('status', ['active'])
                ->first();
            if ($domainRow) {
                $tenant = Tenant::where('id', $domainRow->tenant_id)
                    ->where('is_active', true)
                    ->first();
            }
        }

        if (! $tenant) {
            // Legacy fallback: tenants.custom_domain column. Removed in a
            // future patch once tenant_domains is canonical.
            $tenant = Tenant::where('custom_domain', $host)
                ->where('is_active', true)
                ->first();
        }

        if (! $tenant) {
            abort(404, 'Shop not found.');
        }

        // ----------------------------------------------------------------
        // Subdomain vs custom-domain enforcement
        //
        // Determine which match path produced the tenant. This drives two
        // behaviors below: admin redirect on custom domain, and the
        // session cookie Domain attribute.
        // ----------------------------------------------------------------
        $matchedViaSubdomain = str_ends_with($host, '.' . $rootDomain)
            && $tenant->subdomain === substr($host, 0, strlen($host) - strlen('.' . $rootDomain));

        // Admin is anchored to the tenant subdomain so that the master
        // admin's impersonation cookie (scoped to .intake.works) remains
        // valid. A custom-domain hit on /admin/* is redirected to the
        // canonical subdomain URL.
        if (! $matchedViaSubdomain && str_starts_with($request->path(), 'admin')) {
            $target = 'https://' . $tenant->subdomain . '.' . $rootDomain
                    . '/' . $request->path();
            if ($qs = $request->getQueryString()) {
                $target .= '?' . $qs;
            }
            return redirect($target, 301);
        }

        // Session cookie scoping. SESSION_DOMAIN=.intake.works in .env is
        // the default for subdomain requests (enables cross-subdomain
        // impersonation). On custom-domain requests we clear it so the
        // browser issues a host-only cookie — required because a cookie
        // with Domain=.intake.works cannot be set from a different
        // registrable domain (RFC 6265 §5.3 step 6).
        if (! $matchedViaSubdomain) {
            config(['session.domain' => null]);
        }

        // ----------------------------------------------------------------
        // Bind tenant into the application
        // ----------------------------------------------------------------
        app()->instance('tenant', $tenant);

        // Share with all Blade views
        view()->share('currentTenant', $tenant);

        // Tag the request so controllers/middleware can access it easily
        $request->attributes->set('tenant', $tenant);

        return $next($request);
    }

    /**
     * Is this host a platform domain (not a tenant)?
     */
    private function isPlatformHost(string $host, string $rootDomain, array $reserved): bool
    {
        // Exact root domain match: intake.works
        if ($host === $rootDomain || $host === 'www.' . $rootDomain) {
            return true;
        }

        // Reserved subdomains: app.intake.works, license.intake.works, etc.
        foreach ($reserved as $sub) {
            if ($host === $sub . '.' . $rootDomain) {
                return true;
            }
        }

        return false;
    }
}
