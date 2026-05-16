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

        $list = DB::table('tenant_appointments as a')
            ->leftJoin('tenant_resources as r', 'r.id', '=', 'a.resource_id')
            ->leftJoin('tenant_users as u', 'u.id', '=', 'r.staff_user_id')
            ->where('a.tenant_id', $this->tenant->id)
            ->whereIn('a.status', self::DELIVERED_STATUSES)
            ->where('a.payment_status', 'paid')
            ->whereBetween('a.appointment_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('
                COALESCE(r.staff_user_id, "unassigned") as user_key,
                u.first_name, u.last_name, r.name as resource_name,
                COUNT(*) as appt_count,
                SUM(a.total_cents) as revenue
            ')
            ->groupBy('user_key', 'u.first_name', 'u.last_name', 'r.name')
            ->orderByDesc('revenue')
            ->get()
            ->map(function($r) {
                $name = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? ''));
                if (!$name) $name = $r->resource_name ? $r->resource_name : 'Unassigned';
                return [
                    'name'           => $name,
                    'appt_count'     => (int) $r->appt_count,
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
