<?php
// MARKER-PATCH-261

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Tenant\SiteTemplateService;
use App\Support\SiteTemplate;
use Illuminate\Http\Request;

class SiteTemplateController extends Controller
{
    public function __construct(
        protected SiteTemplateService $templates,
    ) {}

    public function index(Request $request)
    {
        $tenant = tenant();
        $prev   = $tenant->design_tokens['_prev'] ?? null;

        return view('tenant.templates.index', [
            'templates' => SiteTemplate::all(),
            'current'   => $tenant->site_template ?? 'custom',
            'hasPrev'   => is_array($prev),
            'prevName'  => is_array($prev)
                ? SiteTemplate::name($prev['site_template'] ?? 'custom')
                : null,
        ]);
    }

    public function apply(Request $request, string $key)
    {
        if (!$this->templates->apply(tenant(), $key)) {
            return back()->with('flash_error', 'That template no longer exists.');
        }

        return redirect()
            ->route('tenant.templates.index')
            ->with('flash', SiteTemplate::name($key) . ' applied. Your site has been restyled — page content is unchanged.');
    }

    public function revert(Request $request)
    {
        if (!$this->templates->revert(tenant())) {
            return back()->with('flash_error', 'Nothing to revert to.');
        }

        return redirect()
            ->route('tenant.templates.index')
            ->with('flash', 'Reverted to your previous design.');
    }
}
