<?php

namespace App\Http\Controllers\Tenant;

// MARKER-REWIND — history drawer backend.

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantPage;
use App\Models\Tenant\TenantPageRevision;
use App\Services\Tenant\PageRevisionService;
use Illuminate\Http\Request;

class PageRevisionController extends Controller
{
    public function __construct(private PageRevisionService $revisions) {}

    public function index(Request $request, string $id)
    {
        $tenant = tenant();

        TenantPage::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        $rows = TenantPageRevision::where('tenant_id', $tenant->id)
            ->where('page_id', $id)
            ->orderByDesc('created_at')->limit(PageRevisionService::KEEP)->get();

        return response()->json([
            'revisions' => $rows->map(fn ($r) => [
                'id'       => $r->id,
                'label'    => $r->label,
                'actor'    => $r->actor_name,
                'sections' => $r->section_count,
                'when'     => $r->created_at?->diffForHumans(),
                'exact'    => $r->created_at ? tlocal_datetime($r->created_at, 'M j, Y g:i A') : null,
            ])->all(),
        ]);
    }

    public function restore(Request $request, string $id, string $revisionId)
    {
        $tenant = tenant();

        $rev = TenantPageRevision::where('tenant_id', $tenant->id)
            ->where('page_id', $id)->where('id', $revisionId)->firstOrFail();

        $page = $this->revisions->restore($rev);

        return redirect()->route('tenant.pages.edit', $page->id)
            ->with('success', 'Rewound to "' . $rev->label . '". You can undo this from History.');
    }
}
