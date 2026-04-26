<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Tenant\TenantPage;
use App\Models\Tenant\TenantNavItem;
use App\Models\ChangelogEntry;
use App\Models\RoadmapEntry;
use Illuminate\Http\Request;

/**
 * Marketing site (intake.works) controller.
 *
 * Legacy toggle: set USE_LEGACY_MARKETING=true in .env to serve the
 * hardcoded Blade views (resources/views/marketing/*.blade.php) instead
 * of the platform-tenant page builder. Useful as a fallback while
 * iterating on the editor.
 */
class MarketingController extends Controller
{
    public function home()
    {
        if ($this->useLegacy()) {
            return view('marketing.home', $this->legacyShared());
        }
        return $this->renderPage('home');
    }

    public function pricing()
    {
        if ($this->useLegacy()) {
            return view('marketing.pricing', $this->legacyShared());
        }
        return $this->renderPage('pricing');
    }

    public function features()
    {
        if ($this->useLegacy()) {
            return view('marketing.features', $this->legacyShared());
        }
        return $this->renderPage('features');
    }

    /**
     * Public changelog — what shipped, by date.
     * Always served from the hardcoded Blade; data comes from changelog_entries.
     */
    public function changelog()
    {
        $entries = ChangelogEntry::published()
            ->orderByDesc('is_highlighted')
            ->orderByDesc('shipped_on')
            ->orderByDesc('created_at')
            ->get();

        return view('marketing.changelog', [
            'entries' => $entries,
        ] + $this->legacyShared());
    }

    /**
     * Public roadmap — what's coming, grouped by status.
     * Always served from the hardcoded Blade.
     */
    public function roadmap()
    {
        $entries = RoadmapEntry::published()
            ->orderBy('display_order')
            ->orderBy('created_at')
            ->get()
            ->groupBy('status');

        // Stable status order regardless of which buckets have entries.
        $orderedGroups = [];
        foreach (array_keys(RoadmapEntry::STATUSES) as $statusKey) {
            if (isset($entries[$statusKey]) && $entries[$statusKey]->count() > 0) {
                $orderedGroups[$statusKey] = $entries[$statusKey];
            }
        }

        return view('marketing.roadmap', [
            'groups' => $orderedGroups,
        ] + $this->legacyShared());
    }

    public function docs()
    {
        if ($this->useLegacy()) {
            return view('marketing.docs', $this->legacyShared());
        }
        return $this->renderPage('docs');
    }

    public function show(string $slug)
    {
        if (str_starts_with($slug, '__')) abort(404);

        // Legacy mode has no concept of custom slugs — fall back to 404.
        if ($this->useLegacy()) abort(404);

        return $this->renderPage($slug);
    }

    public function forIndustry(string $industry)
    {
        // Legacy mode has no /for/{industry} pages — fall back to 404.
        if ($this->useLegacy()) abort(404);

        $packs = config('industry_packs', []);
        if (! isset($packs[$industry])) abort(404);

        $pack = $packs[$industry];

        $tenant = $this->platformTenant();
        $template = TenantPage::where('tenant_id', $tenant->id)
            ->where('slug', '__industry_template')
            ->first();

        if (! $template) abort(404, 'Industry template not seeded.');

        $sections = $template->sections()->where('is_visible', true)->get()->map(function ($s) use ($pack) {
            $s->content = $this->substituteTokens($s->content, $pack);
            return $s;
        });

        $navItems = TenantNavItem::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')->get();

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

    public function contact(Request $request)
    {
        if ($request->isMethod('get')) {
            if ($this->useLegacy()) {
                return view('marketing.contact', $this->legacyShared());
            }
            return $this->renderPage('contact');
        }

        $request->validate([
            'name'    => ['required', 'string', 'max:255'],
            'email'   => ['required', 'email'],
            'message' => ['required', 'string', 'max:3000'],
        ]);

        return back()->with('contact_success', true);
    }

    // ================================================================
    // Internals
    // ================================================================

    private function useLegacy(): bool
    {
        return (bool) config('intake.use_legacy_marketing', false);
    }

    /**
     * Shared data the legacy Blade views expect. Keep in sync with what
     * the old MarketingController passed so the views render identically.
     */
    private function legacyShared(): array
    {
        $plans = config('intake.plan_prices');
        return [
            'plans' => [
                'starter' => ['price' => $plans['starter'] / 100, 'name' => 'Starter', 'slug' => 'starter'],
                'branded' => ['price' => $plans['branded'] / 100, 'name' => 'Branded', 'slug' => 'branded'],
                'scale'   => ['price' => $plans['scale']   / 100, 'name' => 'Scale',   'slug' => 'scale'],
            ],
        ];
    }

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
        if (! $cached) {
            abort(500, 'Platform tenant not seeded. Run: php artisan db:seed --class=PlatformTenantSeeder');
        }
        return $cached;
    }

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
