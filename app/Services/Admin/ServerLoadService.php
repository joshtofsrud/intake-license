<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-LOAD-PANEL: what the server is busy with right now, for the Load
 * panel on the master-admin dashboard. Read-only. Every source has its own
 * try/catch so one unreadable source shows as "unreadable", not a 500.
 */
class ServerLoadService
{
    private const HISTORY_KEY = 'admin.load_history';
    private const HISTORY_MAX = 72;   // 6 minutes at a 5s poll

    public function snapshot(): array
    {
        $procs = $this->processes();

        return [
            'cpu'       => $this->cpu(),
            'history'   => $this->history(),
            'processes' => $procs['top'],
            'commands'  => $procs['artisan'],
            'queries'   => $this->queries(),
            'queues'    => $this->queues(),
            'scheduled' => $this->scheduled($procs['artisan']),
            'syncs'     => $this->syncs(),
            'taken_at'  => now()->toIso8601String(),
        ];
    }

    private function cpu(): array
    {
        $parts = explode(' ', trim(@file_get_contents('/proc/loadavg') ?: ''));
        $cores = max(1, substr_count(@file_get_contents('/proc/cpuinfo') ?: '', "processor\t:"));
        return [
            'load_1m'  => (float) ($parts[0] ?? 0),
            'load_5m'  => (float) ($parts[1] ?? 0),
            'load_15m' => (float) ($parts[2] ?? 0),
            'cores'    => $cores,
        ];
    }

    /** Load samples taken while someone has the panel open (shared, newest last). */
    private function history(): array
    {
        try {
            $h   = (array) Cache::get(self::HISTORY_KEY, []);
            $now = time();
            $last = end($h) ?: null;
            if (! $last || $now - (int) $last[0] >= 4) {
                $h[] = [$now, (float) $this->cpu()['load_1m']];
            }
            // drop samples older than the window, so a gap reads as a gap.
            $h = array_values(array_filter($h, fn ($p) => $now - (int) $p[0] <= self::HISTORY_MAX * 5));
            $h = array_slice($h, -self::HISTORY_MAX);
            Cache::put(self::HISTORY_KEY, $h, 900);
            return $h;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Busiest processes, plus every artisan command running however busy. */
    private function processes(): array
    {
        $out = [];
        @exec('ps -eo pid,pcpu,pmem,etimes,args --sort=-pcpu --no-headers 2>/dev/null', $out, $rc);
        if ($rc !== 0 || ! $out) {
            return ['top' => null, 'artisan' => null];
        }

        $rows = [];
        foreach ($out as $line) {
            $p = preg_split('/\s+/', trim($line), 5);
            if (count($p) < 5) continue;
            $args = $p[4];
            if (str_starts_with($args, 'ps -eo') || str_starts_with($args, 'sh -c ps')) continue;
            $rows[] = [
                'pid'     => (int) $p[0],
                'cpu'     => (float) $p[1],
                'mem'     => (float) $p[2],
                'secs'    => (int) $p[3],
                'name'    => $this->friendly($args),
                'command' => $this->artisanCommand($args),
            ];
        }

        $top = array_slice(array_values(array_filter($rows, fn ($r) => $r['cpu'] > 0 || $r['command'])), 0, 10);
        if (! $top) $top = array_slice($rows, 0, 5);

        // one line per artisan command; the scheduler's "sh -c" wrapper doubles them up.
        $artisan = [];
        foreach ($rows as $r) {
            if (! $r['command']) continue;
            $k = $r['command'];
            if (! isset($artisan[$k]) || $r['cpu'] > $artisan[$k]['cpu']) {
                $artisan[$k] = $r + ['n' => ($artisan[$k]['n'] ?? 0)];
            }
            $artisan[$k]['n'] = ($artisan[$k]['n'] ?? 0) + 1;
        }
        usort($artisan, fn ($a, $b) => $b['secs'] <=> $a['secs']);

        return ['top' => $top, 'artisan' => array_values($artisan)];
    }

    private function artisanCommand(string $args): ?string
    {
        $args = str_replace(["'", '"'], '', $args);
        if (! preg_match('/artisan\s+(.+)$/', $args, $m)) return null;
        $cmd = trim(preg_replace("/\s*(>|2>|&&|;|'|\").*$/", '', $m[1]));
        if ($cmd === '' || str_starts_with($cmd, 'schedule:run') || str_starts_with($cmd, 'schedule:work')) return null;
        return $cmd;
    }

    private function friendly(string $args): string
    {
        if ($c = $this->artisanCommand($args)) return $c;
        if (str_contains($args, 'artisan schedule:run')) return 'Scheduler tick';
        if (str_starts_with($args, 'php-fpm: master')) return 'PHP-FPM (master)';
        if (str_starts_with($args, 'php-fpm')) return 'Web request (PHP-FPM worker)';
        if (preg_match('/(mysqld|mariadbd)/', $args)) return 'Database (MySQL)';
        if (str_contains($args, 'redis-server')) return 'Redis';
        if (str_starts_with($args, 'nginx')) return 'nginx';
        if (str_contains($args, 'supervisord')) return 'Supervisor';
        return \Illuminate\Support\Str::limit(basename(strtok($args, ' ')) . ' ' . (string) strtok(''), 60);
    }

    /** Statements running now (sleeping connections left out). */
    private function queries(): ?array
    {
        try {
            $rows = DB::select("SELECT ID, USER, DB, TIME, STATE, INFO FROM information_schema.PROCESSLIST
                                WHERE COMMAND <> 'Sleep' AND ID <> CONNECTION_ID() AND INFO IS NOT NULL
                                ORDER BY TIME DESC LIMIT 10");
            return array_map(fn ($r) => [
                'id'    => (int) $r->ID,
                'secs'  => (int) $r->TIME,
                'state' => (string) ($r->STATE ?? ''),
                'sql'   => \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', (string) $r->INFO), 180),
            ], $rows);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Waiting, running and delayed jobs per queue, with the names of running jobs. */
    private function queues(): ?array
    {
        try {
            $out = [];
            foreach (['default', 'emails'] as $q) {
                $running = [];
                foreach ((array) Redis::zrange("queues:{$q}:reserved", 0, 9) as $payload) {
                    $j = json_decode((string) $payload, true);
                    $running[] = class_basename((string) ($j['displayName'] ?? 'Job'));
                }
                $out[] = [
                    'queue'   => $q,
                    'waiting' => (int) Redis::llen("queues:{$q}"),
                    'running' => (int) Redis::zcard("queues:{$q}:reserved"),
                    'delayed' => (int) Redis::zcard("queues:{$q}:delayed"),
                    'names'   => array_count_values($running),
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Scheduled commands due in the next two hours, and which are running now. */
    private function scheduled(?array $running): ?array
    {
        try {
            app(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $events = app(\Illuminate\Console\Scheduling\Schedule::class)->events();
            $tz     = config('app.timezone', 'UTC');
            $until  = now()->addHours(2);
            $runningNames = array_map(fn ($r) => $r['command'], (array) $running);

            $rows = [];
            foreach ($events as $e) {
                $cmd = $this->artisanCommand((string) $e->command) ?? trim((string) ($e->description ?: $e->command));
                if ($cmd === '') continue;
                $next = \Carbon\Carbon::instance($e->nextRunDate(now()));
                $isRunning = (bool) array_filter($runningNames, fn ($n) => $n === $cmd || str_starts_with($n, $cmd));
                if ($next->gt($until) && ! $isRunning) continue;
                // every-minute housekeeping would fill the list; shown only while running.
                if ((string) $e->expression === '* * * * *' && ! $isRunning) continue;
                $rows[] = [
                    'command' => $cmd,
                    'next'    => $next->toIso8601String(),
                    'every'   => $this->cadence((string) $e->expression),
                    'running' => $isRunning,
                ];
            }
            usort($rows, fn ($a, $b) => [$b['running'], $a['next']] <=> [$a['running'], $b['next']]);
            return array_slice($rows, 0, 14);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function cadence(string $cron): string
    {
        $f = preg_split('/\s+/', trim($cron));
        if (count($f) < 5) return $cron;
        [$mi, $h, $dom, $mo, $dow] = $f;
        if (preg_match('/^\*\/(\d+)$/', $mi, $m) && $h === '*') return 'every ' . $m[1] . ' min';
        if ($mi === '*' && $h === '*') return 'every minute';
        if (ctype_digit($mi) && $h === '*') return 'hourly';
        if (preg_match('/^\*\/(\d+)$/', $h, $m)) return 'every ' . $m[1] . ' hours';
        if (ctype_digit($mi) && ctype_digit($h) && $dom === '*' && $mo === '*') return $dow === '*' ? 'daily' : 'weekly';
        return $cron;
    }

    /** Distributor syncs running now, and the last day's finished ones. */
    private function syncs(): ?array
    {
        try {
            if (! Schema::hasTable('distributor_sync_runs')) return null;
            $rows = DB::table('distributor_sync_runs as r')
                ->leftJoin('tenants as t', 't.id', '=', 'r.tenant_id')
                ->where(fn ($q) => $q->where('r.status', 'running')->orWhere('r.started_at', '>=', now()->subDay()))
                ->orderByRaw("r.status = 'running' DESC")->orderByDesc('r.started_at')
                ->limit(12)
                ->get(['r.status', 'r.distributor_code', 'r.linked', 'r.processed', 'r.fetch_seconds', 'r.write_seconds',
                       'r.error', 'r.started_at', 'r.finished_at', 'r.subscription_id', 't.name as tenant']);
            return $rows->map(function ($r) {
                $start = $r->started_at ? \Carbon\Carbon::parse($r->started_at) : null;
                $end   = $r->finished_at ? \Carbon\Carbon::parse($r->finished_at) : now();
                $mins  = $start ? max(1, $start->diffInSeconds($end)) / 60 : null;
                return [
                    // MARKER-SUPPLY-RUN: a distributor's shared run serves every shop
                    'tenant'      => $r->subscription_id === 'supply' ? 'All shops (one run)' : ($r->tenant ?: 'Unknown shop'),
                    'distributor' => $r->distributor_code,
                    'status'      => $r->status,
                    'linked'      => (int) $r->linked,
                    'processed'   => (int) $r->processed,
                    'pct'         => $r->linked ? min(100, (int) floor($r->processed / $r->linked * 100)) : null,
                    'per_min'     => $mins ? (int) round($r->processed / $mins) : null,
                    'started'     => $start?->toIso8601String(),
                    'secs'        => $start ? (int) $start->diffInSeconds($end) : null,
                    'error'       => $r->error,
                ];
            })->all();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
