<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\PageBuilderController;
use App\Models\Tenant;
use App\Models\Tenant\TenantPage;
use Illuminate\Http\Request;

/**
 * MarketingPageController
 *
 * Bridges the master admin to the existing tenant page builder editor so
 * marketing pages (served under the platform tenant) can be edited using
 * the same three-column live preview UI.
 *
 * Flow:
 *   1. Master admin clicks "Edit content" in Filament
 *   2. This controller binds the platform tenant into the container so
 *      PageBuilderController::index() resolves tenant() correctly
 *   3. Hands off to the existing editor view, which renders as usual
 *
 * Auth: the `web` auth middleware on the route ensures only logged-in
 * master admins can reach this.
 */
class MarketingPageController extends Controller
{
    public function editContent(Request $request, string $pageId, PageBuilderController $builder)
    {
        $platform = Tenant::where('is_platform', true)->firstOrFail();

        // Make sure the requested page actually belongs to the platform tenant
        // — defense-in-depth against accidentally editing a real tenant page.
        $page = TenantPage::where('tenant_id', $platform->id)
            ->where('id', $pageId)
            ->firstOrFail();

        // Bind the platform tenant for this request. PageBuilderController::index
        // calls tenant() which resolves from the 'tenant' container key.
        app()->instance('tenant', $platform);
        view()->share('currentTenant', $platform);

        // Re-merge the `edit=pageId` query param and call into the existing
        // editor. This reuses the tenant editor's Blade view wholesale.
        $request->merge(['edit' => $page->id]);

        return $builder->index($request);
    }
}
