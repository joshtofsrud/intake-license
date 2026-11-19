<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** MARKER-BRAND-BUILDER: brands added from master admin, read by the general store reader. */
class BrandSources
{
    /** @return array<string,object> code => row */
    public static function all(): array
    {
        return Cache::remember('brand_sources.all', 300, function () {
            try {
                if (! Schema::hasTable('brand_sources')) {
                    return [];
                }
                $out = [];
                foreach (DB::table('brand_sources')->orderBy('label')->get() as $r) {
                    $r->settings = json_decode((string) $r->settings, true) ?: [];
                    $out[strtoupper($r->code)] = $r;
                }
                return $out;
            } catch (\Throwable) {
                return [];
            }
        });
    }

    public static function get(string $code): ?object
    {
        return self::all()[strtoupper($code)] ?? null;
    }

    public static function forget(): void
    {
        Cache::forget('brand_sources.all');
    }
}
