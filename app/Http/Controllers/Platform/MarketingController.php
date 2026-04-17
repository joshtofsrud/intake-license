<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Tenant\TenantPage;
use App\Models\Tenant\TenantNavItem;
use Illuminate\Http\Request;

/**
 * Marketing site (intake.works) — served from the platform tenant.
 *
 * The platform tenant is the row with is_platform=true. Its TenantPages
 * back every marketing URL. This controller is a thin lookup that
 * resolves the page by slug and hands off to the shared section
 * rendering pipeline used by tenant public sites.
 *
 * URL → page slug mapping:
 *   /                → 'home'
 *   /pricing         → 'pricing'
 *   /features        → 'features'
 *   /contact         → 'contact'
 *   /docs            → 'docs'
 *   /for/{industry}  → industry template + pack merge (see forIndustry)
 *   /{slug}          → any custom page added via the marketing editor
 */
class MarketingController extends Controller
{
    public function home()                { return $this->renderPage('home'); }
    public function pricing()             { return $this->renderPage('pricing'); }
    public function features()            { return $this->renderPage('features'); }
    public function docs()                { return $this->renderPage('docs'); }

    /**
     * Generic slug handler — supports any custom page added via the editor.
     * Registered last in the route file as a catch-all.
     */
    public function show(string $slug)
    {
        // Guard against accessing internal template pages directly
        if (str_starts_with($slug, '__')) abort(404);

        return $this->renderPage($slug);
    }

    /**
     * /for/{industry} — industry-specific landing page.
     *
     * Looks up the industry pack, then renders the __industry_template
     * page with the pack's values substituted into {industry_*} tokens.
     * Falls back to 404 if the slug isn't a known pack.
     */
    public function forIndustry(string $industry)
    {
        $packs = config('industry_packs', []);
        if (! isset($packs[$industry])) abort(404);

        $pack = $packs[$industry];

        $tenant = $this->platformTenant();
        $template = TenantPage::where('tenant_id', $tenant->id)
            ->where('slug', '__industry_template')
            ->first();

        if (! $template) abort(404, 'Industry template not seeded.');

        // Clone sections and run token replacement. We never persist the
        // cloned rows — they're built in-memory per request. Editing the
        // template in the admin changes every /for/X page at once.
        $sections = $template->sections()->where('is_visible', true)->get()->map(function ($s) use ($pack) {
            $s->content = $this->substituteTokens($s->content, $pack);
            return $s;
        });

        $navItems = TenantNavItem::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')->get();

        // Override page metadata so SEO title/description reflect the industry
        $template->meta_title       = "Booking software for {$pack['name']} — Intake";
        $template->meta_description = $pack['tagline'];

        return view('marketing.page', [
            'page'     => $template,
            'sections' => $sections,
            'navItems' => $navItems,
            'tenant'   => $tenant,
            'industry' => $pack,
        ]);
    }

    /**
     * Handle the contact form submission.
     *
     * The contact page is now rendered via the generic renderPage('contact')
     * flow; this method only handles the POST.
     */
    public function contact(Request $request)
    {
        if ($request->isMethod('get')) {
            return $this->renderPage('contact');
        }

        $request->validate([
            'name'    => ['required', 'string', 'max:255'],
            'email'   => ['required', 'email'],
            'message' => ['required', 'string', 'max:3000'],
        ]);

        // TODO: queue an email to support@intake.works once mail is wired up
        return back()->with('contact_success', true);
    }

    // ================================================================
    // Internals
    // ================================================================

    private function renderPage(string $slug)
    {
        $tenant = $this->platformTenant();

        $page = TenantPage::where('tenant_id', $tenant->id)
            ->where('slug', $slug)
            ->where('is_published', true)
            ->first();

        if (! $page) abort(404);

        $sections = $page->sections()->where('is_visible', true)->get();
        $navItems = TenantNavItem::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')->get();

        return view('marketing.page', [
            'page'     => $page,
            'sections' => $sections,
            'navItems' => $navItems,
            'tenant'   => $tenant,
            'industry' => null,
        ]);
    }

    /**
     * Look up the platform tenant — cached per request since every marketing
     * request needs it.
     */
    private function platformTenant(): Tenant
    {
        static $cached = null;
        if ($cached) return $cached;

        $cached = Tenant::where('is_platform', true)->first();
        if (! $cached) {
            abort(500, 'Platform tenant not seeded. Run: php artisan db:seed --class=PlatformTenantSeeder');
        }
        return $cached;
    }

    /**
     * Recursively replace {industry_*} tokens in the section content array
     * with values from the industry pack.
     *
     *   {industry_name}             → $pack['name']
     *   {industry_tagline}          → $pack['tagline']
     *   {industry_services_blurb}   → $pack['services_blurb']
     *   {industry_workflow_blurb}   → $pack['workflow_blurb']
     *   {industry_slug}             → $pack['slug']
     *   {industry_icon}             → $pack['icon']
     */
    private function substituteTokens($value, array $pack)
    {
        if (is_array($value)) {
            return array_map(fn($v) => $this->substituteTokens($v, $pack), $value);
        }
        if (! is_string($value)) return $value;

        return preg_replace_callback('/\{industry_([a-z_]+)\}/', function ($m) use ($pack) {
            $key = $m[1];
            return match ($key) {
                'name'            => $pack['name']            ?? $m[0],
                'slug'            => $pack['slug']            ?? $m[0],
                'tagline'         => $pack['tagline']         ?? $m[0],
                'icon'            => $pack['icon']            ?? $m[0],
                'services_blurb'  => $pack['services_blurb']  ?? $m[0],
                'workflow_blurb'  => $pack['workflow_blurb']  ?? $m[0],
                'category'        => $pack['category']        ?? $m[0],
                default           => $m[0],
            };
        }, $value);
    }
}
