<?php
// MARKER-PATCH-132

namespace App\Filament\Widgets;

use App\Models\Activation;
use App\Models\License;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class WpPluginStatsWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected function getStats(): array
    {
        $total   = Activation::count();
        $active  = Activation::where('last_seen_at', '>=', now()->subDays(30))->count();
        $free    = Activation::whereNull('license_id')->count();
        $premium = Activation::whereNotNull('license_id')->count();
        $activeLicenses = class_exists(License::class)
            ? License::where('status', 'active')->count()
            : 0;

        return [
            Stat::make('WP installs', number_format($total))
                ->description('WordPress plugin')
                ->color('gray'),

            Stat::make('Active (30d)', number_format($active))
                ->description($total > 0 ? round(($active / max($total, 1)) * 100) . '% of installs' : 'no installs yet')
                ->color($active > 0 ? 'success' : 'gray'),

            Stat::make('Free / Premium', $free . ' / ' . $premium)
                ->description($premium > 0
                    ? round(($premium / max($total, 1)) * 100) . '% paid'
                    : 'no paid yet')
                ->color($premium > 0 ? 'success' : 'gray'),

            Stat::make('Active licenses', number_format($activeLicenses))
                ->description('valid, non-expired keys')
                ->color('gray'),
        ];
    }
}
