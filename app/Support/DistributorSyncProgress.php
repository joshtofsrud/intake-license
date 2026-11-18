<?php

namespace App\Support;

use App\Services\Distributors\DistributorRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-SYNC-PROGRESS
 *
 * Live progress for a distributor refresh ("Refresh stock & cost" in a
 * distributor box, "Check for changes now" on Catalog attention), so the page
 * shows what is running and how far along it is instead of "give it a minute".
 *
 * Three sources, read together by state():
 *   - the queued marker, set when the button is pressed (the run row only
 *     exists once a worker picks the job up);
 *   - the tenant_distributor_sync_runs row: slices planned and done, and the
 *     summed results;
 *   - the step a slice is on right now (cost / stock / items, n of m), kept in
 *     the cache because it changes every second and is thrown away after.
 */
class DistributorSyncProgress
{
    /** A run that has said nothing for this long is reported as stalled. */
    private const STALL_MINUTES = 20;

    /** A finished run is still shown on a revisit for this long. */
    private const SHOW_DONE_MINUTES = 30;

    public static function queued(string $tenantId, string $runId, ?string $code): void
    {
        Cache::put(self::pendingKey($tenantId, $code), ['run' => $runId, 'at' => now()->timestamp], now()->addMinutes(30));
    }

    public static function step(string $runId, string $code, int $slice, int $slices, string $phase, int $done, int $total): void
    {
        Cache::put('sync_progress:' . $runId, [
            'code' => $code, 'slice' => $slice, 'slices' => $slices,
            'phase' => $phase, 'done' => $done, 'total' => $total, 'at' => now()->timestamp,
        ], now()->addHours(2));
    }

    /**
     * What a box (one distributor) or Catalog attention (null = any run) should
     * show right now.
     *
     * @return array{state:string}&array<string,mixed>
     */
    public static function state(string $tenantId, ?string $code = null): array
    {
        $code = $code ? strtoupper($code) : null;
        $pending = Cache::get(self::pendingKey($tenantId, $code));

        $q = DB::table('tenant_distributor_sync_runs')
            ->where('tenant_id', $tenantId)
            ->where('started_at', '>=', now()->subHours(6));
        if ($code !== null) {
            // its own refresh, or a whole-shop run that includes it
            $q->where(fn ($w) => $w->where('distributor_code', $code)->orWhereNull('distributor_code'));
        }
        $row = $q->orderByDesc('started_at')->first();

        // Pressed, but no worker has started it yet.
        if ($pending && (! $row || $row->id !== $pending['run'])
            && ! DB::table('tenant_distributor_sync_runs')->where('id', $pending['run'])->exists()) {
            $waited = now()->timestamp - (int) $pending['at'];
            return [
                'state' => $waited > self::STALL_MINUTES * 60 ? 'stalled' : 'queued',
                'run' => $pending['run'],
                'title' => self::title($code),
                'text' => $waited > self::STALL_MINUTES * 60
                    ? 'Still waiting to start after ' . self::mins($waited) . '. The background queue may be stopped.'
                    : 'Waiting for a free worker…',
                'pct' => 0, 'elapsed' => $waited,
            ];
        }

        if (! $row) {
            return ['state' => 'none'];
        }

        $stats = json_decode($row->stats ?? '[]', true) ?: [];
        $started = \Carbon\Carbon::parse($row->started_at, 'UTC');
        $runCode = $row->distributor_code ?? null;
        $base = [
            'run' => $row->id,
            'title' => self::title($runCode) . (! empty($row->dry_run) ? ' (preview only, nothing saved)' : ''),
            'started_at' => $started->timestamp,
        ];

        if ($row->finished_at) {
            $finished = \Carbon\Carbon::parse($row->finished_at, 'UTC');
            if ($finished->lt(now()->subMinutes(self::SHOW_DONE_MINUTES))) {
                return ['state' => 'none'];
            }
            $errors = array_values(array_slice((array) ($stats['errors'] ?? []), 0, 6));
            return $base + [
                'state' => $row->error ? 'failed' : 'done',
                'pct' => 100,
                'elapsed' => max(0, $finished->timestamp - $started->timestamp),
                'finished_ago' => $finished->diffForHumans(),
                'text' => $row->error ? (string) $row->error : self::summary($stats),
                'errors' => $errors,
                'error_count' => count((array) ($stats['errors'] ?? [])),
                'note' => $stats['note'] ?? null,
            ];
        }

        $elapsed = max(0, now()->timestamp - $started->timestamp);
        $slicesTotal = (int) ($stats['slices_total'] ?? 0);
        $slicesDone = (int) ($stats['slices_done'] ?? 0);
        $p = Cache::get('sync_progress:' . $row->id);
        $lastSign = max((int) ($p['at'] ?? 0), \Carbon\Carbon::parse($row->updated_at, 'UTC')->timestamp);

        if ($slicesTotal === 0) {
            return $base + ['state' => 'running', 'pct' => 2, 'elapsed' => $elapsed, 'text' => 'Getting the list of linked items…'];
        }

        $frac = 0.0;
        $text = 'Starting…';
        if ($p && (int) $p['slice'] > $slicesDone) {
            $d = (int) $p['done'];
            $t = max(1, (int) $p['total']);
            [$from, $span, $verb] = match ($p['phase']) {
                'cost'  => [0.05, 0.35, 'Getting your cost'],
                'stock' => [0.40, 0.40, 'Getting stock by warehouse'],
                'items' => [0.80, 0.20, 'Updating your items'],
                default => [0.0, 0.05, 'Starting'],
            };
            $frac = $from + $span * min(1, $d / $t);
            $who = $runCode ? '' : self::label((string) $p['code']) . ': ';
            $text = $p['phase'] === 'start'
                ? $who . 'Starting…'
                : $who . $verb . ' · ' . number_format($d) . ' of ' . number_format((int) $p['total']);
            if ($slicesTotal > 1) {
                $text .= ' · part ' . (int) $p['slice'] . ' of ' . $slicesTotal;
            }
        }
        $pct = (int) round(100 * min(0.99, ($slicesDone + $frac) / $slicesTotal));

        $quiet = now()->timestamp - $lastSign;
        if ($quiet > self::STALL_MINUTES * 60) {
            return $base + [
                'state' => 'stalled', 'pct' => $pct, 'elapsed' => $elapsed,
                'text' => 'No progress for ' . self::mins($quiet) . '. The background queue may be stopped or the distributor is not answering.',
            ];
        }

        return $base + [
            'state' => 'running', 'pct' => max(2, $pct), 'elapsed' => $elapsed, 'text' => $text,
            'items_total' => (int) ($stats['items_total'] ?? 0),
            'items_done' => (int) ($stats['linked'] ?? 0),
        ];
    }

    private static function summary(array $s): string
    {
        $parts = [number_format((int) ($s['linked'] ?? 0)) . ' items checked'];
        $parts[] = number_format((int) ($s['cost_updated'] ?? 0)) . ' costs';
        $parts[] = number_format((int) ($s['avail_updated'] ?? 0)) . ' stock counts';
        if (! empty($s['flags_opened'])) {
            $parts[] = number_format((int) $s['flags_opened']) . ' new to review';
        }
        if (! empty($s['flags_resolved'])) {
            $parts[] = number_format((int) $s['flags_resolved']) . ' cleared';
        }
        return implode(' · ', $parts);
    }

    private static function title(?string $code): string
    {
        return $code ? 'Refreshing ' . self::label($code) : 'Checking all your distributors';
    }

    private static function label(string $code): string
    {
        try { return app(DistributorRegistry::class)->label($code); } catch (\Throwable) { return $code; }
    }

    private static function mins(int $sec): string
    {
        $m = max(1, intdiv($sec, 60));
        return $m . ' minute' . ($m === 1 ? '' : 's');
    }

    private static function pendingKey(string $tenantId, ?string $code): string
    {
        return 'sync_pending:' . $tenantId . ':' . ($code ? strtoupper($code) : 'ALL');
    }
}
