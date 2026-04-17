<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\PageBuilderController;
use App\Models\Tenant;
use App\Models\Tenant\TenantPage;
use Illuminate\Http\Request;

/**
 * Bridges the master admin to the existing tenant page builder editor
 * so marketing pages (served under the platform tenant) can be edited
 * using the same three-column editor UI.
 *
 * Sets an `isMarketing` flag the editor view checks to swap route URLs
 * from tenant-subdomain routes to master-admin routes.
 */
class MarketingPageController extends Controller
{
    public function editContent(Request $request, string $pageId, PageBuilderController $builder)
    {
        $platform = Tenant::where('is_platform', true)->firstOrFail();

        $page = TenantPage::where('tenant_id', $platform->id)
            ->where('id', $pageId)
            ->firstOrFail();

        app()->instance('tenant', $platform);
        view()->share('currentTenant', $platform);

        // Tell the editor view it's in marketing/admin context so it uses
        // the admin routes (no subdomain param) instead of tenant routes.
        view()->share('isMarketing', true);

        $request->merge(['edit' => $page->id]);

        return $builder->index($request);
    }
}
