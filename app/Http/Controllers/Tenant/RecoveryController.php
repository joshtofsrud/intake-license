<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantAbandonedBooking;
use App\Models\Tenant\TenantFunnelEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * RecoveryController — Engage → Recovery. MARKER-PATCH-450.
 *
 * Surfaces the booking funnel (anonymous sessions, last 30 days) and the
 * abandoned-booking worklist (people who left contact info but didn't finish),
 * so the tenant can follow up and mark them handled.
 */
class RecoveryController extends Controller
{
    public function index()
    {
        $tenant = tenant();

        // Booking funnel — distinct sessions reaching each stage in the last 30
        // days. created_at is a UTC instant, so the rolling window uses UTC now.
        $since = now()->subDays(30);

        $stages = TenantFunnelEvent::query()
            ->where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $since)
            ->whereIn('event_type', [
                TenantFunnelEvent::TYPE_BOOKING_PAGE_VIEWED,
                TenantFunnelEvent::TYPE_BOOKING_STARTED,
                TenantFunnelEvent::TYPE_BOOKING_COMPLETED,
            ])
            ->select('event_type', DB::raw('COUNT(DISTINCT session_id) AS sessions'))
            ->groupBy('event_type')
            ->pluck('sessions', 'event_type');

        $viewed    = (int) ($stages[TenantFunnelEvent::TYPE_BOOKING_PAGE_VIEWED] ?? 0);
        $started   = (int) ($stages[TenantFunnelEvent::TYPE_BOOKING_STARTED] ?? 0);
        $completed = (int) ($stages[TenantFunnelEvent::TYPE_BOOKING_COMPLETED] ?? 0);

        $funnel = [
            'viewed'      => $viewed,
            'started'     => $started,
            'completed'   => $completed,
            'pct_start'   => $viewed  > 0 ? (int) round($started   / $viewed  * 100) : 0,
            'pct_finish'  => $started > 0 ? (int) round($completed / $started * 100) : 0,
            'pct_overall' => $viewed  > 0 ? (int) round($completed / $viewed  * 100) : 0,
        ];

        // Step-by-step drop-off — distinct sessions reaching each wizard step
        // (booking_step events). The stored key is "NN label", so ordering by it
        // follows the wizard order; strip the prefix for display.
        $stepRows = TenantFunnelEvent::query()
            ->where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $since)
            ->where('event_type', TenantFunnelEvent::TYPE_BOOKING_STEP)
            ->whereNotNull('step')
            ->select('step', DB::raw('COUNT(DISTINCT session_id) AS sessions'))
            ->groupBy('step')
            ->orderBy('step')
            ->get();

        $first = (int) ($stepRows->first()->sessions ?? 0);
        $prev  = null;
        $steps = [];
        foreach ($stepRows as $r) {
            $sessions = (int) $r->sessions;
            $label = trim(preg_replace('/^\d+\s/', '', $r->step));
            $steps[] = [
                'label'    => $label !== '' ? $label : $r->step,
                'sessions' => $sessions,
                'width'    => $first > 0 ? max(4, (int) round($sessions / $first * 100)) : 0,
                'drop'     => ($prev !== null && $prev > 0 && $sessions < $prev)
                                ? (int) round(($prev - $sessions) / $prev * 100)
                                : null,
            ];
            $prev = $sessions;
        }

        // Abandoned worklist — open rows to follow up, plus recently handled.
        $open = TenantAbandonedBooking::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'open')
            ->orderByDesc('created_at')
            ->get();

        $handled = TenantAbandonedBooking::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['contacted', 'converted'])
            ->orderByDesc('updated_at')
            ->limit(25)
            ->get();

        return view('tenant.recovery.index', compact('funnel', 'steps', 'open', 'handled', 'since'));
    }

    public function updateStatus(Request $request, string $id)
    {
        $tenant = tenant();

        $data = $request->validate([
            'status' => ['required', 'in:open,contacted,converted,dismissed'],
        ]);

        $row = TenantAbandonedBooking::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($id);

        $row->status = $data['status'];
        if ($data['status'] === 'contacted' && ! $row->contacted_at) {
            $row->contacted_at = now();
        }
        $row->save();

        return back()->with('success', 'Recovery list updated.');
    }
}
