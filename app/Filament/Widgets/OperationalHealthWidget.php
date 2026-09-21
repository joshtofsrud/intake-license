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
        // MARKER-ERROR-PARITY
        $unresolved = DebugLog::issues()->where('is_resolved', false)->count();
        $last7      = DebugLog::issues()->where('created_at', '>=', now()->subDays(7))->count();
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
            ->where('received_at', '<', now()->subMinutes(5))  // MARKER-PATCH-134
            ->count();
        return Stat::make('Stripe webhook failures', number_format($count))
            ->description($count > 0 ? 'unprocessed > 5 min' : 'all processed')
            ->color($count > 0 ? 'danger' : 'success');
    }

    protected function failedLogins(): Stat
    {
        $count = DebugLog::where('channel', 'auth')
            ->where('event', 'auth.login_failed')  // MARKER-PATCH-134
            ->where('created_at', '>=', now()->subDay())
            ->count();
        return Stat::make('Failed logins (24h)', number_format($count))
            ->description($count > 20 ? 'investigate' : 'normal')
            ->color($count > 20 ? 'warning' : 'gray');
    }

    protected function mailSent(): Stat
    {
        $count = DebugLog::where('channel', 'mail')
            ->where('event', 'mail.sent')  // MARKER-PATCH-134
            ->where('created_at', '>=', now()->subDay())
            ->count();
        return Stat::make('Mail sent (24h)', number_format($count))
            ->description('comes online once tenant mail is live')
            ->color('gray');
    }

    protected function backupStatus(): Stat
    {
        // MARKER-BACKUP-RECORD — same reading as the dashboard tile.
        $bk = \App\Support\BackupStatus::read();
        if ($bk['state'] === 'none') {
            return Stat::make('Backup status', 'no report')
                ->description('intake-backup.sh has not reported yet')
                ->color('warning');
        }
        if ($bk['failed_at']) {
            return Stat::make('Backup status', 'failed ' . $bk['failed_at']->diffForHumans())
                ->description(\Illuminate\Support\Str::limit($bk['reason'], 80))
                ->color('danger');
        }
        return Stat::make('Backup status', $bk['at']->diffForHumans())
            ->description(($bk['mb'] !== null ? $bk['mb'] . ' MB' : '?') . ' last run')
            ->color(['ok' => 'success', 'warn' => 'warning', 'bad' => 'danger'][$bk['state']] ?? 'gray');
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
