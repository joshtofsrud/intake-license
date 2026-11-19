<?php

namespace App\Support;

use App\Models\PlatformSettings;

/**
 * MARKER-BRAND-KINDS: per brand, the tags that name a kind of product, in the
 * order they win ("Mountain Full Suspension" before "Mountain"). Set on Brand
 * suppliers; the brand's reader uses the first one a product carries as its
 * category under the product type.
 */
class BrandKinds
{
    /** @return string[] */
    public static function for(string $code): array
    {
        $ds = (array) (PlatformSettings::current()->distribution_settings ?? []);
        return array_values(array_filter(array_map('trim', (array) ($ds['brand_kinds'][strtoupper($code)] ?? [])), fn ($k) => $k !== ''));
    }

    /** @param string[] $kinds */
    public static function set(string $code, array $kinds): void
    {
        $ps = PlatformSettings::current();
        $ds = (array) ($ps->distribution_settings ?? []);
        $clean = [];
        foreach ($kinds as $k) {
            $k = trim((string) $k);
            if ($k !== '' && ! in_array(mb_strtolower($k), array_map('mb_strtolower', $clean), true)) {
                $clean[] = $k;
            }
        }
        $ds['brand_kinds'][strtoupper($code)] = $clean;
        $ps->distribution_settings = $ds;
        $ps->save();
        PlatformSettings::forget();
    }
}
