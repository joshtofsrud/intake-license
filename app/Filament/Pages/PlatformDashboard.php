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
    use \App\Support\UsesAdminNav; // MARKER-NAV-ORDER
    use \App\Support\GatedByAdminArea; // MARKER-ADMIN-NAV-GATE
    protected static string $adminArea = 'dashboard';

    protected static ?string $title           = 'Dashboard';
    protected static ?string $slug            = '/';
    protected static ?string $navigationIcon  = 'heroicon-o-home';
    protected static ?string $navigationLabel = 'Dashboard';
    protected static ?int    $navigationSort  = -100;

    protected static string $view = 'filament.pages.platform-dashboard';

    // MARKER-500-ALERT — dashboard switch for 5xx alert emails.
    public bool $alert500Enabled = false;
    public ?string $alert500Email = null;

    public function mount(): void
    {
        $ps = \App\Models\PlatformSettings::current();
        $this->alert500Enabled = (bool) ($ps->alert_500_enabled ?? false);
        $this->alert500Email   = $ps->alert_500_email ?? null;
    }

    public function saveAlert500(): void
    {
        $this->validate(['alert500Email' => ['nullable', 'email']]);
        $ps = \App\Models\PlatformSettings::current();
        $ps->alert_500_enabled = $this->alert500Enabled;
        $ps->alert_500_email   = $this->alert500Email ?: null;
        $ps->save();
        \App\Models\PlatformSettings::forget();
        \Filament\Notifications\Notification::make()
            ->title($this->alert500Enabled && $this->alert500Email
                ? '500 alerts on — sending to ' . $this->alert500Email
                : '500 alerts off')
            ->success()->send();
    }

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

    // MARKER-PATCH-141 — hero shows real numbers with units, sourced from ServerHealthService.
    protected function buildHero(): array
    {
        $snap = app(\App\Services\Admin\ServerHealthService::class)->snapshot();

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
        try { $pending = (int) \Illuminate\Support\Facades\Redis::llen('queues:default'); }
        catch (\Throwable $e) { $qOk = false; }
        $tiles['queue'] = $qOk
            ? ['label'=>'Queue', 'value'=>$pending.' pending',
               'meta'=>$pending === 0 ? 'idle' : ($pending > 50 ? 'backed up' : 'working'),
               'pct'=>min(100, $pending * 2),
               'state'=>$pending > 50 ? 'bad' : ($pending > 5 ? 'warn' : 'ok')]
            : ['label'=>'Queue','value'=>'n/a','meta'=>'redis unreachable','pct'=>0,'state'=>'bad'];

        // Backup
        $bk = \App\Models\SystemHealth::read('last_backup');
        if (! $bk || empty($bk['at'])) {
            $tiles['backup'] = ['label'=>'Backup', 'value'=>'no record',
                'meta'=>'script not yet wired', 'pct'=>0, 'state'=>'warn'];
        } else {
            $ts = \Carbon\Carbon::parse($bk['at']);
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
    }

    // MARKER-PATCH-141 — pulse helpers replaced; mapStatus + headlineWith live in buildHero block above.

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
            ? (int) round((($newThisWeek - $newLastWeek) / $newLastWeek) * 100)
            : ($newThisWeek > 0 ? 100 : 0);
        // MARKER-PATCH-136 — precompute trend so the view doesn't need inline @elseif comparisons.
        $weekTrend       = $weekDelta > 0 ? 'up' : ($weekDelta < 0 ? 'down' : 'flat');
        $weekDeltaLabel  = $weekDelta > 0 ? "+{$weekDelta}%" : ($weekDelta < 0 ? "{$weekDelta}%" : 'flat');

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

        // MARKER-RENTAL-EXT-P2 — platform-wide last-minute extension line.
        $extSince    = now()->subDays(30);
        $extSent     = \App\Models\Tenant\TenantRentalExtensionOffer::where('sent_at', '>=', $extSince)->count();
        $extAccepted = \App\Models\Tenant\TenantRentalExtensionOffer::where('status', 'paid')->where('sent_at', '>=', $extSince)->count();
        $extRevenue  = (int) \App\Models\Tenant\TenantRentalExtensionOffer::where('status', 'paid')->where('sent_at', '>=', $extSince)->sum('total_cents');
        $extTenants  = \App\Models\Tenant\TenantRentalExtensionOffer::where('sent_at', '>=', $extSince)->distinct('tenant_id')->count('tenant_id');

        return [
            'totalTenants'      => $totalTenants,
            'newThisWeek'       => $newThisWeek,
            'weekDelta'         => $weekDelta,
            'weekTrend'         => $weekTrend,        // MARKER-PATCH-136
            'weekDeltaLabel'    => $weekDeltaLabel,   // MARKER-PATCH-136
            'mrr'               => $mrr,
            'paidCount'         => $paidTenants->count(),
            'inTrial'           => $inTrial,
            'trialPotential'    => $trialPotential,
            'weekly'            => $weekly,
            'extSent'           => $extSent,     // MARKER-RENTAL-EXT-P2
            'extAccepted'       => $extAccepted,
            'extRevenue'        => $extRevenue,
            'extTenants'        => $extTenants,
        ];
    }

    // ─────────────────────────────────────────────────────────
    // FUNNEL
    // ─────────────────────────────────────────────────────────

    // MARKER-PATCH-140 — Signed-up row becomes a chart; downstream stays as bars.
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

        // Daily series for the chart: 30 days current + 30 days prior.
        $current = $this->dailySignups(now()->subDays(29)->startOfDay(), now()->endOfDay());
        $prior   = $this->dailySignups(now()->subDays(59)->startOfDay(), now()->subDays(30)->endOfDay());
        $priorTotal = array_sum($prior);
        $delta = $priorTotal > 0
            ? (int) round((($signedUp - $priorTotal) / $priorTotal) * 100)
            : ($signedUp > 0 ? 100 : 0);

        return [
            'signups' => [
                'current'    => $current,
                'prior'      => $prior,
                'total'      => $signedUp,
                'priorTotal' => $priorTotal,
                'delta'      => $delta,
            ],
            'stages' => [
                ['label' => 'Completed onboarding','count' => $onboarded,    'pct' => (int) round($onboarded / $base * 100)],
                ['label' => 'Took 1st booking',    'count' => $firstBooking, 'pct' => (int) round($firstBooking / $base * 100)],
                ['label' => 'Converted to paid',   'count' => $paid,         'pct' => (int) round($paid / $base * 100)],
            ],
        ];
    }

    /**
     * Return an array of integer signup counts, one per day, from $start to $end inclusive.
     */
    protected function dailySignups(\Carbon\Carbon $start, \Carbon\Carbon $end): array
    {
        $rows = Tenant::whereBetween('created_at', [$start, $end])
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
            ->groupBy('d')
            ->pluck('c', 'd');
        $out = [];
        for ($cur = $start->copy(); $cur <= $end; $cur->addDay()) {
            $out[] = (int) ($rows[$cur->toDateString()] ?? 0);
        }
        return $out;
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
