<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Addon;
use App\Models\HelpCategory;
use App\Models\Tenant;
use App\Models\Tenant\TenantPage;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-HELP-TENANT — the hand-written Help & Guides page stays as the
 * landing screen; articles written in master admin appear underneath it,
 * gated per shop. Articles live on the PLATFORM tenant; the current tenant is
 * only ever the subject of the gate, never the owner.
 */
class HelpController extends Controller
{
    /** Section types that make sense in a how-to and render without a shop context. */
    public const DOC_SECTIONS = [
        'hero', 'text_image', 'step_timeline', 'faq_accordion',
        'image_gallery', 'image_carousel', 'custom_html', 'stats_row',
        'feature_grid', 'cta_banner', 'logo_bar',
    ];

    public function index(Request $request)
    {
        $tenant = tenant();
        $q      = trim((string) $request->query('q', ''));

        $articles = $this->articles();

        if ($q !== '' && $articles->isNotEmpty()) {
            // A LIKE over section JSON. Honest for a few hundred articles;
            // beyond that this wants a real index, not a longer LIKE.
            $ids = DB::table('tenant_page_sections')
                ->whereIn('page_id', $articles->pluck('id'))
                ->where('content', 'like', '%' . $q . '%')
                ->pluck('page_id')
                ->unique();

            $articles = $articles->filter(
                fn ($a) => str_contains(mb_strtolower($a->title), mb_strtolower($q)) || $ids->contains($a->id)
            );
        }

        // Gate every article before it reaches the view.
        $visible = $articles->map(function ($a) use ($tenant) {
            $state = $a->visibilityFor($tenant);
            return $state === 'hidden' ? null : ['article' => $a, 'state' => $state];
        })->filter()->values();

        return view('tenant.help.index', [
            'helpQ'          => $q,
            'helpCats'       => HelpCategory::orderBy('sort')->get(),
            'helpGrouped'    => $visible->groupBy(fn ($row) => $row['article']->help_category_id),
            'helpReadable'   => $visible->where('state', 'read')->count(),
            'helpAddonNames' => Addon::pluck('name', 'code')->all(),
        ]);
    }

    public function show(string $slug)
    {
        $tenant   = tenant();
        $platform = $this->platform();
        abort_unless($platform, 404);

        $article = TenantPage::where('tenant_id', $platform->id)
            ->howTo()
            ->where('is_published', true)
            ->where('slug', $slug)
            ->firstOrFail();

        $state = $article->visibilityFor($tenant);

        // A shop that can't see it can't reach it by URL either.
        abort_if($state === 'hidden', 404);

        return view('tenant.help.show', [
            'platform' => $platform,
            'article'  => $article,
            'locked'   => $state === 'locked',
            'missing'  => Addon::whereIn('code', $article->missingAddonsFor($tenant))->get(),
            'sections' => $state === 'read' ? $article->sections : collect(),
            'related'  => $this->related($article, $tenant),
        ]);
    }

    /** The ? button on a screen. Resolves by help_key. */
    public function forKey(string $key)
    {
        $tenant   = tenant();
        $platform = $this->platform();
        abort_unless($platform, 404);

        $article = TenantPage::where('tenant_id', $platform->id)
            ->howTo()
            ->where('is_published', true)
            ->where('help_key', $key)
            ->first();

        // No article, or one this shop can't read: the index, not a dead end.
        if (! $article || $article->visibilityFor($tenant) === 'hidden') {
            return redirect()->route('tenant.help.index');
        }

        return redirect()->route('tenant.help.article', $article->slug);
    }

    protected function platform(): ?Tenant
    {
        return Tenant::where('is_platform', true)->first();
    }

    /** Published articles in display order. */
    protected function articles(): Collection
    {
        $platform = $this->platform();
        if (! $platform) {
            return collect();
        }

        return TenantPage::query()
            ->where('tenant_id', $platform->id)
            ->howTo()
            ->where('is_published', true)
            ->orderBy('help_sort')
            ->orderBy('title')
            ->get();
    }

    protected function related(TenantPage $article, Tenant $tenant): Collection
    {
        return $this->articles()
            ->where('help_category_id', $article->help_category_id)
            ->reject(fn ($a) => $a->id === $article->id)
            ->filter(fn ($a) => $a->visibilityFor($tenant) === 'read')
            ->take(3)
            ->values();
    }
}
