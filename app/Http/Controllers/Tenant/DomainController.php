<?php

namespace App\Http\Controllers\Tenant;

use App\Exceptions\CloudflareException;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Tenant\TenantDomain;
use App\Services\DomainProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Tenant-facing controller for custom domain management.
 *
 * All routes live under /admin/settings/domains and require the tenant
 * to be resolved (handled upstream by ResolveTenant middleware).
 */
class DomainController extends Controller
{
    public function __construct(
        private readonly DomainProvisioningService $provisioning,
    ) {}

    /**
     * GET /admin/settings/domains
     *
     * Lists all domains the tenant has registered. Includes the subdomain
     * (default, always present, never editable) plus any custom domains.
     */
    public function index(): View
    {
        $tenant = $this->getTenant();

        $domains = TenantDomain::where('tenant_id', $tenant->id)
            ->orderBy('is_primary', 'desc')
            ->orderBy('created_at')
            ->get();

        $limit = $this->limitForTenant($tenant);
        $usage = $domains->count();

        return view('tenant.settings.domains.index', compact(
            'tenant', 'domains', 'limit', 'usage'
        ));
    }

    /**
     * POST /admin/settings/domains
     *
     * Adds a new domain. Validates against per-tier limit before calling
     * the provisioning service.
     */
    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->getTenant();

        $data = $request->validate([
            'hostname'   => ['required', 'string', 'max:253', 'regex:/^(?!-)[a-z0-9.-]+(?<!-)$/i'],
            'role'       => ['nullable', 'in:admin,booking,both'],
            'is_primary' => ['nullable', 'boolean'],
            'alias_mode' => ['nullable', 'in:redirect,serve_direct'],
        ]);

        // Limit check before we touch Cloudflare.
        $current = TenantDomain::where('tenant_id', $tenant->id)->count();
        $limit = $this->limitForTenant($tenant);
        if ($limit !== null && $current >= $limit) {
            return redirect()
                ->route('tenant.domains.index', [])
                ->withErrors([
                    'hostname' => "You're using all {$limit} of your domain slots. "
                        . "Upgrade your plan to add more.",
                ]);
        }

        $hostname = strtolower(trim($data['hostname']));

        // Sanity checks beyond the regex.
        if (str_contains($hostname, '..') || str_ends_with($hostname, '.')) {
            return back()->withErrors(['hostname' => 'Invalid hostname format.']);
        }

        // Reject our own platform domains.
        $rootDomain = (string) config('intake.domain', 'intake.works');
        if ($hostname === $rootDomain || str_ends_with($hostname, '.' . $rootDomain)) {
            return back()->withErrors([
                'hostname' => "You can't add an {$rootDomain} domain — that's our platform domain. "
                    . "Add a domain you own and control.",
            ]);
        }

        try {
            $this->provisioning->createForTenant(
                $tenant,
                $hostname,
                [
                    'role'       => $data['role'] ?? 'both',
                    'is_primary' => (bool) ($data['is_primary'] ?? false),
                    'alias_mode' => $data['alias_mode'] ?? 'redirect',
                ],
            );
        } catch (CloudflareException $e) {
            $message = match ($e->errorCode) {
                'hostname_taken'   => "That domain is already attached to another Intake tenant. "
                    . "If you own it, contact support.",
                'invalid_hostname' => "Cloudflare rejected that hostname. Check spelling and try again.",
                'rate_limited'     => "We're being rate-limited by Cloudflare. Try again in a minute.",
                'not_configured'   => "Custom domains aren't set up on our platform yet. Contact support.",
                default            => "Couldn't register that domain right now: " . $e->getMessage(),
            };
            return back()->withErrors(['hostname' => $message]);
        }

        return redirect()
            ->route('tenant.domains.index', [])
            ->with('success', "Added {$hostname}. Add the DNS records to finish setup.");
    }

    /**
     * GET /admin/settings/domains/{id}
     *
     * Detail page showing DNS records, status, and recent state.
     */
    public function show(string $id): View
    {
        $tenant = $this->getTenant();

        $domain = TenantDomain::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        // Pull the latest CF state on every show. Best-effort; if CF is down
        // we still render with the cached row.
        try {
            $this->provisioning->syncFromCloudflare($domain);
            $domain->refresh();
        } catch (\Throwable $e) {
            Log::warning('[TenantDomains] inline sync failed during show', [
                'tenant_id' => $tenant->id,
                'hostname'  => $domain->hostname,
                'error'     => $e->getMessage(),
            ]);
        }

        // DNS records the tenant needs to add.
        // CNAME target is the platform's CF fallback origin from services.cloudflare.fallback_origin.
        $cnameTarget = (string) config('services.cloudflare.fallback_origin', 'link.intake.works');

        return view('tenant.settings.domains.show', compact(
            'tenant', 'domain', 'cnameTarget'
        ));
    }

    /**
     * DELETE /admin/settings/domains/{id}
     *
     * Removes a domain. Tears down CF hostname + deletes local row.
     */
    public function destroy(string $id): RedirectResponse
    {
        $tenant = $this->getTenant();

        $domain = TenantDomain::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $hostname = $domain->hostname;
        $this->provisioning->remove($domain);

        return redirect()
            ->route('tenant.domains.index', [])
            ->with('success', "Removed {$hostname}.");
    }

    /**
     * POST /admin/settings/domains/{id}/sync
     *
     * Manual "check now" — pulls latest from Cloudflare and re-renders.
     * Returns JSON so the page can update without a full reload.
     */
    public function sync(string $id): JsonResponse
    {
        $tenant = $this->getTenant();

        $domain = TenantDomain::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        try {
            $changed = $this->provisioning->syncFromCloudflare($domain);
            $domain->refresh();
        } catch (CloudflareException $e) {
            return response()->json([
                'ok'    => false,
                'error' => $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'ok'      => true,
            'changed' => $changed,
            'status'  => $domain->status,
            'last_check_at' => $domain->last_check_at?->diffForHumans(),
            'last_error_message' => $domain->last_error_message,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────

    private function getTenant(): Tenant
    {
        return app('tenant');
    }

    /**
     * Resolve domain limit for a tenant.
     * Null = unlimited. Falls back to starter (1) if tier is unrecognized.
     */
    private function limitForTenant(Tenant $tenant): ?int
    {
        $limits = (array) config('intake.domain_limits', []);
        $tier = $tenant->plan_tier ?? 'starter';

        if (!array_key_exists($tier, $limits)) {
            return 1; // unknown tier = starter-equivalent
        }

        return $limits[$tier]; // may be null (unlimited)
    }
}
