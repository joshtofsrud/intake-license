<?php

namespace App\Filament\Widgets;

use App\Models\Tenant;
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

        // MARKER-PATCH-132 — WP installs moved to WpPluginStatsWidget.

        return [
            Stat::make('Total tenants', number_format($totalTenants))
                ->description($newThisWeek . ' new this week')
                ->color('success'),

            Stat::make('Active (onboarded)', number_format($active))
                ->description('paying or in onboarding')
                ->color('success'),

            Stat::make('In trial', number_format($trials))
                ->description('within 14-day window')
                ->color($trials > 0 ? 'warning' : 'gray'),

            Stat::make('Est. MRR', '$' . number_format($mrr))
                ->description('from active plans')
                ->color('success'),
        ];
    }
}
