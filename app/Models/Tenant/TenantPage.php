<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantPage extends Model
{
    use HasUuids;
    protected $table    = 'tenant_pages';
    protected $fillable = ['tenant_id','title','slug','meta_title','meta_description','is_home','is_splash','is_published','published_at','is_in_nav','nav_order', // MARKER-PAGE-PUBLISH
        'splash_page_id','splash_mode','splash_style','splash_frequency','splash_starts_at','splash_ends_at', // MARKER-SPLASH-2
        'kind','help_category_id','help_key','help_min_tier','help_addons','help_locked_mode','help_sort']; // MARKER-HELP-ADMIN
    protected $casts    = ['is_home' => 'boolean', 'is_splash' => 'boolean', 'is_published' => 'boolean', 'published_at' => 'datetime', 'is_in_nav' => 'boolean',
        'splash_starts_at' => 'date', 'splash_ends_at' => 'date',
        'help_addons' => 'array', 'help_sort' => 'integer']; // MARKER-SPLASH-2 · MARKER-HELP-ADMIN

    public function sections(): HasMany
    {
        return $this->hasMany(TenantPageSection::class, 'page_id')->orderBy('sort_order');
    }

    // ------------------------------------------------------------------
    // MARKER-HELP-ADMIN — help articles
    // ------------------------------------------------------------------

    /** Plan tiers, weakest first. A null help_min_tier means every plan. */
    public const TIER_ORDER = ['starter', 'branded', 'scale', 'custom'];

    public function scopeHowTo($q)
    {
        return $q->where('kind', 'howto');
    }

    /** Add-on codes this article requires that the tenant does not hold. */
    public function missingAddonsFor(\App\Models\Tenant $tenant): array
    {
        return array_values(array_filter(
            $this->help_addons ?? [],
            fn ($code) => ! $tenant->hasAddon($code)
        ));
    }

    public function tierMetBy(\App\Models\Tenant $tenant): bool
    {
        if (! $this->help_min_tier) {
            return true;
        }

        $need = array_search($this->help_min_tier, self::TIER_ORDER, true);
        $has  = array_search($tenant->plan_tier ?? 'starter', self::TIER_ORDER, true);

        return $need === false || ($has !== false && $has >= $need);
    }

    /** Can this shop READ the article? */
    public function readableBy(\App\Models\Tenant $tenant): bool
    {
        return $this->tierMetBy($tenant) && $this->missingAddonsFor($tenant) === [];
    }

    /**
     * What a shop that fails the gate sees: 'read', 'locked' or 'hidden'.
     *
     * A locked row is a sales surface, so it has to be able to say what would
     * unlock it. An add-on with no price configured cannot — it would show a
     * shop something they have no way to buy — so the article falls back to
     * hidden rather than teasing. Same rule as the onboarding upgrade prompt.
     */
    public function visibilityFor(\App\Models\Tenant $tenant): string
    {
        if ($this->readableBy($tenant)) {
            return 'read';
        }

        if ($this->help_locked_mode !== 'lock') {
            return 'hidden';
        }

        $missing = $this->missingAddonsFor($tenant);

        // A tier gate with no add-on attached has nothing specific to name.
        if ($missing === []) {
            return 'hidden';
        }

        $sellable = \App\Models\Addon::whereIn('code', $missing)
            ->where(function ($w) {
                $w->where('price_cents', '>', 0)
                  ->orWhereNotNull('price_display_override');
            })
            ->count();

        return $sellable === count($missing) ? 'locked' : 'hidden';
    }
}
