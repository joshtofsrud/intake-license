<?php
// MARKER-INV-REPORTS

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
        $categories= $service->byCategory($from, $to);
        $movers    = $service->movers($from, $to);

        if ($request->query('export') === 'csv') {
            return $this->exportCsv($categories, $movers, $days);
        }

        return view('tenant.inventory.reports', compact(
            'valuation', 'turns', 'dead', 'categories', 'movers', 'days'
        ));
    }

    private function exportCsv(array $categories, array $movers, int $days)
    {
        $filename = 'inventory-report-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($categories, $movers, $days) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['By category', 'window: last ' . $days . ' days']);
            fputcsv($out, ['Category', 'SKUs', 'Units on hand', 'Cost', 'Units sold', 'Sell-through %']);
            foreach ($categories as $c) {
                fputcsv($out, [
                    $c['category'], $c['skus'], $c['units'],
                    number_format($c['cost_cents'] / 100, 2, '.', ''),
                    $c['sold_units'],
                    $c['sell_through_pct'] ?? '',
                ]);
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
