#!/bin/bash
# catalog-title-review-page — patch 2 of 3. The page from the mockup.
#   Reads catalog_title_scopes (patch 1) and gives every distributor category
#   a visible row: what rule applies, a real sample title, what's wrong with
#   it, and how many items ride on it. Replaces guessing at a blank scope box.
#
#   Nav: this becomes "Catalog Titles". The old template editor keeps its
#   subtitle/search/allattr fields and moves to "Title templates" beneath it —
#   it is still the right tool for the global defaults, just not the place you
#   start from.
#
#   Defaults to the 48 scopes that need review rather than all 401. Info-only
#   findings (empty tokens) show inside the editor and never queue a scope.
#
#   The preview renders through CatalogTitleComposer, including the unsaved
#   template and unsaved size attribute, so what you see is what recompose
#   will produce — not a re-implementation that can drift.
#
#   Honest limit: recompose still runs per distributor, not per scope, because
#   distributor:recompose has no scope argument. The button says so.
# NO MIGRATION. Server: optimize:clear AND php artisan filament:cache-components
set -e
if [ -f app/Filament/Pages/CatalogTitleReview.php ]; then
  echo "catalog-title-review-page already applied — aborting."; exit 1
fi
if ! grep -q "MARKER-FLAG-TUNING" app/Models/CatalogTitleScope.php; then
  echo "catalog-title-flag-tuning must be applied first — aborting."; exit 1
fi

# ------------------------------------------------------- composer: size override
python3 - <<'CTRP_0_EOF'
import io
p = 'app/Services/Distributors/CatalogTitleComposer.php'
s = io.open(p, encoding='utf-8').read()

old = """    private function makeResolver(string $distributorCode, array $parts): array
    {
        $categoryPath = (string) ($parts['category_path'] ?? '');
        $setting = $this->setting($distributorCode, $categoryPath);
        $attrs = $parts['attributes'] ?? [];"""
assert s.count(old) == 1, s.count(old)
new = """    private function makeResolver(string $distributorCode, array $parts, ?array $sizeAttrOverride = null): array
    {
        $categoryPath = (string) ($parts['category_path'] ?? '');
        $setting = $this->setting($distributorCode, $categoryPath);
        $attrs = $parts['attributes'] ?? [];"""
s = s.replace(old, new)

old = """        $size = $this->pickAttribute($attrs, $setting->size_attribute_priority ?: []);"""
assert s.count(old) == 1
new = """        // MARKER-REVIEW-PAGE \u2014 the editor previews an UNSAVED size attribute,
        // so an override wins over the stored priority when one is passed.
        $sizePriority = $sizeAttrOverride !== null
            ? $sizeAttrOverride
            : ($setting->size_attribute_priority ?: []);
        $size = $this->pickAttribute($attrs, $sizePriority);"""
s = s.replace(old, new)

old = """    public function renderTemplate(string $distributorCode, string $template, array $parts): string
    {
        [$resolve] = $this->makeResolver($distributorCode, $parts);
        return $this->render($template, $resolve);
    }"""
assert s.count(old) == 1
new = """    public function renderTemplate(
        string $distributorCode,
        string $template,
        array $parts,
        ?array $sizeAttrOverride = null
    ): string {
        [$resolve] = $this->makeResolver($distributorCode, $parts, $sizeAttrOverride);
        return $this->render($template, $resolve);
    }"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('composer override ok')
CTRP_0_EOF

# ------------------------------------------------------- demote the old page
python3 - <<'CTRP_1_EOF'
import io
p = 'app/Filament/Pages/CatalogTitles.php'
s = io.open(p, encoding='utf-8').read()

old = """    protected static ?string $navigationLabel = 'Catalog Titles';
    protected static ?string $navigationGroup = 'Distribution';
    protected static ?int    $navigationSort  = 20;"""
assert s.count(old) == 1
new = """    // MARKER-REVIEW-PAGE \u2014 demoted beneath the review page. Still the right
    // place for subtitle / search / allattr and the global defaults.
    protected static ?string $navigationLabel = 'Title templates';
    protected static ?string $navigationGroup = 'Distribution';
    protected static ?int    $navigationSort  = 21;"""
io.open(p, 'w', encoding='utf-8').write(s.replace(old, new))
print('old page demoted ok')
CTRP_1_EOF

# ------------------------------------------------------- the page
cat > 'app/Filament/Pages/CatalogTitleReview.php' <<'CTRP_2_EOF'
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

    private function inheritedTemplate(CatalogTitleScope $scope): string
    {
        return app(CatalogTitleComposer::class)
            ->titleTemplateFor($scope->distributor_code, $scope->resolved_rule_scope ?? '');
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
CTRP_2_EOF

# ------------------------------------------------------- the view
cat > 'resources/views/filament/pages/catalog-title-review.blade.php' <<'CTRP_3_EOF'
{{-- MARKER-REVIEW-PAGE --}}
<x-filament-panels::page>

    {{-- ---------------- controls ---------------- --}}
    <div class="flex flex-wrap items-center gap-2">
        <select wire:model.live="distributor"
                class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 text-sm py-1.5">
            <option value="">All distributors ({{ $this->counts['dists'] }})</option>
            @foreach ($this->distributorOptions as $d)
                <option value="{{ $d }}">{{ $d }}</option>
            @endforeach
        </select>

        <input wire:model.live.debounce.400ms="search" placeholder="Search category or title…"
               class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 text-sm py-1.5 w-64">

        @php $chips = ['review' => 'Needs review', 'info' => 'Info only', 'own' => 'Has own rule', 'all' => 'Everything']; @endphp
        @foreach ($chips as $key => $label)
            <button type="button" wire:click="$set('filter','{{ $key }}')"
                class="text-xs font-semibold rounded-full px-3 py-1.5 ring-1
                    {{ $filter === $key
                        ? 'bg-primary-500/15 text-primary-600 dark:text-primary-400 ring-primary-500'
                        : 'text-gray-500 dark:text-gray-400 ring-gray-300 dark:ring-white/10' }}">
                {{ $label }}
                <span class="opacity-60">{{ number_format($this->counts[$key]) }}</span>
            </button>
        @endforeach

        <div class="flex-1"></div>

        <button type="button" wire:click="startQueue"
            class="fi-btn text-xs font-semibold rounded-lg px-3 py-1.5 ring-1 ring-gray-300 dark:ring-white/10">
            Review queue ({{ $this->queueLeft }})
        </button>
        <button type="button" wire:click="recomposeDistributor"
            class="fi-btn text-xs font-semibold rounded-lg px-3 py-1.5 bg-primary-600 text-white">
            Recompose distributor
        </button>
    </div>

    {{-- ---------------- bulk bar ---------------- --}}
    @if (count($selected))
        <div class="flex items-center gap-3 rounded-xl bg-primary-500/10 ring-1 ring-primary-500/40 px-4 py-2.5">
            <b class="text-sm">{{ count($selected) }} selected</b>
            <button wire:click="markSelectedReviewed"
                class="text-xs font-semibold rounded-lg px-3 py-1.5 ring-1 ring-gray-300 dark:ring-white/10">
                Mark reviewed
            </button>
            <button wire:click="$set('selected', [])"
                class="text-xs text-gray-500">Clear</button>
        </div>
    @endif

    {{-- ---------------- list ---------------- --}}
    <div class="fi-section rounded-xl bg-white dark:bg-gray-900 ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-white/5 text-[10px] uppercase tracking-wider text-gray-400">
                <tr>
                    <th class="w-10 py-2.5 px-4"></th>
                    <th class="text-left py-2.5 pr-4">Category</th>
                    <th class="text-left py-2.5 pr-4 w-32">Rule</th>
                    <th class="text-left py-2.5 pr-4">Sample title today</th>
                    <th class="text-right py-2.5 px-4 w-24">Items</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($this->scopes as $s)
                <tr wire:key="scope-{{ $s->id }}"
                    class="border-t border-gray-100 dark:border-white/5 hover:bg-gray-50 dark:hover:bg-white/5">
                    <td class="px-4 py-3 align-top">
                        <input type="checkbox" wire:model.live="selected" value="{{ $s->id }}"
                               class="rounded border-gray-300 dark:border-white/20">
                    </td>
                    <td class="pr-4 py-3 align-top cursor-pointer" wire:click="edit({{ $s->id }})">
                        <div class="font-semibold">
                            <span class="text-[10px] font-bold tracking-wide rounded bg-gray-100 dark:bg-white/10 px-1.5 py-0.5 mr-1.5">{{ $s->distributor_code }}</span>
                            {{ $s->category_key !== '' ? $s->category_key : '(no category)' }}
                        </div>
                        @if ($s->reviewed)
                            <div class="text-[10px] text-green-600 dark:text-green-400 mt-1">reviewed</div>
                        @endif
                    </td>
                    <td class="pr-4 py-3 align-top text-xs">
                        @if ($s->has_own_rule)
                            <span class="text-primary-600 dark:text-primary-400 font-semibold">Own rule</span>
                        @else
                            <span class="text-gray-400">Inherited</span>
                            <div class="text-[10px] text-gray-400">
                                {{ $s->resolved_rule_scope ?: 'distributor default' }}
                            </div>
                        @endif
                    </td>
                    <td class="pr-4 py-3 align-top cursor-pointer" wire:click="edit({{ $s->id }})">
                        <div class="text-xs truncate max-w-md">{{ $s->sample_title ?: '—' }}</div>
                        <div class="flex flex-wrap gap-1 mt-1.5">
                            @foreach ($s->problems() as $f)
                                <span class="text-[10px] font-bold uppercase tracking-wide rounded px-1.5 py-0.5
                                    {{ $f['severity'] === 'bad'
                                        ? 'bg-red-500/15 text-red-600 dark:text-red-400'
                                        : 'bg-amber-500/15 text-amber-600 dark:text-amber-400' }}"
                                    title="{{ $f['detail'] }}">{{ $f['label'] }}</span>
                            @endforeach
                        </div>
                    </td>
                    <td class="px-4 py-3 align-top text-right font-mono text-xs text-gray-500">
                        {{ number_format($s->item_count) }}
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-8 text-center text-sm text-gray-400">
                    Nothing here. Try another filter, or run
                    <span class="font-mono">catalog:scan-titles</span>.
                </td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="p-3 border-t border-gray-100 dark:border-white/5">
            {{ $this->scopes->links() }}
        </div>
    </div>

    {{-- ---------------- drawer ---------------- --}}
    @if ($this->editing)
        @php $sc = $this->editing; @endphp
        <div class="fixed inset-0 bg-black/50 z-40" wire:click="closeDrawer"></div>
        <aside class="fixed top-0 right-0 bottom-0 w-full max-w-2xl z-50 overflow-y-auto
                      bg-white dark:bg-gray-900 ring-1 ring-gray-950/10 dark:ring-white/10">

            <div class="sticky top-0 bg-white dark:bg-gray-900 border-b border-gray-100 dark:border-white/10 px-6 py-4 z-10">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-bold">
                            {{ $sc->distributor_code }} · {{ $sc->category_key ?: '(no category)' }}
                        </h2>
                        <p class="text-xs text-gray-400 font-mono mt-0.5">
                            {{ number_format($sc->item_count) }} items
                            @if ($queueMode) · {{ $this->queueLeft }} left in queue @endif
                        </p>
                    </div>
                    <button wire:click="closeDrawer" class="text-gray-400 text-sm">Close</button>
                </div>
            </div>

            <div class="px-6 py-5 space-y-6">

                @foreach ($sc->problems() as $f)
                    <div class="rounded-lg px-4 py-3 text-xs
                        {{ $f['severity'] === 'bad'
                            ? 'bg-red-500/10 text-red-600 dark:text-red-400 ring-1 ring-red-500/30'
                            : 'bg-amber-500/10 text-amber-600 dark:text-amber-400 ring-1 ring-amber-500/30' }}">
                        <b>{{ $f['label'] }}</b> — {{ $f['detail'] }}
                    </div>
                @endforeach

                <div>
                    <label class="block text-xs font-semibold mb-1.5">Title template</label>
                    <input wire:model.live.debounce.500ms="tpl"
                        class="w-full font-mono text-xs rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 px-3 py-2.5">
                    @if (! $sc->has_own_rule)
                        <p class="text-[11px] text-gray-400 mt-1.5">
                            Currently inherited. Saving creates a rule for this category only.
                        </p>
                    @endif
                </div>

                <div>
                    <label class="block text-xs font-semibold mb-1.5">Size comes from</label>
                    <input wire:model.live.debounce.500ms="sizeAttr" placeholder="Labeled Size"
                        class="w-full font-mono text-xs rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 px-3 py-2.5">
                    <p class="text-[11px] text-gray-400 mt-1.5">
                        Attribute names, comma separated, tried in order before any text matching.
                    </p>
                </div>

                @if (count($this->attrNames))
                    <div>
                        <div class="text-xs font-semibold mb-2">Attributes on these items</div>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($this->attrNames as $n)
                                <span class="font-mono text-[11px] rounded bg-gray-100 dark:bg-white/10 px-2 py-0.5">{{ '{attr:' . $n . '}' }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if (count($sc->notes()))
                    <div class="text-[11px] text-gray-400 space-y-1">
                        @foreach ($sc->notes() as $n)
                            <div>{{ $n['label'] }} — {{ $n['detail'] }}</div>
                        @endforeach
                    </div>
                @endif

                <div>
                    <div class="text-xs font-semibold mb-2">Preview — real items from this category</div>
                    <div class="rounded-lg ring-1 ring-gray-200 dark:ring-white/10 divide-y divide-gray-100 dark:divide-white/5">
                        @forelse ($this->preview as $p)
                            <div class="px-4 py-3">
                                <div class="text-[11px] text-gray-400 line-through">{{ $p['was'] }}</div>
                                <div class="text-sm font-semibold mt-0.5">{{ $p['now'] ?: '—' }}</div>
                                <div class="text-[10px] font-mono text-gray-400 mt-1">{{ $p['sku'] }}</div>
                            </div>
                        @empty
                            <div class="px-4 py-6 text-xs text-gray-400 text-center">
                                No samples stored — re-run catalog:scan-titles.
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="sticky bottom-0 bg-white dark:bg-gray-900 border-t border-gray-100 dark:border-white/10 px-6 py-3 flex justify-end gap-2">
                @if ($queueMode)
                    <button wire:click="approve({{ $sc->id }})"
                        class="text-xs font-semibold rounded-lg px-3 py-2 ring-1 ring-gray-300 dark:ring-white/10">
                        Looks fine — next
                    </button>
                @endif
                <button wire:click="save"
                    class="text-xs font-semibold rounded-lg px-3 py-2 ring-1 ring-gray-300 dark:ring-white/10">
                    Save only
                </button>
                <button wire:click="saveAndRecompose"
                    class="text-xs font-semibold rounded-lg px-3 py-2 bg-primary-600 text-white">
                    Save &amp; recompose {{ $sc->distributor_code }}
                </button>
            </div>
        </aside>
    @endif

</x-filament-panels::page>
CTRP_3_EOF

php -l app/Filament/Pages/CatalogTitleReview.php
php -l app/Filament/Pages/CatalogTitles.php
php -l app/Services/Distributors/CatalogTitleComposer.php

echo
echo "catalog-title-review-page applied."
