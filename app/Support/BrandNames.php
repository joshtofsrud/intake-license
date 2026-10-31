<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-BRAND-NAMES: master-admin brand spelling pairings.
 *
 * A pairing says "this distributor spelling is that brand". It is applied to
 * the shared catalog's manufacturer column (the field every brand picker,
 * filter and title reads) when it is saved, and by the catalog sync on every
 * row it writes. The distributor's own spelling is kept in manufacturer_raw.
 */
class BrandNames
{
    private static ?array $map = null;

    /** Lower-cased, trimmed, single-spaced: the exact spelling, for lookups. */
    public static function key(?string $s): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $s)));
    }

    /**
     * A loose form for spotting likely duplicates only (never applied on its
     * own): 1st/first, &/and, punctuation, spacing and company suffixes fold.
     */
    public static function fold(?string $s): string
    {
        $s = ' ' . self::key($s) . ' ';
        $s = str_replace('&', ' and ', $s);
        $s = preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $s);
        $words = ['1st' => 'first', '2nd' => 'second', '3rd' => 'third', '4th' => 'fourth', '5th' => 'fifth'];
        foreach ($words as $a => $b) {
            $s = preg_replace('/(?<=\s)' . $a . '(?=\s)/u', $b, $s);
        }
        $s = preg_replace('/\s(inc|co|llc|ltd|corp|corporation|company|the|gmbh|srl)(?=\s)/u', ' ', $s);
        return preg_replace('/\s+/u', '', $s);
    }

    /** alias_key => canonical_name, memoised per request. */
    public static function map(): array
    {
        if (self::$map === null) {
            self::$map = Schema::hasTable('brand_aliases')
                ? DB::table('brand_aliases')->pluck('canonical_name', 'alias_key')->all()
                : [];
        }
        return self::$map;
    }

    public static function forget(): void { self::$map = null; }

    /** The spelling to show for a distributor's brand name. */
    public static function canonical(?string $name): ?string
    {
        if ($name === null || trim($name) === '') return $name;
        return self::map()[self::key($name)] ?? $name;
    }

    /** Applied by the catalog sync to a row it is about to write. */
    public static function applyTo(array $row): array
    {
        if (! array_key_exists('manufacturer', $row)) return $row;
        $raw = $row['manufacturer'];
        $row['manufacturer_raw'] = $raw;
        $row['manufacturer'] = self::canonical($raw);
        return $row;
    }

    /**
     * Save "spelling X is brand Y" and relabel existing catalog rows now.
     * Returns how many catalog rows changed.
     */
    public static function pair(string $alias, string $canonical, ?string $by = null): int
    {
        $alias = trim($alias);
        $canonical = trim($canonical);
        $key = self::key($alias);
        if ($key === '' || $canonical === '' || $key === self::key($canonical)) return 0;

        DB::table('brand_aliases')->updateOrInsert(['alias_key' => $key],
            ['alias_name' => $alias, 'canonical_name' => $canonical, 'created_by' => $by, 'updated_at' => now(), 'created_at' => now()]);
        // anything already pointing at the old spelling follows to the new one
        DB::table('brand_aliases')->where('canonical_name', $alias)->update(['canonical_name' => $canonical, 'updated_at' => now()]);
        self::forget();

        $n = DB::table('platform_distributor_catalogs')
            ->where(fn ($w) => $w->whereRaw('LOWER(TRIM(COALESCE(manufacturer_raw, manufacturer))) = ?', [$key])
                                  ->orWhereRaw('LOWER(TRIM(manufacturer)) = ?', [$key]))
            ->update(['manufacturer_raw' => DB::raw('COALESCE(manufacturer_raw, manufacturer)'), 'manufacturer' => $canonical]);

        // a brand switched off under the old spelling stays off under the new one
        foreach (['distributor_brand_settings', 'tenant_distributor_brand_settings'] as $table) {
            if (! Schema::hasTable($table)) continue;
            $newKey = self::key($canonical);
            DB::table($table)->where('brand_key', $key)->get()->each(function ($r) use ($table, $newKey, $canonical) {
                $clash = DB::table($table)->where('distributor_code', $r->distributor_code)->where('brand_key', $newKey)
                    ->when(property_exists($r, 'tenant_id'), fn ($q) => $q->where('tenant_id', $r->tenant_id))->exists();
                if (! $clash) {
                    DB::table($table)->where('id', $r->id)->update(['brand_key' => $newKey, 'brand_name' => $canonical]);
                }
            });
        }
        return $n;
    }

    /** Remove a pairing and put the distributor's own spelling back. Returns rows restored. */
    public static function unpair(int $id): int
    {
        $a = DB::table('brand_aliases')->where('id', $id)->first();
        if (! $a) return 0;
        DB::table('brand_aliases')->where('id', $id)->delete();
        self::forget();
        return DB::table('platform_distributor_catalogs')
            ->whereNotNull('manufacturer_raw')
            ->whereRaw('LOWER(TRIM(manufacturer_raw)) = ?', [$a->alias_key])
            ->update(['manufacturer' => DB::raw('manufacturer_raw')]);
    }
}
