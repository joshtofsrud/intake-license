<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * StaffReportService — Staff & Resources tab analytics.
 *
 * Real panels:
 *   - bookingDensity()  : appointments per resource per day
 *   - revenueByStaff()  : via resource→staff_user_id linkage on tenant_resources
 *
 * Stub panels (need assigned_staff_id on appointments OR working-hours schema):
 *   - utilization()     : need per-resource working hours to compute %
 *   - servicesByStaff() : need assigned_staff_id on appointments
 *   - tipsByStaff()     : need tip tracking schema
 */
class StaffReportService
{
    private const DELIVERED_STATUSES = ['in_progress', 'completed', 'shipped', 'closed'];

    public function __construct(private readonly Tenant $tenant) {}

    public function bookingDensity(Carbon $from, Carbon $to, bool $aggregatesOnly = false): array
    {
        $rangeDays = $from->diffInDays($to) + 1;

        $totalAppts = (int) DB::table('tenant_appointments')
            ->where('tenant_id', $this->tenant->id)
            ->whereIn('status', self::DELIVERED_STATUSES)
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('resource_id')
            ->count();

        if ($aggregatesOnly) {
            return [
                'total_appts'  => $totalAppts,
                'range_days'   => $rangeDays,
                'list'         => [],
            ];
        }

        $list = DB::table('tenant_appointments as a')
            ->leftJoin('tenant_resources as r', 'r.id', '=', 'a.resource_id')
            ->where('a.tenant_id', $this->tenant->id)
            ->whereIn('a.status', self::DELIVERED_STATUSES)
            ->whereBetween('a.appointment_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('a.resource_id')
            ->selectRaw('
                a.resource_id,
                r.name as resource_name,
                r.type as resource_type,
                COUNT(*) as appt_count
            ')
            ->groupBy('a.resource_id', 'r.name', 'r.type')
            ->orderByDesc('appt_count')
            ->get()
            ->map(fn($r) => [
                'resource_name'  => $r->resource_name ?? '(deleted)',
                'resource_type'  => $r->resource_type ?? 'unknown',
                'appt_count'     => (int) $r->appt_count,
                'appts_per_day'  => $rangeDays > 0 ? round((int)$r->appt_count / $rangeDays, 1) : 0,
            ])
            ->all();

        return [
            'total_appts' => $totalAppts,
            'range_days'  => $rangeDays,
            'list'        => $list,
        ];
    }

    /**
     * Revenue by staff — via the resource→staff_user_id linkage.
     * PRODUCT DECISION: this works for resources of type='staff' that have
     * staff_user_id set. Resources without a linked user (slot, space) are
     * lumped under "Unassigned".
     */
    public function revenueByStaff(Carbon $from, Carbon $to, bool $aggregatesOnly = false): array
    {
        if ($aggregatesOnly) {
            return ['list' => []];
        }

        // tenant_users has only `name` — no first_name/last_name. When a resource
        // is staff-linked we surface the user name; otherwise fall back to the
        // resource name; otherwise the user is "Unassigned".
        // MARKER-PATCH-185 — per-staff revenue = payments received (sale ledger),
        // attributed via payment -> sale -> appointment -> resource -> staff_user.
        // appt_count stays appointment-based (delivered jobs). recorded_at is UTC.
        $tzSt = $this->tenant->timezone();
        $stStart = $from->copy()->setTimezone($tzSt)->startOfDay()->utc();
        $stEnd   = $to->copy()->setTimezone($tzSt)->endOfDay()->utc();

        $apptCounts = DB::table('tenant_appointments as a')
            ->leftJoin('tenant_resources as r', 'r.id', '=', 'a.resource_id')
            ->where('a.tenant_id', $this->tenant->id)
            ->whereIn('a.status', self::DELIVERED_STATUSES)
            ->whereBetween('a.appointment_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COALESCE(r.staff_user_id, "unassigned") as user_key, COUNT(*) as appt_count')
            ->groupBy('user_key')
            ->pluck('appt_count', 'user_key');

        $list = DB::table('tenant_sale_payments as tsp')
            ->join('tenant_sales as ts', 'ts.id', '=', 'tsp.sale_id')
            ->join('tenant_appointments as a', 'a.id', '=', 'ts.appointment_id')
            ->leftJoin('tenant_resources as r', 'r.id', '=', 'a.resource_id')
            ->leftJoin('tenant_users as u', 'u.id', '=', 'r.staff_user_id')
            ->where('tsp.tenant_id', $this->tenant->id)
            ->whereBetween('tsp.recorded_at', [$stStart, $stEnd])
            ->selectRaw('
                COALESCE(r.staff_user_id, "unassigned") as user_key,
                u.name as user_name,
                r.name as resource_name,
                SUM(tsp.amount_cents) as revenue
            ')
            ->groupBy('user_key', 'u.name', 'r.name')
            ->orderByDesc('revenue')
            ->get()
            ->map(function($r) use ($apptCounts) {
                $name = $r->user_name ?: ($r->resource_name ?: 'Unassigned');
                return [
                    'name'           => $name,
                    'appt_count'     => (int) ($apptCounts[$r->user_key] ?? 0),
                    'revenue_cents'  => (int) $r->revenue,
                ];
            })
            ->all();

        return ['list' => $list];
    }

    public function utilization(): array
    {
        return [
            'is_stub' => true,
            'reason'  => 'Awaiting per-resource working hours schema. Coming with staff scheduling.',
        ];
    }

    public function servicesByStaff(): array
    {
        return [
            'is_stub' => true,
            'reason'  => 'Awaiting assigned_staff_id on appointments (pre-launch feature). Coming soon.',
        ];
    }

    public function tipsByStaff(): array
    {
        return [
            'is_stub' => true,
            'reason'  => 'Awaiting tip-tracking schema.',
        ];
    }
}
