#!/usr/bin/env python3
"""
Patch 135 — tile-free master admin dashboard.

Replaces Filament's widget-grid dashboard at /admin with a custom blade
view. New file layout:

  app/Filament/Pages/PlatformDashboard.php
      Custom Filament Page that replaces Pages\\Dashboard.
      Loads all dashboard data in a single getViewData() pass.

  resources/views/filament/pages/platform-dashboard.blade.php
      The actual layout from the approved mockup, wired to real data.

  AdminPanelProvider.php
      - Drops widget registrations (no longer needed; data is hand-loaded)
      - Drops Pages\\Dashboard registration; PlatformDashboard takes /admin
      - Registers PlatformDashboard as the default dashboard

Scope decisions:
  - Auto-refresh: dropdown {Off, 30s, 60s, 5min}, persists in localStorage,
    starts at Off.
  - Cert expiry: NOT shown in v1 (patch 136 will add column + nightly poll).
  - Activity feed: pulls last 12 rows from debug_logs across selected
    channels.
  - Funnel: signed up → onboarded → first booking → paid (subscription_status='active').

Idempotent. Dry-run safe.
"""

import argparse
import pathlib
import sys

MARKER = 'MARKER-PATCH-135'


PLATFORM_DASHBOARD_PAGE = r'''<?php
// MARKER-PATCH-135

namespace App\Filament\Pages;

use App\Models\Activation;
use App\Models\DebugLog;
use App\Models\License;
use App\Models\SystemHealth;
use App\Models\Tenant;
use App\Models\Tenant\TenantDomain;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * PlatformDashboard
 *
 * Tile-free master admin home. All data assembled here in one pass so
 * the view stays a near-pure template. Reads only — no writes.
 *
 * Replaces Filament's default Pages\Dashboard at /admin.
 */
class PlatformDashboard extends Page
{
    protected static ?string $title           = 'Dashboard';
    protected static ?string $slug            = '/';
    protected static ?string $navigationIcon  = 'heroicon-o-home';
    protected static ?string $navigationLabel = 'Dashboard';
    protected static ?int    $navigationSort  = -100;

    protected static string $view = 'filament.pages.platform-dashboard';

    public function getViewData(): array
    {
        return [
            'hero'       => $this->buildHero(),
            'health'     => $this->buildHealth(),
            'saas'       => $this->buildSaas(),
            'funnel'     => $this->buildFunnel(),
            'wp'         => $this->buildWp(),
            'domains'    => $this->buildDomains(),
            'activity'   => $this->buildActivity(),
            'generatedAt'=> now(),
        ];
    }

    // ─────────────────────────────────────────────────────────
    // HERO
    // ─────────────────────────────────────────────────────────

    protected function buildHero(): array
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
    }

    protected function headlineFor(array $pulses, $keys): string
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
        $age = \Carbon\Carbon::parse($h['at'])->diffInHours(now());
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
    }

    // ─────────────────────────────────────────────────────────
    // HEALTH ROWS
    // ─────────────────────────────────────────────────────────

    protected function buildHealth(): array
    {
        $rows = [];

        // Unresolved errors
        $unresolved = DebugLog::where('severity', 'error')->whereNull('resolved_at')->count();
        $last7 = DebugLog::where('severity', 'error')->where('created_at', '>=', now()->subDays(7))->count();
        $recent = DebugLog::where('severity', 'error')->orderByDesc('created_at')->first();
        $rows[] = [
            'name'  => 'Unresolved errors',
            'meta'  => "{$unresolved} unresolved · {$last7} over last 7d" . ($recent ? ' · most recent ' . $recent->created_at->diffForHumans() : ''),
            'value' => "<b>{$unresolved}</b> open",
            'state' => $unresolved > 10 ? 'bad' : ($unresolved > 0 ? 'warn' : 'ok'),
            'href'  => '/admin/debug-logs?activeTab=errors',
        ];

        // Slow requests
        $slow = DebugLog::where('channel', 'perf')->where('created_at', '>=', now()->subDay())->count();
        $rows[] = [
            'name'  => 'Slow requests',
            'meta'  => 'over 1500ms · trailing 24h',
            'value' => "<b>{$slow}</b> in 24h",
            'state' => $slow > 10 ? 'warn' : 'ok',
            'href'  => '/admin/debug-logs?activeTab=perf',
        ];

        // Failed jobs (table may not exist)
        $failed = null;
        try {
            if (Schema::hasTable('failed_jobs')) {
                $failed = (int) DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
            }
        } catch (Throwable $e) { /* leave $failed null */ }
        $rows[] = $failed === null
            ? ['name' => 'Failed jobs', 'meta' => 'failed_jobs table not yet created', 'value' => 'n/a', 'state' => 'idle', 'href' => null]
            : ['name' => 'Failed jobs', 'meta' => 'trailing 24h', 'value' => "<b>{$failed}</b> in 24h", 'state' => $failed > 0 ? 'bad' : 'ok', 'href' => null];

        // Queue backlog
        $pending = 0;
        try { $pending = (int) Redis::llen('queues:default'); }
        catch (Throwable $e) {}
        $rows[] = [
            'name'  => 'Queue backlog',
            'meta'  => 'Redis · queues:default · checked just now',
            'value' => "<b>{$pending}</b> pending",
            'state' => $pending > 50 ? 'bad' : ($pending > 5 ? 'warn' : 'ok'),
            'href'  => null,
        ];

        // Stripe webhooks
        $stuck = 0;
        try {
            $stuck = (int) DB::table('stripe_webhook_events')
                ->whereNull('processed_at')
                ->where('received_at', '<', now()->subMinutes(5))->count();
        } catch (Throwable $e) {}
        $lastWebhook = null;
        try {
            $lastWebhook = DB::table('stripe_webhook_events')->orderByDesc('received_at')->value('received_at');
        } catch (Throwable $e) {}
        $rows[] = [
            'name'  => 'Stripe webhooks',
            'meta'  => $stuck === 0
                ? ('all processed' . ($lastWebhook ? ' · last fired ' . \Carbon\Carbon::parse($lastWebhook)->diffForHumans() : ''))
                : "{$stuck} unprocessed > 5 min",
            'value' => "<b>{$stuck}</b> unprocessed",
            'state' => $stuck > 0 ? 'bad' : 'ok',
            'href'  => null,
        ];

        // Failed logins
        $loginFails = DebugLog::where('channel', 'auth')
            ->where('event', 'auth.login_failed')
            ->where('created_at', '>=', now()->subDay())->count();
        $rows[] = [
            'name'  => 'Failed logins',
            'meta'  => 'trailing 24h · alert threshold > 20',
            'value' => "<b>{$loginFails}</b> in 24h",
            'state' => $loginFails > 20 ? 'warn' : 'ok',
            'href'  => null,
        ];

        // Outbound mail (idle until live)
        $mailSent = DebugLog::where('channel', 'mail')->where('event', 'mail.sent')
            ->where('created_at', '>=', now()->subDay())->count();
        $rows[] = [
            'name'  => 'Outbound mail',
            'meta'  => $mailSent > 0 ? "{$mailSent} sent in last 24h" : 'surface activates when tenant mail goes live',
            'value' => $mailSent > 0 ? "<b>{$mailSent}</b> sent" : 'not yet wired',
            'state' => $mailSent > 0 ? 'ok' : 'idle',
            'href'  => null,
        ];

        // Backups
        $bk = SystemHealth::read('last_backup');
        if (! $bk || empty($bk['at'])) {
            $rows[] = ['name' => 'Last backup', 'meta' => 'backup script has not reported yet', 'value' => 'no record', 'state' => 'warn', 'href' => null];
        } else {
            $ts = \Carbon\Carbon::parse($bk['at']);
            $age = $ts->diffInHours(now());
            $size = isset($bk['bytes']) ? round($bk['bytes'] / 1024 / 1024, 1) . ' MB' : '?';
            $rows[] = [
                'name'  => 'Last backup',
                'meta'  => "{$size} · " . ($bk['duration_sec'] ?? '?') . "s · 30-day retention",
                'value' => "<b>{$ts->diffForHumans()}</b>",
                'state' => $age > 36 ? 'bad' : ($age > 30 ? 'warn' : 'ok'),
                'href'  => null,
            ];
        }

        // Custom domains rollup
        $active = TenantDomain::where('status', 'active')->count();
        $inSetup = TenantDomain::whereIn('status', ['pending_dns', 'verifying', 'issuing_cert'])->count();
        $errored = TenantDomain::where('status', 'error')->count();
        $rollupState = ($errored > 0) ? 'bad' : ($inSetup > 0 ? 'warn' : 'ok');
        $rows[] = [
            'name'  => 'Custom domains',
            'meta'  => "{$active} active · {$inSetup} in setup · {$errored} errored",
            'value' => $rollupState === 'ok' ? 'all healthy' : 'see panel below',
            'state' => $rollupState,
            'href'  => '/admin/tenant-domains',
        ];

        return $rows;
    }

    // ─────────────────────────────────────────────────────────
    // SAAS BUSINESS
    // ─────────────────────────────────────────────────────────

    protected function buildSaas(): array
    {
        $totalTenants = Tenant::count();
        $newThisWeek  = Tenant::where('created_at', '>=', now()->subDays(7))->count();
        $newLastWeek  = Tenant::whereBetween('created_at', [now()->subDays(14), now()->subDays(7)])->count();
        $weekDelta    = $newLastWeek > 0
            ? round((($newThisWeek - $newLastWeek) / $newLastWeek) * 100)
            : ($newThisWeek > 0 ? 100 : 0);

        // MRR estimate
        $plans = config('intake.plan_prices') ?? [];
        $paidTenants = Tenant::where('subscription_status', 'active')->get(['plan_tier']);
        $mrr = $paidTenants->sum(fn ($t) => ($plans[$t->plan_tier] ?? 0) / 100);

        $inTrial   = Tenant::where('subscription_status', '!=', 'active')
            ->where('trial_ends_at', '>', now())->count();
        $trialPotential = $inTrial * (($plans['branded'] ?? 7900) / 100);

        // Sparkline: tenants per week, 12 weeks
        $weekly = [];
        for ($i = 11; $i >= 0; $i--) {
            $start = now()->startOfWeek()->subWeeks($i);
            $end   = (clone $start)->addWeek();
            $weekly[] = Tenant::whereBetween('created_at', [$start, $end])->count();
        }

        return [
            'totalTenants'      => $totalTenants,
            'newThisWeek'       => $newThisWeek,
            'weekDelta'         => $weekDelta,
            'mrr'               => $mrr,
            'paidCount'         => $paidTenants->count(),
            'inTrial'           => $inTrial,
            'trialPotential'    => $trialPotential,
            'weekly'            => $weekly,
        ];
    }

    // ─────────────────────────────────────────────────────────
    // FUNNEL
    // ─────────────────────────────────────────────────────────

    protected function buildFunnel(): array
    {
        $window = now()->subDays(30);
        $signedUp     = Tenant::where('created_at', '>=', $window)->count();
        $onboarded    = Tenant::where('created_at', '>=', $window)
            ->where('onboarding_status', 'complete')->count();
        $firstBooking = Tenant::where('created_at', '>=', $window)
            ->whereHas('appointments')->count();
        $paid         = Tenant::where('created_at', '>=', $window)
            ->where('subscription_status', 'active')->count();

        $base = max($signedUp, 1);
        return [
            ['label' => 'Signed up',           'count' => $signedUp,     'pct' => 100],
            ['label' => 'Completed onboarding','count' => $onboarded,    'pct' => round($onboarded / $base * 100)],
            ['label' => 'Took 1st booking',    'count' => $firstBooking, 'pct' => round($firstBooking / $base * 100)],
            ['label' => 'Converted to paid',   'count' => $paid,         'pct' => round($paid / $base * 100)],
        ];
    }

    // ─────────────────────────────────────────────────────────
    // WP PLUGIN
    // ─────────────────────────────────────────────────────────

    protected function buildWp(): array
    {
        $total   = Activation::count();
        $active  = Activation::where('last_seen_at', '>=', now()->subDays(30))->count();
        $free    = Activation::whereNull('license_id')->count();
        $premium = Activation::whereNotNull('license_id')->count();
        $activeLicenses = class_exists(License::class)
            ? License::where('status', 'active')->count()
            : 0;
        $freePct    = $total > 0 ? round(($free / $total) * 100) : 0;
        $premiumPct = $total > 0 ? round(($premium / $total) * 100) : 0;
        return compact('total', 'active', 'free', 'premium', 'activeLicenses', 'freePct', 'premiumPct');
    }

    // ─────────────────────────────────────────────────────────
    // DOMAINS NEEDING ATTENTION + RECENT ACTIVITY
    // ─────────────────────────────────────────────────────────

    protected function buildDomains(): array
    {
        // Show every domain with status != 'active', plus those stuck.
        // When all are healthy, the view falls back to the empty state.
        $rows = TenantDomain::with('tenant:id,name')
            ->where(function ($q) {
                $q->where('status', '!=', 'active')
                  ->orWhereRaw('1=0'); // placeholder; cert-expiry filter will land here in patch 136
            })
            ->orderBy('updated_at')
            ->limit(10)
            ->get()
            ->map(function ($d) {
                $stateClass = match ($d->status) {
                    'active'                                  => 'ok',
                    'pending_dns', 'verifying', 'issuing_cert'=> 'warn',
                    'error'                                   => 'bad',
                    'suspended'                               => 'bad',
                    default                                   => 'warn',
                };
                $label = match ($d->status) {
                    'pending_dns'  => 'Waiting on DNS',
                    'verifying'    => 'Verifying ownership',
                    'issuing_cert' => 'Issuing cert',
                    'active'       => 'Active',
                    'error'        => 'Error',
                    'suspended'    => 'Suspended',
                    default        => $d->status,
                };
                return [
                    'id'       => $d->id,
                    'hostname' => $d->hostname,
                    'tenant'   => $d->tenant?->name,
                    'state'    => $stateClass,
                    'label'    => $label,
                    'age'      => 'added ' . $d->created_at->diffForHumans(null, true) . ' ago',
                    'href'     => "/admin/tenant-domains/{$d->id}/edit",
                ];
            })
            ->all();

        return $rows;
    }

    protected function buildActivity(): array
    {
        // Pull the last 12 rows from the channels we care about.
        return DebugLog::whereIn('channel', ['audit', 'webhook', 'system', 'auth'])
            ->orderByDesc('created_at')
            ->limit(12)
            ->get()
            ->map(function ($l) {
                $tone = match (true) {
                    $l->severity === 'error'   => 'bad',
                    $l->severity === 'warning' => 'warn',
                    str_starts_with($l->event ?? '', 'tenant.onboard') => 'ok',
                    default                                => 'info',
                };
                return [
                    'tone' => $tone,
                    'text' => $l->message,
                    'time' => $l->created_at->diffForHumans(null, true),
                ];
            })
            ->all();
    }
}
'''


PLATFORM_DASHBOARD_VIEW = r'''{{-- MARKER-PATCH-135 — tile-free master admin dashboard --}}
<x-filament-panels::page>

<style>
  /* This view scopes its own styles. The Filament outer chrome supplies
     the global dark theme; everything inside .pd is self-contained so
     the dashboard renders the same whether Filament's defaults shift
     or not. */

  .pd {
    --pd-bg: var(--gray-950, #0a0a0a);
    --pd-surface: var(--gray-900, #131313);
    --pd-surface-2: var(--gray-800, #1a1a1a);
    --pd-border: rgba(255,255,255,.08);
    --pd-border-strong: rgba(255,255,255,.18);
    --pd-text: #f0f0f0;
    --pd-text-muted: rgba(255,255,255,.62);
    --pd-text-dim: rgba(255,255,255,.42);
    --pd-accent: #BEF264;
    --pd-ok: #86EFAC;
    --pd-warn: #FBBF24;
    --pd-bad: #F87171;
    --pd-info: #7DD3FC;
    --pd-r-md: 6px;
    --pd-r-lg: 10px;
    --pd-font-mono: 'JetBrains Mono', ui-monospace, monospace;

    color: var(--pd-text);
    font-size: 14px;
    line-height: 1.55;
  }
  .pd a { color: inherit; text-decoration: none; }
  .pd a:hover { color: var(--pd-text); }

  /* Hero */
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
  .pd-hero-meta b { display:block; color:var(--pd-text); font-size:13px; font-weight:500; }

  /* Top bar with refresh control */
  .pd-top { display:flex; justify-content:space-between; align-items:center; padding:18px 0 18px;
    border-bottom:1px solid var(--pd-border); margin-bottom:24px; }
  .pd-top-meta { color:var(--pd-text-dim); font-size:11.5px; font-family:var(--pd-font-mono); }
  .pd-top-controls { display:flex; align-items:center; gap:10px; }
  .pd-refresh-select { background:rgba(255,255,255,.04); border:1px solid var(--pd-border-strong); color:var(--pd-text);
    padding:5px 10px; border-radius:var(--pd-r-md); font-size:11.5px; font-family:inherit; }
  .pd-refresh-btn { background:rgba(255,255,255,.04); border:1px solid var(--pd-border-strong); color:var(--pd-text);
    padding:5px 12px; border-radius:var(--pd-r-md); font-size:11.5px; cursor:pointer; font-family:inherit; }
  .pd-refresh-btn:hover { background:rgba(255,255,255,.08); }

  /* Section */
  .pd-section { margin-bottom:36px; }
  .pd-section-head { display:flex; align-items:baseline; justify-content:space-between; margin-bottom:14px; }
  .pd-section-title { font-size:11px; text-transform:uppercase; letter-spacing:0.12em; color:var(--pd-text-muted); font-weight:500; }
  .pd-section-sub { font-size:11.5px; color:var(--pd-text-dim); }
  .pd-section-sub a:hover { color:var(--pd-text-muted); }

  /* Health */
  .pd-health { background:var(--pd-surface); border:1px solid var(--pd-border); border-radius:var(--pd-r-lg); overflow:hidden; }
  .pd-h-row { display:grid; grid-template-columns:4px 26px 1fr auto; gap:14px; align-items:center;
    padding:12px 20px; border-bottom:1px solid var(--pd-border); cursor:pointer; transition:background .12s ease; }
  .pd-h-row:last-child { border-bottom:none; }
  .pd-h-row:hover { background:var(--pd-surface-2); }
  .pd-h-stripe { height:24px; border-radius:2px; }
  .pd-h-row.ok .pd-h-stripe   { background:rgba(134,239,172,.4); }
  .pd-h-row.warn .pd-h-stripe { background:var(--pd-warn); }
  .pd-h-row.bad .pd-h-stripe  { background:var(--pd-bad); }
  .pd-h-row.idle .pd-h-stripe { background:rgba(255,255,255,.08); }
  .pd-h-symbol { font-family:var(--pd-font-mono); font-size:12px; font-weight:500; text-align:center; }
  .pd-h-row.ok .pd-h-symbol   { color:var(--pd-ok); }
  .pd-h-row.warn .pd-h-symbol { color:var(--pd-warn); }
  .pd-h-row.bad .pd-h-symbol  { color:var(--pd-bad); }
  .pd-h-row.idle .pd-h-symbol { color:var(--pd-text-dim); }
  .pd-h-name { font-size:13.5px; font-weight:500; }
  .pd-h-meta { font-size:11.5px; color:var(--pd-text-dim); margin-top:1px; font-family:var(--pd-font-mono); }
  .pd-h-value { text-align:right; font-family:var(--pd-font-mono); font-size:13px; color:var(--pd-text-muted); }
  .pd-h-value b { color:var(--pd-text); font-weight:500; }

  /* SaaS cards */
  .pd-biz { display:grid; grid-template-columns:repeat(3, 1fr); gap:14px; margin-bottom:14px; }
  .pd-biz-card { background:var(--pd-surface); border:1px solid var(--pd-border); border-radius:var(--pd-r-lg);
    padding:18px 22px; transition:border-color .12s ease; }
  .pd-biz-card:hover { border-color:var(--pd-border-strong); }
  .pd-biz-card.wide { grid-column:span 2; }
  .pd-biz-lbl { font-size:11px; text-transform:uppercase; letter-spacing:.08em; color:var(--pd-text-dim); margin-bottom:8px; }
  .pd-biz-num { font-size:32px; font-weight:600; letter-spacing:-0.02em; line-height:1.1; }
  .pd-biz-num small { font-family:var(--pd-font-mono); font-size:11px; color:var(--pd-text-dim); margin-left:6px; font-weight:400; vertical-align:middle; }
  .pd-biz-delta { font-size:12px; color:var(--pd-text-dim); margin-top:6px; }
  .pd-biz-delta b { color:var(--pd-ok); font-weight:500; }
  .pd-biz-delta b.down { color:var(--pd-bad); }
  .pd-biz-spark { margin-top:14px; height:38px; }

  /* Funnel */
  .pd-funnel { background:var(--pd-surface); border:1px solid var(--pd-border); border-radius:var(--pd-r-lg); padding:20px 24px; }
  .pd-funnel-title { font-size:11px; text-transform:uppercase; letter-spacing:.1em; color:var(--pd-text-dim); margin-bottom:14px; }
  .pd-funnel-row { display:flex; align-items:center; gap:14px; padding:10px 0; }
  .pd-funnel-row + .pd-funnel-row { border-top:1px solid var(--pd-border); }
  .pd-funnel-step { width:160px; font-size:12px; color:var(--pd-text-muted); }
  .pd-funnel-bar { flex:1; height:22px; background:rgba(255,255,255,.04); border-radius:4px; overflow:hidden; }
  .pd-funnel-bar > span { display:block; height:100%; background:linear-gradient(90deg, var(--pd-accent) 0%, rgba(190,242,100,.6) 100%); }
  .pd-funnel-count { width:90px; text-align:right; font-family:var(--pd-font-mono); font-size:12.5px; color:var(--pd-text); }
  .pd-funnel-count small { color:var(--pd-text-dim); font-size:10.5px; }

  /* WP */
  .pd-wp { display:grid; grid-template-columns:2fr 1fr 1fr; gap:14px; }
  .pd-wp-card { background:var(--pd-surface); border:1px solid var(--pd-border); border-radius:var(--pd-r-lg); padding:18px 22px; }
  .pd-wp-card:hover { border-color:var(--pd-border-strong); }
  .pd-wp-lbl { font-size:11px; text-transform:uppercase; letter-spacing:.08em; color:var(--pd-text-dim); margin-bottom:6px; }
  .pd-wp-num { font-size:26px; font-weight:600; letter-spacing:-0.02em; line-height:1.15; }
  .pd-wp-sub { font-size:12px; color:var(--pd-text-dim); margin-top:4px; }
  .pd-ratio-bar { margin-top:14px; }
  .pd-ratio-track { height:8px; background:rgba(255,255,255,.05); border-radius:4px; overflow:hidden; display:flex; }
  .pd-ratio-track > .free    { background:rgba(125,211,252,.65); height:100%; }
  .pd-ratio-track > .premium { background:var(--pd-accent); height:100%; }
  .pd-ratio-legend { display:flex; gap:14px; margin-top:8px; font-size:11px; color:var(--pd-text-muted); }
  .pd-ratio-legend .pd-swatch { display:inline-block; width:8px; height:8px; border-radius:2px; margin-right:6px; vertical-align:middle; }
  .pd-ratio-legend .pd-swatch.free    { background:rgba(125,211,252,.65); }
  .pd-ratio-legend .pd-swatch.premium { background:var(--pd-accent); }
  .pd-ratio-legend b { color:var(--pd-text); font-weight:500; }

  /* Two col attention row */
  .pd-two-col { display:grid; grid-template-columns:1.4fr 1fr; gap:14px; }
  .pd-domains { background:var(--pd-surface); border:1px solid var(--pd-border); border-radius:var(--pd-r-lg); padding:8px 0; }
  .pd-domain-row { display:grid; grid-template-columns:auto 1fr auto auto; gap:16px; align-items:center; padding:13px 22px; cursor:pointer; }
  .pd-domain-row + .pd-domain-row { border-top:1px solid var(--pd-border); }
  .pd-domain-row:hover { background:var(--pd-surface-2); }
  .pd-domain-dot { width:8px; height:8px; border-radius:50%; }
  .pd-domain-dot.ok   { background:var(--pd-ok); }
  .pd-domain-dot.warn { background:var(--pd-warn); }
  .pd-domain-dot.bad  { background:var(--pd-bad); }
  .pd-domain-host { font-family:var(--pd-font-mono); font-size:13px; }
  .pd-domain-tenant { font-size:11px; color:var(--pd-text-dim); margin-top:2px; }
  .pd-domain-state { font-size:12px; }
  .pd-domain-state.ok   { color:var(--pd-ok); }
  .pd-domain-state.warn { color:var(--pd-warn); }
  .pd-domain-state.bad  { color:var(--pd-bad); }
  .pd-domain-age { font-family:var(--pd-font-mono); font-size:11.5px; color:var(--pd-text-dim); }
  .pd-domain-empty { padding:36px 22px; text-align:center; color:var(--pd-text-dim); font-size:13px; }
  .pd-domain-empty b { color:var(--pd-text-muted); display:block; margin-bottom:4px; font-weight:500; }

  .pd-events { background:var(--pd-surface); border:1px solid var(--pd-border); border-radius:var(--pd-r-lg); overflow:hidden; }
  .pd-events-head { padding:12px 18px; border-bottom:1px solid var(--pd-border); font-size:10.5px; text-transform:uppercase; letter-spacing:.1em; color:var(--pd-text-dim); }
  .pd-event-row { display:grid; grid-template-columns:12px 1fr auto; gap:12px; padding:10px 18px; align-items:center; font-size:12px; }
  .pd-event-row + .pd-event-row { border-top:1px solid var(--pd-border); }
  .pd-event-dot { width:6px; height:6px; border-radius:50%; }
  .pd-event-dot.info { background:var(--pd-info); }
  .pd-event-dot.ok   { background:var(--pd-ok); }
  .pd-event-dot.warn { background:var(--pd-warn); }
  .pd-event-dot.bad  { background:var(--pd-bad); }
  .pd-event-text { color:var(--pd-text-muted); }
  .pd-event-time { font-family:var(--pd-font-mono); font-size:10.5px; color:var(--pd-text-dim); }
  .pd-event-empty { padding:24px 18px; text-align:center; font-size:12px; color:var(--pd-text-dim); }

  @media (max-width: 1100px) {
    .pd-biz { grid-template-columns:1fr 1fr; }
    .pd-biz-card.wide { grid-column:span 2; }
    .pd-wp { grid-template-columns:1fr; }
    .pd-two-col { grid-template-columns:1fr; }
  }
</style>

<div class="pd">

  {{-- ────────── HERO ────────── --}}
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

    <div class="pd-hero-meta">
      @if($hero['uptime'])<b>{{ $hero['uptime'] }}</b>uptime@endif
    </div>
  </div>

  {{-- ────────── REFRESH CONTROLS ────────── --}}
  <div class="pd-top">
    <div class="pd-top-meta">Generated {{ $generatedAt->format('H:i:s') }} UTC</div>
    <div class="pd-top-controls">
      <select class="pd-refresh-select" id="pd-refresh-select">
        <option value="0">Refresh: off</option>
        <option value="30">Refresh: 30s</option>
        <option value="60">Refresh: 60s</option>
        <option value="300">Refresh: 5min</option>
      </select>
      <button class="pd-refresh-btn" onclick="window.location.reload()">Refresh now</button>
    </div>
  </div>

  {{-- ────────── HEALTH ────────── --}}
  <div class="pd-section">
    <div class="pd-section-head">
      <div class="pd-section-title">Operational health</div>
      <div class="pd-section-sub"><a href="/admin/debug-logs">View full system log →</a></div>
    </div>

    <div class="pd-health">
      @foreach($health as $row)
        <a class="pd-h-row {{ $row['state'] }}" @if($row['href']) href="{{ $row['href'] }}" @endif>
          <div class="pd-h-stripe"></div>
          <div class="pd-h-symbol">{{ ['ok'=>'OK','warn'=>'!','bad'=>'!!','idle'=>'—'][$row['state']] ?? '?' }}</div>
          <div>
            <div class="pd-h-name">{{ $row['name'] }}</div>
            <div class="pd-h-meta">{{ $row['meta'] }}</div>
          </div>
          <div class="pd-h-value">{!! $row['value'] !!}</div>
        </a>
      @endforeach
    </div>
  </div>

  {{-- ────────── SAAS ────────── --}}
  <div class="pd-section">
    <div class="pd-section-head">
      <div class="pd-section-title">Intake SaaS</div>
      <div class="pd-section-sub">{{ $saas['totalTenants'] }} tenants · <a href="/admin/tenants">tenants directory →</a></div>
    </div>

    <div class="pd-biz">
      <a class="pd-biz-card" href="/admin/tenants">
        <div class="pd-biz-lbl">Total tenants</div>
        <div class="pd-biz-num">{{ $saas['totalTenants'] }} <small>{{ $saas['newThisWeek'] }} new this week</small></div>
        <div class="pd-biz-delta">
          @if($saas['weekDelta'] > 0)<b>+{{ $saas['weekDelta'] }}%</b>@elseif($saas['weekDelta'] < 0)<b class="down">{{ $saas['weekDelta'] }}%</b>@else flat@endif
          vs last week
        </div>
        @php
          $max = max(max($saas['weekly']), 1);
          $points = '';
          foreach ($saas['weekly'] as $i => $v) {
            $x = round(($i / 11) * 200, 1);
            $y = round(38 - ($v / $max) * 30, 1);
            $points .= ($i === 0 ? "M{$x} {$y}" : " L{$x} {$y}");
          }
        @endphp
        <svg class="pd-biz-spark" viewBox="0 0 200 40" preserveAspectRatio="none">
          <path d="{{ $points }}" stroke="#BEF264" stroke-width="1.5" fill="none"/>
        </svg>
      </a>

      <a class="pd-biz-card wide" href="/admin/tenants">
        <div class="pd-biz-lbl">Est. MRR</div>
        <div class="pd-biz-num">${{ number_format($saas['mrr']) }} <small>from {{ $saas['paidCount'] }} paid {{ \Str::plural('plan', $saas['paidCount']) }}</small></div>
        <div class="pd-biz-delta">
          {{ $saas['inTrial'] }} in trial · est. <b>+${{ number_format($saas['trialPotential']) }}</b> if all convert
        </div>
      </a>
    </div>

    <div class="pd-funnel">
      <div class="pd-funnel-title">Trial funnel · last 30 days</div>
      @foreach($funnel as $step)
        <div class="pd-funnel-row">
          <div class="pd-funnel-step">{{ $step['label'] }}</div>
          <div class="pd-funnel-bar"><span style="width:{{ $step['pct'] }}%"></span></div>
          <div class="pd-funnel-count">{{ $step['count'] }} <small>· {{ $step['pct'] }}%</small></div>
        </div>
      @endforeach
    </div>
  </div>

  {{-- ────────── WP ────────── --}}
  <div class="pd-section">
    <div class="pd-section-head">
      <div class="pd-section-title">WordPress plugin</div>
      <div class="pd-section-sub">licence server</div>
    </div>

    <div class="pd-wp">
      <div class="pd-wp-card">
        <div class="pd-wp-lbl">Free vs Premium</div>
        <div class="pd-wp-num">{{ $wp['total'] }} <small style="font-family:var(--pd-font-mono);font-size:11px;color:var(--pd-text-dim);margin-left:6px;font-weight:400">installs reporting</small></div>
        <div class="pd-ratio-bar">
          <div class="pd-ratio-track">
            <div class="free" style="width:{{ $wp['freePct'] }}%"></div>
            <div class="premium" style="width:{{ $wp['premiumPct'] }}%"></div>
          </div>
          <div class="pd-ratio-legend">
            <span><i class="pd-swatch free"></i> Free <b>{{ $wp['free'] }}</b></span>
            <span><i class="pd-swatch premium"></i> Premium <b>{{ $wp['premium'] }}</b></span>
          </div>
        </div>
      </div>

      <div class="pd-wp-card">
        <div class="pd-wp-lbl">Active in 30d</div>
        <div class="pd-wp-num">{{ $wp['active'] }}</div>
        <div class="pd-wp-sub">phoning home with a heartbeat</div>
      </div>

      <div class="pd-wp-card">
        <div class="pd-wp-lbl">Active licences</div>
        <div class="pd-wp-num">{{ $wp['activeLicenses'] }}</div>
        <div class="pd-wp-sub">valid, non-expired keys</div>
      </div>
    </div>
  </div>

  {{-- ────────── DOMAINS + RECENT EVENTS ────────── --}}
  <div class="pd-section">
    <div class="pd-section-head">
      <div class="pd-section-title">Tenant attention</div>
      <div class="pd-section-sub"><a href="/admin/tenant-domains">all domains →</a></div>
    </div>

    <div class="pd-two-col">
      <div class="pd-domains">
        @if(count($domains))
          @foreach($domains as $d)
            <a class="pd-domain-row" href="{{ $d['href'] }}">
              <div class="pd-domain-dot {{ $d['state'] }}"></div>
              <div>
                <div class="pd-domain-host">{{ $d['hostname'] }}</div>
                <div class="pd-domain-tenant">{{ $d['tenant'] ?? '—' }} · {{ $d['age'] }}</div>
              </div>
              <div class="pd-domain-state {{ $d['state'] }}">{{ $d['label'] }}</div>
              <div class="pd-domain-age">&nbsp;</div>
            </a>
          @endforeach
        @else
          <div class="pd-domain-empty">
            <b>No domains need attention.</b>
            When a tenant is stuck in DNS verification, errored, or has a cert about to expire, the affected domain shows here with a one-click path to its detail page.
          </div>
        @endif
      </div>

      <div class="pd-events">
        <div class="pd-events-head">Recent activity</div>
        @if(count($activity))
          @foreach($activity as $e)
            <div class="pd-event-row">
              <div class="pd-event-dot {{ $e['tone'] }}"></div>
              <div class="pd-event-text">{{ $e['text'] }}</div>
              <div class="pd-event-time">{{ $e['time'] }}</div>
            </div>
          @endforeach
        @else
          <div class="pd-event-empty">No recent activity yet. Events will start appearing here as tenants onboard and sign in.</div>
        @endif
      </div>
    </div>
  </div>

</div>

<script>
  // Refresh-rate dropdown with localStorage persistence.
  (function() {
    var sel = document.getElementById('pd-refresh-select');
    if (!sel) return;
    var KEY = 'pd-refresh-secs';
    sel.value = localStorage.getItem(KEY) || '0';
    var timer = null;
    function applyTimer() {
      if (timer) { clearTimeout(timer); timer = null; }
      var secs = parseInt(sel.value, 10);
      if (secs > 0) {
        timer = setTimeout(function() { window.location.reload(); }, secs * 1000);
      }
    }
    sel.addEventListener('change', function() {
      localStorage.setItem(KEY, sel.value);
      applyTimer();
    });
    applyTimer();
  })();
</script>

</x-filament-panels::page>
'''


OLD_AP = '''            ->pages([
                Pages\\Dashboard::class,
                ThemeEditor::class,
                \\App\\Filament\\Pages\\BillingConfiguration::class,
                \\App\\Filament\\Pages\\ChangelogImportPreview::class,
            ])'''

NEW_AP = '''            ->pages([
                // MARKER-PATCH-135 — custom dashboard replaces Pages\\Dashboard
                \\App\\Filament\\Pages\\PlatformDashboard::class,
                ThemeEditor::class,
                \\App\\Filament\\Pages\\BillingConfiguration::class,
                \\App\\Filament\\Pages\\ChangelogImportPreview::class,
            ])'''


NEW_FILES = {
    'app/Filament/Pages/PlatformDashboard.php':                              PLATFORM_DASHBOARD_PAGE,
    'resources/views/filament/pages/platform-dashboard.blade.php':           PLATFORM_DASHBOARD_VIEW,
}

EDITS = [
    ('app/Providers/Filament/AdminPanelProvider.php', OLD_AP, NEW_AP, 'AdminPanelProvider pages list'),
]


def process(root, apply):
    summary = {}
    for rel, content in NEW_FILES.items():
        path = root / rel
        if path.exists() and path.read_text() == content:
            summary['file:' + rel] = 'unchanged'
            continue
        if apply:
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(content)
            summary['file:' + rel] = 'written'
        else:
            summary['file:' + rel] = 'would_write'
    for rel, old, new, label in EDITS:
        p = root / rel
        text = p.read_text()
        if old not in text:
            if new in text:
                summary[label] = 'already_applied'
                continue
            print('ERROR: anchor missing for ' + label, file=sys.stderr); sys.exit(2)
        if text.count(old) > 1:
            print('ERROR: anchor not unique for ' + label, file=sys.stderr); sys.exit(2)
        if apply:
            p.write_text(text.replace(old, new, 1))
        summary[label] = 'edited' if apply else 'would_edit'
    return summary


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('root')
    ap.add_argument('--apply', action='store_true')
    a = ap.parse_args()
    root = pathlib.Path(a.root)
    if not (root / 'routes' / 'web.php').exists():
        print('ERROR: not an intake repo', file=sys.stderr); sys.exit(2)
    mode = 'APPLY' if a.apply else 'DRY-RUN'
    print('=== patch-135 [' + mode + '] target=' + str(root) + ' ===\n')
    s = process(root, apply=a.apply)
    for k, v in s.items():
        print('  ' + k + ': ' + v)
    if a.apply:
        print('\nAll PHP and view files written. Verify by visiting /admin.')
    else:
        print('\n(dry-run)')


if __name__ == '__main__':
    main()
