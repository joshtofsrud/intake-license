<?php
// MARKER-PATCH-239

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantRentalCategory;
use App\Services\RentalAvailabilityService;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Public rental availability browse. Pick a window → see what's genuinely
 * free, grouped by category → model cards with rates and live counts.
 * Availability runs through the same RentalAvailabilityService the booking
 * lock re-verifies, and only online-bookable units count, so nothing shown
 * here can turn out to be a mirage at reservation time (PATCH-240).
 */
class RentalBrowseController extends Controller
{
    public function __construct(private readonly RentalAvailabilityService $availability) {}

    public function index(Request $request)
    {
        $tenant = tenant();
        abort_unless($tenant && $tenant->rentals_visible, 404);

        $tz = $tenant->timezone();

        // Default window: tomorrow 9 AM → 5 PM tenant-local.
        $defStart = Carbon::now($tz)->addDay()->setTime(9, 0);
        $defDue   = Carbon::now($tz)->addDay()->setTime(17, 0);

        $error = null;
        try {
            $startLocal = $request->filled('starts')
                ? Carbon::parse($request->query('starts'), $tz) : $defStart;
            $dueLocal = $request->filled('due')
                ? Carbon::parse($request->query('due'), $tz) : $defDue;
        } catch (\Throwable $e) {
            $startLocal = $defStart;
            $dueLocal   = $defDue;
            $error = 'That date didn\'t parse — showing tomorrow instead.';
        }

        if ($dueLocal->lessThanOrEqualTo($startLocal)) {
            $dueLocal = $startLocal->copy()->addHours(4);
            $error = 'Return time must be after pickup — adjusted it for you.';
        }
        if ($startLocal->lessThan(Carbon::now($tz))) {
            $error = $startLocal->isToday() ? null : 'That pickup is in the past — pick a future date.';
        }

        $units = $this->availability->availableUnits(
            $tenant->id,
            null,
            $startLocal->copy()->utc(),
            $dueLocal->copy()->utc(),
            onlineOnly: true,
        );

        // Group: category → model → [count, sizes, rates from the model].
        $categories = TenantRentalCategory::where('tenant_id', $tenant->id)
            ->whereNull('archived_at')
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name'])
            ->keyBy('id');

        $groups = [];
        foreach ($units as $u) {
            if (!$u->model) {
                continue;
            }
            $catName = $categories[$u->category_id]->name ?? 'Other';
            $key = $u->model->id;
            if (!isset($groups[$catName][$key])) {
                $groups[$catName][$key] = [
                    'model' => $u->model,
                    'count' => 0,
                    'sizes' => [],
                ];
            }
            $groups[$catName][$key]['count']++;
            if ($u->size && !in_array($u->size, $groups[$catName][$key]['sizes'], true)) {
                $groups[$catName][$key]['sizes'][] = $u->size;
            }
        }

        return view('public.rentals', [
            'groups'     => $groups,
            'startLocal' => $startLocal,
            'dueLocal'   => $dueLocal,
            'error'      => $error,
            'unitCount'  => $units->count(),
        ]);
    }
}
