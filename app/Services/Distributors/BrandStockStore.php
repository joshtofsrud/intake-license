<?php

namespace App\Services\Distributors;

use Illuminate\Support\Facades\DB;

/**
 * MARKER-BRAND-STOCK: a shop's dealer stock file for a brand-direct supplier,
 * held as rows so the per-shop sync can read cost and quantity from it the
 * way it reads them from a distributor's live feed.
 */
class BrandStockStore
{
    /**
     * Replace the shop's file with these rows (from BrandStockFile::read).
     *
     * @param array<int,array<string,mixed>> $rows
     */
    public static function replace(string $tenantId, string $code, array $rows, string $fileName, string $source, ?array $check = null, ?int $modified = null): void
    {
        $code = strtoupper($code);
        $now = now();
        DB::transaction(function () use ($tenantId, $code, $rows, $fileName, $source, $check, $modified, $now) {
            DB::table('tenant_brand_stock')->where('tenant_id', $tenantId)->where('distributor_code', $code)->delete();
            $seen = [];
            $batch = [];
            foreach ($rows as $r) {
                if (isset($seen[$r['id']])) {
                    continue; // a part number twice in one file: the first row counts
                }
                $seen[$r['id']] = true;
                $batch[] = [
                    'tenant_id' => $tenantId, 'distributor_code' => $code, 'variant_no' => $r['id'],
                    'upc' => $r['upc'] ?? null, 'description' => mb_substr((string) ($r['description'] ?? ''), 0, 255),
                    'model_year' => $r['year'] ?? null,
                    'cost_cents' => isset($r['cost']) && $r['cost'] !== null ? (int) round($r['cost'] * 100) : null,
                    'retail_cents' => isset($r['retail']) && $r['retail'] !== null ? (int) round($r['retail'] * 100) : null,
                    'qty' => $r['qty'] ?? null, 'created_at' => $now, 'updated_at' => $now,
                ];
                if (count($batch) >= 500) {
                    DB::table('tenant_brand_stock')->insert($batch);
                    $batch = [];
                }
            }
            if ($batch) {
                DB::table('tenant_brand_stock')->insert($batch);
            }
            DB::table('tenant_brand_stock_files')->updateOrInsert(
                ['tenant_id' => $tenantId, 'distributor_code' => $code],
                ['file_name' => mb_substr($fileName, 0, 255), 'source' => $source, 'row_count' => count($seen),
                 'check' => $check ? json_encode(self::slim($check)) : null,
                 'loaded_at' => $now, 'file_modified_at' => $modified ? date('Y-m-d H:i:s', $modified) : null,
                 'last_error' => null, 'last_attempt_at' => $now, 'updated_at' => $now, 'created_at' => $now]
            );
        });
    }

    /** A failed fetch is recorded without touching the rows already loaded. */
    public static function failed(string $tenantId, string $code, string $message): void
    {
        $code = strtoupper($code);
        DB::table('tenant_brand_stock_files')->updateOrInsert(
            ['tenant_id' => $tenantId, 'distributor_code' => $code],
            ['last_error' => mb_substr($message, 0, 500), 'last_attempt_at' => now(), 'updated_at' => now(), 'created_at' => now()]
        );
    }

    /** A fetch that found nothing new: note the time, clear any old error. */
    public static function touchAttempt(string $tenantId, string $code): void
    {
        DB::table('tenant_brand_stock_files')->where('tenant_id', $tenantId)->where('distributor_code', strtoupper($code))
            ->update(['last_error' => null, 'last_attempt_at' => now(), 'updated_at' => now()]);
    }

    /** What the box shows: the file on record and how it checked out. */
    public static function file(string $tenantId, string $code): ?object
    {
        $f = DB::table('tenant_brand_stock_files')->where('tenant_id', $tenantId)->where('distributor_code', strtoupper($code))->first();
        if ($f && is_string($f->check)) {
            $f->check = json_decode($f->check, true);
        }
        return $f;
    }

    /** @return array<string,int> part number => the shop's cost in cents */
    public static function costs(string $tenantId, string $code, array $variantNos): array
    {
        return self::column($tenantId, $code, $variantNos, 'cost_cents');
    }

    /** @return array<string,int> part number => quantity on hand at the supplier */
    public static function stock(string $tenantId, string $code, array $variantNos): array
    {
        return self::column($tenantId, $code, $variantNos, 'qty');
    }

    private static function column(string $tenantId, string $code, array $variantNos, string $col): array
    {
        $out = [];
        foreach (array_chunk($variantNos, 1000) as $chunk) {
            foreach (DB::table('tenant_brand_stock')->where('tenant_id', $tenantId)->where('distributor_code', strtoupper($code))
                ->whereIn('variant_no', $chunk)->whereNotNull($col)->get(['variant_no', $col]) as $r) {
                $out[(string) $r->variant_no] = (int) $r->{$col};
            }
        }
        return $out;
    }

    /** Part numbers in the shop's file, for "only what's in my file". */
    public static function ids(string $tenantId, string $code): array
    {
        return DB::table('tenant_brand_stock')->where('tenant_id', $tenantId)->where('distributor_code', strtoupper($code))->pluck('variant_no')->all();
    }

    /** The check result trimmed to what the box shows (lists capped, so the row stays small). */
    private static function slim(array $check): array
    {
        $check['missing_count'] = count((array) ($check['missing'] ?? []));
        $check['missing'] = array_slice((array) ($check['missing'] ?? []), 0, 25);
        $check['shared_upc'] = array_slice((array) ($check['shared_upc'] ?? []), 0, 10);
        $check['price_diff'] = array_slice((array) ($check['price_diff'] ?? []), 0, 25);
        return $check;
    }
}
