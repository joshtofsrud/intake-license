#!/usr/bin/env python3
"""
Patch 141 — Hero strip becomes real numbers, not abstract pulses.

The patch-135 hero used six "pulse bars" with no units and no labels
beyond a one-word channel name. Not actionable.

This replaces it with 8 tiles:
  - SYSTEM         — status dot + plain-English headline
  - CPU LOAD       — "0.02 / 2 cores" + fill bar
  - MEMORY         — "1.3 of 3.8 GB" + fill bar
  - DISK           — "4 of 76 GB" + fill bar
  - PHP-FPM        — "3 of 12 workers" + fill bar
  - DATABASE       — "1 conn · 1ms" + fill bar
  - QUEUE          — "0 pending" + fill bar
  - BACKUP         — "9h ago" + age status

Data comes from app(ServerHealthService::class)->snapshot() which was
already running for the old widget. Stripe and Domains drop out of the
hero — they remain in the Operational Health list below.

Idempotent.
"""

import argparse
import pathlib
import sys


# ─────────────────────────────────────────────────────────
# Controller: rewrite buildHero
# ─────────────────────────────────────────────────────────

OLD_BUILD = '''    protected function buildHero(): array
    {
        // Compute six pulses; aggregate into one overall state.
        $pulses = [
            'server'  => $this->pulseServer(),
            'db'      => $this->pulseDb(),
            'queue'   => $this->pulseQueue(),
            'stripe'  => $this->pulseStripe(),
            'domains' => $this->pulseDomains(),
            'backups' => $this->pulseBackups(),
        ];

        $bads  = collect($pulses)->where('state', 'bad')->keys();
        $warns = collect($pulses)->where('state', 'warn')->keys();

        if ($bads->isNotEmpty()) {
            $state = 'bad';
            $headline = $this->headlineFor($pulses, $bads);
        } elseif ($warns->isNotEmpty()) {
            $state = 'warn';
            $headline = $this->headlineFor($pulses, $warns);
        } else {
            $state = 'ok';
            $headline = 'All systems normal';
        }

        // Uptime — server uptime if we can read it, else null.
        $uptime = $this->serverUptimeReadable();

        return [
            'state'    => $state,
            'headline' => $headline,
            'pulses'   => $pulses,
            'uptime'   => $uptime,
        ];
    }'''

NEW_BUILD = '''    // MARKER-PATCH-141 — hero shows real numbers with units, sourced from ServerHealthService.
    protected function buildHero(): array
    {
        $snap = app(\\App\\Services\\Admin\\ServerHealthService::class)->snapshot();

        // Tile definitions. Each maps to a status (ok/warn/bad/idle), a
        // formatted big value, a one-line meta, and a fill 0..100 for
        // the tiny progress bar at the bottom. unavailable -> idle/n/a.
        $tiles = [];

        // CPU
        $c = $snap['cpu'] ?? ['available' => false];
        $tiles['cpu'] = $c['available']
            ? ['label'=>'CPU load', 'value'=>$c['load_1m'].' / '.$c['cores'].' cores',
               'meta'=>'5m '.$c['load_5m'].' · 15m '.$c['load_15m'],
               'pct'=>$c['load_pct'], 'state'=>$this->mapStatus($c['status'])]
            : ['label'=>'CPU load','value'=>'n/a','meta'=>'unreadable','pct'=>0,'state'=>'idle'];

        // Memory
        $m = $snap['memory'] ?? ['available' => false];
        $tiles['memory'] = $m['available']
            ? ['label'=>'Memory', 'value'=>$m['used_gb'].' of '.$m['total_gb'].' GB',
               'meta'=>$m['pct'].'% used',
               'pct'=>$m['pct'], 'state'=>$this->mapStatus($m['status'])]
            : ['label'=>'Memory','value'=>'n/a','meta'=>'unreadable','pct'=>0,'state'=>'idle'];

        // Disk
        $d = $snap['disk'] ?? ['available' => false];
        $tiles['disk'] = $d['available']
            ? ['label'=>'Disk', 'value'=>$d['used_gb'].' of '.$d['total_gb'].' GB',
               'meta'=>$d['pct'].'% used',
               'pct'=>$d['pct'], 'state'=>$this->mapStatus($d['status'])]
            : ['label'=>'Disk','value'=>'n/a','meta'=>'unreadable','pct'=>0,'state'=>'idle'];

        // PHP-FPM
        $f = $snap['php_fpm'] ?? ['available' => false];
        $tiles['fpm'] = $f['available']
            ? ['label'=>'PHP-FPM', 'value'=>$f['workers'].' of '.$f['max'].' workers',
               'meta'=>'master + active',
               'pct'=>$f['pct'], 'state'=>$this->mapStatus($f['status'])]
            : ['label'=>'PHP-FPM','value'=>'n/a','meta'=>'unreadable','pct'=>0,'state'=>'idle'];

        // Database
        $b = $snap['db'] ?? ['available' => false];
        $tiles['db'] = $b['available']
            ? ['label'=>'Database', 'value'=>$b['connections'].' conn · '.$b['query_ms'].'ms',
               'meta'=>'cap '.$b['max'],
               'pct'=>$b['pct'], 'state'=>$this->mapStatus($b['status'])]
            : ['label'=>'Database','value'=>'n/a','meta'=>'unreadable','pct'=>0,'state'=>'idle'];

        // Queue
        $pending = 0; $qOk = true;
        try { $pending = (int) \\Illuminate\\Support\\Facades\\Redis::llen('queues:default'); }
        catch (\\Throwable $e) { $qOk = false; }
        $tiles['queue'] = $qOk
            ? ['label'=>'Queue', 'value'=>$pending.' pending',
               'meta'=>$pending === 0 ? 'idle' : ($pending > 50 ? 'backed up' : 'working'),
               'pct'=>min(100, $pending * 2),
               'state'=>$pending > 50 ? 'bad' : ($pending > 5 ? 'warn' : 'ok')]
            : ['label'=>'Queue','value'=>'n/a','meta'=>'redis unreachable','pct'=>0,'state'=>'bad'];

        // Backup
        $bk = \\App\\Models\\SystemHealth::read('last_backup');
        if (! $bk || empty($bk['at'])) {
            $tiles['backup'] = ['label'=>'Backup', 'value'=>'no record',
                'meta'=>'script not yet wired', 'pct'=>0, 'state'=>'warn'];
        } else {
            $ts = \\Carbon\\Carbon::parse($bk['at']);
            $age = $ts->diffInHours(now());
            $sizeMb = isset($bk['bytes']) ? round($bk['bytes'] / 1024 / 1024, 1) : null;
            $tiles['backup'] = [
                'label'=>'Backup',
                'value'=>$ts->diffForHumans(null, true).' ago',
                'meta'=>$sizeMb ? $sizeMb.' MB' : 'size unknown',
                'pct'=> min(100, ($age / 36) * 100),
                'state'=>$age > 36 ? 'bad' : ($age > 30 ? 'warn' : 'ok'),
            ];
        }

        // Roll up into single status
        $states = array_column($tiles, 'state');
        $bads  = array_keys(array_filter($tiles, fn ($t) => $t['state'] === 'bad'));
        $warns = array_keys(array_filter($tiles, fn ($t) => $t['state'] === 'warn'));
        if (! empty($bads)) {
            $state = 'bad';
            $headline = $this->headlineWith($tiles, $bads, 'critical');
        } elseif (! empty($warns)) {
            $state = 'warn';
            $headline = $this->headlineWith($tiles, $warns, 'attention');
        } else {
            $state = 'ok';
            $headline = 'All systems normal';
        }

        return [
            'state'    => $state,
            'headline' => $headline,
            'uptime'   => $snap['uptime'] ?? null,
            'tiles'    => $tiles,
        ];
    }

    /** Map ServerHealthService status names to our token. */
    protected function mapStatus(?string $s): string
    {
        return match ($s) {
            'ok'   => 'ok',
            'warn' => 'warn',
            'err'  => 'bad',
            default => 'idle',
        };
    }

    /** Build a plain-English headline naming the affected subsystems. */
    protected function headlineWith(array $tiles, array $keys, string $verb): string
    {
        $names = array_map(fn ($k) => $tiles[$k]['label'], $keys);
        if (count($names) === 1) {
            $first = reset($keys);
            $t = $tiles[$first];
            return "{$t['label']} needs {$verb} — {$t['value']}";
        }
        $last = array_pop($names);
        return ucfirst($verb) . ': ' . implode(', ', $names) . ' and ' . $last;
    }'''


# Drop the now-unused pulse* helpers + headlineFor + serverUptimeReadable
OLD_HELPERS = '''    protected function headlineFor(array $pulses, $keys): string
    {
        $labels = $keys->map(fn ($k) => $pulses[$k]['label'] ?? $k)->all();
        if (count($labels) === 1) return "Attention: {$labels[0]}";
        $last = array_pop($labels);
        return 'Attention: ' . implode(', ', $labels) . " and {$last}";
    }

    protected function pulseServer(): array
    {
        // ServerHealthWidget already computes this; recompute the
        // load average ourselves to keep this class self-contained.
        try {
            $load = sys_getloadavg()[0] ?? 0;
            $cores = (int) shell_exec('nproc') ?: 1;
            $ratio = $load / max($cores, 1);
            $state = $ratio > 1.2 ? 'bad' : ($ratio > 0.7 ? 'warn' : 'ok');
        } catch (Throwable $e) {
            $state = 'idle';
        }
        return ['label' => 'Server', 'state' => $state];
    }

    protected function pulseDb(): array
    {
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $ms = (microtime(true) - $start) * 1000;
            $state = $ms > 200 ? 'warn' : 'ok';
        } catch (Throwable $e) {
            $state = 'bad';
        }
        return ['label' => 'Database', 'state' => $state];
    }

    protected function pulseQueue(): array
    {
        try {
            $pending = (int) Redis::llen('queues:default');
            $state = $pending > 50 ? 'bad' : ($pending > 5 ? 'warn' : ($pending === 0 ? 'idle' : 'ok'));
        } catch (Throwable $e) {
            $state = 'bad';
        }
        return ['label' => 'Queue', 'state' => $state];
    }

    protected function pulseStripe(): array
    {
        try {
            $stuck = (int) DB::table('stripe_webhook_events')
                ->whereNull('processed_at')
                ->where('received_at', '<', now()->subMinutes(5))
                ->count();
            $state = $stuck > 0 ? 'bad' : 'ok';
        } catch (Throwable $e) {
            $state = 'idle';
        }
        return ['label' => 'Stripe', 'state' => $state];
    }

    protected function pulseDomains(): array
    {
        $stuck = TenantDomain::stuckVerifying()->count();
        $errored = TenantDomain::where('status', 'error')
            ->where('updated_at', '<', now()->subDay())->count();
        $state = ($stuck + $errored) > 0 ? 'bad' : 'ok';
        return ['label' => 'Domains', 'state' => $state];
    }

    protected function pulseBackups(): array
    {
        $h = SystemHealth::read('last_backup');
        if (! $h || empty($h['at'])) {
            return ['label' => 'Backups', 'state' => 'warn'];
        }
        $age = \\Carbon\\Carbon::parse($h['at'])->diffInHours(now());
        $state = $age > 36 ? 'bad' : ($age > 30 ? 'warn' : 'ok');
        return ['label' => 'Backups', 'state' => $state];
    }

    protected function serverUptimeReadable(): ?string
    {
        try {
            $secs = (int) (file_get_contents('/proc/uptime') ? (float) explode(' ', file_get_contents('/proc/uptime'))[0] : 0);
            if ($secs <= 0) return null;
            $days  = intdiv($secs, 86400);
            $hours = intdiv($secs % 86400, 3600);
            return "{$days}d {$hours}h";
        } catch (Throwable $e) {
            return null;
        }
    }'''

NEW_HELPERS = '''    // MARKER-PATCH-141 — pulse helpers replaced; mapStatus + headlineWith live in buildHero block above.'''


# ─────────────────────────────────────────────────────────
# View: replace .pd-hero block
# ─────────────────────────────────────────────────────────

OLD_VIEW = '''  {{-- ────────── HERO ────────── --}}
  <div class="pd-hero">
    <div class="pd-hero-state {{ $hero['state'] }}">
      <div class="pd-hero-dot"></div>
      <div>
        <div class="pd-hero-label">System status</div>
        <div class="pd-hero-headline">{{ $hero['headline'] }}</div>
      </div>
    </div>

    <div class="pd-hero-pulses">
      @foreach($hero['pulses'] as $key => $p)
        <div class="pd-pulse" title="{{ $p['label'] }}">
          <div class="pd-pulse-lbl">{{ $p['label'] }}</div>
          <div class="pd-pulse-bar {{ $p['state'] }}"><span></span></div>
        </div>
      @endforeach
    </div>

    {{-- MARKER-PATCH-138 — block-form to avoid blade parse failure --}}
    <div class="pd-hero-meta">
      @if($hero['uptime'])
        <b>{{ $hero['uptime'] }}</b>uptime
      @endif
    </div>
  </div>'''

NEW_VIEW = '''  {{-- ────────── HERO (MARKER-PATCH-141) — real numbers tiles ────────── --}}
  <div class="pd-hero2">
    <div class="pd-hero2-status pd-h2-{{ $hero['state'] }}">
      <div class="pd-h2-label">SYSTEM</div>
      <div class="pd-h2-headrow">
        <div class="pd-h2-dot"></div>
        <div class="pd-h2-headline">{{ $hero['headline'] }}</div>
      </div>
      @if($hero['uptime'])
        <div class="pd-h2-meta">uptime {{ $hero['uptime'] }}</div>
      @endif
    </div>

    @foreach($hero['tiles'] as $key => $t)
      <div class="pd-hero2-tile pd-h2-{{ $t['state'] }}">
        <div class="pd-h2-label">{{ strtoupper($t['label']) }}</div>
        <div class="pd-h2-value">{{ $t['value'] }}</div>
        <div class="pd-h2-bar"><span style="width:{{ $t['pct'] }}%"></span></div>
        <div class="pd-h2-meta">{{ $t['meta'] }}</div>
      </div>
    @endforeach
  </div>'''


# CSS additions/replacements — drop old pulse styles, add tile styles
OLD_CSS = '''  /* Hero */
  .pd-hero { display:grid; grid-template-columns:auto 1fr auto; gap:24px; align-items:center;
    padding:18px 22px; background:var(--pd-surface); border:1px solid var(--pd-border);
    border-radius:var(--pd-r-lg); margin-bottom:8px; }
  .pd-hero-state { display:flex; align-items:center; gap:12px; }
  .pd-hero-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
  .pd-hero-state.ok .pd-hero-dot   { background:var(--pd-ok); box-shadow:0 0 12px var(--pd-ok); }
  .pd-hero-state.warn .pd-hero-dot { background:var(--pd-warn); box-shadow:0 0 12px var(--pd-warn); }
  .pd-hero-state.bad .pd-hero-dot  { background:var(--pd-bad); box-shadow:0 0 12px var(--pd-bad); }
  .pd-hero-label { font-size:11px; text-transform:uppercase; letter-spacing:.08em; color:var(--pd-text-dim); margin-bottom:2px; }
  .pd-hero-headline { font-size:15px; font-weight:500; }
  .pd-hero-pulses { display:flex; gap:14px; }
  .pd-pulse { text-align:center; padding:4px 10px; border-radius:var(--pd-r-md); }
  .pd-pulse-lbl { font-size:9.5px; text-transform:uppercase; letter-spacing:.06em; color:var(--pd-text-dim); }
  .pd-pulse-bar { width:62px; height:4px; background:rgba(255,255,255,.06); border-radius:2px; margin-top:6px; overflow:hidden; }
  .pd-pulse-bar > span { display:block; height:100%; }
  .pd-pulse-bar.ok > span    { width:96%; background:var(--pd-ok); }
  .pd-pulse-bar.warn > span  { width:55%; background:var(--pd-warn); }
  .pd-pulse-bar.bad > span   { width:22%; background:var(--pd-bad); }
  .pd-pulse-bar.idle > span  { width:8%;  background:var(--pd-text-dim); }
  .pd-hero-meta { text-align:right; font-family:var(--pd-font-mono); font-size:11px; color:var(--pd-text-dim); }
  .pd-hero-meta b { display:block; color:var(--pd-text); font-size:13px; font-weight:500; }'''

NEW_CSS = '''  /* Hero v2 (MARKER-PATCH-141) — 8 tiles with real numbers + a status cell */
  .pd-hero2 {
    display: grid;
    grid-template-columns: 1.4fr repeat(7, 1fr);
    gap: 1px;
    background: var(--pd-border);
    border: 1px solid var(--pd-border);
    border-radius: var(--pd-r-lg);
    overflow: hidden;
    margin-bottom: 22px;
  }
  .pd-hero2-status, .pd-hero2-tile {
    background: var(--pd-surface);
    padding: 12px 14px 11px;
    display: flex; flex-direction: column; gap: 5px;
    min-height: 92px; justify-content: space-between;
  }
  .pd-h2-label { font-size: 9.5px; letter-spacing: 0.08em; color: var(--pd-text-dim); font-weight: 600; }
  .pd-h2-value { font-size: 17px; font-weight: 600; letter-spacing: -0.01em; color: var(--pd-text); line-height: 1.15; }
  .pd-h2-meta { font-size: 10.5px; color: var(--pd-text-dim); font-family: var(--pd-font-mono); }
  .pd-h2-bar { height: 3px; background: rgba(0,0,0,.06); border-radius: 2px; overflow: hidden; }
  .dark .pd .pd-h2-bar { background: rgba(255,255,255,.07); }
  .pd-h2-bar > span { display: block; height: 100%; background: var(--pd-text-dim); transition: width .3s ease; }
  .pd-h2-ok .pd-h2-bar > span   { background: var(--pd-ok); }
  .pd-h2-warn .pd-h2-bar > span { background: var(--pd-warn); }
  .pd-h2-bad .pd-h2-bar > span  { background: var(--pd-bad); }
  .pd-h2-idle .pd-h2-bar > span { background: var(--pd-text-dim); opacity: .4; }

  /* Status cell */
  .pd-hero2-status { gap: 8px; }
  .pd-h2-headrow { display: flex; align-items: center; gap: 10px; }
  .pd-h2-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
  .pd-h2-ok .pd-h2-dot   { background: var(--pd-ok);   box-shadow: 0 0 10px var(--pd-ok); }
  .pd-h2-warn .pd-h2-dot { background: var(--pd-warn); box-shadow: 0 0 10px var(--pd-warn); }
  .pd-h2-bad .pd-h2-dot  { background: var(--pd-bad);  box-shadow: 0 0 10px var(--pd-bad); }
  .pd-h2-idle .pd-h2-dot { background: var(--pd-text-dim); }
  .pd-h2-headline { font-size: 14px; font-weight: 500; color: var(--pd-text); }

  @media (max-width: 1100px) {
    .pd-hero2 { grid-template-columns: repeat(4, 1fr); }
    .pd-hero2-status { grid-column: 1 / -1; }
  }'''


EDITS = [
    ('app/Filament/Pages/PlatformDashboard.php', OLD_BUILD,   NEW_BUILD,   'controller buildHero'),
    ('app/Filament/Pages/PlatformDashboard.php', OLD_HELPERS, NEW_HELPERS, 'controller pulse helpers'),
    ('resources/views/filament/pages/platform-dashboard.blade.php', OLD_VIEW, NEW_VIEW, 'view hero markup'),
    ('resources/views/filament/pages/platform-dashboard.blade.php', OLD_CSS,  NEW_CSS,  'view hero css'),
]


def main():
    ap = argparse.ArgumentParser(); ap.add_argument('root'); ap.add_argument('--apply', action='store_true')
    a = ap.parse_args()
    root = pathlib.Path(a.root)
    for rel, old, new, label in EDITS:
        p = root / rel
        t = p.read_text()
        # Simple idempotence rule: if the old string isn't in the file, assume already applied.
        # If the old string IS in the file, apply the replacement. Avoid clever marker checks
        # that mis-classify in cases where a single file has multiple edits with shared markers.
        if old not in t:
            print(f'already_applied: {label}'); continue
        if t.count(old) > 1:
            print(f'ERROR: anchor not unique for {label} ({t.count(old)} matches)', file=sys.stderr); sys.exit(2)
        if a.apply:
            p.write_text(t.replace(old, new, 1))
        print(f'{"applied" if a.apply else "would_apply"}: {label}')


if __name__ == '__main__':
    main()
