<?php
// MARKER-PATCH-HLC16

namespace App\Services\Distributors;

use App\Models\CatalogTitlePattern;
use App\Models\CatalogTitleSetting;

/**
 * Dumb executor. Reads the editable title template + size patterns + color
 * priority from the database and assembles a display title and subtitle. No
 * operational specifics are hardcoded here — only the mechanics of applying
 * whatever rows master admin has configured. Settings/patterns are loaded once
 * per distributor and cached for the life of the instance (resolve the composer
 * once per sync run, not per variant).
 */
class CatalogTitleComposer
{
    /** @var array<string,CatalogTitleSetting> keyed "distributor|categoryPath" */
    private array $settingCache = [];
    /** @var array<string,array<int,string>> keyed "distributor|categoryPath" */
    private array $patternCache = [];
    /** MARKER-TITLE-CATEGORY-SCOPE — whole tables, read once per instance.
     *  Both are small and every row is a candidate now that matching happens
     *  in PHP, so per-scope queries would be strictly more work. */
    private ?array $settingRows = null;
    private ?array $patternRows = null;

    // Last-resort fallback so a missing/empty config never crashes a sync.
    private const FALLBACK_TITLE = '{brand} {model}';
    private const FALLBACK_SUBTITLE = '{mpn}';
    private const FALLBACK_COLOR_PRIORITY = ['Color', 'Primary Color'];

    /**
     * @param array{brand?:?string,model?:?string,mpn?:?string,description?:?string,attributes?:array} $parts
     * @return array{title:string,subtitle:string,size:string,color:string}
     */
    public function compose(string $distributorCode, array $parts): array
    {
        // MARKER-TITLE-CATEGORY-SCOPE — this used to carry its own copy of
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

    /**
     * Build the token resolver for a parts array.
     * @return array{0:callable,1:string,2:string} [resolver, size, color]
     */
    private function makeResolver(string $distributorCode, array $parts, ?array $sizeAttrOverride = null): array
    {
        $categoryPath = (string) ($parts['category_path'] ?? '');
        $setting = $this->setting($distributorCode, $categoryPath);
        $attrs = $parts['attributes'] ?? [];

        $color = $this->pickAttribute(
            $attrs,
            $setting->color_attribute_priority ?: self::FALLBACK_COLOR_PRIORITY
        );

        // MARKER-TITLE-CATEGORY-SCOPE — a named attribute beats scraping the
        // description. On tires the description says "TPI 60x2TPI" before it
        // ever says "Labeled Size 27.5''x2.40", so the regex path returned
        // the thread count as the size.
        // MARKER-REVIEW-PAGE — the editor previews an UNSAVED size attribute,
        // so an override wins over the stored priority when one is passed.
        $sizePriority = $sizeAttrOverride !== null
            ? $sizeAttrOverride
            : ($setting->size_attribute_priority ?: []);
        $size = $this->pickAttribute($attrs, $sizePriority);
        if ($size === '') {
            $size = $this->extractSize(
                $distributorCode,
                (string) ($parts['description'] ?? ''),
                $categoryPath
            );
        }

        $brand = trim((string) ($parts['brand'] ?? ''));
        $model = trim((string) ($parts['model'] ?? ''));
        $mpn   = trim((string) ($parts['mpn'] ?? ''));
        if ($mpn !== '' && str_ends_with($model, $mpn)) {
            $model = rtrim(substr($model, 0, -strlen($mpn)), " -,");
        }

        $type  = trim((string) ($parts['category'] ?? ''));
        $type0 = '';
        $cp = trim((string) ($parts['category_path'] ?? ''));
        if ($cp !== '') {
            $segs = array_values(array_filter(array_map('trim', preg_split('/>+/', $cp))));
            $type0 = $segs[0] ?? '';
            if ($type === '' && $segs) { $type = (string) end($segs); }
        }
        $unit = trim((string) ($parts['unit'] ?? ''));

        $tokens = [
            'brand' => $brand, 'model' => $model, 'size' => $size,
            'color' => $color, 'mpn' => $mpn,
            'type'  => $type,  'type0' => $type0, 'unit' => $unit,
        ];

        $resolve = function (string $name) use ($tokens, $attrs): string {
            $name = trim($name);
            if ($name === 'allattr') {
                $out = [];
                foreach ($attrs as $a) {
                    if (is_array($a) && isset($a['Name'], $a['Value']) && trim((string) $a['Value']) !== '') {
                        $out[] = trim($a['Name'] . ' ' . $a['Value']);
                    }
                }
                return implode(' ', $out);
            }
            if (str_starts_with($name, 'attr:')) {
                $want = trim(substr($name, 5));
                foreach ($attrs as $a) {
                    if (is_array($a) && isset($a['Name'], $a['Value'])
                        && strcasecmp((string) $a['Name'], $want) === 0) {
                        return trim((string) $a['Value']);
                    }
                }
                return '';
            }
            return $tokens[$name] ?? '';
        };

        return [$resolve, $size, $color];
    }

    /** Render an ARBITRARY template against parts — used by the live editor preview. */
    public function renderTemplate(
        string $distributorCode,
        string $template,
        array $parts,
        ?array $sizeAttrOverride = null
    ): string {
        [$resolve] = $this->makeResolver($distributorCode, $parts, $sizeAttrOverride);
        return $this->render($template, $resolve);
    }

    /** Map a stored catalog row to the parts array compose() expects. */
    public function partsFromRow(\App\Models\PlatformDistributorCatalog $row): array
    {
        return [
            'brand'         => $row->manufacturer,
            'model'         => $row->name,
            'mpn'           => $row->manufacturer_sku,
            'description'   => $row->description,
            'attributes'    => $row->attributes ?? [],
            'category'      => $row->category,
            'category_path' => $row->category_path,
            'unit'          => $row->uom,
        ];
    }

    private function render(string $template, callable $resolve): string
    {
        if ($template === '') { return ''; }
        $out = preg_replace_callback('/\{([^}]+)\}/', fn ($m) => $resolve($m[1]), $template);
        $out = preg_replace('/\s{2,}/', ' ', (string) $out);   // collapse gaps left by empty tokens
        return trim((string) $out, " \t\n\r\0\x0B-·|,");        // trim stray separators too
    }

    /** First attribute value whose Name matches the priority list (case-insensitive). */
    private function pickAttribute(array $attributes, array $priority): string
    {
        foreach ($priority as $name) {
            foreach ($attributes as $a) {
                if (! is_array($a)) {
                    continue;
                }
                if (isset($a['Name'], $a['Value'])
                    && strcasecmp((string) $a['Name'], (string) $name) === 0
                    && trim((string) $a['Value']) !== '') {
                    return trim((string) $a['Value']);
                }
            }
        }
        return '';
    }

    /** First size-shaped token in the description, by configured pattern order. */
    /**
     * MARKER-TITLE-SCOPES — the effective title template for a distributor's
     * catch-all scope, so the health scan knows which tokens to check for
     * emptiness without re-deriving the template itself.
     */
    /**
     * MARKER-ONE-RESOLVER — pass the item's FULL category path here, not a
     * pre-resolved scope. The ladder is this method's job.
     */
    public function titleTemplateFor(string $distributorCode, string $categoryPath = ''): string
    {
        return $this->setting($distributorCode, $categoryPath)->title_template
            ?: self::FALLBACK_TITLE;
    }

    public function extractSize(string $distributorCode, string $description, string $categoryPath = ''): string
    {
        if ($description === '') {
            return '';
        }
        foreach ($this->patterns($distributorCode, $categoryPath) as $pattern) {
            $re = '~' . $pattern . '~u';
            $ok = @preg_match($re, $description, $m);
            if ($ok === 1 && isset($m[0]) && trim($m[0]) !== '') {
                return trim($m[0]);
            }
        }
        return '';
    }

    /**
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
        $key = preg_replace('/\s+/', ' ', trim($key));
        $key = preg_replace('/\s*>\s*/', ' > ', (string) $key);
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
    /**
     * MARKER-ONE-RESOLVER — THE rule ladder. Anything that needs to know
     * which rule applies to a category calls this; nothing re-implements it.
     * Returns the matched row, or null when only the built-in fallback
     * applies. Callers that want a usable object should use setting().
     */
    public function matchedSetting(string $code, string $categoryPath = ''): ?CatalogTitleSetting
    {
        $rows = $this->settingRows();

        foreach ([$code, '*'] as $dist) {
            foreach ($this->categoryCandidates($categoryPath) as $cand) {
                foreach ($rows as $row) {
                    if ($row->distributor_code === $dist
                        && $this->normKey((string) $row->category_key) === $this->normKey($cand)) {
                        return $row;
                    }
                }
            }
        }
        return null;
    }

    /**
     * MARKER-FIELD-INHERITANCE — resolve each field up the ladder on its own.
     *
     * A rule that sets only a title used to discard its parent's subtitle,
     * search blob and colour priority, because the first matching ROW won
     * outright. The review page writes title-only rules, so editing a
     * category silently replaced its descriptor with '{mpn}' and emptied
     * its search blob — search_template has no constant fallback.
     *
     * Now the most specific rule that actually SETS a field wins that field,
     * and everything it leaves blank keeps coming from the parent. That is
     * what "override" has to mean for partial rules to be safe to write.
     */
    private function setting(string $code, string $categoryPath = ''): CatalogTitleSetting
    {
        $cacheKey = $code . '|' . $categoryPath;
        if (isset($this->settingCache[$cacheKey])) {
            return $this->settingCache[$cacheKey];
        }

        $rows = $this->settingRows();

        $strings = ['title_template' => null, 'subtitle_template' => null, 'search_template' => null];
        $arrays  = ['color_attribute_priority' => null, 'size_attribute_priority' => null];

        // Walk the whole ladder — no early break. Each field is filled by the
        // first rule that has a value for it, so specificity is per field.
        foreach ([$code, '*'] as $dist) {
            foreach ($this->categoryCandidates($categoryPath) as $cand) {
                foreach ($rows as $row) {
                    if ($row->distributor_code !== $dist
                        || $this->normKey((string) $row->category_key) !== $this->normKey($cand)) {
                        continue;
                    }
                    foreach (array_keys($strings) as $f) {
                        if ($strings[$f] === null && trim((string) $row->$f) !== '') {
                            $strings[$f] = (string) $row->$f;
                        }
                    }
                    foreach (array_keys($arrays) as $f) {
                        if ($arrays[$f] === null && ! empty($row->$f)) {
                            $arrays[$f] = $row->$f;
                        }
                    }
                }
            }
        }

        return $this->settingCache[$cacheKey] = new CatalogTitleSetting([
            'title_template'           => $strings['title_template']    ?? self::FALLBACK_TITLE,
            'subtitle_template'        => $strings['subtitle_template'] ?? self::FALLBACK_SUBTITLE,
            // No constant for search: an empty blob is a legitimate choice at
            // the top of the ladder, it just must not be an ACCIDENT of a
            // partial rule below it.
            'search_template'          => $strings['search_template']   ?? '',
            'color_attribute_priority' => $arrays['color_attribute_priority'] ?? self::FALLBACK_COLOR_PRIORITY,
            'size_attribute_priority'  => $arrays['size_attribute_priority']  ?? [],
        ]);
    }

    /** All active pattern rows, read once per instance. */
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
     * MARKER-TITLE-CATEGORY-SCOPE — patterns resolve by the same ladder as
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
    }
}
