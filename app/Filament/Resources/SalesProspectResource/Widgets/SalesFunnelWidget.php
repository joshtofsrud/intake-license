<?php
// MARKER-SALES-WIDGET

namespace App\Filament\Resources\SalesProspectResource\Widgets;

use App\Models\SalesProspect;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Header widget on the prospect list. Cumulative funnel + targetable value.
 * "Targetable value" weights A/B prospects by lead_score against the cheapest
 * paid plan, so it's a deliberately conservative pipeline estimate — replace
 * the proxy with real linked-tenant MRR as conversions land.
 */
class SalesFunnelWidget extends BaseWidget
{
    protected static ?int $sort = 0;
    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $total    = SalesProspect::count();
        $aCount   = SalesProspect::where('priority', 'A')->count();
        $verified = SalesProspect::where('verified', true)->count();
        $trials   = SalesProspect::where('stage', 'trial')->count();
        $won      = SalesProspect::where('stage', 'won')->count();
        $tenants  = SalesProspect::whereNotNull('tenant_id')->count();

        // Conservative targetable value: A/B shops, lead_score as a 0..1 weight,
        // times the lowest configured paid plan price (cents -> dollars).
        $plans = \App\Support\PlanPricing::all();
        $floor = $plans ? min(array_filter($plans)) / 100 : 89;
        $value = (int) round(
            SalesProspect::whereIn('priority', ['A', 'B'])
                ->get(['lead_score'])
                ->sum(fn ($p) => ($p->lead_score / 110) * $floor)
        );

        return [
            Stat::make('Prospects', number_format($total))
                ->description($aCount . ' A-priority')
                ->color('gray'),

            Stat::make('Verified', number_format($verified))
                ->description(($total - $verified) . ' to check')
                ->color($verified ? 'success' : 'gray'),

            Stat::make('Active trials', number_format($trials))
                ->description($won . ' won')
                ->color($trials ? 'warning' : 'gray'),

            Stat::make('Linked tenants', number_format($tenants))
                ->description('converted to billing')
                ->color($tenants ? 'success' : 'gray'),

            Stat::make('Targetable value', '$' . number_format($value))
                ->description('A+B weighted, monthly')
                ->color('success'),
        ];
    }
}
