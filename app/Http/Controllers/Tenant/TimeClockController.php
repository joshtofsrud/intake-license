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

    /**
     * MARKER-PATCH-614 — Team timesheet (manager grid). Gated by timeclock.manage.
     * Week of staff x days, with per-person totals and open/flag markers.
     */
    public function team(Request $request)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();
        abort_unless($user->can('timeclock.manage'), 403);

        // Week window (tenant-local), navigable via ?week=YYYY-MM-DD (any day in it).
        $anchor = $request->filled('week')
            ? \Carbon\Carbon::parse($request->input('week'), $tenant->timezone())
            : tnow();
        $weekStart = $anchor->copy()->startOfWeek();
        $weekEnd   = $anchor->copy()->endOfWeek();
        $fromUtc   = $weekStart->copy()->utc();
        $toUtc     = $weekEnd->copy()->utc();

        $staff = \App\Models\Tenant\TenantUser::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'role']);

        $punches = TenantTimePunch::where('tenant_id', $tenant->id)
            ->where('clock_in_at', '>=', $fromUtc)
            ->where('clock_in_at', '<=', $toUtc)
            ->orderBy('clock_in_at')
            ->get();

        // Group by user, then by tenant-local day index (0..6 from weekStart).
        $byUser = [];
        foreach ($staff as $m) {
            $byUser[$m->id] = ['name' => $m->name, 'role' => $m->role, 'days' => array_fill(0, 7, 0), 'flags' => array_fill(0, 7, null), 'total' => 0];
        }
        foreach ($punches as $p) {
            if (!isset($byUser[$p->tenant_user_id])) continue;
            $localDay = (int) tlocal_carbon($p->clock_in_at)->startOfDay()->diffInDays($weekStart->copy()->startOfDay());
            $idx = max(0, min(6, $localDay));
            $mins = $p->minutes();
            $byUser[$p->tenant_user_id]['days'][$idx] += $mins;
            $byUser[$p->tenant_user_id]['total'] += $mins;
            if (!$p->clock_out_at)   $byUser[$p->tenant_user_id]['flags'][$idx] = 'open';
            elseif ($p->auto_closed) $byUser[$p->tenant_user_id]['flags'][$idx] = 'auto';
        }

        $canEdit = $user->can('timeclock.edit');
        $days = [];
        for ($i = 0; $i < 7; $i++) $days[] = $weekStart->copy()->addDays($i);

        // Recent audit trail (last 20).
        $audits = \App\Models\Tenant\TenantTimePunchAudit::with('actor:id,name')
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return view('tenant.timeclock.team', compact('byUser', 'days', 'weekStart', 'canEdit', 'audits'));
    }

    /** MARKER-PATCH-614 — edit a punch (in/out/break) with a required reason. */
    public function editPunch(Request $request, string $punchId)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();
        abort_unless($user->can('timeclock.edit'), 403);

        $punch = TenantTimePunch::where('tenant_id', $tenant->id)->where('id', $punchId)->firstOrFail();

        $data = $request->validate([
            'clock_in_at'   => ['required', 'date'],
            'clock_out_at'  => ['nullable', 'date'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'reason'        => ['required', 'string', 'max:500'],
        ]);

        $tz = $tenant->timezone();
        $punch->update([
            'clock_in_at'   => \Carbon\Carbon::parse($data['clock_in_at'], $tz)->utc(),
            'clock_out_at'  => $data['clock_out_at'] ? \Carbon\Carbon::parse($data['clock_out_at'], $tz)->utc() : null,
            'break_minutes' => (int) ($data['break_minutes'] ?? 0),
            'auto_closed'   => false,
            'edited_by'     => $user->id,
            'edit_reason'   => $data['reason'],
            'edited_at'     => now(),
        ]);

        \App\Models\Tenant\TenantTimePunchAudit::log(
            $tenant->id, $punch->id, $punch->tenant_user_id, $user->id,
            'edited', 'Edited punch — ' . $data['reason']
        );

        return back()->with('success', 'Punch updated.');
    }

    /** MARKER-PATCH-614 — create a punch for someone (forgotten clock-in). */
    public function createPunch(Request $request)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();
        abort_unless($user->can('timeclock.edit'), 403);

        $data = $request->validate([
            'tenant_user_id' => ['required', 'uuid'],
            'clock_in_at'    => ['required', 'date'],
            'clock_out_at'   => ['nullable', 'date'],
            'reason'         => ['required', 'string', 'max:500'],
        ]);

        $tz = $tenant->timezone();
        $punch = TenantTimePunch::create([
            'tenant_id'      => $tenant->id,
            'tenant_user_id' => $data['tenant_user_id'],
            'clock_in_at'    => \Carbon\Carbon::parse($data['clock_in_at'], $tz)->utc(),
            'clock_out_at'   => $data['clock_out_at'] ? \Carbon\Carbon::parse($data['clock_out_at'], $tz)->utc() : null,
            'source'         => 'manual',
            'created_by'     => $user->id,
            'edited_by'      => $user->id,
            'edit_reason'    => $data['reason'],
            'edited_at'      => now(),
        ]);

        \App\Models\Tenant\TenantTimePunchAudit::log(
            $tenant->id, $punch->id, $punch->tenant_user_id, $user->id,
            'created', 'Added punch manually — ' . $data['reason']
        );

        return back()->with('success', 'Punch added.');
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

