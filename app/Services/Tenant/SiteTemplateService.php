<?php
// MARKER-PATCH-260

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Support\SiteTemplate;
use App\Models\Tenant\TenantPage;
use App\Models\Tenant\TenantPageSection;
use Illuminate\Support\Facades\DB;

/**
 * SiteTemplateService — applies a named template to a tenant. The ONE writer
 * for tenants.site_template / design_tokens; nothing else mutates them.
 *
 * Apply is non-destructive by design: page content (TenantPage /
 * TenantPageSection) is never touched. We only restyle — write the preset's
 * five mapped tokens onto the discrete design columns (so the public layout
 * reflects the new look immediately) and stash the full resolved bundle in
 * design_tokens. Before overwriting, we snapshot the tenant's current design
 * values under design_tokens['_prev'] so revert() can put them back.
 */
class SiteTemplateService
{
    /** Fields on tenants.* that a template drives directly. */
    private const MAPPED = [
        'accent'       => 'accent_color',
        'text'         => 'text_color',
        'bg'           => 'bg_color',
        'font_heading' => 'font_heading',
        'font_body'    => 'font_body',
    ];

    /**
     * Apply $key to $tenant. Returns true on success, false if the key is
     * unknown (caller should 404 / flash).
     */
    public function apply(Tenant $tenant, string $key): bool
    {
        $tokens = SiteTemplate::tokens($key);
        if ($tokens === null) {
            return false;
        }

        return DB::transaction(function () use ($tenant, $key, $tokens) {
            // Snapshot current design so a switch is reversible.
            // MARKER-PREVFIX — drop the existing _prev before storing. Without
            // this the snapshot contains the previous snapshot, which contains
            // the one before it: the column doubles on every apply and revert
            // still only ever reads the top level.
            $currentTokens = (array) ($tenant->design_tokens ?? []);
            unset($currentTokens['_prev']);

            $prev = [
                'site_template' => $tenant->site_template,
                'accent_color'  => $tenant->accent_color,
                'text_color'    => $tenant->text_color,
                'bg_color'      => $tenant->bg_color,
                'font_heading'  => $tenant->font_heading,
                'font_body'     => $tenant->font_body,
                'design_tokens' => $currentTokens ?: null,
            ];

            foreach (self::MAPPED as $tokenKey => $column) {
                if (isset($tokens[$tokenKey])) {
                    $tenant->{$column} = $tokens[$tokenKey];
                }
            }

            $bundle = $tokens;
            $bundle['_prev'] = $prev;

            $tenant->site_template = $key;
            $tenant->design_tokens = $bundle;
            $tenant->save();

            return true;
        });
    }

    /**
     * Revert to the design captured before the last apply(). Returns true if
     * a snapshot existed and was restored.
     */
    public function revert(Tenant $tenant): bool
    {
        $prev = $tenant->design_tokens['_prev'] ?? null;
        if (!is_array($prev)) {
            return false;
        }

        return DB::transaction(function () use ($tenant, $prev) {
            $tenant->accent_color  = $prev['accent_color'] ?? $tenant->accent_color;
            $tenant->text_color    = $prev['text_color']   ?? $tenant->text_color;
            $tenant->bg_color      = $prev['bg_color']      ?? $tenant->bg_color;
            $tenant->font_heading  = $prev['font_heading']  ?? $tenant->font_heading;
            $tenant->font_body     = $prev['font_body']     ?? $tenant->font_body;
            $tenant->site_template = $prev['site_template'] ?? 'custom';
            $tenant->design_tokens = $prev['design_tokens'] ?? null;
            $tenant->save();

            return true;
        });
    }

    /**
     * Seed the template's blueprint into the tenant's HOME page. Opt-in and
     * destructive to that one page's layout only — never other pages, never
     * customer data. Each section is based on the builder's own DEFAULTS
     * (valid placeholder content) with the blueprint's copy overlaid.
     */
    public function seedLayout(Tenant $tenant, string $key): bool
    {
        $tpl    = SiteTemplate::find($key);
        $blocks = $tpl['layout'] ?? null;
        if (!is_array($blocks)) {
            return false;
        }

        $defaults = \App\Http\Controllers\Tenant\PageBuilderController::defaults();

        // blueprint block type -> PUBLIC section partial type. Types without a
        // public partial (testimonial) are intentionally absent and skipped.
        $map = [
            'hero' => 'hero', 'cta' => 'cta_banner', 'text_image' => 'text_image',
            'gallery' => 'image_gallery', 'stats' => 'stats_row', 'steps' => 'step_timeline',
            'services' => 'services', 'feature' => 'feature_grid', 'faq' => 'faq_accordion',
            'contact' => 'contact_form', 'footer' => 'footer',
        ];

        return DB::transaction(function () use ($tenant, $blocks, $defaults, $map) {
            $home = TenantPage::where('tenant_id', $tenant->id)
                ->where('is_home', true)->first();

            if (!$home) {
                $home = TenantPage::create([
                    'tenant_id' => $tenant->id, 'title' => 'Home', 'slug' => 'home',
                    'is_home' => true, 'is_published' => false, 'is_in_nav' => false, 'nav_order' => 0,
                ]);
            }

            TenantPageSection::where('page_id', $home->id)->delete();

            $sort = 0;
            $make = function (string $type, array $content) use ($home, &$sort) {
                TenantPageSection::create([
                    'page_id' => $home->id, 'tenant_id' => $home->tenant_id,
                    'section_type' => $type, 'content' => $content,
                    'padding' => 'normal', 'is_visible' => true, 'sort_order' => $sort++,
                ]);
            };

            // Nav is implicit (not in the blueprint) — always first.
            $make('nav', $defaults['nav'] ?? []);

            foreach ($blocks as $b) {
                $type = $map[$b['type'] ?? ''] ?? null;
                if ($type === null || !isset($defaults[$type])) {
                    continue; // unsupported on the public renderer — skip
                }
                $make($type, array_merge($defaults[$type], $this->overlayCopy($type, $b)));
            }

            return true;
        });
    }

    /** Overlay a blueprint block's copy onto the right content keys per type. */
    private function overlayCopy(string $type, array $b): array
    {
        $h   = trim((string) ($b['h'] ?? ''));
        $sub = trim((string) ($b['sub'] ?? ''));
        $cta = trim((string) ($b['cta'] ?? ''));
        $out = [];

        switch ($type) {
            case 'hero':
                if ($h !== '')   $out['headline'] = $h;
                if ($sub !== '') $out['subheading'] = $sub;
                if ($cta !== '') $out['cta_primary_label'] = $cta;
                break;
            case 'cta_banner':
                if ($h !== '')   $out['headline'] = $h;
                if ($cta !== '') $out['cta_label'] = $cta;
                break;
            case 'text_image':
                if ($h !== '')   $out['heading'] = $h;
                if ($sub !== '') $out['body'] = $sub;
                break;
            case 'services':
            case 'feature_grid':
            case 'step_timeline':
            case 'stats_row':
            case 'faq_accordion':
            case 'image_gallery':
            case 'contact_form':
                if ($h !== '')   $out['heading'] = $h;
                if ($sub !== '') $out['subheading'] = $sub;
                break;
        }

        return $out;
    }
}
