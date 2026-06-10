<?php
// MARKER-PATCH-223

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantRental;
use App\Models\Tenant\TenantRentalCategory;
use App\Models\Tenant\TenantRentalUnit;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Fleet-wide availability timeline. Renders the same world view as
 * RentalAvailabilityService: bars are reserved/out rentals (overdue 'out'
 * bars roll forward to now) plus maintenance; empty track = bookable.
 * All bar math happens in tenant-local minutes; storage stays UTC.
 */
class RentalAvailabilityTimelineController extends Controller
{
    private const DAYS = 7;

    public function index(Request $request)
    {
        $tenant = tenant();
        $tz     = $tenant->timezone();

        $startParam = $request->query('start');
        $winStartLocal = $startParam
            ? Carbon::parse($startParam, $tz)->startOfDay()
            : Carbon::now($tz)->startOfDay();
        $winEndLocal = $winStartLocal->copy()->addDays(self::DAYS);

        $winStartUtc = $winStartLocal->copy()->utc();
        $winEndUtc   = $winEndLocal->copy()->utc();
        $totalMin    = self::DAYS * 24 * 60;
        $nowUtc      = now();

        $categoryId = $request->query('category');

        $categories = TenantRentalCategory::where('tenant_id', $tenant->id)
            ->whereNull('archived_at')
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name']);

        $units = TenantRentalUnit::where('tenant_id', $tenant->id)
            ->whereNull('archived_at')
            ->where('status', '!=', 'retired')
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->with('category:id,name')
            ->orderBy('name')
            ->limit(60)
            ->get();

        // All bars in one query: reserved/out rentals overlapping the window,
        // with their unit lines + customer, grouped per unit afterwards.
        $rentals = TenantRental::where('tenant_id', $tenant->id)
            ->whereIn('status', ['reserved', 'out'])
            ->where('starts_at', '<', $winEndUtc)
            ->where(function ($q) use ($winStartUtc, $nowUtc) {
                // Ends inside/after the window — where an overdue 'out'
                // rental's effective end is NOW (rolls forward), so when
                // "now" is inside/after the window start, overdue rows
                // qualify even though their due_at is in the past.
                $q->where('due_at', '>', $winStartUtc);
                if ($nowUtc->greaterThan($winStartUtc)) {
                    $q->orWhere(function ($qq) use ($nowUtc) {
                        $qq->where('status', 'out')
                           ->whereNull('returned_at')
                           ->where('due_at', '<', $nowUtc);
                    });
                }
            })
            ->with([
                'customer:id,first_name,last_name',
                'lines' => fn ($q) => $q->where('kind', 'unit'),
            ])
            ->get();

        $barsByUnit = [];
        foreach ($rentals as $rental) {
            $isOverdue = $rental->status === 'out'
                && $rental->returned_at === null
                && $rental->due_at !== null
                && $rental->due_at->lessThan($nowUtc);

            $effEndUtc = $isOverdue ? $nowUtc->copy() : $rental->due_at->copy();

            // Clamp to the window (UTC), then convert to local minutes.
            $barStartUtc = $rental->starts_at->greaterThan($winStartUtc) ? $rental->starts_at->copy() : $winStartUtc->copy();
            $barEndUtc   = $effEndUtc->lessThan($winEndUtc) ? $effEndUtc : $winEndUtc->copy();
            if ($barEndUtc->lessThanOrEqualTo($barStartUtc)) {
                continue;
            }

            $leftMin  = $winStartUtc->diffInMinutes($barStartUtc);
            $widthMin = $barStartUtc->diffInMinutes($barEndUtc);

            $name = trim(($rental->customer?->first_name ?? '') . ' ' . mb_substr((string) $rental->customer?->last_name, 0, 1));
            $type = $isOverdue ? 'overdue' : $rental->status; // overdue | out | reserved
            $label = $name !== '' ? $name . ($isOverdue ? ' — overdue' : '') : ucfirst($type);

            foreach ($rental->lines as $line) {
                if (!$line->unit_id) {
                    continue;
                }
                $barsByUnit[$line->unit_id][] = [
                    'left'  => round($leftMin / $totalMin * 100, 3),
                    'width' => max(0.8, round($widthMin / $totalMin * 100, 3)),
                    'type'  => $type,
                    'label' => $label,
                    'href'  => route('tenant.rentals.bookings.show', $rental->id),
                ];
            }
        }

        $rows = $units->map(function (TenantRentalUnit $u) use ($barsByUnit) {
            $bars = $barsByUnit[$u->id] ?? [];
            if ($u->status === 'maintenance') {
                $bars = [[
                    'left' => 0, 'width' => 100, 'type' => 'maint',
                    'label' => 'In maintenance', 'href' => route('tenant.rentals.fleet'),
                ]];
            }
            return [
                'id'   => $u->id,
                'name' => $u->name,
                'sub'  => trim(($u->identifier ? $u->identifier . ' · ' : '') . ($u->category?->name ?? '')),
                'bars' => $bars,
            ];
        });

        // Day headers + weekend flags, tenant-local.
        $days = [];
        for ($i = 0; $i < self::DAYS; $i++) {
            $d = $winStartLocal->copy()->addDays($i);
            $days[] = ['label' => $d->format('D j'), 'weekend' => $d->isWeekend()];
        }

        return view('tenant.rentals.availability', [
            'rows'        => $rows,
            'days'        => $days,
            'categories'  => $categories,
            'categoryId'  => $categoryId,
            'winStart'    => $winStartLocal,
            'prevStart'   => $winStartLocal->copy()->subDays(self::DAYS)->toDateString(),
            'nextStart'   => $winStartLocal->copy()->addDays(self::DAYS)->toDateString(),
            'rangeLabel'  => $winStartLocal->format('M j') . ' – ' . $winEndLocal->copy()->subDay()->format('M j'),
            'unitsShown'  => $units->count(),
        ]);
    }
}
