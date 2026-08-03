#!/usr/bin/env bash
# apply-title-control-page.sh
# MARKER-TITLE-CONTROL — one page for titles, replacing two.
#
# Today the same row in catalog_title_settings is edited from two screens.
# Title templates writes title + subtitle + search + size priority; the
# Catalog Titles drawer writes only the title. Neither says which is
# authoritative, and saving from Title templates with an empty size box
# NULLS size_attribute_priority for that scope — the review page's own code
# comment says it refuses to write that field for exactly this reason.
#
# This page takes the union and drops the ambiguity:
#
#   * ONE surface. The old two are unregistered from navigation but left in
#     place, so nothing 404s and reverting is a one-line change.
#
#   * THE WHOLE LADDER IS ON SCREEN. Built-in fallback (locked, shown because
#     it exists in code and no screen ever admitted it), global default,
#     per-distributor default, then categories. Each default says how many
#     categories currently inherit from it, because editing one changes all
#     of them at once.
#
#   * PER-FIELD INHERITANCE. The ladder fills title, subtitle and search
#     INDEPENDENTLY — a category can own its title while its subtitle comes
#     from the distributor and its search text from global. That is true
#     today and invisible, and is most of why the source of truth feels
#     unclear. Each field now says "set here" or "inherited · HLC", with the
#     source clickable to go and edit the level actually responsible.
#
#   * TOKENS ARE FINDABLE. One search box filters every token and every
#     attribute present on the sampled items, and each one shows WHAT IT
#     RESOLVES TO on the first sample row. Clicking inserts into whichever
#     field you last touched. Guessing whether {attr:Casing} has a value is
#     the slowest part of writing a rule.
#
#   * SIZE-FROM-ATTRIBUTE IS GONE. {size|attr:Labeled Size} does the same job
#     inside the template. The old field is not written by this page, so the
#     stored value on seeded rules is left intact rather than nulled.
#
# Previews always render through the real composer, so what is shown cannot
# drift from what recompose produces. Editing a DEFAULT samples across
# several categories rather than one — editing a global rule while looking
# at tyres is how brake pads get worse unnoticed.
set -e

# ---------------------------------------------------------------- composer
python3 <<'PY'
import io

p = 'app/Services/Distributors/CatalogTitleComposer.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-TITLE-CONTROL' not in s, 'already applied'

old = """    public function matchedSetting(string $code, string $categoryPath = ''): ?CatalogTitleSetting"""
assert s.count(old) == 1, 'C1 matchedSetting anchor'
s = s.replace(old, """    /**
     * MARKER-TITLE-CONTROL — which rule supplied EACH field, not just the row
     * that matched first.
     *
     * setting() already fills every field from the first rule that has a
     * value for it, so a scope can own its title while inheriting its
     * subtitle from the distributor and its search text from global. That
     * provenance is thrown away when setting() synthesises its return value,
     * which is why no screen has ever been able to say where a field came
     * from. This walks the same ladder and keeps it.
     *
     * @return array<string,array{value:string,from:string,code:?string,key:?string}>
     */
    public function fieldSources(string $code, string $categoryPath = ''): array
    {
        $rows   = $this->settingRows();
        $fields = ['title_template', 'subtitle_template', 'search_template'];

        $out = [];
        foreach ($fields as $f) {
            $out[$f] = ['value' => null, 'from' => null, 'code' => null, 'key' => null];
        }

        foreach ([$code, '*'] as $dist) {
            foreach ($this->categoryCandidates($categoryPath) as $cand) {
                foreach ($rows as $row) {
                    if ($row->distributor_code !== $dist
                        || $this->normKey((string) $row->category_key) !== $this->normKey($cand)) {
                        continue;
                    }
                    foreach ($fields as $f) {
                        if ($out[$f]['value'] !== null || trim((string) $row->$f) === '') {
                            continue;
                        }
                        $out[$f] = [
                            'value' => (string) $row->$f,
                            'from'  => $row->distributor_code === '*'
                                ? 'Global default'
                                : $row->distributor_code . ' · '
                                  . ($row->category_key !== '' ? $row->category_key : 'any category'),
                            'code'  => (string) $row->distributor_code,
                            'key'   => (string) $row->category_key,
                        ];
                    }
                }
            }
        }

        // Nothing in the ladder had a value — this is the built-in, which is
        // real and worth naming rather than letting a title appear from
        // nowhere.
        $builtin = [
            'title_template'    => self::FALLBACK_TITLE,
            'subtitle_template' => self::FALLBACK_SUBTITLE,
            'search_template'   => '',
        ];
        foreach ($fields as $f) {
            if ($out[$f]['value'] === null) {
                $out[$f] = ['value' => $builtin[$f], 'from' => 'built-in fallback', 'code' => null, 'key' => null];
            }
        }

        return $out;
    }

    public function matchedSetting(string $code, string $categoryPath = ''): ?CatalogTitleSetting""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

# ---------------------------------------------------------------- page class
cat <<'PHPEOF' > app/Filament/Pages/CatalogTitleControl.php
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
PHPEOF
echo "created app/Filament/Pages/CatalogTitleControl.php"

# ---------------------------------------------------------------- view
mkdir -p resources/views/filament/pages
cat <<'BLADEEOF' > resources/views/filament/pages/catalog-title-control.blade.php
{{-- MARKER-TITLE-CONTROL --}}
<x-filament-panels::page>

<style>
  .tc-grid{display:grid;grid-template-columns:300px 1fr;gap:16px;align-items:start}
  .tc-rail{display:flex;flex-direction:column;gap:14px;position:sticky;top:16px}
  .tc-defs{border:1px solid #3b3320;border-radius:10px;background:rgba(217,164,65,.05);overflow:hidden}
  .tc-defs .h{padding:9px 12px;background:rgba(217,164,65,.10);border-bottom:1px solid #3b3320;
    font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#d9a441}
  .tc-lvl{padding:10px 12px;border-bottom:1px solid rgba(217,164,65,.14);cursor:pointer;width:100%;text-align:left;background:none}
  .tc-lvl:last-child{border-bottom:0}
  .tc-lvl:hover{background:rgba(217,164,65,.07)}
  .tc-lvl.on{background:rgba(124,92,255,.14);box-shadow:inset 2px 0 0 rgb(var(--primary-500))}
  .tc-lvl .n{font-size:12.5px;font-weight:600;display:flex;align-items:center;gap:7px}
  .tc-lvl .s{display:block;font-size:10.5px;opacity:.55;margin-top:3px;line-height:1.5}
  .tc-lvl.locked{opacity:.55;cursor:default}
  .tc-step{display:inline-block;width:13px;opacity:.45;font-size:10px}

  .tc-q{border:1px solid rgba(255,255,255,.10);border-radius:10px;overflow:hidden}
  .tc-q .h{padding:9px 12px;background:rgba(255,255,255,.03);border-bottom:1px solid rgba(255,255,255,.10);
    font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;opacity:.55}
  .tc-qi{padding:9px 12px;border-bottom:1px solid rgba(255,255,255,.06);font-size:12px;cursor:pointer;width:100%;text-align:left;background:none}
  .tc-qi:hover{background:rgba(255,255,255,.04)}
  .tc-qi.on{background:rgba(124,92,255,.14);box-shadow:inset 2px 0 0 rgb(var(--primary-500))}
  .tc-qi .s{display:block;font-size:10.5px;opacity:.5;margin-top:2px}

  .tc-chain{display:flex;align-items:center;gap:7px;flex-wrap:wrap;font-size:11.5px;margin-bottom:14px}
  .tc-node{border:1px solid rgba(255,255,255,.12);border-radius:7px;padding:5px 10px;background:none;cursor:pointer}
  .tc-node.on{border-color:rgb(var(--primary-500));background:rgba(124,92,255,.12)}
  .tc-node.def{border-color:#3b3320;background:rgba(217,164,65,.06)}
  .tc-node .t{font-size:9.5px;opacity:.5;display:block}

  .tc-split{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  @media (max-width:1500px){.tc-split{grid-template-columns:1fr}}

  .tc-card{border:1px solid rgba(255,255,255,.10);border-radius:10px;padding:13px}
  .tc-lbl{font-size:10.5px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;opacity:.55;
    margin-bottom:6px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
  .tc-tpl{width:100%;background:rgba(0,0,0,.28);border:1px solid rgba(255,255,255,.12);border-radius:8px;
    padding:9px 11px;font-family:ui-monospace,Menlo,monospace;font-size:12.5px;color:inherit}
  .tc-tpl.inh{border-style:dashed;opacity:.72}

  .tc-pill{font-size:9.5px;border-radius:4px;padding:2px 6px;font-weight:600}
  .tc-pill.own{background:rgba(74,222,128,.16);color:#4ade80}
  .tc-pill.inh{background:rgba(255,255,255,.09);opacity:.75}
  .tc-pill.warn{background:rgba(217,164,65,.18);color:#d9a441}
  .tc-link{text-decoration:underline;text-underline-offset:2px;cursor:pointer}

  .tc-tok{display:flex;justify-content:space-between;gap:10px;width:100%;text-align:left;background:none;
    padding:5px 8px;border-radius:6px;font-size:11.5px;cursor:pointer;border:1px solid transparent}
  .tc-tok:hover{background:rgba(124,92,255,.12);border-color:rgba(124,92,255,.35)}
  .tc-tok .k{font-family:ui-monospace,monospace}
  .tc-tok .v{opacity:.5;font-size:11px;max-width:52%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .tc-tok .v.none{opacity:.28;font-style:italic}
  .tc-grp{font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;opacity:.4;margin:9px 0 4px}

  .tc-prow{padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.06);font-size:12px;line-height:1.65}
  .tc-prow:last-child{border-bottom:0}
  .tc-was{opacity:.42;text-decoration:line-through}
  .tc-now{color:#4ade80}
  .tc-sku{font-family:ui-monospace,monospace;font-size:10.5px;opacity:.45;display:block;margin-bottom:3px}
</style>

@php
  // Each of these runs a query or the composer. Livewire does not memoize a
  // getXProperty between reads, so a bare $this->preview in three places
  // would recompose the samples three times.
  $sources = $this->sources;
  $deps    = $this->dependents;
  $counts  = $this->counts;
  $preview = $this->preview;
  $tokens  = $this->tokens;

  $seenWas = [];
  $seenNow = [];
  foreach ($preview as $p) {
      $w = trim((string) $p['was']);
      $n = trim((string) $p['now']);
      if ($w !== '') { $seenWas[$w] = ($seenWas[$w] ?? 0) + 1; }
      if ($n !== '') { $seenNow[$n] = ($seenNow[$n] ?? 0) + 1; }
  }
  $dupes = [
      'was' => count(array_filter($seenWas, fn ($c) => $c > 1)),
      'now' => count(array_filter($seenNow, fn ($c) => $c > 1)),
  ];
@endphp

<div class="tc-grid">

  {{-- ============================ rail ============================ --}}
  <div class="tc-rail">

    <div class="tc-defs">
      <div class="h">Defaults — the ladder</div>

      <div class="tc-lvl locked">
        <div class="n"><span class="tc-step">0</span> Built-in fallback</div>
        <span class="s"><code>{brand} {model}</code> · used only when nothing below has a value. Not editable.</span>
      </div>

      <button type="button" class="tc-lvl {{ $level === 'global' ? 'on' : '' }}" wire:click="selectGlobal">
        <div class="n"><span class="tc-step">1</span> Global default</div>
        <span class="s">Every distributor · <b>{{ number_format($deps['*'] ?? 0) }}</b> categories inherit from here</span>
      </button>

      @foreach ($this->distributors as $code)
        <button type="button"
                class="tc-lvl {{ $level === 'dist' && $distributor === $code ? 'on' : '' }}"
                wire:click="selectDistributor('{{ $code }}')">
          <div class="n"><span class="tc-step">2</span> {{ $code }} · any category</div>
          <span class="s">Overrides global for {{ $code }} · <b>{{ number_format($deps[$code] ?? 0) }}</b> categories inherit from here</span>
        </button>
      @endforeach
    </div>

    <div class="tc-q">
      <div class="h">Categories — {{ number_format($this->queueLeft) }} to review</div>

      <div style="padding:9px 12px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;gap:6px;flex-wrap:wrap">
        <select wire:model.live="queueDistributor" class="fi-input" style="font-size:11.5px;padding:4px 8px;border-radius:6px;background:rgba(0,0,0,.25);border:1px solid rgba(255,255,255,.12);color:inherit">
          <option value="">All</option>
          @foreach ($this->distributors as $code)
            <option value="{{ $code }}">{{ $code }}</option>
          @endforeach
        </select>
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="Find category…"
               style="flex:1;min-width:110px;font-size:11.5px;padding:4px 8px;border-radius:6px;background:rgba(0,0,0,.25);border:1px solid rgba(255,255,255,.12);color:inherit">
      </div>

      <div style="padding:7px 12px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;gap:5px;flex-wrap:wrap">
        <button type="button" wire:click="$set('filter','review')"
                class="tc-pill {{ $filter === 'review' ? 'warn' : 'inh' }}">Needs work {{ $counts['review'] }}</button>
        <button type="button" wire:click="$set('filter','own')"
                class="tc-pill {{ $filter === 'own' ? 'warn' : 'inh' }}">Has rule {{ $counts['own'] }}</button>
        <button type="button" wire:click="$set('filter','all')"
                class="tc-pill {{ $filter === 'all' ? 'warn' : 'inh' }}">All {{ $counts['all'] }}</button>
      </div>

      <div style="max-height:430px;overflow:auto">
        @forelse ($this->scopes as $scope)
          <button type="button"
                  class="tc-qi {{ $level === 'scope' && $scopeId === $scope->id ? 'on' : '' }}"
                  wire:click="selectScope({{ $scope->id }})">
            <div style="display:flex;align-items:center;gap:6px">
              <span style="opacity:.5;font-size:10.5px">{{ $scope->distributor_code }}</span>
              <span>{{ $scope->category_key ?: 'any category' }}</span>
              @if ($scope->reviewed)
                <span style="margin-left:auto;color:#4ade80;font-size:10.5px">done</span>
              @endif
            </div>
            <span class="s">
              {{ number_format($scope->item_count) }} items
              @if ($scope->has_own_rule) · own rule @else · inherits @endif
            </span>
          </button>
        @empty
          <div style="padding:14px;font-size:12px;opacity:.6">Nothing matches.</div>
        @endforelse
      </div>

      <div style="padding:8px 12px;border-top:1px solid rgba(255,255,255,.08)">
        {{ $this->scopes->links() }}
      </div>
    </div>
  </div>

  {{-- ============================ main ============================ --}}
  <div>

    <div class="tc-chain">
      @foreach ($this->chain as $node)
        @if ($node['level'] === 'fallback')
          <span class="tc-node" style="opacity:.5;cursor:default">built-in</span>
        @elseif ($node['level'] === 'global')
          <button type="button" class="tc-node def {{ $node['active'] ? 'on' : '' }}" wire:click="selectGlobal">
            <span class="t">level 1</span>Global</button>
        @elseif ($node['level'] === 'dist')
          <button type="button" class="tc-node def {{ $node['active'] ? 'on' : '' }}"
                  wire:click="selectDistributor('{{ $node['code'] }}')">
            <span class="t">level 2</span>{{ $node['label'] }}</button>
        @else
          <span class="tc-node on"><span class="t">editing · level 3</span>{{ $node['label'] }}</span>
        @endif
        @if (! $loop->last)
          <span style="opacity:.35">&rarr;</span>
        @endif
      @endforeach
    </div>

    @if ($level !== 'scope')
      <div style="border:1px solid #3b3320;background:rgba(217,164,65,.06);border-radius:9px;padding:11px 13px;font-size:12.5px;line-height:1.6;margin-bottom:14px">
        <b style="color:#d9a441">Editing a default.</b>
        {{ number_format($level === 'global' ? ($deps['*'] ?? 0) : ($deps[$distributor] ?? 0)) }}
        categories inherit their titles from here — changing it changes all of them.
        The preview below samples across several categories rather than one.
      </div>
    @endif

    <div class="tc-split">

      {{-- ---------------- templates ---------------- --}}
      <div style="display:flex;flex-direction:column;gap:12px">

        <div class="tc-card">
          <div class="tc-lbl">
            Display title
            @if ($sources['title_template']['own'])
              <span class="tc-pill own">set here</span>
            @else
              <span class="tc-pill inh">inherited · {{ $sources['title_template']['from'] }}</span>
            @endif
          </div>
          <input type="text" class="tc-tpl {{ $sources['title_template']['own'] ? '' : 'inh' }}"
                 wire:model.live.debounce.500ms="title_template"
                 wire:focus="focusField('title_template')">
          <div style="font-size:11.5px;opacity:.55;margin-top:6px">Short — what the cashier scans.</div>
        </div>

        <div class="tc-card">
          <div class="tc-lbl">
            Subtitle
            @if ($sources['subtitle_template']['own'])
              <span class="tc-pill own">set here</span>
            @else
              <span class="tc-pill inh">inherited · {{ $sources['subtitle_template']['from'] }}</span>
            @endif
          </div>
          <input type="text" class="tc-tpl {{ $sources['subtitle_template']['own'] ? '' : 'inh' }}"
                 wire:model.live.debounce.500ms="subtitle_template"
                 wire:focus="focusField('subtitle_template')">
          <div style="font-size:11.5px;opacity:.55;margin-top:6px">The line that confirms the right item.</div>
        </div>

        <div class="tc-card">
          <div class="tc-lbl">
            Search text
            @if ($sources['search_template']['own'])
              <span class="tc-pill own">set here</span>
            @else
              <span class="tc-pill inh">inherited · {{ $sources['search_template']['from'] }}</span>
            @endif
          </div>
          <input type="text" class="tc-tpl {{ $sources['search_template']['own'] ? '' : 'inh' }}"
                 wire:model.live.debounce.500ms="search_template"
                 wire:focus="focusField('search_template')">
          <div style="font-size:11.5px;opacity:.55;margin-top:6px">Never shown — indexed so odd searches still find it.</div>
        </div>

        {{-- ---------------- tokens ---------------- --}}
        <div class="tc-card">
          <div class="tc-lbl">
            Tokens
            <span style="font-weight:400;text-transform:none;letter-spacing:0;opacity:.7">
              click to add to <b>{{ str_replace('_template', '', $activeField) }}</b>
            </span>
          </div>

          <input type="text" wire:model.live.debounce.250ms="tokenSearch"
                 placeholder="Search tokens and attributes…"
                 style="width:100%;margin-bottom:9px;font-size:12px;padding:6px 10px;border-radius:7px;background:rgba(0,0,0,.25);border:1px solid rgba(255,255,255,.12);color:inherit">

          <div style="font-size:11.5px;opacity:.55;line-height:1.6;margin-bottom:8px">
            Values shown are from the first sample row. Separate tokens with
            <code>|</code> to try them in order — <code>{size|attr:Labeled Size}</code>
            uses the first that has a value.
          </div>

          <div style="max-height:330px;overflow:auto">
            @forelse ($tokens as $group => $items)
              <div class="tc-grp">{{ $group }}</div>
              @foreach ($items as $t)
                <button type="button" class="tc-tok" wire:click="insertToken(@js($t['token']))">
                  <span class="k">{{ $t['token'] }}</span>
                  <span class="v {{ $t['value'] === '' ? 'none' : '' }}">
                    {{ $t['value'] === '' ? 'empty here' : \Illuminate\Support\Str::limit($t['value'], 40) }}
                  </span>
                </button>
              @endforeach
            @empty
              <div style="font-size:12px;opacity:.6;padding:6px 0">No token matches “{{ $tokenSearch }}”.</div>
            @endforelse
          </div>
        </div>
      </div>

      {{-- ---------------- preview ---------------- --}}
      <div style="display:flex;flex-direction:column;gap:12px">
        <div style="border:1px solid rgba(255,255,255,.10);border-radius:10px;overflow:hidden">
          <div style="padding:10px 13px;border-bottom:1px solid rgba(255,255,255,.10);background:rgba(255,255,255,.03);font-size:12px;display:flex;align-items:center;gap:9px;flex-wrap:wrap">
            <b>Preview</b>
            <span style="opacity:.55">{{ count($preview) }} sample rows</span>
            @if ($dupes['was'] || $dupes['now'])
              <span class="tc-pill {{ $dupes['now'] < $dupes['was'] ? 'own' : 'warn' }}">
                {{ $dupes['was'] }} &rarr; {{ $dupes['now'] }} duplicate titles
              </span>
            @endif
          </div>

          <div style="max-height:560px;overflow:auto">
            @forelse ($preview as $p)
              <div class="tc-prow">
                <span class="tc-sku">
                  {{ $p['dist'] }} · {{ $p['sku'] }}
                  @if ($level !== 'scope' && $p['category'])
                    · {{ \Illuminate\Support\Str::limit($p['category'], 40) }}
                  @endif
                </span>

                @if ($p['empty'])
                  <div class="tc-was">{{ $p['was'] }}</div>
                  <div style="color:#f87171">produces an empty title — every token in the rule is blank on this item</div>
                @elseif ($p['unchanged'])
                  <div>{{ $p['was'] }}</div>
                  <div style="opacity:.5;font-size:11.5px">unchanged — this rule resolves to what it already had</div>
                @else
                  <div class="tc-was">{{ $p['was'] }}</div>
                  <div class="tc-now">{{ $p['now'] }}</div>
                @endif
              </div>
            @empty
              <div style="padding:16px;font-size:12px;opacity:.6">
                No sample rows for this scope. Run <code>catalog:scan-titles</code> to rebuild the samples.
              </div>
            @endforelse
          </div>
        </div>

        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <span style="font-size:11.5px;opacity:.55">Saving stores the rule; recompose rewrites stored titles.</span>
          <span style="flex:1"></span>
          @if ($level === 'scope')
            <x-filament::button size="sm" color="gray" wire:click="saveAndNext">Save &amp; next</x-filament::button>
          @endif
          <x-filament::button size="sm" color="gray" wire:click="save">Save only</x-filament::button>
          <x-filament::button size="sm" wire:click="saveAndRecompose">Save &amp; recompose</x-filament::button>
        </div>
      </div>
    </div>
  </div>
</div>

</x-filament-panels::page>
BLADEEOF
echo "created resources/views/filament/pages/catalog-title-control.blade.php"

# ---------------------------------------------------------------- register
python3 <<'PY'
import io

p = 'app/Providers/Filament/AdminPanelProvider.php'
s = io.open(p, encoding='utf-8').read()

assert 'CatalogTitleControl' not in s, 'already registered'

old = """                \\App\\Filament\\Pages\\CatalogTitles::class,"""
assert s.count(old) >= 1, 'R1 CatalogTitles registration anchor'
s = s.replace(old, """                // MARKER-TITLE-CONTROL — the merged surface. The two below stay
                // registered so their URLs keep working and reverting is one
                // line, but they are hidden from navigation.
                \\App\\Filament\\Pages\\CatalogTitleControl::class,
                \\App\\Filament\\Pages\\CatalogTitles::class,""", 1)

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# hide the two old pages from nav
for page in ['CatalogTitles', 'CatalogTitleReview']:
    p = f'app/Filament/Pages/{page}.php'
    s = io.open(p, encoding='utf-8').read()
    if 'shouldRegisterNavigation' in s:
        print('  already has shouldRegisterNavigation:', page)
        continue
    anchor = '    protected static string $view'
    assert s.count(anchor) == 1, f'nav anchor in {page}'
    s = s.replace(anchor, """    // MARKER-TITLE-CONTROL — superseded by the merged Titles page. Left
    // routable so bookmarks and any link still resolve, hidden from nav so
    // there is one place to edit a rule.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected static string $view""")
    io.open(p, 'w', encoding='utf-8').write(s)
    print('  hidden from nav:', page)
PY

echo
echo "--- registered and hidden ---"
grep -n "CatalogTitleControl\|CatalogTitles::class\|CatalogTitleReview::class" app/Providers/Filament/AdminPanelProvider.php
grep -c "shouldRegisterNavigation" app/Filament/Pages/CatalogTitles.php app/Filament/Pages/CatalogTitleReview.php

echo
echo "--- blade sweep ---"
python3 - <<'PY'
import io, re
f = 'resources/views/filament/pages/catalog-title-control.blade.php'
raw = io.open(f, encoding='utf-8').read()
s = re.sub(r'\{\{--.*?--\}\}', '', raw, flags=re.S)
print('glued:', len(re.findall(r'\w@(?:if|endif|foreach|endforeach|forelse|endforelse|else|elseif|php|endphp)\b', s)))
for a, b in [('@if','@endif'), ('@foreach','@endforeach'), ('@forelse','@endforelse'), ('@php','@endphp')]:
    o = len(re.findall(r'\B'+a+r'\b', s)); c = len(re.findall(r'\B'+b+r'\b', s))
    print(' ', a, o, b, c, 'OK' if o == c else 'MISMATCH')
# the known trap: blade comments inside @php blocks
for m in re.finditer(r'@php(.*?)@endphp', raw, re.S):
    if '{{--' in m.group(1):
        print('  *** blade comment inside @php — parse error ***')
print('  no blade comments in @php blocks' if not any('{{--' in m.group(1) for m in re.finditer(r'@php(.*?)@endphp', raw, re.S)) else '')
PY

echo "--- php balance ---"
python3 - <<'PY'
import io
for p in ['app/Filament/Pages/CatalogTitleControl.php',
          'app/Services/Distributors/CatalogTitleComposer.php',
          'app/Providers/Filament/AdminPanelProvider.php',
          'app/Filament/Pages/CatalogTitles.php',
          'app/Filament/Pages/CatalogTitleReview.php']:
    s = io.open(p, encoding='utf-8').read()
    i, n, d, par, brk = 0, len(s), 0, 0, 0
    while i < n:
        c = s[i]
        if c == '#' or (c == '/' and i+1 < n and s[i+1] == '/'):
            while i < n and s[i] != '\n': i += 1
        elif c == '/' and i+1 < n and s[i+1] == '*':
            i += 2
            while i+1 < n and not (s[i] == '*' and s[i+1] == '/'): i += 1
            i += 2
        elif c in '"\'':
            q = c; i += 1
            while i < n and s[i] != q:
                if s[i] == '\\': i += 1
                i += 1
            i += 1
        else:
            if c == '{': d += 1
            elif c == '}': d -= 1
            elif c == '(': par += 1
            elif c == ')': par -= 1
            elif c == '[': brk += 1
            elif c == ']': brk -= 1
            i += 1
    print('%-52s braces %d parens %d brackets %d' % (p.split('/')[-1], d, par, brk))
PY

echo
echo "--- every method the view calls exists on the page ---"
python3 - <<'PY'
import io, re
page = io.open('app/Filament/Pages/CatalogTitleControl.php', encoding='utf-8').read()
view = io.open('resources/views/filament/pages/catalog-title-control.blade.php', encoding='utf-8').read()

methods = set(re.findall(r'public function (\w+)', page))
props   = {m[3:].lower()[:-8] for m in methods if m.startswith('get') and m.endswith('Property')}
props   = {re.sub(r'Property$', '', m[3:]) for m in methods if m.startswith('get') and m.endswith('Property')}
props   = {p[0].lower() + p[1:] for p in props}

called = set(re.findall(r'wire:click="(\w+)\(', view))
used   = set(re.findall(r'\$this->(\w+)', view))

missing_m = sorted(c for c in called if c not in methods and not c.startswith('$'))
missing_p = sorted(u for u in used if u not in props and u not in methods)
print('wire:click targets missing :', missing_m or 'none')
print('$this-> properties missing :', missing_p or 'none')
PY

echo
echo "apply-title-control-page: OK"
