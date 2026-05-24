<?php
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
