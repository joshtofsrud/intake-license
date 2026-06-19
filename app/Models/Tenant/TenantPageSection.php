<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantPageSection extends Model
{
    /**
     * MARKER-PATCH-PB1 — Inherit the home page's nav + footer for any page that
     * doesn't define its own, so chrome is authored once (on home) and shared.
     * Returns an ordered collection: [inherited nav?] + page sections + [footer?].
     *
     * @param  \Illuminate\Support\Collection|iterable  $sections  the page's own visible sections
     */
    public static function withInheritedChrome($sections, string $tenantId, ?string $currentPageId)
    {
        $sections = collect($sections);
        $hasNav    = $sections->contains(fn ($s) => $s->section_type === 'nav');
        $hasFooter = $sections->contains(fn ($s) => $s->section_type === 'footer');
        if ($hasNav && $hasFooter) {
            return $sections;
        }

        $home = \App\Models\Tenant\TenantPage::query()
            ->where('tenant_id', $tenantId)->where('is_home', true)->first();
        if (! $home || $home->id === $currentPageId) {
            return $sections; // no home, or this IS home — nothing to inherit
        }

        $homeChrome = static::query()
            ->where('page_id', $home->id)->where('is_visible', true)
            ->whereIn('section_type', ['nav', 'footer'])->get();

        $result = collect();
        if (! $hasNav && ($nav = $homeChrome->firstWhere('section_type', 'nav'))) {
            $result->push($nav);
        }
        foreach ($sections as $s) {
            $result->push($s);
        }
        if (! $hasFooter && ($footer = $homeChrome->firstWhere('section_type', 'footer'))) {
            $result->push($footer);
        }
        return $result;
    }
    use HasUuids;
    protected $table    = 'tenant_page_sections';
    protected $fillable = ['page_id','tenant_id','section_type','content','bg_color','padding','is_visible','sort_order'];
    protected $casts    = ['content' => 'array', 'is_visible' => 'boolean'];
}
