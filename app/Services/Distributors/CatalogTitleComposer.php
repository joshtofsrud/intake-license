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
    /** @var array<string,CatalogTitleSetting> */
    private array $settingCache = [];
    /** @var array<string,array<int,string>> */
    private array $patternCache = [];

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
        $setting = $this->setting($distributorCode);
        $attrs = $parts['attributes'] ?? [];

        $color = $this->pickColor(
            $attrs,
            $setting->color_attribute_priority ?: self::FALLBACK_COLOR_PRIORITY
        );
        $size = $this->extractSize($distributorCode, (string) ($parts['description'] ?? ''));

        $brand = trim((string) ($parts['brand'] ?? ''));
        $model = trim((string) ($parts['model'] ?? ''));
        $mpn   = trim((string) ($parts['mpn'] ?? ''));
        // HLC sometimes bakes the MPN into the product name; strip a trailing
        // copy so it doesn't duplicate the subtitle.
        if ($mpn !== '' && str_ends_with($model, $mpn)) {
            $model = rtrim(substr($model, 0, -strlen($mpn)), " -,");
        }

        // {type} = specific category (L1, from `category`); {type0} = broad
        // category (L0, the first segment of `category_path` = "L0 > L1 > ...").
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

        // Resolver handles static tokens plus dynamic {attr:NAME} and {allattr}.
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
    private function makeResolver(string $distributorCode, array $parts): array
    {
        $setting = $this->setting($distributorCode);
        $attrs = $parts['attributes'] ?? [];

        $color = $this->pickColor(
            $attrs,
            $setting->color_attribute_priority ?: self::FALLBACK_COLOR_PRIORITY
        );
        $size = $this->extractSize($distributorCode, (string) ($parts['description'] ?? ''));

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
    public function renderTemplate(string $distributorCode, string $template, array $parts): string
    {
        [$resolve] = $this->makeResolver($distributorCode, $parts);
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
    private function pickColor(array $attributes, array $priority): string
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
    public function extractSize(string $distributorCode, string $description): string
    {
        if ($description === '') {
            return '';
        }
        foreach ($this->patterns($distributorCode) as $pattern) {
            $re = '~' . $pattern . '~u';
            $ok = @preg_match($re, $description, $m);
            if ($ok === 1 && isset($m[0]) && trim($m[0]) !== '') {
                return trim($m[0]);
            }
        }
        return '';
    }

    private function setting(string $code): CatalogTitleSetting
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
    }

    /** @return array<int,string> ordered regex bodies */
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
    }
}
