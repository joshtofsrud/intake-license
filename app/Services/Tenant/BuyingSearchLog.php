<?php

namespace App\Services\Tenant;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MARKER-BUYING: searches at the register and on the online store that found
 * nothing in stock. Each is a customer who wanted something the shop didn't
 * have, so Buying lists them as ordering signals.
 *
 * Both searches run as you type. A search that grows or shrinks into another
 * within a few seconds ("hel" → "helm" → "helmet") is one search, so only
 * the last spelling counts; if that last spelling did find stock, the earlier
 * unanswered one is taken back off.
 *
 * Logged for every retail shop, add-on or not, so a shop that turns Buying on
 * has history waiting. One row per query per day per place keeps it small.
 * Never throws: a search must never fail because of this.
 */
class BuyingSearchLog
{
    public const WINDOW_SECONDS = 20;

    /**
     * @param  string       $source       'register' | 'online'
     * @param  string|null  $locationId   the register's location; null online
     * @param  string|null  $closestId    best match, if anything matched
     * @param  int|null     $closestStock that match's stock where it counts
     * @param  bool         $answered     true when something matched AND had stock
     */
    public static function record(string $tenantId, string $source, ?string $sessionId, ?string $locationId, string $query, ?string $closestId, ?int $closestStock, bool $answered): void
    {
        try {
            $query = trim(preg_replace('/\s+/', ' ', $query));
            if (mb_strlen($query) < 3) {
                return;
            }
            $key = mb_strtolower(mb_substr($query, 0, 120));
            $loc = (string) ($locationId ?? '');
            $sess = $sessionId ? substr(hash('sha256', $sessionId), 0, 24) : null;
            $ck = $sess ? "buying:search:{$tenantId}:{$source}:{$sess}" : null;

            $prev = $ck ? Cache::get($ck) : null;
            $related = $prev && (time() - (int) $prev['at']) <= self::WINDOW_SECONDS
                && (str_starts_with($key, $prev['key']) || str_starts_with($prev['key'], $key));

            if ($related && $prev['key'] === $key) {
                // the same search fired again (paging, a filter change): count it once
                Cache::put($ck, ['key' => $key, 'at' => time(), 'row' => $prev['row'] ?? null], self::WINDOW_SECONDS * 3);
                return;
            }
            if ($related && ! empty($prev['row'])) {
                self::takeBack($prev['row']);
            }

            $rowId = null;
            if (! $answered) {
                $rowId = self::bump($tenantId, $source, $loc, $query, $key, $closestId, $closestStock);
            }
            if ($ck) {
                Cache::put($ck, ['key' => $key, 'at' => time(), 'row' => $rowId], self::WINDOW_SECONDS * 3);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private static function bump(string $tenantId, string $source, string $loc, string $query, string $key, ?string $closestId, ?int $closestStock): ?string
    {
        $day = now()->toDateString();
        $match = ['tenant_id' => $tenantId, 'source' => $source, 'location_id' => $loc, 'query_key' => $key, 'day' => $day];
        $row = DB::table('tenant_buying_searches')->where($match)->first(['id']);
        if ($row) {
            DB::table('tenant_buying_searches')->where('id', $row->id)->update([
                'times' => DB::raw('times + 1'), 'query' => mb_substr($query, 0, 120),
                'closest_item_id' => $closestId, 'closest_stock' => $closestStock,
                'last_at' => now(), 'updated_at' => now(),
            ]);

            return $row->id;
        }
        $id = (string) Str::uuid();
        try {
            DB::table('tenant_buying_searches')->insert($match + [
                'id' => $id, 'query' => mb_substr($query, 0, 120), 'times' => 1,
                'closest_item_id' => $closestId, 'closest_stock' => $closestStock,
                'last_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // two tills at once: the other one made the row
            DB::table('tenant_buying_searches')->where($match)->update(['times' => DB::raw('times + 1'), 'last_at' => now(), 'updated_at' => now()]);
            $id = DB::table('tenant_buying_searches')->where($match)->value('id');
        }

        return $id;
    }

    private static function takeBack(string $rowId): void
    {
        $row = DB::table('tenant_buying_searches')->where('id', $rowId)->first(['id', 'times']);
        if (! $row) {
            return;
        }
        if ((int) $row->times <= 1) {
            DB::table('tenant_buying_searches')->where('id', $rowId)->delete();
        } else {
            DB::table('tenant_buying_searches')->where('id', $rowId)->update(['times' => DB::raw('times - 1'), 'updated_at' => now()]);
        }
    }
}
