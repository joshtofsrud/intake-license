<?php
// MARKER-LEDGER-REPWIDGET — the rep's book at a glance.
// Principal: agency-wide numbers. Rep: their own.

namespace App\Filament\Rep\Widgets;

use App\Models\SalesCommissionEntry;
use App\Models\SalesProspect;
use App\Models\SalesRep;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class RepBookWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $rep = SalesRep::with('agency')->where('user_id', auth()->id())->first();
        if (! $rep) {
            return [];
        }

        $isPrincipal = $rep->role === 'principal';

        $prospects = SalesProspect::query()->when(
            $isPrincipal,
            fn ($query) => $query->where('agency_id', $rep->agency_id),
            fn ($query) => $query->where('sales_rep_id', $rep->id),
        );

        $entries = SalesCommissionEntry::query()->when(
            $isPrincipal,
            fn ($query) => $query->where('agency_id', $rep->agency_id),
            fn ($query) => $query->where('sales_rep_id', $rep->id),
        );

        $open = (clone $prospects)->whereNotIn('stage', ['won', 'lost'])->count();
        $due  = (clone $prospects)->whereNotIn('stage', ['won', 'lost'])
            ->whereNotNull('next_action_on')->whereDate('next_action_on', '<=', today())->count();
        $won  = (clone $prospects)->whereNotNull('tenant_id')->count();
        $unpaidCents = (clone $entries)->where('status', 'accrued')->sum('commission_cents');
        $paidCents   = (clone $entries)->where('status', 'paid')->sum('commission_cents');

        $scope = $isPrincipal ? ($rep->agency?->name ?? 'Agency') : 'Your book';

        return [
            Stat::make('Open prospects', $open)
                ->description($scope),
            Stat::make('Due today', $due)
                ->description('next actions due or overdue')
                ->color($due > 0 ? 'warning' : 'gray'),
            Stat::make('Won tenants', $won)
                ->description('converted to Intake')
                ->color('success'),
            Stat::make('Commission unpaid', '$' . number_format($unpaidCents / 100, 2))
                ->description('$' . number_format($paidCents / 100, 2) . ' paid to date')
                ->color($unpaidCents > 0 ? 'warning' : 'gray'),
        ];
    }
}
