<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Tenant\ReportBuilder\ReportAccess;
use App\Services\Tenant\ReportBuilder\ReportEngine;
use App\Services\Tenant\ReportBuilder\TabPanels;
use App\Services\Tenant\ReportBuilder\TabQuery;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ReportsController
 *
 * Phase 3: single global date range drives every zone.
 * custom from/to are parsed in the tenant's
 * timezone, the same as the preset ranges. Parsed as UTC they became the
 * previous local day once a zone shifted them into the tenant zone.
 *   /admin/reports                          (defaults to today)
 *   /admin/reports?range=today
 *   /admin/reports?range=week
 *   /admin/reports?range=month
 *   /admin/reports?range=custom&from=2026-04-01&to=2026-04-15
 */
class ReportsController extends Controller
{
    /** inline target edits from the reports page. */
    public function saveTargets(Request $request)
    {
        $tenant = app('tenant');

        $data = $request->validate([
            'targets'   => ['array'],
            'targets.*' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'spotlight' => ['nullable', 'string', 'max:120'],
        ]);

        $targets = [];
        foreach (($data['targets'] ?? []) as $key => $value) {
            // A blank target is an ABSENT target, not a zero — a zero would
            // read as "target met" on every measure.
            if ($value === null || $value === '') {
                continue;
            }
            $targets[$key] = (float) $value;
        }

        $settings = $tenant->settings ?? [];
        $settings['report_targets'] = $targets;
        $settings['report_spotlight_category'] = $data['spotlight'] ?: null;
        $tenant->settings = $settings;
        $tenant->save();

        return back()->with('info', 'Targets saved.');
    }

    /** Operations and Customers run on the report engine too. (MARKER-REPORT-TABS-ENGINE) */
    public function index(Request $request): View
    {
        return $this->engineTab($request, 'operations', 'How the shop is running: sales, labor and parts, bookings, targets, and how busy people are.');
    }

    public function customers(Request $request): View
    {
        return $this->engineTab($request, 'customers', 'Who your customers are, who is new, who has gone quiet, and who is worth the most.');
    }

    /**
     * what's missing across the records as they
     * stand, as opposed to what happened during any one import.
     */
    public function dataQuality(Request $request): View
    {
        $tenant = tenant();
        $svc    = new \App\Services\Tenant\DataCompletenessService($tenant);

        return view('tenant.reports.data-quality', [
            'tenant'    => $tenant,
            'customers' => $svc->customers(),
            'inventory' => $svc->inventory(),
            'consent'   => $svc->consent(),
        ]);
    }

    /** the affected records, to fix in bulk. */
    public function dataQualityExport(Request $request)
    {
        $tenant = tenant();

        $data = $request->validate([
            'type'  => 'required|in:customers,inventory',
            'field' => 'required|string|max:64',
        ]);

        $svc      = new \App\Services\Tenant\DataCompletenessService($tenant);
        $filename = 'missing-' . $data['field'] . '-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($svc, $data) {
            $out = fopen('php://output', 'w');
            fputcsv($out, \App\Services\Tenant\DataCompletenessService::exportHeader($data['type']));
            $svc->exportMissing($data['type'], $data['field'], function (array $row) use ($out) {
                fputcsv($out, $row);
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** Services and Staff run on the report engine too. (MARKER-REPORT-TABS-ENGINE) */
    public function services(Request $request): View
    {
        return $this->engineTab($request, 'services', 'How much work got done, what it was, and how busy each bay or chair was.');
    }

    public function staff(Request $request): View
    {
        return $this->engineTab($request, 'staff', 'Who sold what, and how it compares with the hours they worked.');
    }

    /** Retail and Money run on the report engine: the same numbers, ranges and settings as Custom. (MARKER-REPORT-TABS-ENGINE) */
    public function retail(Request $request): View
    {
        return $this->engineTab($request, 'retail', 'What sold, who sold it, what it earned, and what is left on the shelf.');
    }

    public function money(Request $request): View
    {
        return $this->engineTab($request, 'money', 'Money in: what you were paid, what was refunded, and the tax you collected.');
    }

    private function engineTab(Request $request, string $tab, string $subtitle): View
    {
        $tenant = tenant();
        $user = Auth::guard('tenant')->user();
        $isManager = (bool) ($user && $user->isManager());
        $noAccess = !ReportAccess::allowed($tenant);
        // Operations was open to every shop before the engine, so its tables stay open. Explain and Open in builder still need extended reports.
        $locked = $tab === 'operations' ? false : $noAccess;
        $q = TabQuery::read($request);
        $engine = new ReportEngine($tenant, $isManager);
        $panels = new TabPanels($tenant, $engine, $q, $isManager, $locked);
        $built = match ($tab) {
            'money' => $panels->money(), 'staff' => $panels->staff(), 'services' => $panels->services(),
            'operations' => $panels->operations(), 'customers' => $panels->customers(), default => $panels->retail(),
        };

        [$from, $to] = $engine->periods()->rangeFor(['range' => $q['range'], 'from' => $q['from'], 'to' => $q['to']]);
        $prev = $q['compare'] === 'off' ? null : $engine->periods()->previous($from, $to, $q['compare']);

        return view('tenant.reports.engine_tab', [
            'tab'      => $tab,
            'title'    => ucfirst($tab),
            'subtitle' => $subtitle,
            'q'        => $q,
            'locked'   => $locked,
            'noAccess' => $noAccess,
            'routeName' => $tab === 'operations' ? 'tenant.reports.index' : 'tenant.reports.' . $tab,
            'panels'   => $built['panels'],
            'legend'   => $built['legend'],
            'from'     => $from,
            'to'       => $to,
            'prev'     => $prev,
        ]);
    }

    /**
     * Traffic tab — site usage analytics over tenant_funnel_events.
     * Free for all tenants. Window: 7d / 30d (default) / 90d.
     */
    public function traffic(Request $request): View
    {
        $tenant = tenant();

        // a custom date range (shared calendar picker) wins over
        // the preset windows when both `from` and `to` are supplied and valid.
        $fromStr = trim((string) $request->query('from', ''));
        $toStr   = trim((string) $request->query('to', ''));

        $svc = null;
        if ($fromStr !== '' && $toStr !== '') {
            try {
                $svc = new \App\Services\Tenant\TrafficReportService($tenant, '30d', $fromStr, $toStr);
            } catch (\Throwable $e) {
                $svc     = null;
                $fromStr = '';
                $toStr   = '';
            }
        }

        $window = $request->query('window', '30d');
        if (!in_array($window, ['1d', '7d', '30d', '90d'], true)) {
            $window = '30d';
        }
        if ($svc === null) {
            $svc = new \App\Services\Tenant\TrafficReportService($tenant, $window);
        }

        $bf = $svc->bookingFunnelData();

        return view('tenant.reports.traffic', [
            'tenant'         => $tenant,
            'window'         => $svc->window(),
            'isCustom'       => $svc->isCustom(),
            'rangeText'      => $svc->rangeLabel(),
            'from'           => $fromStr,
            'to'             => $toStr,
            'topStats'       => $topStats = $svc->topStats(),
            'sessions'       => $topStats['sessions'] ?? [],
            'dailyVisitors'  => $svc->dailyVisitors(),
            'dailyStart'     => $svc->curStart(),
            'topSearches'    => $svc->topSearches(),
            'zeroSearches'   => $svc->zeroResultSearches(),
            'searchRules'    => \App\Models\Tenant\TenantSearchRule::where('tenant_id', tenant()->id)
                                    ->orderBy('type')->orderBy('from_term')->get(),
            // additional panels
            'funnel'         => $bf['funnel'],
            'funnelDetail'   => $bf['detail'],
            'topSources'     => $svc->topSources(),
            'deviceSplit'    => $svc->deviceSplit(),
            'topPages'       => $svc->topPages(),
            'newVsReturning' => $svc->newVsReturning(),
        ]);
    }
}
