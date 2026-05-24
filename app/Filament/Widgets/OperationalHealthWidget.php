<?php
// MARKER-PATCH-132

namespace App\Filament\Widgets;

use App\Models\DebugLog;
use App\Models\SystemHealth;
use App\Models\Tenant\TenantDomain;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class OperationalHealthWidget extends BaseWidget
{
    protected static ?int $sort = 0;

    protected function getStats(): array
    {
        return [
            $this->unresolvedErrors(),
            $this->slowRequests(),
            $this->failedJobs(),
            $this->queueBacklog(),
            $this->stripeWebhookFailures(),
            $this->failedLogins(),
            $this->mailSent(),
            $this->backupStatus(),
            $this->domainsNeedingAttention(),
        ];
    }

    protected function unresolvedErrors(): Stat
    {
        $unresolved = DebugLog::where('severity', 'error')->whereNull('resolved_at')->count();
        $last7      = DebugLog::where('severity', 'error')->where('created_at', '>=', now()->subDays(7))->count();
        return Stat::make('Unresolved errors', number_format($unresolved))
            ->description($last7 . ' in last 7 days')
            ->color($unresolved > 0 ? 'danger' : 'success');
    }

    protected function slowRequests(): Stat
    {
        $count = DebugLog::where('channel', 'perf')->where('created_at', '>=', now()->subDay())->count();
        return Stat::make('Slow requests (24h)', number_format($count))
            ->description('over 1500ms')
            ->color($count > 10 ? 'warning' : ($count > 0 ? 'gray' : 'success'));
    }

    // MARKER-PATCH-133 — failed_jobs table is optional; guard for its absence.
    protected function failedJobs(): Stat
    {
        $count = 0;
        $tableMissing = false;
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('failed_jobs')) {
                $count = (int) DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
            } else {
                $tableMissing = true;
            }
        } catch (\Throwable $e) {
            $tableMissing = true;
        }
        if ($tableMissing) {
            return Stat::make('Failed jobs (24h)', 'n/a')
                ->description('failed_jobs table not created')
                ->color('gray');
        }
        return Stat::make('Failed jobs (24h)', number_format($count))
            ->description($count > 0 ? 'investigate' : 'clean')
            ->color($count > 0 ? 'danger' : 'success');
    }

    protected function queueBacklog(): Stat
    {
        $pending = 0;
        try {
            $pending = (int) Redis::llen('queues:default');
        } catch (Throwable $e) {
            // Redis unreachable; unresolvedErrors picks up the actual error.
        }
        $color = $pending > 50 ? 'danger' : ($pending > 5 ? 'warning' : 'success');
        return Stat::make('Queue backlog', number_format($pending))
            ->description($pending === 0 ? 'idle' : ($pending > 50 ? 'backed up' : 'working through it'))
            ->color($color);
    }

    protected function stripeWebhookFailures(): Stat
    {
        $count = (int) DB::table('stripe_webhook_events')
            ->whereNull('processed_at')
            ->where('created_at', '<', now()->subMinutes(5))
            ->count();
        return Stat::make('Stripe webhook failures', number_format($count))
            ->description($count > 0 ? 'unprocessed > 5 min' : 'all processed')
            ->color($count > 0 ? 'danger' : 'success');
    }

    protected function failedLogins(): Stat
    {
        $count = DebugLog::where('channel', 'auth')
            ->where('action', 'auth.login_failed')
            ->where('created_at', '>=', now()->subDay())
            ->count();
        return Stat::make('Failed logins (24h)', number_format($count))
            ->description($count > 20 ? 'investigate' : 'normal')
            ->color($count > 20 ? 'warning' : 'gray');
    }

    protected function mailSent(): Stat
    {
        $count = DebugLog::where('channel', 'mail')
            ->where('action', 'mail.sent')
            ->where('created_at', '>=', now()->subDay())
            ->count();
        return Stat::make('Mail sent (24h)', number_format($count))
            ->description('comes online once tenant mail is live')
            ->color('gray');
    }

    protected function backupStatus(): Stat
    {
        $h = SystemHealth::read('last_backup');
        if (! $h || empty($h['at'])) {
            return Stat::make('Backup status', 'no record')
                ->description('backup script has not reported yet')
                ->color('warning');
        }
        $ts = \Carbon\Carbon::parse($h['at']);
        $ageHours = $ts->diffInHours(now());
        $sizeMb   = isset($h['bytes']) ? round($h['bytes'] / 1024 / 1024, 1) . ' MB' : '?';
        $color    = $ageHours > 36 ? 'danger' : ($ageHours > 30 ? 'warning' : 'success');
        return Stat::make('Backup status', $ts->diffForHumans())
            ->description($sizeMb . ' last run')
            ->color($color);
    }

    protected function domainsNeedingAttention(): Stat
    {
        $erroredOver24h = TenantDomain::where('status', 'error')->where('updated_at', '<', now()->subDay())->count();
        $stuckVerifying = TenantDomain::stuckVerifying()->count();
        $total = $erroredOver24h + $stuckVerifying;
        return Stat::make('Domains needing attention', number_format($total))
            ->description($total === 0 ? 'all healthy' : ($erroredOver24h . ' errored, ' . $stuckVerifying . ' stuck'))
            ->color($total > 0 ? 'danger' : 'success');
    }
}
