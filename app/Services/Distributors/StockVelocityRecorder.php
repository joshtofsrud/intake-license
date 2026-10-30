<?php

namespace App\Services\Distributors;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-VELOCITY: records distributor stock readings and keeps a running
 * per-item summary (see the create_distributor_stock_velocity migration).
 *
 * The pace is a time-weighted average of DROPS only:
 *   drop_units    = drop_units    * f + max(0, previous - now)
 *   observed_days = observed_days * f + days since the previous reading
 *   rate          = drop_units / observed_days
 * with f = exp(-days / MEMORY_DAYS), so older movement fades out. A restock
 * (stock going up) adds no drop and is never read as a sale.
 *
 * Several shops can read the same distributor item on the same night with
 * their own credentials. Only the first reading inside READING_GAP_HOURS
 * counts, so one item is not measured twice in a night and two accounts that
 * see slightly different warehouses can't fake a drop.
 */
class StockVelocityRecorder
{
    public const MEMORY_DAYS       = 30;
    public const KEEP_DAYS         = 60;
    public const READING_GAP_HOURS = 6;
    public const GOOD_READINGS     = 8;
    public const GOOD_SPAN_DAYS    = 21;
    public const MIN_OBSERVED_DAYS = 1.0;

    /**
     * @param array<string, array{avail:int|null, catalog_id?:string|null, eta?:string|null, has_eta_info?:bool}> $readings
     *        keyed by distributor_variant_no
     * @return array{read:int, counted:int, changed:int, new:int}
     */
    public function record(string $code, array $readings, ?Carbon $at = null): array
    {
        $at    = ($at ?? now())->copy()->utc();
        $today = $at->toDateString();
        $out   = ['read' => 0, 'counted' => 0, 'changed' => 0, 'new' => 0];

        foreach (array_chunk($readings, 1000, true) as $chunk) {
            $existing = DB::table('distributor_stock_summary')
                ->where('distributor_code', $code)
                ->whereIn('distributor_variant_no', array_map('strval', array_keys($chunk)))
                ->get()->keyBy('distributor_variant_no');

            $rows = [];
            $changes = [];
            $etaOnly = [];
            foreach ($chunk as $vno => $r) {
                $vno = (string) $vno;
                if (! array_key_exists('avail', $r) || $r['avail'] === null) {
                    continue;
                }
                $out['read']++;
                $avail = max(0, (int) $r['avail']);
                $eta   = (! empty($r['eta']) && $r['eta'] >= $today) ? substr((string) $r['eta'], 0, 10) : null;
                $prev  = $existing->get($vno);

                if (! $prev) {
                    $rows[] = [
                        'distributor_code' => $code, 'distributor_variant_no' => $vno,
                        'distributor_catalog_id' => $r['catalog_id'] ?? null,
                        'avail' => $avail, 'prev_avail' => null,
                        'drop_units' => 0, 'observed_days' => 0, 'rate_per_day' => null, 'days_left' => null,
                        'readings' => 1, 'changes' => 1,
                        'first_seen_at' => $at, 'last_reading_at' => $at, 'last_change_at' => $at, 'last_restock_at' => null,
                        'next_arrival_on' => $eta,
                        'baseline_avail' => null, 'baseline_at' => null,
                        'created_at' => $at, 'updated_at' => $at,
                    ];
                    $changes[] = ['distributor_code' => $code, 'distributor_variant_no' => $vno, 'avail' => $avail, 'prev_avail' => null, 'recorded_at' => $at];
                    $out['counted']++; $out['changed']++; $out['new']++;
                    continue;
                }

                $last = $prev->last_reading_at ? Carbon::parse($prev->last_reading_at, 'UTC') : null;
                $days = $last ? max(0.0, $last->diffInSeconds($at, false) / 86400) : 0.0;
                if ($last && $days * 24 < self::READING_GAP_HOURS) {
                    // already measured tonight; a later reading can still bring
                    // the arrival date the first one lacked (QBP's bulk call has none)
                    if (! empty($r['has_eta_info'])) {
                        $etaOnly[$eta ?? ''][] = $vno;
                    }
                    continue;
                }

                $prevAvail = $prev->avail === null ? null : (int) $prev->avail;
                $f      = exp(-$days / self::MEMORY_DAYS);
                $drop   = ($prevAvail !== null && $avail < $prevAvail) ? $prevAvail - $avail : 0;
                $units  = (float) $prev->drop_units * $f + $drop;
                $obs    = (float) $prev->observed_days * $f + $days;
                $rate   = $obs >= self::MIN_OBSERVED_DAYS ? round($units / $obs, 3) : null;
                $changed = $prevAvail !== $avail;

                $rows[] = [
                    'distributor_code' => $code, 'distributor_variant_no' => $vno,
                    'distributor_catalog_id' => $r['catalog_id'] ?? $prev->distributor_catalog_id,
                    'avail' => $avail, 'prev_avail' => $prevAvail,
                    'drop_units' => round($units, 3), 'observed_days' => round($obs, 3),
                    'rate_per_day' => $rate,
                    'days_left' => ($rate !== null && $rate > 0.001) ? round(min($avail / $rate, 99999), 1) : null,
                    'readings' => (int) $prev->readings + 1,
                    'changes' => (int) $prev->changes + ($changed ? 1 : 0),
                    'first_seen_at' => $prev->first_seen_at,
                    'last_reading_at' => $at,
                    'last_change_at' => $changed ? $at : $prev->last_change_at,
                    'last_restock_at' => ($prevAvail !== null && $avail > $prevAvail) ? $at : $prev->last_restock_at,
                    // a reading that carries warehouse detail decides the date (null = none due);
                    // one without warehouse detail leaves the last known date alone
                    'next_arrival_on' => ! empty($r['has_eta_info']) ? $eta : ($prev->next_arrival_on && $prev->next_arrival_on >= $today ? $prev->next_arrival_on : null),
                    'baseline_avail' => $prev->baseline_avail, 'baseline_at' => $prev->baseline_at,
                    'created_at' => $prev->created_at, 'updated_at' => $at,
                ];
                if ($changed) {
                    $changes[] = ['distributor_code' => $code, 'distributor_variant_no' => $vno, 'avail' => $avail, 'prev_avail' => $prevAvail, 'recorded_at' => $at];
                    $out['changed']++;
                }
                $out['counted']++;
            }

            if ($rows) {
                DB::table('distributor_stock_summary')->upsert($rows, ['distributor_code', 'distributor_variant_no'], [
                    'distributor_catalog_id', 'avail', 'prev_avail', 'drop_units', 'observed_days', 'rate_per_day', 'days_left',
                    'readings', 'changes', 'last_reading_at', 'last_change_at', 'last_restock_at', 'next_arrival_on', 'updated_at',
                ]);
            }
            foreach (array_chunk($changes, 1000) as $batch) {
                DB::table('distributor_stock_changes')->insert($batch);
            }
            foreach ($etaOnly as $date => $vnos) {
                DB::table('distributor_stock_summary')->where('distributor_code', $code)
                    ->whereIn('distributor_variant_no', $vnos)
                    ->update(['next_arrival_on' => $date === '' ? null : $date]);
            }
        }

        return $out;
    }

    /** Earliest future warehouse ETA for one variant's warehouse list (MARKER-SUPPLY shape). */
    public static function earliestEta(?array $warehouses, string $today): ?string
    {
        $best = null;
        foreach ($warehouses ?? [] as $w) {
            $eta = $w['eta'] ?? null;
            if ($eta && $eta >= $today && ($best === null || $eta < $best)) {
                $best = $eta;
            }
        }
        return $best;
    }

    /**
     * Build summaries from the readings that existed before this build
     * (distributor_availability_snapshots keeps the latest reading per shop
     * per item). The latest reading per distributor item becomes the starting
     * point and the baseline; items that already have a summary are left alone.
     *
     * @return array{seeded:int, skipped:int}
     */
    public function seedFromSnapshots(): array
    {
        $latest = [];
        DB::table('distributor_availability_snapshots')
            ->select('id', 'distributor_code', 'distributor_variant_no', 'distributor_catalog_id', 'avail', 'checked_at')
            ->whereNotNull('avail')
            ->orderBy('id')
            ->chunkById(5000, function ($rows) use (&$latest) {
                foreach ($rows as $s) {
                    $k = $s->distributor_code . "\x1f" . $s->distributor_variant_no;
                    if (! isset($latest[$k]) || $s->checked_at > $latest[$k]->checked_at) {
                        $latest[$k] = $s;
                    }
                }
            });

        $seeded = 0;
        $skipped = 0;
        foreach (array_chunk($latest, 1000) as $chunk) {
            $rows = [];
            $changes = [];
            foreach ($chunk as $s) {
                $at = Carbon::parse($s->checked_at, 'UTC');
                $rows[] = [
                    'distributor_code' => $s->distributor_code, 'distributor_variant_no' => (string) $s->distributor_variant_no,
                    'distributor_catalog_id' => $s->distributor_catalog_id,
                    'avail' => max(0, (int) $s->avail), 'prev_avail' => null,
                    'drop_units' => 0, 'observed_days' => 0, 'rate_per_day' => null, 'days_left' => null,
                    'readings' => 1, 'changes' => 1,
                    'first_seen_at' => $at, 'last_reading_at' => $at, 'last_change_at' => $at, 'last_restock_at' => null,
                    'next_arrival_on' => null,
                    'baseline_avail' => max(0, (int) $s->avail), 'baseline_at' => $at,
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
            $n = DB::table('distributor_stock_summary')->insertOrIgnore($rows);
            $seeded += $n;
            $skipped += count($rows) - $n;

            // a starting point for the chart, only for items just seeded
            if ($n > 0) {
                foreach ($chunk as $s) {
                    $changes[] = ['distributor_code' => $s->distributor_code, 'distributor_variant_no' => (string) $s->distributor_variant_no,
                        'avail' => max(0, (int) $s->avail), 'prev_avail' => null, 'recorded_at' => Carbon::parse($s->checked_at, 'UTC')];
                }
                $have = DB::table('distributor_stock_changes')
                    ->whereIn('distributor_variant_no', array_column($changes, 'distributor_variant_no'))
                    ->select('distributor_code', 'distributor_variant_no')->distinct()->get()
                    ->map(fn ($r) => $r->distributor_code . "\x1f" . $r->distributor_variant_no)->flip();
                $changes = array_values(array_filter($changes, fn ($c) => ! isset($have[$c['distributor_code'] . "\x1f" . $c['distributor_variant_no']])));
                foreach (array_chunk($changes, 1000) as $batch) {
                    DB::table('distributor_stock_changes')->insert($batch);
                }
            }
        }

        return ['seeded' => $seeded, 'skipped' => $skipped];
    }

    /** Remove change rows older than KEEP_DAYS. Returns rows removed. */
    public function prune(): int
    {
        $cut = now()->subDays(self::KEEP_DAYS);
        $total = 0;
        do {
            $n = DB::table('distributor_stock_changes')->where('recorded_at', '<', $cut)->limit(5000)->delete();
            $total += $n;
        } while ($n > 0);
        return $total;
    }

    /** good | building | early, from a summary row. */
    public static function confidence(object $row): string
    {
        $span = ($row->first_seen_at && $row->last_reading_at)
            ? Carbon::parse($row->first_seen_at)->diffInDays(Carbon::parse($row->last_reading_at)) : 0;
        if ((int) $row->readings >= self::GOOD_READINGS && $span >= self::GOOD_SPAN_DAYS) return 'good';
        if ((int) $row->readings >= 3 && $row->rate_per_day !== null) return 'building';
        return 'early';
    }
}
