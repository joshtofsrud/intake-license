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
use Livewire\Attributes\Url; // MARKER-MKTDONE

class MarketingTraffic extends Page
{
    use \App\Support\UsesAdminNav; // MARKER-NAV-ORDER
    use \App\Support\GatedByAdminArea; // MARKER-ADMIN-NAV-GATE
    protected static string $adminArea = 'analytics';

    protected static ?string $title           = 'Marketing Traffic';
    protected static ?string $slug            = 'marketing-traffic';
    protected static ?string $navigationIcon  = 'heroicon-o-chart-bar';
    protected static ?string $navigationLabel = 'Marketing Traffic';
    protected static ?int    $navigationSort  = -80;

    protected static string $view = 'filament.pages.marketing-traffic';

    // MARKER-MKTDONE — every one of these is in the URL now. A metric click is a
    // Livewire round trip, and before this the chosen date range lived only in
    // component state: the range survived the click but was absent from the
    // address bar, so a refresh or a copied link silently reverted to the preset.
    #[Url(as: 'window', keep: true)]
    public string $window = '30d';

    // MARKER-TRAFFIC-V2 — a real date range, and which metric the chart draws.
    #[Url(as: 'from', keep: true)]
    public ?string $from   = null;
    #[Url(as: 'to', keep: true)]
    public ?string $to     = null;
    #[Url(as: 'metric', keep: true)]
    public string  $metric = 'visitors';
    #[Url(as: 'compare')]
    public bool    $compare = true;   // MARKER-TRAFFIC-V3 — the ghost line

    public function mount(): void
    {
        $this->window = request()->query('window', '30d');
        // MARKER-TRAFFIC-V2
        $this->from = request()->query('from');
        $this->to   = request()->query('to');
        // MARKER-MKTSID -- '1d' is TrafficReportService's existing today window.
        if (! in_array($this->window, ['1d', '7d', '30d', '90d'], true)) {
            $this->window = '30d';
        }
    }

    /**
     * MARKER-MKTDONE — ONE report object for the page. conversions() used to build
     * its own rolling window from now(), so the Conversions tab answered a
     * different question than the tab beside it whenever a custom range was set.
     */
    protected function report(): ?TrafficReportService
    {
        $platform = Tenant::where('is_platform', true)->first();
        if (! $platform) {
            return null;
        }

        // MARKER-TRAFFIC-V2 — a custom range wins over the preset. The service
        // has always accepted from/to; only the page never offered it.
        return $this->from && $this->to
            ? (new TrafficReportService($platform, $this->window, $this->from, $this->to))->excludeBots()
            : (new TrafficReportService($platform, $this->window))->excludeBots();
    }

    protected function getViewData(): array
    {
        $platform = Tenant::where('is_platform', true)->first();

        if (! $platform) {
            return ['platform' => null, 'window' => $this->window];
        }

        $report = $this->report();
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
            'metric'     => $this->metric,          // MARKER-TRAFFIC-V2
            'sources'    => $report->topSources(6), // MARKER-TRAFFIC-V3
            'pages'      => $report->topPages(6),
            // MARKER-MKTDONE — the Pages & sources tab had two lists' worth of
            // room and one list's worth of content.
            'allSources' => $report->topSources(20),
            'allPages'   => $report->topPages(20),
            'devices'    => $report->deviceSplit(),
            'outcomes'   => $funnel->outcomes(),
            'health'     => $this->trackingHealth($platform),
            'compare'    => $this->compare,
            'series'     => $this->series($report),
            'identityCutover' => $this->identityCutover($report),
            'sessions'   => (new MarketingSessionsService( // MARKER-MKTSESSIONS
                CarbonImmutable::instance($report->curStart()),
                CarbonImmutable::instance($report->curEnd())
            ))->recent(200), // MARKER-MKTSESSTYLE — the scroll box bounds height now
        ];
    }

    /**
     * MARKER-TRAFFIC-V2 — the chart's points for whichever metric is selected,
     * plus the same window a period earlier so the comparison is drawable.
     */
    private function series($report): array
    {
        // MARKER-TRAFFIC-V3 — draw the metric that is actually selected.
        if ($this->metric !== 'visitors') {
            $cur  = $report->dailyMetricSeries($this->metric,
                        \Carbon\CarbonImmutable::instance($report->curStart()),
                        \Carbon\CarbonImmutable::instance($report->curEnd()));
            $prev = $report->dailyMetricSeries($this->metric,
                        \Carbon\CarbonImmutable::instance($report->prevStart()),
                        \Carbon\CarbonImmutable::instance($report->prevEnd()));

            return [
                'current'  => $cur,
                'previous' => $prev,
                // MARKER-MKTREPAIR — a 1-day window is bucketed hourly for every
                // metric now, not only for visitors.
                'hourly'   => $report->isHourly(),
                'peak'     => max(1, (int) max([0, ...$cur, ...$prev])),
                'points'   => count($cur),
                'labels'   => $report->dayLabels(),
            ];
        }

        $daily = $report->dailyVisitors();

        // The service returns ['current' => [int,...], 'prior' => [int,...],
        // 'hourly' => bool] — plain lists, keyed by position, and the earlier
        // window is 'prior' rather than 'previous'. Reading it as anything else
        // silently yields an empty chart.
        $cur  = array_values($daily['current'] ?? []);
        $prev = array_values($daily['prior'] ?? []);

        return [
            'current' => $cur,
            'previous'=> $prev,
            'hourly'  => (bool) ($daily['hourly'] ?? false),
            'peak'    => max(1, (int) max([0, ...$cur, ...$prev])),
            'points'  => count($cur),
            'labels'  => $report->dayLabels(),   // MARKER-TRAFFIC-V3
        ];
    }

    /**
     * MARKER-TRAFFIC-V2 — visitor counting changed meaning on the day
     * MARKER-TRAFFIC-IDENTITY deployed: before it, one person returning counted
     * twice. A window spanning that date mixes two definitions, and a chart that
     * does not say so invites a conclusion about a trend that is partly an
     * artefact of the fix.
     */
    private function identityCutover($report): ?string
    {
        $cutover = config('intake.traffic_identity_cutover');
        if (! $cutover) return null;

        try {
            $c = \Carbon\CarbonImmutable::parse($cutover);
        } catch (\Throwable $e) {
            return null;
        }

        return $c->between(
            \Carbon\CarbonImmutable::instance($report->curStart()),
            \Carbon\CarbonImmutable::instance($report->curEnd())
        ) ? $c->format('M j') : null;
    }

    /**
     * MARKER-MKTDONE — is the tracker alive? Browser-sent events stopped for weeks
     * when an include moved to a layout nothing renders, and NOTHING on this page
     * looked different: the tiles read zero, which is also what a quiet week looks
     * like. This reports the last browser-sent event regardless of window, so a
     * dead tracker is visible on sight instead of being mistaken for no traffic.
     */
    protected function trackingHealth(Tenant $platform): array
    {
        $last = \Illuminate\Support\Facades\DB::table('tenant_funnel_events')
            ->where('tenant_id', $platform->id)
            ->where('event_type', 'page_view')
            ->max('created_at');

        $at    = $last ? \Carbon\CarbonImmutable::parse($last) : null;
        $hours = $at ? $at->diffInHours(now()) : null;

        return [
            'last_page_view' => $at,
            'stale'          => $at === null || $hours > 24,
            'ago'            => $at ? $at->diffForHumans() : null,
        ];
    }

    /**
     * MARKER-MKTCONV — what the window actually converted. Sessions, not raw
     * events, so a page reloaded five times counts once.
     */
    public function conversions(): array
    {
        $report = $this->report();
        if (! $report) return [];

        $tenant = \App\Models\Tenant::where('is_platform', true)->first();

        // MARKER-MKTDONE — the page's window, including a custom from/to range.
        // This used to be its own rolling now()-minus-N and ignored from/to.
        $rows = \Illuminate\Support\Facades\DB::table('tenant_funnel_events')
            ->where('tenant_id', $tenant->id)
            ->whereIn('event_type', ['demo_entered', 'booking_started', 'booking_completed', 'cta_click'])
            ->where('created_at', '>=', $report->curStart())
            ->where('created_at', '<',  $report->curEnd())
            ->where(function ($w) { $w->whereNull('device')->orWhere('device', '!=', 'bot'); })
            ->get(['event_type', 'session_id', 'step', 'path', 'created_at']);

        $bucket = fn ($type) => $rows->where('event_type', $type);

        $clicks = [];
        foreach ($bucket('cta_click') as $r) {
            $k = $r->step ?: 'other';
            $clicks[$k] = ($clicks[$k] ?? 0) + 1;
        }
        arsort($clicks);

        return [
            'demo_entries'      => $bucket('demo_entered')->count(),
            'demo_sessions'     => $bucket('demo_entered')->pluck('session_id')->unique()->count(),
            'booking_views'     => $bucket('booking_started')->pluck('session_id')->unique()->count(),
            'bookings'          => $bucket('booking_completed')->count(),
            'clicks'            => $clicks,
            'recent'            => $rows->whereIn('event_type', ['demo_entered', 'booking_completed'])
                ->sortByDesc('created_at')->take(15)
                ->map(fn ($r) => [
                    'what' => $r->event_type === 'demo_entered' ? 'Demo entry' : 'Call booked',
                    'step' => $r->step,
                    'at'   => $r->created_at,
                ])->values()->all(),
        ];
    }
}
