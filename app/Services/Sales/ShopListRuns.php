<?php
// MARKER-BG-IMPORT: loads queued shop lists 1,000 rows at a time. The scheduler runs it
// every minute (sales:run-imports) and the page runs the first chunk right away. A run is
// claimed with a short lock so two workers never load the same rows.

namespace App\Services\Sales;

use Illuminate\Support\Facades\DB;

class ShopListRuns
{
    public const CHUNK = 1000;

    public static function claim(int $id, int $seconds = 120): bool
    {
        return DB::table('sales_import_runs')->where('id', $id)->whereIn('status', ['queued', 'running'])
            ->where(fn ($w) => $w->whereNull('locked_until')->orWhere('locked_until', '<', now()))
            ->update(['locked_until' => now()->addSeconds($seconds), 'status' => 'running', 'updated_at' => now()]) === 1;
    }

    /** Load chunks of one run for up to $budget seconds. Returns the run's status afterwards. */
    public static function work(int $id, int $budget = 50): string
    {
        if (! self::claim($id, $budget + 70)) return 'busy';
        $start = microtime(true);
        $imp = new ShopListImporter();
        while (true) {
            $run = DB::table('sales_import_runs')->where('id', $id)->first();
            if (! $run || ! in_array($run->status, ['queued', 'running'], true)) return $run->status ?? 'gone';
            if (! is_file($run->path)) {
                DB::table('sales_import_runs')->where('id', $id)->update(['status' => 'failed', 'error' => 'The uploaded file is gone from the server.', 'locked_until' => null, 'updated_at' => now()]);
                return 'failed';
            }
            try {
                $r = $imp->import($run->path, $run->batch, (bool) $run->assign, false, null, json_decode((string) $run->map, true) ?: null, $run->channel_id, (int) $run->pos, self::CHUNK);
            } catch (\Throwable $e) {
                \App\Support\JobFailureReporter::report(self::class, 'Shop list load stopped (batch ' . $run->batch . ')', $e, ['run' => $id, 'pos' => $run->pos]);
                DB::table('sales_import_runs')->where('id', $id)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 250), 'locked_until' => null, 'updated_at' => now()]);
                return 'failed';
            }
            if ($r['error']) {
                DB::table('sales_import_runs')->where('id', $id)->update(['status' => 'failed', 'error' => mb_substr($r['error'], 0, 250), 'locked_until' => null, 'updated_at' => now()]);
                return 'failed';
            }
            $up = ['pos' => $r['pos'], 'updated_at' => now()];
            foreach (['total', 'inserted', 'matched', 'blank', 'assigned', 'with_coords'] as $k) $up[$k] = DB::raw("$k + " . (int) $r[$k]);
            if ($r['done']) { $up += ['status' => 'done', 'finished_at' => now(), 'locked_until' => null]; }
            DB::table('sales_import_runs')->where('id', $id)->update($up);
            if ($r['done']) { @unlink($run->path); return 'done'; }
            if (microtime(true) - $start > $budget) {
                DB::table('sales_import_runs')->where('id', $id)->update(['locked_until' => null]);
                return 'running';
            }
        }
    }
}
