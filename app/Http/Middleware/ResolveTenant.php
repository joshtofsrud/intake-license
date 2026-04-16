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
            return $next($request);
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
        // ----------------------------------------------------------------
        if (! $tenant) {
            $tenant = Tenant::where('custom_domain', $host)
                ->where('is_active', true)
                ->first();
        }

        if (! $tenant) {
            abort(404, 'Shop not found.');
        }

        // ----------------------------------------------------------------
        // Bind tenant into the application
        // ----------------------------------------------------------------
        app()->instance('tenant', $tenant);

        // Share with all Blade views
        view()->share('currentTenant', $tenant);

        // Tag the request so controllers/middleware can access it easily
        $request->attributes->set('tenant', $tenant);

        // Inject `{subdomain}` into URL::defaults so every route() call for
        // a tenant route works without having to pass `subdomain` explicitly.
        // e.g. route('tenant.dashboard') Just Works on a subdomain request.
        URL::defaults(['subdomain' => $tenant->subdomain]);

        // Also set it on the current route's parameters if a route has already
        // matched (it hasn't, usually — this runs before SubstituteBindings —
        // but it's cheap insurance for controllers that read route params).
        if ($route = $request->route()) {
            $route->setParameter('subdomain', $tenant->subdomain);
        }

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
