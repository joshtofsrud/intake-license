<?php

namespace App\Filament\Widgets;

use App\Models\Tenant;
use App\Models\Activation;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PlatformStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $plans       = config('intake.plan_prices');
        $totalTenants= Tenant::count();
        $active      = Tenant::where('onboarding_status', 'complete')->count();
        $trials      = Tenant::where('onboarding_status', 'pending')
                          ->where('created_at', '>=', now()->subDays(14))
                          ->count();
        $newThisWeek = Tenant::where('created_at', '>=', now()->subDays(7))->count();

        // Rough MRR estimate from plan distribution
        $mrr = Tenant::where('onboarding_status', 'complete')
            ->selectRaw('plan_tier, COUNT(*) as cnt')
            ->groupBy('plan_tier')
            ->pluck('cnt', 'plan_tier')
            ->reduce(function ($carry, $cnt, $tier) use ($plans) {
                return $carry + ($cnt * (($plans[$tier] ?? 0) / 100));
            }, 0);

        $freeInstalls = Activation::whereNull('license_id')->count();
        $premiumSites = Activation::whereNotNull('license_id')->count();

        return [
            Stat::make('Total tenants', number_format($totalTenants))
                ->description($newThisWeek . ' new this week')
                ->color('success'),

            Stat::make('Active (onboarded)', number_format($active))
                ->description($trials . ' in trial')
                ->color('primary'),

            Stat::make('Est. MRR', '$' . number_format($mrr))
                ->description('From active plans')
                ->color('warning'),

            Stat::make('WP installs', number_format($freeInstalls + $premiumSites))
                ->description($premiumSites . ' premium · ' . $freeInstalls . ' free')
                ->color('gray'),
        ];
    }
}
