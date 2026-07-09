<?php
// MARKER-PATCH-610 — time clock: page + punch endpoints.

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

        // Today's punches for the signed-in user (tenant-local day via tnow()).
        $dayStart = tnow()->startOfDay()->utc();
        $mine = TenantTimePunch::where('tenant_id', $tenant->id)
            ->where('tenant_user_id', $user->id)
            ->where('clock_in_at', '>=', $dayStart)
            ->orderByDesc('clock_in_at')
            ->get();

        // Roster: everyone currently on the clock (visible to all — it's a wall clock).
        $onClock = TenantTimePunch::with('user:id,name')
            ->where('tenant_id', $tenant->id)
            ->whereNull('clock_out_at')
            ->orderBy('clock_in_at')
            ->get();

        $todayMinutes = $mine->sum(fn ($p) => $p->minutes());

        return view('tenant.timeclock.index', compact('open', 'mine', 'onClock', 'todayMinutes'));
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
            'clock_in_at'    => now(),   // UTC instant by design (datetime cast)
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
        $h = intdiv($mins, 60); $m = $mins % 60;

        return back()->with('success', "Clocked out — {$h}h {$m}m this shift.");
    }
}

