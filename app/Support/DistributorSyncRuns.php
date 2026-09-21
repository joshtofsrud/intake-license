<?php
// MARKER-SYNC-CHUNKED

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The tenant_distributor_sync_runs row behind Catalog attention, written by a
 * chain of slice jobs instead of one long job. Each slice adds its counts;
 * the last job (or a slice that dies) closes the row, so the page never
 * sits on "running" after the work has stopped.
 */
class DistributorSyncRuns
{
    private const TABLE = 'tenant_distributor_sync_runs';

    /**
     * Add one slice's result: numbers are summed, errors are prefixed with the
     * distributor and de-duplicated, and $failed marks that distributor as
     * failed for the rest of the run.
     */
    public static function merge(string $tenantId, string $runId, array $res, ?string $code = null, bool $failed = false): void
    {
        DB::transaction(function () use ($tenantId, $runId, $res, $code, $failed) {
            $row = DB::table(self::TABLE)
                ->where('tenant_id', $tenantId)->where('id', $runId)
                ->lockForUpdate()->first(['stats']);
            if (! $row) {
                return;
            }

            $agg = json_decode($row->stats ?? '[]', true) ?: [];
            foreach ($res as $k => $v) {
                if (is_int($v) || is_float($v)) {
                    $agg[$k] = ($agg[$k] ?? 0) + $v;
                }
            }
            foreach ((array) ($res['errors'] ?? []) as $msg) {
                $line = $code ? $code . ': ' . $msg : (string) $msg;
                if (! in_array($line, $agg['errors'] ?? [], true)) {
                    $agg['errors'][] = $line;
                }
            }
            if (isset($res['note'])) {
                $agg['note'] = $res['note'];
            }
            if ($failed && $code) {
                $agg['failed'] = array_values(array_unique(array_merge($agg['failed'] ?? [], [$code])));
            }

            DB::table(self::TABLE)
                ->where('tenant_id', $tenantId)->where('id', $runId)
                ->update(['stats' => json_encode($agg), 'updated_at' => now()]);
        });
    }

    /** Distributors already marked failed in this run. */
    public static function failedCodes(string $tenantId, string $runId): array
    {
        $stats = DB::table(self::TABLE)
            ->where('tenant_id', $tenantId)->where('id', $runId)->value('stats');

        return (array) ((json_decode($stats ?? '[]', true) ?: [])['failed'] ?? []);
    }

    /** Finish the run. First close wins; a later call never overwrites it. */
    public static function close(string $tenantId, string $runId, ?string $error = null): void
    {
        DB::table(self::TABLE)
            ->where('tenant_id', $tenantId)->where('id', $runId)
            ->whereNull('finished_at')
            ->update(['finished_at' => now(), 'error' => $error, 'updated_at' => now()]);
    }
}
