<?php

// MARKER-TITLE-CONTROL

namespace App\Filament\Pages;

use App\Models\CatalogTitleScope;
use App\Models\CatalogTitleSetting;
use App\Models\PlatformDistributorCatalog;
use App\Services\Distributors\CatalogTitleComposer;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Livewire\WithPagination;

/**
 * One surface for how product titles are built.
 *
 * Replaces the pair of screens that both wrote catalog_title_settings from
 * different halves of the same row. The ladder — built-in, global,
 * distributor, category — is navigable in one rail, and every template field
 * states whether it is set here or inherited, and from where.
 */
class CatalogTitleControl extends Page
{
    use WithPagination;

    protected static ?string $navigationIcon  = 'heroicon-o-tag';
    protected static ?string $navigationLabel = 'Titles';
    protected static ?string $navigationGroup = 'Distribution';
    protected static ?int    $navigationSort  = 19;
    protected static ?string $title = 'Product titles';

    protected static string $view = 'filament.pages.catalog-title-control';

    /** fallback | global | dist | scope */
    public string $level = 'global';
    public string $distributor = '';
    public ?int $scopeId = null;

    public string $title_template = '';
    public string $subtitle_template = '';
    public string $search_template = '';

    /** Which field a clicked token lands in. */
    public string $activeField = 'title_template';

    public string $tokenSearch = '';
    public string $filter = 'review';       // review | own | all
    public string $search = '';
    public string $queueDistributor = '';

    public function mount(): void
    {
        $this->selectGlobal();
    }

    public function updatingSearch(): void           { $this->resetPage(); }
    public function updatingFilter(): void           { $this->resetPage(); }
    public function updatingQueueDistributor(): void { $this->resetPage(); }

    // ------------------------------------------------------------- selection

    public function selectGlobal(): void
    {
        $this->level = 'global';
        $this->distributor = '*';
        $this->scopeId = null;
        $this->loadTemplates('*', '');
    }

    public function selectDistributor(string $code): void
    {
        $this->level = 'dist';
        $this->distributor = strtoupper($code);
        $this->scopeId = null;
        $this->loadTemplates($this->distributor, '');
    }

    public function selectScope(int $id): void
    {
        $scope = CatalogTitleScope::find($id);
        if (! $scope) {
            return;
        }
        $this->level = 'scope';
        $this->distributor = $scope->distributor_code;
        $this->scopeId = $id;
        $this->loadTemplates($scope->distributor_code, $scope->category_key);
    }

    /**
     * Load the three fields with what this level RESOLVES to, so an inherited
     * value is visible and editable in place rather than an empty box that
     * hides what is actually in force.
     */
    private function loadTemplates(string $code, string $key): void
    {
        $own = CatalogTitleSetting::where('distributor_code', $code)
            ->where('category_key', $key)->first();

        $src = app(CatalogTitleComposer::class)->fieldSources($code, $key);

        $this->title_template    = $own?->title_template    ?: $src['title_template']['value'];
        $this->subtitle_template = $own?->subtitle_template ?: $src['subtitle_template']['value'];
        $this->search_template   = $own?->search_template   ?: $src['search_template']['value'];
    }

    public function getCurrentKeyProperty(): string
    {
        return $this->level === 'scope'
            ? (string) (CatalogTitleScope::find($this->scopeId)?->category_key ?? '')
            : '';
    }

    public function getCurrentLabelProperty(): string
    {
        return match ($this->level) {
            'global' => 'Global default',
            'dist'   => $this->distributor . ' · any category',
            'scope'  => $this->distributor . ' · ' . ($this->currentKey ?: 'any category'),
            default  => 'Built-in fallback',
        };
    }

    // ------------------------------------------------------------- the ladder

    /** Distributor codes that have scopes, for the defaults block. */
    public function getDistributorsProperty(): array
    {
        return CatalogTitleScope::query()->select('distributor_code')->distinct()
            ->orderBy('distributor_code')->pluck('distributor_code')->all();
    }

    /**
     * How many categories actually inherit each default.
     *
     * Counted by walking the real ladder per scope rather than assuming
     * "no own rule means global" — a distributor default sits in between, and
     * guessing there would overstate global's reach and understate the
     * distributor's.
     */
    public function getDependentsProperty(): array
    {
        $composer = app(CatalogTitleComposer::class);
        $out = ['*' => 0];

        foreach (CatalogTitleScope::query()->get(['distributor_code', 'category_key']) as $scope) {
            $row = $composer->matchedSetting($scope->distributor_code, $scope->category_key);
            if (! $row) {
                continue;
            }
            if ($row->category_key !== '') {
                continue;   // owns a category rule, inherits from no default
            }
            $k = $row->distributor_code;
            $out[$k] = ($out[$k] ?? 0) + 1;
        }

        return $out;
    }

    /** The inheritance chain above whatever is being edited. */
    public function getChainProperty(): array
    {
        $chain = [
            ['label' => 'built-in', 'level' => 'fallback', 'code' => null, 'active' => $this->level === 'fallback'],
            ['label' => 'Global',   'level' => 'global',   'code' => '*',  'active' => $this->level === 'global'],
        ];

        if ($this->level === 'dist' || $this->level === 'scope') {
            $chain[] = ['label' => $this->distributor, 'level' => 'dist',
                        'code' => $this->distributor, 'active' => $this->level === 'dist'];
        }
        if ($this->level === 'scope') {
            $chain[] = ['label' => $this->currentKey ?: 'any category', 'level' => 'scope',
                        'code' => null, 'active' => true];
        }

        return $chain;
    }

    /** Per-field provenance for the pills beside each template box. */
    public function getSourcesProperty(): array
    {
        $code = $this->level === 'global' ? '*' : $this->distributor;
        $key  = $this->currentKey;

        $own = CatalogTitleSetting::where('distributor_code', $code)
            ->where('category_key', $key)->first();

        $src = app(CatalogTitleComposer::class)->fieldSources($code, $key);

        $out = [];
        foreach (['title_template', 'subtitle_template', 'search_template'] as $f) {
            $setHere = $own && trim((string) $own->$f) !== '';
            $out[$f] = [
                'own'  => $setHere,
                'from' => $setHere ? null : ($src[$f]['from'] ?? 'built-in fallback'),
                'code' => $src[$f]['code'] ?? null,
                'key'  => $src[$f]['key'] ?? null,
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------- queue

    public function getScopesProperty()
    {
        return CatalogTitleScope::query()
            ->when($this->queueDistributor !== '', fn ($q) => $q->where('distributor_code', $this->queueDistributor))
            ->when($this->filter === 'review', fn ($q) => $q->whereIn('severity', ['warn', 'bad']))
            ->when($this->filter === 'own',    fn ($q) => $q->where('has_own_rule', true))
            ->when($this->search !== '', function ($q) {
                $t = '%' . $this->search . '%';
                $q->where(fn ($w) => $w->where('category_key', 'like', $t)
                    ->orWhere('sample_title', 'like', $t));
            })
            ->orderByRaw("FIELD(severity,'bad','warn','info')")
            ->orderByDesc('item_count')
            ->paginate(20);
    }

    public function getCountsProperty(): array
    {
        $base = fn () => CatalogTitleScope::query()
            ->when($this->queueDistributor !== '', fn ($q) => $q->where('distributor_code', $this->queueDistributor));

        return [
            'review' => (clone $base())->whereIn('severity', ['warn', 'bad'])->count(),
            'own'    => (clone $base())->where('has_own_rule', true)->count(),
            'all'    => (clone $base())->count(),
        ];
    }

    // ------------------------------------------------------------- samples

    /**
     * Rows to preview against.
     *
     * A category previews its own samples. A DEFAULT samples across several
     * categories that inherit from it — editing a global rule while looking
     * at one category's items is how a change that helps tyres quietly ruins
     * brake pads.
     */
    private function sampleRows(): \Illuminate\Support\Collection
    {
        if ($this->level === 'scope') {
            $scope = CatalogTitleScope::find($this->scopeId);
            return PlatformDistributorCatalog::whereIn('id', $scope?->sample_ids ?? [])->get();
        }

        $q = CatalogTitleScope::query()->where('has_own_rule', false);
        if ($this->level === 'dist') {
            $q->where('distributor_code', $this->distributor);
        }

        $ids = [];
        foreach ($q->orderByDesc('item_count')->limit(4)->get() as $scope) {
            foreach (array_slice((array) ($scope->sample_ids ?? []), 0, 2) as $id) {
                $ids[] = $id;
            }
        }

        return PlatformDistributorCatalog::whereIn('id', $ids)->get();
    }

    /** Before/after through the real composer, so preview cannot drift. */
    public function getPreviewProperty(): array
    {
        $rows = $this->sampleRows();
        if ($rows->isEmpty()) {
            return [];
        }

        $composer = app(CatalogTitleComposer::class);
        $code = $this->level === 'global' ? '*' : $this->distributor;

        $out = [];
        foreach ($rows as $row) {
            $parts = $composer->partsFromRow($row);
            $was = (string) $row->display_name;
            $now = $composer->renderTemplate($row->distributor_code, $this->title_template, $parts);

            $out[] = [
                'sku'       => $row->manufacturer_sku ?: ($row->upc ?: $row->distributor_variant_no),
                'dist'      => $row->distributor_code,
                'category'  => (string) $row->category_path,
                'was'       => $was,
                'now'       => $now,
                'unchanged' => trim($was) === trim($now),
                'empty'     => trim($now) === '',
            ];
        }
        return $out;
    }

    /** Duplicate titles inside the previewed sample, before and after. */
    public function getDupesProperty(): array
    {
        $rows = $this->preview;
        if (! $rows) {
            return ['was' => 0, 'now' => 0];
        }
        $count = function (string $k) use ($rows) {
            $seen = [];
            foreach ($rows as $r) {
                $t = trim((string) $r[$k]);
                if ($t === '') { continue; }
                $seen[$t] = ($seen[$t] ?? 0) + 1;
            }
            return count(array_filter($seen, fn ($n) => $n > 1));
        };
        return ['was' => $count('was'), 'now' => $count('now')];
    }

    // ------------------------------------------------------------- tokens

    /**
     * Every token, with what it resolves to on the first sample row.
     *
     * Seeing the value is the point — the slow part of writing a rule is
     * discovering that {attr:Casing} is blank on the items you care about,
     * and that is not knowable from a name.
     */
    public function getTokensProperty(): array
    {
        $rows = $this->sampleRows();
        $row  = $rows->first();

        $composer = app(CatalogTitleComposer::class);
        $parts = $row ? $composer->partsFromRow($row) : [];
        $code  = $row->distributor_code ?? ($this->distributor ?: '*');

        $resolve = function (string $token) use ($composer, $code, $parts, $row): string {
            if (! $row) {
                return '';
            }
            return trim($composer->renderTemplate($code, $token, $parts));
        };

        $groups = [
            'Basics' => ['{brand}', '{model}', '{mpn}', '{size}', '{color}', '{unit}'],
            'Category' => ['{type}', '{type0}', '{category_path}', '{group}'],
            'Product data' => ['{desc}', '{size_code}', '{color_code}', '{case_qty}', '{weight}', '{dimensions}'],
            'Identifiers' => ['{upc}', '{ean}', '{variant_no}', '{product_no}'],
        ];

        // Attribute names genuinely present on these items.
        $attrs = [];
        foreach ($rows as $r) {
            foreach (($r->attributes ?? []) as $a) {
                if (is_array($a) && isset($a['Name']) && trim((string) ($a['Value'] ?? '')) !== '') {
                    $attrs[trim((string) $a['Name'])] = true;
                }
            }
        }
        ksort($attrs);
        $groups['Attributes on these items'] = array_map(
            fn ($n) => '{attr:' . $n . '}',
            array_keys($attrs)
        );
        $groups['Attributes on these items'][] = '{allattr}';

        $needle = mb_strtolower(trim($this->tokenSearch));

        $out = [];
        foreach ($groups as $label => $tokens) {
            $items = [];
            foreach ($tokens as $t) {
                if ($needle !== '' && ! str_contains(mb_strtolower($t), $needle)) {
                    continue;
                }
                $items[] = ['token' => $t, 'value' => $resolve($t)];
            }
            if ($items) {
                $out[$label] = $items;
            }
        }
        return $out;
    }

    public function insertToken(string $token): void
    {
        $f = in_array($this->activeField, ['title_template', 'subtitle_template', 'search_template'], true)
            ? $this->activeField
            : 'title_template';

        $this->$f = trim(trim((string) $this->$f) . ' ' . $token);
    }

    public function focusField(string $field): void
    {
        $this->activeField = $field;
    }

    // ------------------------------------------------------------- writes

    /**
     * Writes only the three templates.
     *
     * size_attribute_priority is deliberately NOT written. The old Title
     * templates page wrote it from a form field, so saving with that box
     * empty nulled it for the scope and silently changed how {size}
     * resolved. {size|attr:Labeled Size} does the same job in the open.
     */
    public function save(bool $recompose = false, bool $next = false): void
    {
        $code = $this->level === 'global' ? '*' : $this->distributor;
        $key  = $this->currentKey;

        if ($this->level === 'fallback') {
            Notification::make()->warning()->title('The built-in fallback is not editable')
                ->body('Set a Global default instead — it overrides the built-in for every distributor.')->send();
            return;
        }

        CatalogTitleSetting::updateOrCreate(
            ['distributor_code' => $code, 'category_key' => $key],
            [
                'title_template'    => trim($this->title_template),
                'subtitle_template' => trim($this->subtitle_template),
                'search_template'   => trim($this->search_template),
                'is_active'         => true,
            ]
        );

        if ($this->level === 'scope' && $this->scopeId) {
            CatalogTitleScope::where('id', $this->scopeId)->update([
                'has_own_rule'        => true,
                'resolved_rule_scope' => $key ?: null,
                'reviewed'            => true,
                'reviewed_at'         => now(),
            ]);
        }

        Notification::make()->success()->title('Saved')
            ->body($this->currentLabel . ' — recompose to apply it to stored rows.')->send();

        if ($recompose) {
            $this->recompose();
        }
        if ($next) {
            $this->nextInQueue();
        }
    }

    public function saveAndRecompose(): void { $this->save(true, false); }
    public function saveAndNext(): void      { $this->save(false, true); }

    public function recompose(): void
    {
        $code = $this->level === 'global' ? '' : $this->distributor;

        Artisan::call('distributor:recompose', $code !== '' ? ['code' => $code] : []);

        Notification::make()->success()->title('Recomposed')
            ->body($code !== '' ? "Rebuilt titles for {$code}." : 'Rebuilt titles for every distributor.')
            ->send();
    }

    /** Next unreviewed scope in the queue, so the work can be walked. */
    public function nextInQueue(): void
    {
        $next = CatalogTitleScope::query()
            ->when($this->queueDistributor !== '', fn ($q) => $q->where('distributor_code', $this->queueDistributor))
            ->whereIn('severity', ['warn', 'bad'])
            ->where('reviewed', false)
            ->orderByRaw("FIELD(severity,'bad','warn','info')")
            ->orderByDesc('item_count')
            ->first();

        if (! $next) {
            Notification::make()->success()->title('Queue empty')
                ->body('Every flagged category has been reviewed.')->send();
            return;
        }

        $this->selectScope($next->id);
    }

    public function getQueueLeftProperty(): int
    {
        return CatalogTitleScope::query()
            ->when($this->queueDistributor !== '', fn ($q) => $q->where('distributor_code', $this->queueDistributor))
            ->whereIn('severity', ['warn', 'bad'])
            ->where('reviewed', false)
            ->count();
    }
}
