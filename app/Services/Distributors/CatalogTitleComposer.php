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

        $color = $this->pickColor(
            $parts['attributes'] ?? [],
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

        $tokens = [
            'brand' => $brand,
            'model' => $model,
            'size'  => $size,
            'color' => $color,
            'mpn'   => $mpn,
        ];

        return [
            'title'    => $this->render($setting->title_template ?: self::FALLBACK_TITLE, $tokens),
            'subtitle' => $this->render($setting->subtitle_template ?: self::FALLBACK_SUBTITLE, $tokens),
            'size'     => $size,
            'color'    => $color,
        ];
    }

    private function render(string $template, array $tokens): string
    {
        $out = preg_replace_callback('/\{(\w+)\}/', fn ($m) => $tokens[$m[1]] ?? '', $template);
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
    private function extractSize(string $distributorCode, string $description): string
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
