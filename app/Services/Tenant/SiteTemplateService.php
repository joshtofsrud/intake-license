<?php
// MARKER-PATCH-260

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Support\SiteTemplate;
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
            $prev = [
                'site_template' => $tenant->site_template,
                'accent_color'  => $tenant->accent_color,
                'text_color'    => $tenant->text_color,
                'bg_color'      => $tenant->bg_color,
                'font_heading'  => $tenant->font_heading,
                'font_body'     => $tenant->font_body,
                'design_tokens' => $tenant->design_tokens,
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
}
