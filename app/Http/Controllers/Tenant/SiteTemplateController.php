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

        $name = SiteTemplate::name($key);
        $msg  = $name . ' applied. Your site has been restyled — page content is unchanged.';

        // MARKER-REWIND — rebuilding the homepage replaces every section, so
        // take a labelled restore point first. This is what makes the rebuild
        // a safe action instead of a one-way door.
        if ($request->boolean('seed_layout')) {
            $home = \App\Models\Tenant\TenantPage::where('tenant_id', tenant()->id)
                ->where('is_home', true)->first();
            if ($home) {
                app(\App\Services\Tenant\PageRevisionService::class)
                    ->snapshot($home, 'Before applying ' . $name . ' layout', true);
            }
        }

        // MARKER-PATCH-264 — opt-in: also rebuild the home page from the blueprint.
        if ($request->boolean('seed_layout') && $this->templates->seedLayout(tenant(), $key)) {
            $msg = $name . ' applied. Your homepage was rebuilt with this template’s layout and restyled to match. Other pages are untouched.';
        }

        return redirect()->route('tenant.templates.index')->with('flash', $msg);
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

    /**
     * MARKER-CUSTOMIZER — save per-tenant token overrides.
     *
     * Only values that differ from the active template's own token are stored,
     * so "reset to template default" is simply the absence of a key and a
     * later template change still moves anything untouched. The five columns
     * stay the source of truth for their tokens (booking, the customer portal
     * and transactional email all read them), so those are written through to
     * the columns rather than into design_tokens.
     */
    public function customize(Request $request)
    {
        $tenant = tenant();

        $data = $request->validate([
            'accent'            => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'text'              => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'bg'                => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'surface'           => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'muted'             => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'hero_bg'           => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'hero_text'         => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'font_heading'      => ['nullable', 'string', 'max:40'],
            'font_body'         => ['nullable', 'string', 'max:40'],
            'heading_weight'    => ['nullable', 'integer', 'min:300', 'max:900'],
            'heading_transform' => ['nullable', 'in:none,uppercase'],
            'button_style'      => ['nullable', 'in:solid,outline,ghost'],
            'button_radius'     => ['nullable', 'integer', 'min:0', 'max:24'],
        ]);

        $template = $tenant->site_template
            ? (\App\Support\SiteTemplate::tokens($tenant->site_template) ?? [])
            : [];

        // Preserve the template-revert snapshot; it is not a token.
        $stored = (array) ($tenant->design_tokens ?? []);
        $next   = array_key_exists('_prev', $stored) ? ['_prev' => $stored['_prev']] : [];

        $columns = ['accent' => 'accent_color', 'text' => 'text_color', 'bg' => 'bg_color',
                    'font_heading' => 'font_heading', 'font_body' => 'font_body'];

        foreach ($data as $key => $value) {
            if ($value === null || $value === '') {
                continue; // absent = use the template default
            }

            if (isset($columns[$key])) {
                $tenant->{$columns[$key]} = $value;
                continue;
            }

            $default = $template[$key] ?? null;
            if ($default === null || (string) $default !== (string) $value) {
                $next[$key] = $value;
            }
        }

        $tenant->design_tokens = $next;
        $tenant->save();

        return redirect()->route('tenant.templates.index')
            ->with('flash', 'Your look has been saved and is live on your site.');
    }
}
