#!/bin/bash
# catalog-title-category-scope — title rules become (distributor, category)
# instead of distributor alone, and size can be read from an attribute
# instead of scraped out of the description.
#
#   Why: one template per distributor means a tire and a helmet are titled
#   the same way. And {size} came from running regex patterns over the
#   description text, first match wins — on a Maxxis tire that matched
#   "60x2" (the casing TPI, written 60x2TPI) long before it reached
#   "Labeled Size 27.5''x2.40". Hence "Maxxis Minion DHR2 60x2 Black EA
#   Tires".
#
#   Resolution, most specific first:
#     HLC + "Tires > Mountain > Tubeless Ready"   (exact path)
#     HLC + "Tires > Mountain"                    (drop a segment)
#     HLC + "Tires"                               (longest prefix that has a rule)
#     HLC + ""                                    (any category — today's row)
#     *   + ""                                    (global default)
#   Prefix matching is the point: one "Tires" rule covers the whole branch,
#   and a narrower rule can still be added later and will win. Patterns are
#   scoped by the SAME rules, because size regexes are more category-specific
#   than templates are — that mismatch is what produced 60x2 in the first
#   place.
#
#   category_key is '' (not null) for "any category" so the unique index
#   actually constrains — MySQL allows repeated NULLs in a unique index.
#
#   Also folds compose()'s duplicated token-building into makeResolver(),
#   which already had an identical copy. Two copies of that logic is how
#   the editor preview and the real sync drift apart.
#
#   Seeds one HLC + Tires rule matching the target title. Seeding changes
#   nothing on its own: stored titles only move when you press Save &
#   Recompose.
# MIGRATION REQUIRED. Server: migrate, then optimize:clear.
set -e
if grep -q "MARKER-TITLE-CATEGORY-SCOPE" app/Services/Distributors/CatalogTitleComposer.php; then
  echo "catalog-title-category-scope already applied — aborting."; exit 1
fi

# ---------------------------------------------------------------- migration
cat > 'database/migrations/2026_07_27_000001_add_category_scope_to_catalog_titles.php' <<'CTS_0_EOF'
<?php

// MARKER-TITLE-CATEGORY-SCOPE

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_title_settings', function (Blueprint $t) {
            // '' = any category. Empty string rather than null so the unique
            // index below actually prevents duplicates.
            $t->string('category_key', 191)->default('')->after('distributor_code');
            // Attribute names to try for {size}, in order, before falling back
            // to the regex patterns. e.g. ["Labeled Size"] on tires.
            $t->json('size_attribute_priority')->nullable()->after('color_attribute_priority');
        });

        Schema::table('catalog_title_settings', function (Blueprint $t) {
            $t->dropUnique('cts_dist_unique');
            $t->unique(['distributor_code', 'category_key'], 'cts_dist_cat_unique');
        });

        Schema::table('catalog_title_patterns', function (Blueprint $t) {
            $t->string('category_key', 191)->default('')->after('distributor_code');
            $t->index(['distributor_code', 'category_key'], 'ctp_dist_cat_idx');
        });

        // Seed the tire rule off whatever HLC already uses, so search and
        // color behaviour carry over and only the title line differs.
        $hlc = DB::table('catalog_title_settings')
            ->where('distributor_code', 'HLC')
            ->where('category_key', '')
            ->first();

        $exists = DB::table('catalog_title_settings')
            ->where('distributor_code', 'HLC')
            ->where('category_key', 'Tires')
            ->exists();

        if ($hlc && ! $exists) {
            DB::table('catalog_title_settings')->insert([
                'distributor_code'         => 'HLC',
                'category_key'             => 'Tires',
                'title_template'           => '{brand} {model} {size} {attr:Tire Compound} {attr:Tire Technology}',
                'subtitle_template'        => $hlc->subtitle_template,
                'search_template'          => $hlc->search_template,
                'color_attribute_priority' => $hlc->color_attribute_priority,
                'size_attribute_priority'  => json_encode(['Labeled Size']),
                'is_active'                => 1,
                'notes'                    => 'Tires: size from the Labeled Size attribute, not the description.',
                'created_at'               => now(),
                'updated_at'               => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('catalog_title_settings')
            ->where('distributor_code', 'HLC')
            ->where('category_key', 'Tires')
            ->delete();

        Schema::table('catalog_title_patterns', function (Blueprint $t) {
            $t->dropIndex('ctp_dist_cat_idx');
            $t->dropColumn('category_key');
        });

        Schema::table('catalog_title_settings', function (Blueprint $t) {
            $t->dropUnique('cts_dist_cat_unique');
            $t->dropColumn(['category_key', 'size_attribute_priority']);
        });

        Schema::table('catalog_title_settings', function (Blueprint $t) {
            $t->unique('distributor_code', 'cts_dist_unique');
        });
    }
};
CTS_0_EOF

# ---------------------------------------------------------------- models
python3 - <<'CTS_1_EOF'
import io

p = 'app/Models/CatalogTitleSetting.php'
s = io.open(p, encoding='utf-8').read()
old = """    protected $fillable = [
        'distributor_code', 'title_template', 'subtitle_template', 'search_template',
        'color_attribute_priority', 'is_active', 'notes',
    ];

    protected $casts = [
        'color_attribute_priority' => 'array',
        'is_active' => 'boolean',
    ];"""
assert s.count(old) == 1
new = """    // MARKER-TITLE-CATEGORY-SCOPE \u2014 category_key '' means "any category".
    protected $fillable = [
        'distributor_code', 'category_key', 'title_template', 'subtitle_template',
        'search_template', 'color_attribute_priority', 'size_attribute_priority',
        'is_active', 'notes',
    ];

    protected $casts = [
        'color_attribute_priority' => 'array',
        'size_attribute_priority'  => 'array',
        'is_active' => 'boolean',
    ];"""
io.open(p, 'w', encoding='utf-8').write(s.replace(old, new))
print('CatalogTitleSetting ok')

p = 'app/Models/CatalogTitlePattern.php'
s = io.open(p, encoding='utf-8').read()
old = """        'distributor_code', 'label', 'pattern', 'sort_order', 'is_active', 'notes',"""
assert s.count(old) == 1
new = """        'distributor_code', 'category_key', 'label', 'pattern', 'sort_order',
        'is_active', 'notes',"""
io.open(p, 'w', encoding='utf-8').write(s.replace(old, new))
print('CatalogTitlePattern ok')
CTS_1_EOF

# ---------------------------------------------------------------- composer
python3 - <<'CTS_2_EOF'
import io
p = 'app/Services/Distributors/CatalogTitleComposer.php'
s = io.open(p, encoding='utf-8').read()

# --- 1. compose(): drop the duplicated resolver, delegate to makeResolver --
start = s.index('    public function compose(string $distributorCode, array $parts): array')
end = s.index('    /**\n     * Build the token resolver for a parts array.')
new_compose = """    public function compose(string $distributorCode, array $parts): array
    {
        // MARKER-TITLE-CATEGORY-SCOPE \u2014 this used to carry its own copy of
        // the whole token-building block that makeResolver() already had.
        // Two copies meant the editor preview and the real sync could drift.
        $categoryPath = (string) ($parts['category_path'] ?? '');
        $setting = $this->setting($distributorCode, $categoryPath);

        [$resolve, $size, $color] = $this->makeResolver($distributorCode, $parts);

        return [
            'title'    => $this->render($setting->title_template ?: self::FALLBACK_TITLE, $resolve),
            'subtitle' => $this->render($setting->subtitle_template ?: self::FALLBACK_SUBTITLE, $resolve),
            'search'   => $this->render((string) ($setting->search_template ?? ''), $resolve),
            'size'     => $size,
            'color'    => $color,
        ];
    }

"""
s = s[:start] + new_compose + s[end:]

# --- 2. makeResolver(): scope-aware setting + attribute-first size ---------
old = """        $setting = $this->setting($distributorCode);
        $attrs = $parts['attributes'] ?? [];

        $color = $this->pickColor(
            $attrs,
            $setting->color_attribute_priority ?: self::FALLBACK_COLOR_PRIORITY
        );
        $size = $this->extractSize($distributorCode, (string) ($parts['description'] ?? ''));"""
assert s.count(old) == 1, s.count(old)
new = """        $categoryPath = (string) ($parts['category_path'] ?? '');
        $setting = $this->setting($distributorCode, $categoryPath);
        $attrs = $parts['attributes'] ?? [];

        $color = $this->pickAttribute(
            $attrs,
            $setting->color_attribute_priority ?: self::FALLBACK_COLOR_PRIORITY
        );

        // MARKER-TITLE-CATEGORY-SCOPE \u2014 a named attribute beats scraping the
        // description. On tires the description says "TPI 60x2TPI" before it
        // ever says "Labeled Size 27.5''x2.40", so the regex path returned
        // the thread count as the size.
        $size = $this->pickAttribute($attrs, $setting->size_attribute_priority ?: []);
        if ($size === '') {
            $size = $this->extractSize(
                $distributorCode,
                (string) ($parts['description'] ?? ''),
                $categoryPath
            );
        }"""
s = s.replace(old, new)

# --- 3. pickColor -> pickAttribute ---------------------------------------
old = """    /** First attribute value whose Name matches the priority list (case-insensitive). */
    private function pickColor(array $attributes, array $priority): string"""
assert s.count(old) == 1
new = """    /** First attribute value whose Name matches the priority list (case-insensitive). */
    private function pickAttribute(array $attributes, array $priority): string"""
s = s.replace(old, new)

# --- 4. extractSize(): take the category through ---------------------------
old = """    public function extractSize(string $distributorCode, string $description): string
    {
        if ($description === '') {
            return '';
        }
        foreach ($this->patterns($distributorCode) as $pattern) {"""
assert s.count(old) == 1
new = """    public function extractSize(string $distributorCode, string $description, string $categoryPath = ''): string
    {
        if ($description === '') {
            return '';
        }
        foreach ($this->patterns($distributorCode, $categoryPath) as $pattern) {"""
s = s.replace(old, new)

# --- 5. setting() + patterns(): scope resolution ---------------------------
old = """    private function setting(string $code): CatalogTitleSetting
    {
        if (! isset($this->settingCache[$code])) {
            $row = CatalogTitleSetting::query()
                ->where('is_active', true)
                ->whereIn('distributor_code', [$code, '*'])
                ->orderByRaw('CASE WHEN distributor_code = ? THEN 0 ELSE 1 END', [$code])
                ->first();
            $this->settingCache[$code] = $row ?? new CatalogTitleSetting([
                'title_template' => self::FALLBACK_TITLE,
                'subtitle_template' => self::FALLBACK_SUBTITLE,
                'color_attribute_priority' => self::FALLBACK_COLOR_PRIORITY,
            ]);
        }
        return $this->settingCache[$code];
    }"""
assert s.count(old) == 1, s.count(old)
new = """    /**
     * MARKER-TITLE-CATEGORY-SCOPE
     *
     * Category keys for a path, most specific first, always ending in ''
     * (any category). "Tires > Mountain > Tubeless Ready" yields the full
     * path, then "Tires > Mountain", then "Tires", then ''. So a single
     * "Tires" rule covers the branch without enumerating leaves, and a
     * narrower rule added later automatically outranks it.
     */
    private function categoryCandidates(string $categoryPath): array
    {
        $segs = array_values(array_filter(array_map('trim', preg_split('/>+/', $categoryPath))));
        $out = [];
        for ($i = count($segs); $i > 0; $i--) {
            $out[] = implode(' > ', array_slice($segs, 0, $i));
        }
        $out[] = '';
        return $out;
    }

    /** Compare keys without caring about spacing or case around the separator. */
    private function normKey(string $key): string
    {
        $key = preg_replace('/\\s+/', ' ', trim($key));
        $key = preg_replace('/\\s*>\\s*/', ' > ', (string) $key);
        return mb_strtolower((string) $key);
    }

    /** All active setting rows, read once per instance. */
    private function settingRows(): array
    {
        if ($this->settingRows === null) {
            $this->settingRows = CatalogTitleSetting::query()
                ->where('is_active', true)
                ->get()
                ->all();
        }
        return $this->settingRows;
    }

    /**
     * Most specific rule wins: this distributor across every category
     * candidate first, then the global distributor. A distributor's
     * catch-all beats another distributor's specific rule, which is why
     * the distributor loop is the outer one.
     */
    private function setting(string $code, string $categoryPath = ''): CatalogTitleSetting
    {
        $cacheKey = $code . '|' . $categoryPath;
        if (isset($this->settingCache[$cacheKey])) {
            return $this->settingCache[$cacheKey];
        }

        $rows = $this->settingRows();
        $found = null;

        foreach ([$code, '*'] as $dist) {
            foreach ($this->categoryCandidates($categoryPath) as $cand) {
                foreach ($rows as $row) {
                    if ($row->distributor_code === $dist
                        && $this->normKey((string) $row->category_key) === $this->normKey($cand)) {
                        $found = $row;
                        break 3;
                    }
                }
            }
        }

        return $this->settingCache[$cacheKey] = $found ?? new CatalogTitleSetting([
            'title_template' => self::FALLBACK_TITLE,
            'subtitle_template' => self::FALLBACK_SUBTITLE,
            'color_attribute_priority' => self::FALLBACK_COLOR_PRIORITY,
        ]);
    }"""
s = s.replace(old, new)

old = """    /** @return array<int,string> ordered regex bodies */
    private function patterns(string $code): array
    {
        if (! isset($this->patternCache[$code])) {
            $this->patternCache[$code] = CatalogTitlePattern::query()
                ->where('is_active', true)
                ->whereIn('distributor_code', [$code, '*'])
                ->orderBy('sort_order')
                ->pluck('pattern')
                ->all();
        }
        return $this->patternCache[$code];
    }"""
assert s.count(old) == 1, s.count(old)
new = """    /** All active pattern rows, read once per instance. */
    private function patternRows(): array
    {
        if ($this->patternRows === null) {
            $this->patternRows = CatalogTitlePattern::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get()
                ->all();
        }
        return $this->patternRows;
    }

    /**
     * MARKER-TITLE-CATEGORY-SCOPE \u2014 patterns resolve by the same ladder as
     * templates, and the FIRST scope with any rows wins outright. They are
     * not merged: a tire list that inherited the generic NNxNN pattern
     * would keep matching the TPI, which is the bug this exists to stop.
     *
     * @return array<int,string> ordered regex bodies
     */
    private function patterns(string $code, string $categoryPath = ''): array
    {
        $cacheKey = $code . '|' . $categoryPath;
        if (isset($this->patternCache[$cacheKey])) {
            return $this->patternCache[$cacheKey];
        }

        $rows = $this->patternRows();
        $out = [];

        foreach ([$code, '*'] as $dist) {
            foreach ($this->categoryCandidates($categoryPath) as $cand) {
                $hit = [];
                foreach ($rows as $row) {
                    if ($row->distributor_code === $dist
                        && $this->normKey((string) $row->category_key) === $this->normKey($cand)) {
                        $hit[] = (string) $row->pattern;
                    }
                }
                if ($hit) {
                    $out = $hit;
                    break 2;
                }
            }
        }

        return $this->patternCache[$cacheKey] = $out;
    }"""
s = s.replace(old, new)

# --- 6. instance caches for the row reads ---------------------------------
old = """    /** @var array<string,CatalogTitleSetting> */
    private array $settingCache = [];
    /** @var array<string,array<int,string>> */
    private array $patternCache = [];"""
assert s.count(old) == 1
new = """    /** @var array<string,CatalogTitleSetting> keyed "distributor|categoryPath" */
    private array $settingCache = [];
    /** @var array<string,array<int,string>> keyed "distributor|categoryPath" */
    private array $patternCache = [];
    /** MARKER-TITLE-CATEGORY-SCOPE \u2014 whole tables, read once per instance.
     *  Both are small and every row is a candidate now that matching happens
     *  in PHP, so per-scope queries would be strictly more work. */
    private ?array $settingRows = null;
    private ?array $patternRows = null;"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('CatalogTitleComposer ok')
CTS_2_EOF

# ---------------------------------------------------------------- filament
python3 - <<'CTS_3_EOF'
import io
p = 'app/Filament/Pages/CatalogTitles.php'
s = io.open(p, encoding='utf-8').read()

# --- property ------------------------------------------------------------
old = """    public ?array $data = [];
    public string $code = '*';"""
assert s.count(old) == 1
new = """    public ?array $data = [];
    public string $code = '*';
    /** MARKER-TITLE-CATEGORY-SCOPE \u2014 '' means the distributor's catch-all. */
    public string $categoryKey = '';"""
s = s.replace(old, new)

# --- form fields ---------------------------------------------------------
old = """            Select::make('code')->label('Distributor')->native(false)
                ->options($this->distributorOptions())
                ->default('*')->live()
                ->afterStateUpdated(fn ($state) => $this->loadCode((string) $state)),
"""
assert s.count(old) == 1
new = """            Select::make('code')->label('Distributor')->native(false)
                ->options($this->distributorOptions())
                ->default('*')->live()
                ->afterStateUpdated(fn ($state) => $this->loadScope((string) $state, $this->categoryKey)),

            // MARKER-TITLE-CATEGORY-SCOPE
            TextInput::make('category_key')->label('Category scope')
                ->placeholder('Tires')
                ->helperText('Blank applies to every category. Otherwise matches the start of the category path, so "Tires" also covers "Tires > Mountain".')
                ->live(debounce: 500)
                ->afterStateUpdated(fn ($state) => $this->loadScope($this->code, (string) $state))
                ->maxLength(191),

            TextInput::make('size_attribute_priority')->label('Size from attribute')
                ->placeholder('Labeled Size')
                ->helperText('Comma-separated attribute names tried in order for {size}. Falls back to the size patterns when none are present.')
                ->live(debounce: 500)->maxLength(255),
"""
s = s.replace(old, new)

# --- load ----------------------------------------------------------------
old = """    public function loadCode(string $code): void
    {
        $row = CatalogTitleSetting::query()->where('distributor_code', $code)->first()
            ?? CatalogTitleSetting::query()->where('distributor_code', '*')->first();

        $this->code = $code;
        $this->data['code']              = $code;
        $this->data['title_template']    = $row->title_template ?? '{brand} {model} {size} {color}';
        $this->data['subtitle_template'] = $row->subtitle_template ?? '{mpn}';
        $this->data['search_template']   = $row->search_template ?? '';
        $this->data['previewSkus']       = $this->data['previewSkus'] ?? $this->previewSkus;
    }"""
assert s.count(old) == 1, s.count(old)
new = """    public function loadCode(string $code): void
    {
        $this->loadScope($code, $this->categoryKey);
    }

    /**
     * MARKER-TITLE-CATEGORY-SCOPE
     *
     * Load the rule for exactly this (distributor, category). When that
     * pair has no row yet, prefill from whatever rule is in effect for it
     * today \u2014 the distributor catch-all, then the global default \u2014 so a
     * new scope starts from current behaviour rather than from blank, and
     * saving is what creates the row.
     */
    public function loadScope(string $code, string $categoryKey = ''): void
    {
        $categoryKey = trim($categoryKey);

        $row = CatalogTitleSetting::query()
            ->where('distributor_code', $code)
            ->where('category_key', $categoryKey)
            ->first();

        $inherited = false;
        if (! $row) {
            $inherited = $categoryKey !== '';
            $row = CatalogTitleSetting::query()
                    ->where('distributor_code', $code)->where('category_key', '')->first()
                ?? CatalogTitleSetting::query()
                    ->where('distributor_code', '*')->where('category_key', '')->first();
        }

        $this->code = $code;
        $this->categoryKey = $categoryKey;
        $this->data['code']              = $code;
        $this->data['category_key']      = $categoryKey;
        $this->data['inherited']         = $inherited;
        $this->data['title_template']    = $row->title_template ?? '{brand} {model} {size} {color}';
        $this->data['subtitle_template'] = $row->subtitle_template ?? '{mpn}';
        $this->data['search_template']   = $row->search_template ?? '';
        $this->data['size_attribute_priority'] = implode(', ', (array) ($row->size_attribute_priority ?? []));
        $this->data['previewSkus']       = $this->data['previewSkus'] ?? $this->previewSkus;
    }"""
s = s.replace(old, new)

# --- save ----------------------------------------------------------------
old = """        $code = $this->data['code'] ?? '*';
        CatalogTitleSetting::query()->updateOrCreate(
            ['distributor_code' => $code],
            [
                'title_template'    => $this->data['title_template'] ?? '{brand} {model}',
                'subtitle_template' => $this->data['subtitle_template'] ?? '{mpn}',
                'search_template'   => $this->data['search_template'] ?? '',
                'is_active'         => true,
            ]
        );
        Notification::make()->success()->title('Templates saved')
            ->body('Preview reflects the new templates. Recompose to apply to stored rows.')->send();"""
assert s.count(old) == 1, s.count(old)
new = """        $code = $this->data['code'] ?? '*';
        // MARKER-TITLE-CATEGORY-SCOPE \u2014 the row is keyed on both axes now.
        $categoryKey = trim((string) ($this->data['category_key'] ?? ''));

        $sizeAttrs = collect(explode(',', (string) ($this->data['size_attribute_priority'] ?? '')))
            ->map(fn ($v) => trim($v))->filter()->values()->all();

        CatalogTitleSetting::query()->updateOrCreate(
            ['distributor_code' => $code, 'category_key' => $categoryKey],
            [
                'title_template'    => $this->data['title_template'] ?? '{brand} {model}',
                'subtitle_template' => $this->data['subtitle_template'] ?? '{mpn}',
                'search_template'   => $this->data['search_template'] ?? '',
                'size_attribute_priority' => $sizeAttrs ?: null,
                'is_active'         => true,
            ]
        );

        $this->data['inherited'] = false;
        $this->categoryKey = $categoryKey;

        $scope = $categoryKey === '' ? 'every category' : $categoryKey;
        Notification::make()->success()->title('Templates saved')
            ->body(\"Saved for {$code} \u00b7 {$scope}. Recompose to apply to stored rows.\")->send();"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('CatalogTitles page ok')
CTS_3_EOF

php -l app/Services/Distributors/CatalogTitleComposer.php
php -l app/Filament/Pages/CatalogTitles.php
php -l app/Models/CatalogTitleSetting.php

echo
echo "catalog-title-category-scope applied."
