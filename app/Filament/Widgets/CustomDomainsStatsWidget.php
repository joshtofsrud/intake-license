<?php

namespace App\Filament\Widgets;

use App\Models\Tenant\TenantDomain;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Custom Domains stats card on the master admin dashboard.
 *
 * Four tiles: Active, In setup, Errored, Needs attention (errored >24h).
 */
class CustomDomainsStatsWidget extends BaseWidget
{
    protected static ?int $sort = 5;

    protected function getStats(): array
    {
        $active = TenantDomain::where('status', 'active')->count();
        $activeLastWeek = TenantDomain::where('status', 'active')
            ->where('activated_at', '>=', now()->subWeek())
            ->count();

        $inSetup = TenantDomain::whereIn('status', ['pending_dns', 'verifying', 'issuing_cert'])->count();

        $errored = TenantDomain::where('status', 'error')->count();
        $erroredOver24h = TenantDomain::where('status', 'error')
            ->where('last_check_at', '<=', now()->subHours(24))
            ->count();

        return [
            Stat::make('Active', $active)
                ->description($activeLastWeek > 0 ? "+{$activeLastWeek} this week" : 'no new this week')
                ->descriptionColor($activeLastWeek > 0 ? 'success' : 'gray')
                ->color('success'),

            Stat::make('In setup', $inSetup)
                ->description('pending DNS / verifying / issuing')
                ->color($inSetup > 0 ? 'warning' : 'gray'),

            Stat::make('Errored', $errored)
                ->description($erroredOver24h > 0 ? "{$erroredOver24h} over 24h old" : 'all recent')
                ->descriptionColor($erroredOver24h > 0 ? 'danger' : 'gray')
                ->color($errored > 0 ? 'danger' : 'gray'),

            Stat::make('Total domains', TenantDomain::count())
                ->description('all states combined')
                ->color('gray'),
        ];
    }
}
