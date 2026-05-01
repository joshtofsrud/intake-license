<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Gathers droplet/server-level health metrics for the master admin
 * dashboard. Each metric is wrapped in its own try/catch so a single
 * broken source can't take down the whole widget.
 *
 * Results cached for 5 seconds — at a 30-second poll interval that means
 * we run system commands at most every other refresh, and concurrent
 * sessions don't pile up.
 */
class ServerHealthService
{
    private const CACHE_KEY = 'admin.server_health';
    private const CACHE_TTL = 5;

    public function snapshot(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return [
                'cpu'     => $this->cpu(),
                'memory'  => $this->memory(),
                'disk'    => $this->disk(),
                'php_fpm' => $this->phpFpm(),
                'db'      => $this->db(),
                'uptime'  => $this->uptime(),
                'taken_at'=> now()->toIso8601String(),
            ];
        });
    }

    /** Read /proc/loadavg for 1m, 5m, 15m load averages and core count. */
    private function cpu(): array
    {
        try {
            $loadavg = trim(@file_get_contents('/proc/loadavg') ?: '');
            $parts = explode(' ', $loadavg);
            $load1  = (float) ($parts[0] ?? 0);
            $load5  = (float) ($parts[1] ?? 0);
            $load15 = (float) ($parts[2] ?? 0);

            // Core count via /proc/cpuinfo
            $cpuinfo = @file_get_contents('/proc/cpuinfo') ?: '';
            $cores = max(1, substr_count($cpuinfo, "processor\t:"));

            // Health is load-per-core. >1.0/core = saturated.
            $loadPerCore = $cores > 0 ? $load1 / $cores : 0;
            $status = $loadPerCore > 0.85 ? 'err' : ($loadPerCore > 0.6 ? 'warn' : 'ok');

            return [
                'available'   => true,
                'status'      => $status,
                'load_1m'     => $load1,
                'load_5m'     => $load5,
                'load_15m'    => $load15,
                'cores'       => $cores,
                'load_pct'    => min(100, round($loadPerCore * 100)),
            ];
        } catch (\Throwable $e) {
            return ['available' => false, 'status' => 'unknown', 'error' => $e->getMessage()];
        }
    }

    /** Read /proc/meminfo for total + available memory in MB. */
    private function memory(): array
    {
        try {
            $meminfo = @file_get_contents('/proc/meminfo') ?: '';
            preg_match('/MemTotal:\s+(\d+)\s+kB/i', $meminfo, $totalMatch);
            preg_match('/MemAvailable:\s+(\d+)\s+kB/i', $meminfo, $availMatch);

            $totalKb = (int) ($totalMatch[1] ?? 0);
            $availKb = (int) ($availMatch[1] ?? 0);
            $usedKb  = max(0, $totalKb - $availKb);

            $totalMb = round($totalKb / 1024);
            $usedMb  = round($usedKb / 1024);

            $pct = $totalKb > 0 ? round(($usedKb / $totalKb) * 100) : 0;
            $status = $pct > 90 ? 'err' : ($pct > 70 ? 'warn' : 'ok');

            return [
                'available'  => true,
                'status'     => $status,
                'used_mb'    => $usedMb,
                'total_mb'   => $totalMb,
                'used_gb'    => round($usedMb / 1024, 1),
                'total_gb'   => round($totalMb / 1024, 1),
                'pct'        => $pct,
            ];
        } catch (\Throwable $e) {
            return ['available' => false, 'status' => 'unknown', 'error' => $e->getMessage()];
        }
    }

    /** Disk usage on root partition via disk_total_space / disk_free_space. */
    private function disk(): array
    {
        try {
            $total = @disk_total_space('/');
            $free  = @disk_free_space('/');
            if (!$total) return ['available' => false, 'status' => 'unknown'];

            $used = $total - $free;
            $totalGb = round($total / 1024 / 1024 / 1024);
            $usedGb  = round($used  / 1024 / 1024 / 1024);
            $pct = round(($used / $total) * 100);
            $status = $pct > 90 ? 'err' : ($pct > 75 ? 'warn' : 'ok');

            return [
                'available' => true,
                'status'    => $status,
                'used_gb'   => $usedGb,
                'total_gb'  => $totalGb,
                'pct'       => $pct,
            ];
        } catch (\Throwable $e) {
            return ['available' => false, 'status' => 'unknown', 'error' => $e->getMessage()];
        }
    }

    /**
     * Count active PHP-FPM workers via process inspection. Doesn't require
     * exposing the FPM status page — just counts php-fpm processes (minus
     * the master).
     */
    private function phpFpm(): array
    {
        try {
            $output = [];
            @exec('pgrep -c php-fpm', $output, $rc);
            if ($rc !== 0 && $rc !== 1) {
                return ['available' => false, 'status' => 'unknown'];
            }
            $totalProcs = (int) ($output[0] ?? 0);
            // Subtract 1 for the master process; result is workers.
            $workers = max(0, $totalProcs - 1);

            // We don't know configured pm.max_children without parsing the
            // pool config file. Default php-fpm.conf on Ubuntu is 5; we'll
            // use a heuristic of 12 as max for the bar (covers default + headroom).
            // If you tune pm settings later this can be made configurable.
            $maxAssumed = 12;
            $pct = min(100, round(($workers / $maxAssumed) * 100));
            $status = $pct > 90 ? 'err' : ($pct > 70 ? 'warn' : 'ok');

            return [
                'available' => true,
                'status'    => $status,
                'workers'   => $workers,
                'max'       => $maxAssumed,
                'pct'       => $pct,
            ];
        } catch (\Throwable $e) {
            return ['available' => false, 'status' => 'unknown', 'error' => $e->getMessage()];
        }
    }

    /** MySQL connection count + average query time (rough). */
    private function db(): array
    {
        try {
            $threadsConnected = (int) (DB::select("SHOW STATUS LIKE 'Threads_connected'")[0]->Value ?? 0);
            $maxConnections   = (int) (DB::select("SHOW VARIABLES LIKE 'max_connections'")[0]->Value ?? 100);

            $pct = $maxConnections > 0 ? round(($threadsConnected / $maxConnections) * 100) : 0;
            $status = $pct > 80 ? 'err' : ($pct > 50 ? 'warn' : 'ok');

            // Quick query latency check — run a trivial query and time it
            $start = microtime(true);
            DB::select('SELECT 1');
            $queryMs = round((microtime(true) - $start) * 1000);

            return [
                'available'  => true,
                'status'     => $status,
                'connections'=> $threadsConnected,
                'max'        => $maxConnections,
                'pct'        => $pct,
                'query_ms'   => $queryMs,
            ];
        } catch (\Throwable $e) {
            return ['available' => false, 'status' => 'unknown', 'error' => $e->getMessage()];
        }
    }

    /** Server uptime as a human-readable string. */
    private function uptime(): ?string
    {
        try {
            $uptimeStr = trim(@file_get_contents('/proc/uptime') ?: '');
            $seconds = (int) explode(' ', $uptimeStr)[0];
            if ($seconds < 1) return null;

            $days = intdiv($seconds, 86400);
            $hours = intdiv($seconds % 86400, 3600);
            return $days > 0 ? "{$days}d {$hours}h" : "{$hours}h";
        } catch (\Throwable $e) {
            return null;
        }
    }
}
