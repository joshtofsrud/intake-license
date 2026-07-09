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

    /**
     * MARKER-PATCH-615 — Reports: per-person hours with regular/OT split.
     * OT is computed PER WEEK (tenant-local) against the threshold, then summed,
     * so a multi-week range doesn't wrongly treat 41h across two weeks as OT.
     */
    public function reports(Request $request)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();
        abort_unless($user->can('timeclock.manage'), 403);

        [$from, $to, $label] = $this->reportRange($request);
        $rows  = $this->hoursByPerson($tenant, $from, $to);
        $preset = $request->input('preset', 'this_month');

        $totals = [
            'regular' => array_sum(array_column($rows, 'regular')),
            'ot'      => array_sum(array_column($rows, 'ot')),
            'dt'      => array_sum(array_column($rows, 'dt')),
            'shifts'  => array_sum(array_column($rows, 'shifts')),
        ];

        return view('tenant.timeclock.reports', compact('rows', 'label', 'preset', 'totals'));
    }

    /** MARKER-PATCH-615 — CSV of the same summary. */
    public function reportsCsv(Request $request)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();
        abort_unless($user->can('timeclock.manage'), 403);

        [$from, $to, $label] = $this->reportRange($request);
        $rows = $this->hoursByPerson($tenant, $from, $to);

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Staff', 'Regular (h)', 'Overtime (h)', 'Double-time (h)', 'Total (h)', 'Shifts', 'Avg shift (h)']);
        foreach ($rows as $r) {
            $tot = $r['regular'] + $r['ot'] + ($r['dt'] ?? 0);
            fputcsv($out, [
                $r['name'],
                round($r['regular'] / 60, 2),
                round($r['ot'] / 60, 2),
                round(($r['dt'] ?? 0) / 60, 2),
                round($tot / 60, 2),
                $r['shifts'],
                $r['shifts'] ? round(($tot / $r['shifts']) / 60, 2) : 0,
            ]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        $fname = 'timesheet-' . str_replace([' ', ','], ['-', ''], strtolower($label)) . '.csv';
        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $fname . '"',
        ]);
    }

    /** MARKER-PATCH-615 — printable team report (browser print → PDF). */
    public function reportPrint(Request $request)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();
        abort_unless($user->can('timeclock.manage'), 403);

        [$from, $to, $label] = $this->reportRange($request);
        $rows = $this->hoursByPerson($tenant, $from, $to);

        return view('tenant.timeclock.report-print', [
            'tenantName' => $tenant->name,
            'rangeLabel' => $label,
            'rows'       => $rows,
            'print'      => true,
        ]);
    }

    /** MARKER-PATCH-615 — email the team report through the branded rail. */
    public function reportEmail(Request $request)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();
        abort_unless($user->can('timeclock.manage'), 403);

        $data = $request->validate(['to' => ['required', 'email']]);
        [$from, $to, $label] = $this->reportRange($request);
        $rows = $this->hoursByPerson($tenant, $from, $to);

        $html = view('tenant.timeclock.report-print', [
            'tenantName' => $tenant->name,
            'rangeLabel' => $label,
            'rows'       => $rows,
            'print'      => false,
        ])->render();

        (new \App\Services\EmailService($tenant))->sendRendered(
            'timeclock_report', $data['to'], 'Hours report — ' . $label, $html
        );

        return back()->with('success', 'Report emailed to ' . $data['to'] . '.');
    }

    /**
     * Per-person hours over [from,to], split regular/ot/dt via the jurisdiction-
     * aware OvertimeCalculator (daily + weekly + double-time + 7th-day, "greater of").
     */
    private function hoursByPerson($tenant, $fromUtc, $toUtc): array
    {
        $calc = new \App\Services\Tenant\OvertimeCalculator($tenant);

        $punches = TenantTimePunch::with('user:id,name')
            ->where('tenant_id', $tenant->id)
            ->where('clock_in_at', '>=', $fromUtc)
            ->where('clock_in_at', '<=', $toUtc)
            ->get();

        // person => ['name', days => ['Y-m-d' => minutes], shifts]
        $acc = [];
        foreach ($punches as $p) {
            $uid  = $p->tenant_user_id;
            $name = $p->user->name ?? 'Staff';
            $day  = tlocal_carbon($p->clock_in_at)->format('Y-m-d'); // tenant-local calendar day
            if (!isset($acc[$uid])) $acc[$uid] = ['name' => $name, 'days' => [], 'shifts' => 0];
            $acc[$uid]['days'][$day] = ($acc[$uid]['days'][$day] ?? 0) + $p->minutes();
            $acc[$uid]['shifts']++;
        }

        $rows = [];
        foreach ($acc as $uid => $d) {
            $split = $calc->split($d['days']);
            $rows[] = [
                'name'    => $d['name'],
                'regular' => $split['regular'],
                'ot'      => $split['ot'],
                'dt'      => $split['dt'],
                'shifts'  => $d['shifts'],
            ];
        }
        usort($rows, fn ($a, $b) => strcmp($a['name'], $b['name']));
        return $rows;
    }

    /**
     * MARKER-PATCH-616 — Approvals: pick a pay period, sign off per person, lock.
     * Gated by timeclock.approve. A locked period is the payroll source of truth.
     */
    public function approvals(Request $request)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();
        abort_unless($user->can('timeclock.approve'), 403);

        $svc     = \App\Services\Tenant\PayPeriodService::for($tenant);
        $periods = $svc->recent(8);

        // Selected period (default: current).
        $selectedId = $request->input('period');
        $period = $selectedId
            ? $periods->firstWhere('id', $selectedId) ?? $svc->current()
            : $svc->current();

        $approvals = \App\Models\Tenant\TenantTimePunchApproval::where('pay_period_id', $period->id)
            ->get()->keyBy('tenant_user_id');

        // Open/auto flags per person in this period.
        $flagRows = TenantTimePunch::where('tenant_id', $tenant->id)
            ->where('clock_in_at', '>=', $period->starts_at)
            ->where('clock_in_at', '<=', $period->ends_at)
            ->get()
            ->groupBy('tenant_user_id');

        // Build a uid-keyed view for the template (name + minutes + flags + approved).
        $people = [];
        foreach ($flagRows as $uid => $ps) {
            $mins = $ps->sum(fn ($p) => $p->minutes());
            $flags = 0;
            foreach ($ps as $p) { if (!$p->clock_out_at || $p->auto_closed) $flags++; }
            $people[$uid] = [
                'name'     => $ps->first()->user->name ?? ($ps->first()->tenant_user_id),
                'minutes'  => $mins,
                'flags'    => $flags,
                'approved' => $approvals->has($uid),
                'approver' => optional($approvals->get($uid))->approved_at,
            ];
        }
        // Include zero-punch active staff so they can be explicitly approved too.
        uasort($people, fn ($a, $b) => strcmp($a['name'], $b['name']));

        $canApproveCount = collect($people)->reject(fn ($p) => $p['approved'])->count();

        return view('tenant.timeclock.approvals', compact('periods', 'period', 'people', 'canApproveCount'));
    }

    public function approvePerson(Request $request)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();
        abort_unless($user->can('timeclock.approve'), 403);

        $data = $request->validate([
            'pay_period_id'  => ['required', 'uuid'],
            'tenant_user_id' => ['required', 'uuid'],
        ]);

        $period = \App\Models\Tenant\TenantPayPeriod::where('tenant_id', $tenant->id)
            ->where('id', $data['pay_period_id'])->firstOrFail();
        abort_if($period->isLocked(), 422, 'Period is locked.');

        $mins = TenantTimePunch::where('tenant_id', $tenant->id)
            ->where('tenant_user_id', $data['tenant_user_id'])
            ->where('clock_in_at', '>=', $period->starts_at)
            ->where('clock_in_at', '<=', $period->ends_at)
            ->get()->sum(fn ($p) => $p->minutes());

        \App\Models\Tenant\TenantTimePunchApproval::updateOrCreate(
            ['pay_period_id' => $period->id, 'tenant_user_id' => $data['tenant_user_id']],
            ['tenant_id' => $tenant->id, 'approved_by' => $user->id, 'approved_at' => now(), 'minutes_at_approval' => $mins]
        );

        return back()->with('success', 'Approved.');
    }

    public function lockPeriod(Request $request)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();
        abort_unless($user->can('timeclock.approve'), 403);

        $period = \App\Models\Tenant\TenantPayPeriod::where('tenant_id', $tenant->id)
            ->where('id', $request->input('pay_period_id'))->firstOrFail();

        $period->update(['status' => 'locked', 'locked_at' => now(), 'locked_by' => $user->id]);

        \App\Models\Tenant\TenantTimePunchAudit::log(
            $tenant->id, null, null, $user->id, 'period_locked',
            'Locked pay period ' . tlocal_date($period->starts_at) . '–' . tlocal_date($period->ends_at)
        );

        return back()->with('success', 'Pay period locked. It is now the payroll record.');
    }

    public function reopenPeriod(Request $request)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();
        abort_unless($user->can('timeclock.approve'), 403);

        $data = $request->validate([
            'pay_period_id' => ['required', 'uuid'],
            'reason'        => ['required', 'string', 'max:500'],
        ]);

        $period = \App\Models\Tenant\TenantPayPeriod::where('tenant_id', $tenant->id)
            ->where('id', $data['pay_period_id'])->firstOrFail();

        $period->update(['status' => 'open', 'reopen_reason' => $data['reason']]);

        \App\Models\Tenant\TenantTimePunchAudit::log(
            $tenant->id, null, null, $user->id, 'period_reopened',
            'Reopened locked period — ' . $data['reason']
        );

        return back()->with('success', 'Period reopened.');
    }

    /** MARKER-PATCH-616 — save time-clock policy settings. */
    public function saveSettings(Request $request)
    {
        $tenant = tenant();
        $user   = Auth::guard('tenant')->user();
        abort_unless($user->can('timeclock.approve'), 403);

        $data = $request->validate([
            'timeclock_pay_cycle'         => ['required', 'in:weekly,biweekly,semi_monthly,monthly'],
            'timeclock_ot_weekly_hours'   => ['required', 'integer', 'min:0', 'max:168'],
            'timeclock_ot_daily_hours'    => ['required', 'integer', 'min:0', 'max:24'],
            'timeclock_dt_daily_hours'    => ['required', 'integer', 'min:0', 'max:24'],
            'timeclock_seventh_day_rule'  => ['nullable', 'boolean'],
            'timeclock_autoclose_hours'   => ['required', 'integer', 'min:1', 'max:48'],
        ]);
        $data['timeclock_seventh_day_rule'] = $request->boolean('timeclock_seventh_day_rule');
        // Keep legacy key in sync so anything still reading it stays correct.
        $data['timeclock_ot_threshold_hours'] = $data['timeclock_ot_weekly_hours'];

        $settings = $tenant->settings ?? [];
        foreach ($data as $k => $v) $settings[$k] = $v;
        $tenant->update(['settings' => $settings]);

        return back()->with('success', 'Time clock settings saved.');
    }

    /** Report range presets → UTC instants. */
    private function reportRange(Request $request): array
    {
        $tz = tenant()->timezone();
        $preset = $request->input('preset', 'this_month');

        switch ($preset) {
            case 'this_week':
                $from = tnow()->startOfWeek();  $to = tnow()->endOfWeek();
                $label = 'This week · ' . $from->format('M j') . '–' . $to->format('M j'); break;
            case 'last_week':
                $from = tnow()->subWeek()->startOfWeek(); $to = tnow()->subWeek()->endOfWeek();
                $label = 'Last week · ' . $from->format('M j') . '–' . $to->format('M j'); break;
            case 'last_month':
                $from = tnow()->subMonthNoOverflow()->startOfMonth(); $to = tnow()->subMonthNoOverflow()->endOfMonth();
                $label = $from->format('F Y'); break;
            case 'custom':
                $from = \Carbon\Carbon::parse($request->input('from', tnow()->startOfMonth()->toDateString()), $tz)->startOfDay();
                $to   = \Carbon\Carbon::parse($request->input('to_date', tnow()->toDateString()), $tz)->endOfDay();
                $label = $from->format('M j') . ' – ' . $to->format('M j, Y'); break;
            case 'this_month':
            default:
                $from = tnow()->startOfMonth(); $to = tnow()->endOfMonth();
                $label = $from->format('F Y'); break;
        }
        return [$from->copy()->utc(), $to->copy()->utc(), $label];
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

