<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\MediaLibraryController;
use App\Http\Controllers\Tenant\PageBuilderController;
use App\Http\Controllers\Tenant\PageRevisionController;
use App\Http\Controllers\Tenant\UploadController;
use App\Models\Tenant;
use App\Models\Tenant\TenantNavItem;
use App\Models\Tenant\TenantPage;
use App\Models\Tenant\TenantPageRevision;
use App\Services\Tenant\PageRevisionService;
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

    // MARKER-MKT-PARITY — full builder parity for the marketing context.
    // Each endpoint binds the platform tenant, then delegates to the exact
    // tenant controller the tenant routes use, so behaviour stays verbatim.

    /** Inspector partial (?_inspector={sid}); full editor without it. */
    public function edit(Request $request, string $pageId, PageBuilderController $builder)
    {
        $this->bindPlatform($this->platform());
        return $builder->edit($request, $pageId);
    }

    /** Page meta + the set_published / set_in_nav / update_page ops. */
    public function update(Request $request, string $pageId, PageBuilderController $builder)
    {
        $this->bindPlatform($this->platform());
        return $builder->update($request, $pageId);
    }

    /**
     * Draft-capable iframe preview. The public URL only shows published
     * content; this renders the marketing shell for any page, with the
     * builder-sync wrappers on (and the funnel tracker off).
     */
    public function preview(Request $request, string $pageId)
    {
        $platform = $this->platform();
        $this->bindPlatform($platform);

        $page = TenantPage::where('tenant_id', $platform->id)
            ->where('id', $pageId)
            ->firstOrFail();

        $sections = $page->sections()->where('is_visible', true)->get();
        $navItems = TenantNavItem::where('tenant_id', $platform->id)
            ->orderBy('sort_order')->get();

        return view('marketing.page', [
            'page'           => $page,
            'sections'       => $sections,
            'navItems'       => $navItems,
            'tenant'         => $platform,
            'industry'       => null,
            'builderPreview' => true,
        ]);
    }

    /** Rewind drawer list (JSON). */
    public function history(Request $request, string $pageId, PageRevisionController $revisions)
    {
        $this->bindPlatform($this->platform());
        return $revisions->index($request, $pageId);
    }

    /**
     * Rewind restore. Not delegated: the tenant controller redirects to
     * tenant.pages.edit, which is the wrong destination on the apex.
     */
    public function historyRestore(Request $request, string $pageId, string $revisionId, PageRevisionService $revisions)
    {
        $platform = $this->platform();
        $this->bindPlatform($platform);

        $rev = TenantPageRevision::where('tenant_id', $platform->id)
            ->where('page_id', $pageId)
            ->where('id', $revisionId)
            ->firstOrFail();

        $page = $revisions->restore($rev);

        return redirect(url('/admin/marketing-pages/' . $page->id . '/edit-content'))
            ->with('success', 'Rewound to "' . $rev->label . '". You can undo this from History.');
    }

    /** Library picker feed (JSON), scoped to the platform tenant's media. */
    public function mediaFeed(Request $request, MediaLibraryController $media)
    {
        $this->bindPlatform($this->platform());
        return $media->feed($request);
    }

    /** Direct image upload from the builder. */
    public function upload(Request $request, UploadController $uploads)
    {
        $this->bindPlatform($this->platform());
        return $uploads->store($request);
    }

    private function platform(): Tenant
    {
        return Tenant::where('is_platform', true)->firstOrFail();
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
