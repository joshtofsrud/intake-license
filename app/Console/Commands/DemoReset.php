<?php

namespace App\Console\Commands;

use App\Models\DemoSetting;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * MARKER-DEMO-RESET — restore a demo tenant from its frozen template.
 *
 * Runs hourly on the hour, and on demand. Everything visitors did is discarded;
 * the template is never modified. Dates are shifted by whole weeks so the demo
 * always reads as a live, busy shop rather than a museum piece.
 */
class DemoReset extends Command
{
    protected $signature   = 'demo:reset {--slug=demo : Which demo tenant} {--force : Ignore the pause}';
    protected $description = 'Restore the demo tenant from its frozen template';

    public function handle(): int
    {
        $slug = (string) $this->option('slug');

        if (! $this->option('force')) {
            $pausedUntil = DemoSetting::get("paused_until:{$slug}");
            if ($pausedUntil && CarbonImmutable::parse($pausedUntil)->isFuture()) {
                $this->line('paused until ' . $pausedUntil . ' — skipping');
                return self::SUCCESS;
            }
        }

        $local = Storage::disk('local');
        $dir   = "demo/{$slug}";
        if (! $local->exists("{$dir}/manifest.json") || ! $local->exists("{$dir}/template.jsonl")) {
            $this->error("No frozen template at storage/app/{$dir} — run demo:build-template first.");
            return self::FAILURE;
        }
        $manifest = json_decode($local->get("{$dir}/manifest.json"), true);
        $tenantId = $manifest['tenant_id'] ?? null;
        $tables   = $manifest['tables'] ?? [];
        if (! $tenantId || ! $tables) {
            $this->error('Manifest is missing tenant_id or tables.');
            return self::FAILURE;
        }

        $tenant = Tenant::withTrashed()->find($tenantId);
        if ($tenant && ! $tenant->is_demo) {
            $this->error('Refusing: the manifest tenant is not flagged is_demo.');
            return self::FAILURE;
        }

        $shiftDays = $this->shiftDays($manifest, $slug);
        $started   = microtime(true);

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            // wipe: child tables first is unnecessary with checks off, and the
            // manifest order is the copy order anyway
            // MARKER-DEMO-RESET-TENANTS — the manifest lists 'tenants' too (the
            // freeze records the tenant row under that name), but it has no
            // tenant_id column and is removed by id just below.
            foreach (array_reverse($tables) as $t) {
                if ($t === 'tenants') continue;
                DB::table($t)->where('tenant_id', $tenantId)->delete();
            }
            DB::table('tenants')->where('id', $tenantId)->delete();

            $rows = 0; $buffer = []; $current = null;
            $fh = fopen(storage_path("app/{$dir}/template.jsonl"), 'r');
            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if ($line === '') continue;
                $entry = json_decode($line, true);
                if (! $entry) continue;
                $table = $entry['table'];
                $row   = $this->shiftRow($table, $entry['row'], $shiftDays);

                if ($current !== null && $table !== $current) {
                    $this->flush($current, $buffer);
                    $buffer = [];
                }
                $current  = $table;
                $buffer[] = $row;
                $rows++;
                if (count($buffer) >= 200) {
                    $this->flush($current, $buffer);
                    $buffer = [];
                }
            }
            fclose($fh);
            if ($buffer) $this->flush($current, $buffer);
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        // media: the frozen snapshot is authoritative
        $public = Storage::disk('public');
        $public->deleteDirectory('tenants/' . $tenantId);
        foreach ($local->allFiles("{$dir}/files") as $file) {
            $rel = substr($file, strlen("{$dir}/files") + 1);
            $public->put('tenants/' . $tenantId . '/' . $rel, $local->get($file));
        }

        $this->realignAppointments($tenantId); // MARKER-DEMO-TIMELINE

        // sessions from before this moment are stale; the banner middleware
        // compares against this and ejects them
        DemoSetting::put("epoch:{$slug}", (string) now()->timestamp);
        DemoSetting::put("last_reset_at:{$slug}", now()->toIso8601String());
        DemoSetting::put("shift_days:{$slug}", (string) $shiftDays);

        $secs = round(microtime(true) - $started, 1);
        $this->info("demo '{$slug}' reset: {$rows} rows, dates shifted {$shiftDays} days, {$secs}s");
        Log::info('MARKER-DEMO-RESET complete', ['slug' => $slug, 'rows' => $rows, 'shift_days' => $shiftDays, 'seconds' => $secs]);
        return self::SUCCESS;
    }

    /**
     * MARKER-DEMO-TIMELINE — status follows the calendar, not the source data.
     *
     * The shift moves dates but not meaning: an appointment that was finished
     * in the source can land three days from now and still say "completed".
     * This walks the demo's appointments and makes each one make sense where
     * it now sits. Past days are left exactly alone — that history is what
     * makes the sales and reports screens worth looking at.
     */
    private function realignAppointments(string $tenantId): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('tenant_appointments')) return;

        $today   = \Carbon\CarbonImmutable::now(config('app.timezone'))->toDateString();
        $future  = 0; $todayN = 0;

        foreach (DB::table('tenant_appointments')->where('tenant_id', $tenantId)
            ->where('appointment_date', '>=', $today)
            ->get(['id', 'appointment_date', 'status']) as $a) {

            if (in_array($a->status, ['cancelled', 'refunded'], true)) {
                continue; // a cancellation reads fine on any date
            }

            // deterministic per row: the same reset yields the same board
            $bucket = hexdec(substr(md5((string) $a->id), 0, 2)) % 3;
            $upd    = ['completed_at' => null];

            if ((string) $a->appointment_date === $today) {
                $upd['status'] = [0 => 'in_progress', 1 => 'confirmed', 2 => 'completed'][$bucket];
                if ($upd['status'] === 'completed') {
                    $upd['completed_at'] = \Carbon\CarbonImmutable::now(config('app.timezone'))->subHours(2);
                } else {
                    $upd['payment_status'] = 'unpaid';
                }
                $todayN++;
            } else {
                // still to come: open, and not yet paid for
                $upd['status']         = $bucket === 2 ? 'pending' : 'confirmed';
                $upd['payment_status'] = 'unpaid';
                $future++;
            }

            DB::table('tenant_appointments')->where('id', $a->id)->update($upd);
        }

        $this->line("  appointments realigned: {$todayN} today, {$future} upcoming");
    }

    private function flush(string $table, array $rows): void
    {
        if ($rows) DB::table($table)->insert($rows);
    }

    /**
     * Whole weeks between the anchor week and this week, so weekday-keyed data
     * (availability, capacity rules, shifts) still lines up after the shift.
     */
    private function shiftDays(array $manifest, string $slug): int
    {
        $anchor = DemoSetting::get("anchor_week:{$slug}") ?: ($manifest['busiest_week'] ?? null);
        if (! $anchor) return 0;
        $anchorMonday = CarbonImmutable::parse($anchor)->startOfWeek();
        $thisMonday   = CarbonImmutable::now(config('app.timezone'))->startOfWeek();
        return (int) $anchorMonday->diffInDays($thisMonday, false);
    }

    private array $dateCols = [];

    private function shiftRow(string $table, array $row, int $days): array
    {
        if ($days === 0) return $row;
        if (! isset($this->dateCols[$table])) {
            $cols = DB::select(
                "SELECT COLUMN_NAME c FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
                   AND DATA_TYPE IN ('date','datetime','timestamp')", [DB::getDatabaseName(), $table]);
            $this->dateCols[$table] = array_map(fn ($r) => $r->c, $cols);
        }
        foreach ($this->dateCols[$table] as $col) {
            $v = $row[$col] ?? null;
            if ($v === null || $v === '' || str_starts_with((string) $v, '0000')) continue;
            try {
                $row[$col] = CarbonImmutable::parse($v)->addDays($days)->format(
                    strlen((string) $v) <= 10 ? 'Y-m-d' : 'Y-m-d H:i:s');
            } catch (\Throwable) {
                // unparseable: leave it exactly as frozen
            }
        }
        return $row;
    }
}
