<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Tenant\InventoryReportService;
use Illuminate\Http\Request;

class InventoryReportController extends Controller
{
    public function index(Request $request)
    {
        $tenant  = tenant();
        $service = new InventoryReportService($tenant);

        // Window applies to MOVEMENT only. Valuation and dead stock are
        // "right now" questions and ignore it.
        $days = (int) $request->query('days', 90);
        if (! in_array($days, [30, 90, 180, 365], true)) $days = 90;

        $to   = $tenant->localToday();
        $from = $to->copy()->subDays($days);

        $valuation = $service->valuation();
        $turns     = $service->turns();
        $dead      = $service->deadStock();
        // MARKER-INV-REPORT-LEVELS / MARKER-INV-REPORT-SORT: chosen once, remembered on this device
        $pref  = json_decode((string) $request->cookie('inv_report_view', ''), true) ?: [];
        $level = in_array($request->query('level'), ['top', 'all'], true) ? $request->query('level') : ($pref['level'] ?? 'top');
        $sort  = in_array($request->query('sort'), InventoryReportService::CATEGORY_SORTS, true) ? $request->query('sort') : ($pref['sort'] ?? 'cost');
        $dir   = in_array($request->query('dir'), ['asc', 'desc'], true) ? $request->query('dir') : ($pref['dir'] ?? ($sort === 'name' ? 'asc' : 'desc'));
        \Illuminate\Support\Facades\Cookie::queue('inv_report_view', json_encode(['level' => $level, 'sort' => $sort, 'dir' => $dir]), 60 * 24 * 365);
        $categories= InventoryReportService::sortCategoryRows($service->byCategory($from, $to, $level), $sort, $dir);
        $movers    = $service->movers($from, $to);

        if ($request->query('export') === 'csv') {
            return $this->exportCsv($categories, $movers, $days, $level);
        }

        return view('tenant.inventory.reports', compact(
            'valuation', 'turns', 'dead', 'categories', 'movers', 'days', 'level', 'sort', 'dir'
        ));
    }

    private function exportCsv(array $categories, array $movers, int $days, string $level = 'all')
    {
        $filename = 'inventory-report-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($categories, $movers, $days, $level) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['By category', 'window: last ' . $days . ' days', $level === 'top' ? 'top-level totals, with the level below' : 'every category']);
            fputcsv($out, ['Category', 'Within', 'SKUs', 'Units on hand', 'Cost', 'Units sold', 'Sell-through %']);
            $line = fn ($c) => [
                $c['category'], $c['parent'] ?? '', $c['skus'], $c['units'],
                number_format($c['cost_cents'] / 100, 2, '.', ''),
                $c['sold_units'],
                $c['sell_through_pct'] ?? '',
            ];
            foreach ($categories as $c) {
                fputcsv($out, $line($c));
                foreach ($c['children'] ?? [] as $k) fputcsv($out, $line($k));
            }

            fputcsv($out, []);
            fputcsv($out, ['Top movers']);
            fputcsv($out, ['SKU', 'Name', 'Units sold', 'Revenue', 'On hand']);
            foreach ($movers['top'] as $m) {
                fputcsv($out, [
                    $m['sku'], $m['name'], $m['units'],
                    number_format($m['revenue'] / 100, 2, '.', ''),
                    $m['on_hand'],
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
