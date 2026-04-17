<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\PageBuilderController;
use App\Models\Tenant;
use App\Models\Tenant\TenantPage;
use Illuminate\Http\Request;

/**
 * Bridges the master admin to the tenant page builder for marketing pages.
 *
 * The tenant admin layout (layouts.tenant.app) depends on a few view
 * globals that the tenant subdomain middleware normally sets:
 *   - $adminTheme    (light|dark — set by ApplyTenantTheme middleware)
 *   - $currentTenant (set by ResolveTenant middleware)
 * We share those manually here so the editor view renders for master
 * admins at intake.works/admin/marketing-pages/.../edit-content.
 */
class MarketingPageController extends Controller
{
    public function editContent(Request $request, string $pageId, PageBuilderController $builder)
    {
        $platform = Tenant::where('is_platform', true)->firstOrFail();

        TenantPage::where('tenant_id', $platform->id)
            ->where('id', $pageId)
            ->firstOrFail();

        $this->bindPlatform($platform);

        $request->merge(['edit' => $pageId]);

        return $builder->index($request);
    }

    public function store(Request $request, PageBuilderController $builder)
    {
        $platform = Tenant::where('is_platform', true)->firstOrFail();
        $this->bindPlatform($platform);
        return $builder->store($request);
    }

    private function bindPlatform(Tenant $platform): void
    {
        app()->instance('tenant', $platform);
        view()->share('currentTenant', $platform);
        view()->share('isMarketing', true);

        // Tenant admin layout expects this from ApplyTenantTheme middleware.
        view()->share('adminTheme', 'dark');
    }
}
