<?php

namespace App\Filament\Widgets;

use App\Models\Activation;
use App\Models\License;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $totalInstalls   = Activation::count();
        $freeInstalls    = Activation::where('type', 'free')->count();
        $premiumInstalls = Activation::where('type', 'premium')->count();
        $activeLicenses  = License::active()->count();

        // Installs seen in the last 30 days
        $recentInstalls = Activation::where('last_seen_at', '>=', now()->subDays(30))->count();

        return [
            Stat::make('Total installs', number_format($totalInstalls))
                ->description($recentInstalls . ' active in last 30 days')
                ->color('primary'),

            Stat::make('Free installs', number_format($freeInstalls))
                ->description('Sites running free tier')
                ->color('gray'),

            Stat::make('Premium installs', number_format($premiumInstalls))
                ->description('Sites with active license')
                ->color('success'),

            Stat::make('Active licenses', number_format($activeLicenses))
                ->description('Valid, non-expired keys')
                ->color('success'),
        ];
    }
}
