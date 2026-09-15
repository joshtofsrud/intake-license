<?php

namespace App\Filament\Pages;

use App\Models\Addon;
use App\Models\HelpCategory;
use App\Models\Tenant;
use App\Models\Tenant\TenantPage;
use Filament\Pages\Page;
use Illuminate\Support\Str;

/**
 * MARKER-HELP-ADMIN — write the how-to articles shops read at /help.
 *
 * Organisation is drag-first: categories reorder, articles reorder inside a
 * category, and an article dropped on a category moves into it. Everything
 * writes immediately — there is no separate save for ordering, because an
 * order you have to remember to save is an order that ends up wrong.
 */
class HelpArticles extends Page
{
    use \App\Support\UsesAdminNav;

    protected static ?string $navigationIcon  = 'heroicon-o-book-open';
    protected static ?string $navigationLabel = 'Help articles';
    protected static ?string $navigationGroup = 'Content';
    protected static ?int    $navigationSort  = 30;
    protected static string  $view            = 'filament.pages.help-articles';
    protected static ?string $slug            = 'help-articles';

    public ?string $selCat = null;
    public ?string $selArt = null;
    public string  $newCategory = '';

    public function mount(): void
    {
        $this->selCat = HelpCategory::orderBy('sort')->value('id');
        $this->selArt = $this->articlesIn($this->selCat)->first()?->id;
    }

    // ---------------------------------------------------------------- data

    protected function platform(): ?Tenant
    {
        return Tenant::where('is_platform', true)->first();
    }

    protected function articlesIn(?string $catId)
    {
        $platform = $this->platform();
        if (! $platform || ! $catId) {
            return collect();
        }

        return TenantPage::query()
            ->where('tenant_id', $platform->id)
            ->howTo()
            ->where('help_category_id', $catId)
            ->orderBy('help_sort')
            ->orderBy('title')
            ->get();
    }

    /** Shops that can read a given article right now. */
    protected function reach(TenantPage $art, $tenants): array
    {
        $can = $tenants->filter(fn ($t) => $art->readableBy($t));

        return [
            'count'  => $can->count(),
            'total'  => $tenants->count(),
            'can'    => $can->pluck('name')->all(),
            'cannot' => $tenants->reject(fn ($t) => $art->readableBy($t))->pluck('name')->all(),
        ];
    }

    protected function getViewData(): array
    {
        $platform = $this->platform();
        $tenants  = Tenant::where('is_platform', false)->orderBy('name')->get();
        $cats     = HelpCategory::orderBy('sort')->get();
        $arts     = $this->articlesIn($this->selCat);
        $selected = $arts->firstWhere('id', $this->selArt) ?? $arts->first();

        $counts = [];
        if ($platform) {
            $counts = TenantPage::query()
                ->where('tenant_id', $platform->id)
                ->howTo()
                ->selectRaw('help_category_id, COUNT(*) as n')
                ->groupBy('help_category_id')
                ->pluck('n', 'help_category_id')
                ->all();
        }

        return [
            'platform' => $platform,
            'cats'     => $cats,
            'counts'   => $counts,
            'arts'     => $arts,
            'reaches'  => $arts->mapWithKeys(fn ($a) => [$a->id => $this->reach($a, $tenants)])->all(),
            'sel'      => $selected,
            'selReach' => $selected ? $this->reach($selected, $tenants) : null,
            // MARKER-HELP-PICKER — only add-ons that can gate a feature: recurring,
            // not onboarding services, not retired. Grouped by category in the
            // table's own order so the picker reads like the pricing page.
            'addonGroups' => Addon::query()
                ->whereNotIn('billing_cadence', ['one_time', 'usage'])
                ->where('category', '!=', 'onboarding')
                ->whereNotIn('status', ['retired', 'archived'])
                ->orderBy('category')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['code', 'name', 'category', 'price_cents', 'price_display_override'])
                ->groupBy('category'),
            'tiers'    => TenantPage::TIER_ORDER,
            'tenants'  => $tenants,
        ];
    }

    // ------------------------------------------------------------- actions

    public function selectCategory(string $id): void
    {
        $this->selCat = $id;
        $this->selArt = $this->articlesIn($id)->first()?->id;
    }

    public function selectArticle(string $id): void
    {
        $this->selArt = $id;
    }

    public function addCategory(): void
    {
        $name = trim($this->newCategory);
        if ($name === '') {
            return;
        }

        $cat = HelpCategory::create([
            'name' => $name,
            'sort' => (int) HelpCategory::max('sort') + 1,
        ]);

        $this->newCategory = '';
        $this->selectCategory($cat->id);
    }

    public function createArticle(): void
    {
        $platform = $this->platform();
        if (! $platform || ! $this->selCat) {
            return;
        }

        $art = TenantPage::create([
            'tenant_id'        => $platform->id,
            'title'            => 'Untitled article',
            'slug'             => 'help-' . Str::random(8),
            'kind'             => 'howto',
            'help_category_id' => $this->selCat,
            'help_locked_mode' => 'lock',   // Josh's default: the quiet upsell
            'help_sort'        => (int) TenantPage::where('help_category_id', $this->selCat)->max('help_sort') + 1,
            'is_published'     => false,
            'is_in_nav'        => false,
        ]);

        $this->selArt = $art->id;
    }

    /** Called by the drag handler with the ids in their new order. */
    public function reorderArticles(array $ids): void
    {
        foreach (array_values($ids) as $i => $id) {
            TenantPage::where('id', $id)->howTo()->update(['help_sort' => $i]);
        }
    }

    public function reorderCategories(array $ids): void
    {
        foreach (array_values($ids) as $i => $id) {
            HelpCategory::where('id', $id)->update(['sort' => $i]);
        }
    }

    public function moveArticle(string $id, string $catId): void
    {
        TenantPage::where('id', $id)->howTo()->update([
            'help_category_id' => $catId,
            'help_sort'        => (int) TenantPage::where('help_category_id', $catId)->max('help_sort') + 1,
        ]);
    }

    /** One field at a time, so a half-filled inspector can't lose the rest. */
    public function setField(string $id, string $field, $value): void
    {
        $allowed = ['title', 'help_key', 'help_min_tier', 'help_locked_mode', 'help_category_id', 'is_published'];
        if (! in_array($field, $allowed, true)) {
            return;
        }

        $art = TenantPage::where('id', $id)->howTo()->first();
        if (! $art) {
            return;
        }

        if ($field === 'help_min_tier' && ! in_array($value, TenantPage::TIER_ORDER, true)) {
            $value = null;
        }

        if ($field === 'help_category_id') {
            $this->selCat = $value;
        }

        $art->update([$field => $value]);
    }

    public function toggleAddon(string $id, string $code): void
    {
        $art = TenantPage::where('id', $id)->howTo()->first();
        if (! $art) {
            return;
        }

        $have = $art->help_addons ?? [];
        $art->update([
            'help_addons' => in_array($code, $have, true)
                ? array_values(array_diff($have, [$code]))
                : [...$have, $code],
        ]);
    }
}
