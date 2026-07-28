<?php

// MARKER-REVIEW-PAGE

namespace App\Filament\Pages;

use App\Jobs\RecomposeAndSyncTitlesJob;
use App\Models\CatalogTitleScope;
use App\Models\CatalogTitleSetting;
use App\Models\PlatformDistributorCatalog;
use App\Services\Distributors\CatalogTitleComposer;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\WithPagination;

/**
 * Catalog Titles — the list-first surface.
 *
 * Every row is one (distributor, DISTRIBUTOR category path) scope from
 * catalog_title_scopes. category_key is always the distributor's own path
 * off the feed; tenant categories never appear here and never affect a rule.
 */
class CatalogTitleReview extends Page
{
    use WithPagination;

    protected static ?string $navigationIcon  = 'heroicon-o-tag';
    protected static ?string $navigationLabel = 'Catalog Titles';
    protected static ?string $navigationGroup = 'Distribution';
    protected static ?int    $navigationSort  = 20;
    protected static ?string $title = 'Catalog titles';

    protected static string $view = 'filament.pages.catalog-title-review';

    /** Filters. */
    public string $distributor = '';
    public string $filter = 'review';   // review | info | own | all
    public string $search = '';

    /** Bulk selection — scope ids. */
    public array $selected = [];

    /** Drawer state. */
    public ?int $editingId = null;
    public string $tpl = '';
    public string $sizeAttr = '';
    public bool $queueMode = false;

    public function updatingSearch(): void      { $this->resetPage(); }
    public function updatingFilter(): void      { $this->resetPage(); $this->selected = []; }
    public function updatingDistributor(): void { $this->resetPage(); $this->selected = []; }

    // ------------------------------------------------------------- listing

    public function getScopesProperty()
    {
        return CatalogTitleScope::query()
            ->when($this->distributor !== '', fn ($q) => $q->where('distributor_code', $this->distributor))
            ->when($this->filter === 'review', fn ($q) => $q->whereIn('severity', ['warn', 'bad']))
            ->when($this->filter === 'info',   fn ($q) => $q->where('severity', 'info'))
            ->when($this->filter === 'own',    fn ($q) => $q->where('has_own_rule', true))
            ->when($this->search !== '', function ($q) {
                $t = '%' . $this->search . '%';
                $q->where(fn ($w) => $w->where('category_key', 'like', $t)
                    ->orWhere('sample_title', 'like', $t));
            })
            ->orderByRaw("FIELD(severity,'bad','warn','info') ")
            ->orderByDesc('item_count')
            ->paginate(25);
    }

    public function getCountsProperty(): array
    {
        $base = fn () => CatalogTitleScope::query()
            ->when($this->distributor !== '', fn ($q) => $q->where('distributor_code', $this->distributor));

        return [
            'review' => (clone $base())->whereIn('severity', ['warn', 'bad'])->count(),
            'info'   => (clone $base())->where('severity', 'info')->count(),
            'own'    => (clone $base())->where('has_own_rule', true)->count(),
            'all'    => (clone $base())->count(),
            'dists'  => CatalogTitleScope::distinct()->count('distributor_code'),
        ];
    }

    public function getDistributorOptionsProperty(): array
    {
        return CatalogTitleScope::query()
            ->select('distributor_code')->distinct()->orderBy('distributor_code')
            ->pluck('distributor_code')->all();
    }

    // ------------------------------------------------------------- drawer

    public function edit(int $id): void
    {
        $scope = CatalogTitleScope::findOrFail($id);
        $this->editingId = $id;

        $rule = $this->ruleFor($scope);
        // MARKER-ONE-RESOLVER — own rule if there is one, otherwise whatever
        // the ladder actually resolves for this category path.
        $this->tpl = $rule?->title_template ?? $this->inheritedTemplate($scope);
        $this->sizeAttr = implode(', ', $rule?->size_attribute_priority ?? []);
    }

    public function closeDrawer(): void
    {
        $this->editingId = null;
    }

    public function getEditingProperty(): ?CatalogTitleScope
    {
        return $this->editingId ? CatalogTitleScope::find($this->editingId) : null;
    }

    /** The rule row owned by this exact scope, if it has one. */
    private function ruleFor(CatalogTitleScope $scope): ?CatalogTitleSetting
    {
        return CatalogTitleSetting::where('distributor_code', $scope->distributor_code)
            ->where('category_key', $scope->category_key)
            ->first();
    }

    /**
     * MARKER-ONE-RESOLVER — pass the scope's OWN category path and let the
     * composer walk the ladder. Passing resolved_rule_scope meant a null
     * from the scan turned into a request for the catch-all, so the editor
     * showed a template that recompose would never use.
     */
    private function inheritedTemplate(CatalogTitleScope $scope): string
    {
        return app(CatalogTitleComposer::class)
            ->titleTemplateFor($scope->distributor_code, $scope->category_key);
    }

    /** Which rule the editor is actually showing, for the drawer label. */
    public function getInheritedFromProperty(): ?string
    {
        $scope = $this->editing;
        if (! $scope) return null;

        $row = app(CatalogTitleComposer::class)
            ->matchedSetting($scope->distributor_code, $scope->category_key);

        if (! $row) return 'built-in fallback';

        return $row->distributor_code . ' · '
            . ($row->category_key !== '' ? $row->category_key : 'any category');
    }

    /**
     * Before/after for the scope's sample rows, rendered through the real
     * composer so the preview cannot drift from what recompose produces.
     */
    public function getPreviewProperty(): array
    {
        $scope = $this->editing;
        if (! $scope) return [];

        $rows = PlatformDistributorCatalog::whereIn('id', $scope->sample_ids ?? [])->get();
        if ($rows->isEmpty()) return [];

        $composer = app(CatalogTitleComposer::class);
        $sizeOverride = array_values(array_filter(array_map('trim', explode(',', $this->sizeAttr))));

        $out = [];
        foreach ($rows as $row) {
            $parts = $composer->partsFromRow($row);
            $out[] = [
                'sku'   => $row->manufacturer_sku ?: $row->upc,
                'was'   => $composer->compose($scope->distributor_code, $parts)['title'],
                'now'   => $composer->renderTemplate(
                    $scope->distributor_code, $this->tpl, $parts,
                    $sizeOverride ?: null
                ),
            ];
        }
        return $out;
    }

    /** Attribute names present on this scope's samples, for the token palette. */
    public function getAttrNamesProperty(): array
    {
        $scope = $this->editing;
        if (! $scope) return [];

        $names = [];
        foreach (PlatformDistributorCatalog::whereIn('id', $scope->sample_ids ?? [])->get() as $row) {
            foreach (($row->attributes ?? []) as $a) {
                if (is_array($a) && isset($a['Name']) && trim((string) ($a['Value'] ?? '')) !== '') {
                    $names[trim((string) $a['Name'])] = true;
                }
            }
        }
        ksort($names);
        return array_keys($names);
    }

    // ------------------------------------------------------------- writes

    public function save(bool $recompose = false): void
    {
        $scope = $this->editing;
        if (! $scope) return;

        if (trim($this->tpl) === '') {
            Notification::make()->danger()->title('Title template is empty')->send();
            return;
        }

        $sizes = array_values(array_filter(array_map('trim', explode(',', $this->sizeAttr))));

        CatalogTitleSetting::updateOrCreate(
            ['distributor_code' => $scope->distributor_code, 'category_key' => $scope->category_key],
            [
                'title_template'          => trim($this->tpl),
                'size_attribute_priority' => $sizes ?: null,
                'is_active'               => true,
            ]
        );

        $scope->update([
            'has_own_rule'        => true,
            'resolved_rule_scope' => $scope->category_key ?: null,
            'reviewed'            => true,
            'reviewed_at'         => now(),
        ]);

        if ($recompose) {
            RecomposeAndSyncTitlesJob::dispatch($scope->distributor_code);
            Notification::make()->success()
                ->title('Saved — recomposing ' . $scope->distributor_code)
                ->body('Runs in the background. Titles update as it goes.')->send();
        } else {
            Notification::make()->success()->title('Rule saved')
                ->body('Nothing is retitled until you recompose.')->send();
        }

        $this->closeDrawer();
        $this->queueMode ? $this->nextInQueue() : null;
    }

    public function saveAndRecompose(): void { $this->save(true); }

    public function approve(int $id): void
    {
        CatalogTitleScope::whereKey($id)->update(['reviewed' => true, 'reviewed_at' => now()]);
        if ($this->queueMode) $this->nextInQueue();
    }

    public function markSelectedReviewed(): void
    {
        $n = CatalogTitleScope::whereIn('id', $this->selected)
            ->update(['reviewed' => true, 'reviewed_at' => now()]);
        $this->selected = [];
        Notification::make()->success()->title("{$n} marked reviewed")->send();
    }

    public function recomposeDistributor(): void
    {
        if ($this->distributor === '') {
            Notification::make()->warning()
                ->title('Pick a distributor first')
                ->body('Recompose runs per distributor, not per category.')->send();
            return;
        }
        RecomposeAndSyncTitlesJob::dispatch($this->distributor);
        Notification::make()->success()->title('Recomposing ' . $this->distributor)->send();
    }

    // ------------------------------------------------------------- queue

    public function startQueue(): void
    {
        $this->queueMode = true;
        $this->nextInQueue();
    }

    public function stopQueue(): void
    {
        $this->queueMode = false;
        $this->closeDrawer();
    }

    public function nextInQueue(): void
    {
        $next = CatalogTitleScope::query()
            ->when($this->distributor !== '', fn ($q) => $q->where('distributor_code', $this->distributor))
            ->whereIn('severity', ['warn', 'bad'])
            ->where('reviewed', false)
            ->orderByRaw("FIELD(severity,'bad','warn')")
            ->orderByDesc('item_count')
            ->first();

        if (! $next) {
            $this->queueMode = false;
            $this->closeDrawer();
            Notification::make()->success()->title('Queue clear')
                ->body('Nothing left needing review.')->send();
            return;
        }

        $this->edit($next->id);
    }

    public function getQueueLeftProperty(): int
    {
        return CatalogTitleScope::query()
            ->when($this->distributor !== '', fn ($q) => $q->where('distributor_code', $this->distributor))
            ->whereIn('severity', ['warn', 'bad'])->where('reviewed', false)->count();
    }
}
