<?php

namespace App\Services\Distributors;

use App\Support\SimpleXlsx;
use Illuminate\Support\Facades\DB;

/**
 * A dealer's stock file from a brand sold direct, .xlsx or .csv. One row per
 * part number with the dealer's cost, retail, stock on hand, UPC and model
 * year. Read here for master admin's check against the catalog; the shop-side
 * upload reads it the same way. A brand's adapter may declare its own export
 * name and column names through a static stockFile() (see KonaClient); these
 * defaults cover any it leaves out.
 */
class BrandStockFile
{
    /** Column names accepted for each field, before a brand's own are added. */
    public const COLUMNS = [
        'id'          => ['inventory id', 'item', 'item id', 'part number', 'sku'],
        'description' => ['description', 'item description'],
        'year'        => ['year', 'model year'],
        'qty'         => ['qty. hard available', 'qty hard available', 'qty available', 'available', 'qty'],
        'cost'        => ['base price', 'cost', 'dealer price'],
        'retail'      => ['retail price', 'retail', 'msrp'],
        'upc'         => ['upc', 'barcode'],
    ];

    /**
     * @return array{rows:array<int,array<string,mixed>>,missing:string[]}
     *         missing lists required columns the file does not have
     */
    public static function read(string $path, ?string $originalName = null, ?string $code = null): array
    {
        $columns = self::columns($code);
        $ext = strtolower(pathinfo($originalName ?: $path, PATHINFO_EXTENSION));
        $records = $ext === 'csv' || $ext === 'txt' ? self::csv($path) : iterator_to_array(SimpleXlsx::records($path), false);

        $map = [];
        $first = $records[0] ?? [];
        foreach (array_keys($first) as $h) {
            $l = mb_strtolower(trim((string) $h));
            foreach ($columns as $key => $names) {
                if (! isset($map[$key]) && in_array($l, $names, true)) {
                    $map[$key] = $h;
                }
            }
        }
        $missing = array_values(array_diff(['id', 'cost', 'qty'], array_keys($map)));

        $rows = [];
        foreach ($records as $r) {
            $id = strtoupper(trim((string) ($r[$map['id'] ?? ''] ?? '')));
            if ($id === '') {
                continue;
            }
            $year = trim((string) ($r[$map['year'] ?? ''] ?? ''));
            $rows[] = [
                'id'          => $id,
                'description' => trim(preg_replace('/\s+/u', ' ', (string) ($r[$map['description'] ?? ''] ?? '')) ?? ''),
                'year'        => preg_match('/^(19|20)\d{2}$/', $year) ? (int) $year : null,
                'qty'         => is_numeric($q = $r[$map['qty'] ?? ''] ?? null) ? (int) $q : null,
                'cost'        => self::money($r[$map['cost'] ?? ''] ?? null),
                'retail'      => self::money($r[$map['retail'] ?? ''] ?? null),
                'upc'         => preg_replace('/\D/', '', (string) ($r[$map['upc'] ?? ''] ?? '')) ?: null,
            ];
        }
        return ['rows' => $rows, 'missing' => $missing];
    }

    /** What the brand's adapter declares about its stock file, or []. */
    public static function declared(?string $code): array
    {
        $class = $code ? app(DistributorRegistry::class)->adapterClass($code) : null;
        return $class && method_exists($class, 'stockFile') ? (array) $class::stockFile() : [];
    }

    /** Accepted column names per field: the defaults plus any the brand adds. */
    public static function columns(?string $code): array
    {
        $out = self::COLUMNS;
        foreach ((array) (self::declared($code)['columns'] ?? []) as $k => $names) {
            $out[$k] = array_values(array_unique(array_merge(array_map('strtolower', (array) $names), $out[$k] ?? [])));
        }
        return $out;
    }

    /** The export's name as the brand calls it, for messages. */
    public static function exportName(?string $code): string
    {
        return (string) (self::declared($code)['export'] ?? 'stock file');
    }

    /** Required fields, named the way the file does. */
    public static function fieldLabels(): array
    {
        return ['id' => 'Inventory ID', 'cost' => 'Base Price', 'qty' => 'Qty. Hard Available'];
    }

    /**
     * The file against the catalog: matched rows, rows not in the catalog,
     * UPCs shared by more than one part number, rows with no year (and
     * whether the catalog can fill it), and retail prices that differ from
     * the brand's store.
     */
    public static function check(array $rows, string $code): array
    {
        $ids = array_column($rows, 'id');
        $cat = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach (DB::table('platform_distributor_catalogs')
                ->where('distributor_code', $code)->whereIn('distributor_variant_no', $chunk)
                ->whereNull('deleted_at')
                ->get(['distributor_variant_no', 'display_name', 'name', 'size', 'msrp_cents', 'attributes', 'images']) as $c) {
                $cat[strtoupper((string) $c->distributor_variant_no)] = $c;
            }
        }

        $byUpc = [];
        foreach ($rows as $r) {
            if ($r['upc']) {
                $byUpc[$r['upc']][] = $r;
            }
        }
        $shared = array_values(array_filter($byUpc, fn ($g) => count($g) > 1));

        $dupIds = array_keys(array_filter(array_count_values($ids), fn ($n) => $n > 1));

        $matched = [];
        $missing = [];
        $noYear = ['count' => 0, 'filled' => 0, 'example' => null];
        $priceDiff = [];
        foreach ($rows as $r) {
            $c = $cat[$r['id']] ?? null;
            if ($c) {
                $matched[] = $r['id'];
            } else {
                $missing[] = $r;
            }
            if ($r['year'] === null) {
                $noYear['count']++;
                $noYear['example'] ??= $r['description'];
                if ($c && self::catalogYear($c->attributes)) {
                    $noYear['filled']++;
                }
            }
            if ($c && $r['retail'] !== null && $c->msrp_cents !== null && (int) round($r['retail'] * 100) !== (int) $c->msrp_cents) {
                $priceDiff[] = ['id' => $r['id'], 'description' => $r['description'], 'file' => $r['retail'], 'store' => $c->msrp_cents / 100];
            }
        }

        return [
            'rows'       => count($rows),
            'matched'    => count($matched),
            'missing'    => $missing,
            'shared_upc' => array_map(fn ($g) => ['upc' => $g[0]['upc'], 'rows' => array_map(fn ($r) => ['id' => $r['id'], 'description' => $r['description']], $g)], $shared),
            'dup_ids'    => $dupIds,
            'no_year'    => $noYear,
            'price_diff' => $priceDiff,
            'catalog'    => DB::table('platform_distributor_catalogs')->where('distributor_code', $code)->whereNull('deleted_at')->count(),
        ];
    }

    private static function catalogYear(mixed $attrs): ?string
    {
        foreach ((array) (is_string($attrs) ? json_decode($attrs, true) : $attrs) as $a) {
            if (is_array($a) && mb_strtolower((string) ($a['Name'] ?? '')) === 'model year') {
                return (string) $a['Value'];
            }
        }
        return null;
    }

    private static function money(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        $n = preg_replace('/[^\d.\-]/', '', (string) $v);
        return is_numeric($n) ? round((float) $n, 2) : null;
    }

    /** @return array<int,array<string,string>> */
    private static function csv(string $path): array
    {
        $fh = fopen($path, 'r');
        if (! $fh) {
            return [];
        }
        $header = null;
        $out = [];
        while (($cells = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if ($header === null) {
                $header = array_map(fn ($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)), $cells);
                continue;
            }
            $rec = [];
            foreach ($header as $i => $h) {
                if ($h !== '') {
                    $rec[$h] = $cells[$i] ?? null;
                }
            }
            $out[] = $rec;
        }
        fclose($fh);
        return $out;
    }
}
