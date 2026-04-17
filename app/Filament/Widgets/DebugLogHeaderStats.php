<?php

namespace App\Filament\Widgets;

use App\Models\DebugLog;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Four-stat overview shown at the top of the debug logs page.
 *
 * Kept cheap — all four queries hit the (channel, created_at) index.
 */
class DebugLogHeaderStats extends BaseWidget
{
    protected static ?int $sort = 1;

    /** Tighten the grid — four stats across. */
    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $errors24   = DebugLog::where('channel', 'error')
            ->where('is_resolved', false)
            ->where('created_at', '>=', now()->subDay())->count();

        $errors7    = DebugLog::where('channel', 'error')
            ->where('created_at', '>=', now()->subDays(7))->count();

        $slowReqs24 = DebugLog::where('channel', 'request')
            ->where('duration_ms', '>=', (int) config('debug.request.slow_threshold_ms', 1500))
            ->where('created_at', '>=', now()->subDay())->count();

        $failedJobs = DebugLog::where('channel', 'job')
            ->where('event', 'job.failed')
            ->where('created_at', '>=', now()->subDay())->count();

        $mail24     = DebugLog::where('channel', 'mail')
            ->where('created_at', '>=', now()->subDay())->count();

        $authFails  = DebugLog::where('channel', 'auth')
            ->where('event', 'auth.login_failed')
            ->where('created_at', '>=', now()->subDay())->count();

        return [
            Stat::make('Unresolved errors', number_format($errors24))
                ->description($errors7 . ' in last 7 days')
                ->descriptionIcon($errors24 > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($errors24 > 0 ? 'danger' : 'success'),

            Stat::make('Slow requests (24h)', number_format($slowReqs24))
                ->description('Over ' . config('debug.request.slow_threshold_ms', 1500) . 'ms')
                ->color($slowReqs24 > 10 ? 'warning' : 'gray'),

            Stat::make('Failed jobs (24h)', number_format($failedJobs))
                ->color($failedJobs > 0 ? 'danger' : 'success'),

            Stat::make('Mail sent (24h)', number_format($mail24))
                ->description($authFails . ' failed logins')
                ->color('primary'),
        ];
    }
}
