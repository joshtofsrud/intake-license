<?php

namespace App\Filament\Pages;

// MARKER-MKTTRAFFIC — intake.works traffic + signup funnel, master admin.
// Reuses the tenant TrafficReportService against the platform tenant rather
// than reimplementing windows, comparisons and daily series.

use App\Models\Tenant;
use App\Services\Platform\MarketingSessionsService; // MARKER-MKTSESSIONS
use App\Services\Platform\SignupFunnelService;
use App\Services\Tenant\TrafficReportService;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;

class MarketingTraffic extends Page
{
    protected static ?string $title           = 'Marketing Traffic';
    protected static ?string $slug            = 'marketing-traffic';
    protected static ?string $navigationIcon  = 'heroicon-o-chart-bar';
    protected static ?string $navigationLabel = 'Marketing Traffic';
    protected static ?int    $navigationSort  = -80;

    protected static string $view = 'filament.pages.marketing-traffic';

    public string $window = '30d';

    public function mount(): void
    {
        $this->window = request()->query('window', '30d');
        if (! in_array($this->window, ['7d', '30d', '90d'], true)) {
            $this->window = '30d';
        }
    }

    protected function getViewData(): array
    {
        $platform = Tenant::where('is_platform', true)->first();

        if (! $platform) {
            return ['platform' => null, 'window' => $this->window];
        }

        $report = new TrafficReportService($platform, $this->window);
        $funnel = new SignupFunnelService(
            CarbonImmutable::instance($report->curStart()),
            CarbonImmutable::instance($report->curEnd())
        );

        return [
            'platform'   => $platform,
            'window'     => $this->window,
            'rangeLabel' => $report->rangeLabel(),
            'stats'      => $report->topStats(),
            'daily'      => $report->dailyVisitors(),
            'stages'     => $funnel->stages(),
            'intent'     => $funnel->intent(),
            'sessions'   => (new MarketingSessionsService( // MARKER-MKTSESSIONS
                CarbonImmutable::instance($report->curStart()),
                CarbonImmutable::instance($report->curEnd())
            ))->recent(200), // MARKER-MKTSESSTYLE — the scroll box bounds height now
        ];
    }
}
