<?php
// MARKER-PATCH-610 / 613 — time clock: clock in/out, My Time history, print/email.

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantTimePunch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TimeClockController extends Controller
{
    public function index(Request $request)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();

        $open = TenantTimePunch::openFor($tenant->id, $user->id);

        // Today's punches (tenant-local day via tnow()).
        $dayStart = tnow()->startOfDay()->utc();
        $mine = TenantTimePunch::where('tenant_id', $tenant->id)
            ->where('tenant_user_id', $user->id)
            ->where('clock_in_at', '>=', $dayStart)
            ->orderByDesc('clock_in_at')
            ->get();

        // Roster: everyone on the clock now (a wall clock — visible to all).
        $onClock = TenantTimePunch::with('user:id,name')
            ->where('tenant_id', $tenant->id)
            ->whereNull('clock_out_at')
            ->orderBy('clock_in_at')
            ->get();

        $todayMinutes = $mine->sum(fn ($p) => $p->minutes());

        // MARKER-PATCH-613 — My Time: history + rolling totals (pay-period-aware
        // totals arrive with the pay-period settings in a later stage).
        $weekStart  = tnow()->startOfWeek()->utc();
        $monthStart = tnow()->startOfMonth()->utc();

        $history = TenantTimePunch::where('tenant_id', $tenant->id)
            ->where('tenant_user_id', $user->id)
            ->orderByDesc('clock_in_at')
            ->limit(60)
            ->get();

        $weekMinutes  = $history->where('clock_in_at', '>=', $weekStart)->sum(fn ($p) => $p->minutes());
        $monthMinutes = $history->where('clock_in_at', '>=', $monthStart)->sum(fn ($p) => $p->minutes());

        return view('tenant.timeclock.index', compact(
            'open', 'mine', 'onClock', 'todayMinutes',
            'history', 'weekMinutes', 'monthMinutes'
        ));
    }

    public function punchIn(Request $request)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();

        if (TenantTimePunch::openFor($tenant->id, $user->id)) {
            return back()->with('error', 'You are already clocked in.');
        }

        TenantTimePunch::create([
            'tenant_id'      => $tenant->id,
            'tenant_user_id' => $user->id,
            'location_id'    => session('current_location_id'),
            'clock_in_at'    => now(),
            'source'         => $request->input('source', 'page'),
            'created_by'     => $user->id,
        ]);

        return back()->with('success', 'Clocked in — have a good shift.');
    }

    public function punchOut(Request $request)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();

        $open = TenantTimePunch::openFor($tenant->id, $user->id);
        if (! $open) {
            return back()->with('error', 'You are not clocked in.');
        }

        $open->update(['clock_out_at' => now()]);

        $mins = $open->minutes();
        return back()->with('success', 'Clocked out — ' . intdiv($mins, 60) . 'h ' . ($mins % 60) . 'm this shift.');
    }

    /**
     * MARKER-PATCH-613 — printable timesheet (browser print → PDF).
     * Range defaults to the current month; ?from=&to= override (tenant-local dates).
     */
    public function timesheet(Request $request)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();

        [$from, $to, $label] = $this->range($request);

        $punches = TenantTimePunch::where('tenant_id', $tenant->id)
            ->where('tenant_user_id', $user->id)
            ->where('clock_in_at', '>=', $from)
            ->where('clock_in_at', '<=', $to)
            ->orderBy('clock_in_at')
            ->get();

        $totalMinutes = $punches->sum(fn ($p) => $p->minutes());

        return view('tenant.timeclock.timesheet', [
            'staffName'    => $user->name,
            'tenantName'   => $tenant->name,
            'rangeLabel'   => $label,
            'punches'      => $punches,
            'totalMinutes' => $totalMinutes,
            'print'        => true,
        ]);
    }

    /** MARKER-PATCH-613 — email my timesheet through the branded Postmark rail. */
    public function emailTimesheet(Request $request)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();

        $data = $request->validate([
            'to'   => ['required', 'email'],
            'from' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
        ]);

        [$from, $to, $label] = $this->range($request);

        $punches = TenantTimePunch::where('tenant_id', $tenant->id)
            ->where('tenant_user_id', $user->id)
            ->where('clock_in_at', '>=', $from)
            ->where('clock_in_at', '<=', $to)
            ->orderBy('clock_in_at')
            ->get();

        $totalMinutes = $punches->sum(fn ($p) => $p->minutes());

        $html = view('tenant.timeclock.timesheet', [
            'staffName'    => $user->name,
            'tenantName'   => $tenant->name,
            'rangeLabel'   => $label,
            'punches'      => $punches,
            'totalMinutes' => $totalMinutes,
            'print'        => false,
        ])->render();

        (new \App\Services\EmailService($tenant))->sendRendered(
            'timesheet',
            $data['to'],
            'Timesheet — ' . $user->name . ' — ' . $label,
            $html
        );

        return back()->with('success', 'Timesheet emailed to ' . $data['to'] . '.');
    }

    /** Resolve a tenant-local date range to UTC instants. */
    private function range(Request $request): array
    {
        $tz = tenant()->timezone();

        if ($request->filled('from') && $request->filled('to_date')) {
            $from  = \Carbon\Carbon::parse($request->input('from'), $tz)->startOfDay();
            $to    = \Carbon\Carbon::parse($request->input('to_date'), $tz)->endOfDay();
            $label = $from->format('M j') . ' – ' . $to->format('M j, Y');
        } else {
            $from  = tnow()->startOfMonth();
            $to    = tnow()->endOfMonth();
            $label = $from->format('F Y');
        }

        return [$from->copy()->utc(), $to->copy()->utc(), $label];
    }
}

