<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\ChangelogEntry;
use App\Models\RoadmapEntry;
use App\Models\Tenant;
use App\Models\Tenant\TenantPage;
use App\Models\Tenant\TenantNavItem;
use App\Services\IndustryPackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

/**
 * MarketingController — drives every marketing page.
 *
 * Patch 45: CMS-only marketing. Every page renders via renderPage($slug).
 * The five formerly-static pages (roadmap, changelog, why-intake, contact,
 * invest) are now stored as TenantPage rows with sections.
 *
 * Dynamic content (roadmap entries, changelog entries) is fetched by the
 * special section types `roadmap_grid` and `changelog_list` at render time.
 */
class MarketingController extends Controller
{
    public function home()      { return $this->renderPage('home'); }
    public function pricing()   { return $this->renderPage('pricing'); }
    public function features()  { return $this->renderPage('features'); }
    public function whyIntake() { return $this->renderPage('why-intake'); }
    public function invest()    { return $this->renderPage('invest'); }
    public function roadmap()   { return $this->renderPage('roadmap'); }
    public function changelog() { return $this->renderPage('changelog'); }
    public function docs()      { return $this->renderPage('docs'); }

    public function show(string $slug)
    {
        if (str_starts_with($slug, '__')) abort(404);
        return $this->renderPage($slug);
    }

    public function forIndustry(string $industry)
    {
        $packService = app(IndustryPackService::class);
        $pack = $packService->get($industry);
        if (! $pack) abort(404);

        // Use the __for-industry template; substitute tokens.
        $tenant = $this->platformTenant();
        $template = TenantPage::where('tenant_id', $tenant->id)
            ->where('slug', '__for-industry')
            ->where('is_published', true)
            ->first();

        if (! $template) abort(404);

        $sections = $template->sections()->where('is_visible', true)->get()->map(function ($s) use ($pack) {
            $s->content = $this->substituteTokens($s->content, $pack);
            return $s;
        });

        $navItems = TenantNavItem::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')->get();

        return view('marketing.page', [
            'page'     => $template,
            'sections' => $sections,
            'navItems' => $navItems,
            'tenant'   => $tenant,
            'industry' => $pack,
        ]);
    }

    public function contact(Request $request)
    {
        if ($request->isMethod('GET')) {
            return $this->renderPage('contact');
        }

        // POST: validate and email. Keeps existing behavior.
        $validator = Validator::make($request->all(), [
            'name'    => 'required|string|max:120',
            'email'   => 'required|email|max:191',
            'phone'   => 'nullable|string|max:32',
            'message' => 'required|string|max:5000',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        // TODO(patch 46): wire to real mail when SMTP settings finalized.
        // For now log and redirect.
        \Log::info('Marketing contact form', $request->only(['name','email','phone','message']));

        return back()->with('status', 'Thanks! We\'ll be in touch within 1 business day.');
    }

    // Patch 45: CMS-only marketing — single render path.
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

    private function platformTenant(): Tenant
    {
        static $cached = null;
        if ($cached) return $cached;

        $cached = Tenant::where('is_platform', true)->first();
        if (! $cached) abort(503, 'No platform tenant configured');

        return $cached;
    }

    private function substituteTokens($value, array $pack)
    {
        if (is_array($value)) {
            return array_map(fn ($v) => $this->substituteTokens($v, $pack), $value);
        }
        if (! is_string($value)) return $value;

        return preg_replace_callback('/\{industry_([a-z_]+)\}/', function ($m) use ($pack) {
            return $pack[$m[1]] ?? $m[0];
        }, $value);
    }
}
