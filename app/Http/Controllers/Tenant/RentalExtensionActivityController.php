<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantRentalExtensionOffer;
use Illuminate\Http\Request;

/**
 * MARKER-RENTAL-EXT-P2 — Offers activity: did the robot make money?
 * 30-day stats + the offer table, filterable, CSV export.
 */
class RentalExtensionActivityController extends Controller
{
    public function index(Request $request)
    {
        $tenant = tenant();
        abort_unless($tenant && $tenant->rental_extensions_enabled, 404);

        $since     = now()->subDays(30);
        $prevSince = now()->subDays(60);

        $base = TenantRentalExtensionOffer::where('tenant_id', $tenant->id);

        $sent     = (clone $base)->where('sent_at', '>=', $since)->count();
        $sentPrev = (clone $base)->whereBetween('sent_at', [$prevSince, $since])->count();
        $accepted = (clone $base)->where('status', 'paid')->where('sent_at', '>=', $since)->count();
        $revenue  = (clone $base)->where('status', 'paid')->where('sent_at', '>=', $since)->sum('total_cents');
        $avgMins  = (int) (clone $base)->where('status', 'paid')->where('sent_at', '>=', $since)
            ->get(['offer_from', 'extend_to'])
            ->avg(fn ($o) => $o->offer_from && $o->extend_to ? $o->offer_from->diffInMinutes($o->extend_to) : null);

        $filter = $request->query('filter', 'all');
        $offers = (clone $base)
            ->with(['rental.customer', 'rental.lines' => fn ($q) => $q->where('kind', 'unit')])
            ->when($filter === 'accepted', fn ($q) => $q->where('status', 'paid'))
            ->when($filter === 'dead',     fn ($q) => $q->whereIn('status', ['declined', 'expired', 'cancelled']))
            ->orderByDesc('sent_at')
            ->limit(200)
            ->get();

        if ($request->query('export') === 'csv') {
            $rows = [['Sent', 'Customer', 'Unit', 'Channel', 'Discount %', 'Total', 'Status']];
            foreach ($offers as $o) {
                $rows[] = [
                    $o->sent_at?->toDateTimeString(),
                    $o->rental?->customer?->fullName() ?? '—',
                    $o->rental?->lines?->first()?->name_snapshot ?? '—',
                    $o->channel,
                    $o->discount_pct,
                    number_format($o->total_cents / 100, 2),
                    $o->status,
                ];
            }
            $csv = implode("\n", array_map(fn ($r) => implode(',', array_map(fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"', $r)), $rows));
            return response($csv, 200, [
                'Content-Type'        => 'text/csv',
                'Content-Disposition' => 'attachment; filename="extension-offers.csv"',
            ]);
        }

        return view('tenant.rentals.extension-activity', [
            'sent'     => $sent,
            'sentPrev' => $sentPrev,
            'accepted' => $accepted,
            'convPct'  => $sent > 0 ? round($accepted * 100 / $sent, 1) : 0,
            'revenue'  => (int) $revenue,
            'avgPer'   => $accepted > 0 ? (int) round($revenue / $accepted) : 0,
            'avgMins'  => $avgMins,
            'offers'   => $offers,
            'filter'   => $filter,
        ]);
    }
}
