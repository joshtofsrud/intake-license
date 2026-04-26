<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantCalendarBreak;
use App\Models\Tenant\TenantCapacityRule;
use App\Models\Tenant\TenantResource;
use App\Models\Tenant\TenantWalkinHold;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CalendarController extends Controller
{
    /**
     * Calendar index — routes to day/week/month based on ?view= query param.
     * Default: day. Each view loads its own data shape via a private helper
     * and renders a shared shell that includes the appropriate grid partial.
     *
     * Resource filtering and tenant-local "today" math are shared across
     * all three views via the helpers below.
     */
    public function index(Request $request)
    {
        $view = $request->query('view', 'day');
        if (!in_array($view, ['day', 'week', 'month'], true)) {
            $view = 'day';
        }

        return match ($view) {
            'week'  => $this->weekView($request),
            'month' => $this->monthView($request),
            default => $this->dayView($request),
        };
    }

    /* ============================================================
     * Day view — high-fidelity time-axis grid with resource columns
     * ============================================================ */
    protected function dayView(Request $request)
    {
        $tenant = tenant();
        $tz     = $tenant->timezone();

        $date = $this->resolveDate($request->query('date'), $tenant);
        $dateStr = $date->toDateString();

        $prevDate = $date->copy()->subDay()->toDateString();
        $nextDate = $date->copy()->addDay()->toDateString();
        $todayStr = $tenant->localToday()->toDateString();
        $isToday  = $dateStr === $todayStr;

        [$allResources, $resources, $visibleIds, $myResource, $filterMode]
            = $this->resolveResources($tenant, $request);

        $rule = TenantCapacityRule::where('tenant_id', $tenant->id)
            ->where('rule_type', 'default')
            ->where('day_of_week', $date->dayOfWeek)
            ->first();

        $hasRule  = $rule && $rule->open_time && $rule->close_time;
        $openMin  = $hasRule ? $this->timeToMinutes($rule->open_time)  : 9 * 60;
        $closeMin = $hasRule ? $this->timeToMinutes($rule->close_time) : 17 * 60;
        $slotMin  = max((int) ($rule->slot_interval_minutes ?? 30), 15);

        $appointments = TenantAppointment::query()
            ->where('tenant_id', $tenant->id)
            ->where('appointment_date', $dateStr)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->whereNotNull('appointment_time')
            ->whereIn('resource_id', $visibleIds)
            ->with(['items:id,appointment_id,item_name_snapshot,duration_minutes_snapshot,prep_before_minutes_snapshot,cleanup_after_minutes_snapshot'])
            ->orderBy('appointment_time')
            ->get([
                'id', 'resource_id', 'customer_first_name', 'customer_last_name',
                'appointment_time', 'appointment_end_time', 'total_duration_minutes',
                'status', 'total_cents', 'needs_time_review',
            ]);

        $breakWindows = $this->collectBreakWindows($tenant->id, $date);
        $holdWindows  = $this->collectHoldWindows($tenant->id, $date);

        // Customer prefill — when the calendar is opened from the customer
        // detail page via "+ New appointment", auto-open the QuickBook modal
        // with the customer pre-selected. Falls back to no prefill on bad ids.
        $prefillCustomer = null;
        $customerIdParam = $request->query('customer_id');
        if ($customerIdParam) {
            $c = \App\Models\Tenant\TenantCustomer::where('tenant_id', $tenant->id)
                ->where('id', $customerIdParam)
                ->first(['id', 'first_name', 'last_name', 'email', 'phone']);
            if ($c) {
                $prefillCustomer = [
                    'id'         => $c->id,
                    'first_name' => $c->first_name,
                    'last_name'  => $c->last_name,
                    'email'      => $c->email,
                    'phone'      => $c->phone,
                ];
            }
        }

        return view('tenant.calendar.index', [
            'viewMode'      => 'day',
            'date'          => $date,
            'dateStr'       => $dateStr,
            'prevDate'      => $prevDate,
            'nextDate'      => $nextDate,
            'todayStr'      => $todayStr,
            'isToday'       => $isToday,
            'resources'     => $resources,
            'allResources'  => $allResources,
            'myResource'    => $myResource,
            'filterMode'    => $filterMode,
            'hasRule'       => $hasRule,
            'openMin'       => $openMin,
            'closeMin'      => $closeMin,
            'slotMin'       => $slotMin,
            'appointments'  => $appointments,
            'breakWindows'  => $breakWindows,
            'holdWindows'   => $holdWindows,
            'prefillCustomer' => $prefillCustomer,
        ]);
    }

    /* ============================================================
     * Week view — per-resource swimlanes, 7 day-columns Sun–Sat
     * Compact-list rendering. No continuous time axis; appointments
     * stack inside their day-cell ordered by appointment_time.
     * ============================================================ */
    protected function weekView(Request $request)
    {
        $tenant = tenant();

        // Sunday-anchored week containing the requested date.
        $anchor      = $this->resolveDate($request->query('date'), $tenant);
        $weekStart   = $anchor->copy()->startOfWeek(Carbon::SUNDAY);
        $weekEnd     = $weekStart->copy()->addDays(6);
        $weekStartStr = $weekStart->toDateString();
        $weekEndStr   = $weekEnd->toDateString();

        // Build the seven days for the header row.
        $days = [];
        $todayStr = $tenant->localToday()->toDateString();
        for ($i = 0; $i < 7; $i++) {
            $d = $weekStart->copy()->addDays($i);
            $ds = $d->toDateString();
            $days[] = [
                'date'     => $d,
                'dateStr'  => $ds,
                'short'    => $d->format('D'),       // Sun, Mon
                'num'      => $d->format('j'),       // 1–31
                'isToday'  => $ds === $todayStr,
                'isWeekend'=> in_array($d->dayOfWeek, [0, 6], true),
            ];
        }

        $prevDate = $weekStart->copy()->subWeek()->toDateString();
        $nextDate = $weekStart->copy()->addWeek()->toDateString();

        [$allResources, $resources, $visibleIds, $myResource, $filterMode]
            = $this->resolveResources($tenant, $request);

        // Pull all active appointments in the week range, scoped + ordered.
        // We hydrate items so the per-cell rendering can show service name.
        $appointments = TenantAppointment::query()
            ->where('tenant_id', $tenant->id)
            ->whereBetween('appointment_date', [$weekStartStr, $weekEndStr])
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->whereNotNull('appointment_time')
            ->whereIn('resource_id', $visibleIds)
            ->with(['items:id,appointment_id,item_name_snapshot'])
            ->orderBy('appointment_date')
            ->orderBy('appointment_time')
            ->get([
                'id', 'resource_id', 'customer_first_name', 'customer_last_name',
                'appointment_date', 'appointment_time', 'total_duration_minutes',
                'status', 'needs_time_review',
            ]);

        // Group: resource_id => date => collection of appointments.
        // The Blade renders one row per resource, then iterates $days for cells.
        $byResourceDate = [];
        foreach ($appointments as $a) {
            $rid = $a->resource_id;
            $ds  = is_string($a->appointment_date)
                ? $a->appointment_date
                : $a->appointment_date->toDateString();
            $byResourceDate[$rid][$ds][] = $a;
        }

        return view('tenant.calendar.index', [
            'viewMode'        => 'week',
            'weekStart'       => $weekStart,
            'weekEnd'         => $weekEnd,
            'weekStartStr'    => $weekStartStr,
            'weekEndStr'      => $weekEndStr,
            'days'            => $days,
            'prevDate'        => $prevDate,
            'nextDate'        => $nextDate,
            'todayStr'        => $todayStr,
            'resources'       => $resources,
            'allResources'    => $allResources,
            'myResource'      => $myResource,
            'filterMode'      => $filterMode,
            'byResourceDate'  => $byResourceDate,
        ]);
    }

    /* ============================================================
     * Month view — 6×7 density grid. Always 6 weeks so layout is stable.
     * Up to 4 stacked color-coded bars per cell, "+N more" overflow.
     * Hover bar = tooltip; click cell = drill to day view.
     * ============================================================ */
    protected function monthView(Request $request)
    {
        $tenant = tenant();

        $anchor    = $this->resolveDate($request->query('date'), $tenant);
        $monthStart = $anchor->copy()->startOfMonth();
        $monthEnd   = $anchor->copy()->endOfMonth();

        // Grid: always 6 weeks (42 cells), Sunday-anchored. Spillover
        // before/after gets greyed out. Stable layout = no jump on month flip.
        $gridStart = $monthStart->copy()->startOfWeek(Carbon::SUNDAY);
        $gridEnd   = $gridStart->copy()->addDays(41);

        $todayStr = $tenant->localToday()->toDateString();
        $cells = [];
        for ($i = 0; $i < 42; $i++) {
            $d  = $gridStart->copy()->addDays($i);
            $ds = $d->toDateString();
            $cells[] = [
                'date'         => $d,
                'dateStr'      => $ds,
                'num'          => $d->format('j'),
                'inMonth'      => $d->month === $anchor->month,
                'isToday'      => $ds === $todayStr,
                'dayOfWeek'    => $d->dayOfWeek,
            ];
        }

        $prevDate = $monthStart->copy()->subMonthNoOverflow()->toDateString();
        $nextDate = $monthStart->copy()->addMonthNoOverflow()->toDateString();

        [$allResources, $resources, $visibleIds, $myResource, $filterMode]
            = $this->resolveResources($tenant, $request);

        // Pull all active appointments in the visible 6-week window.
        // Even spillover days render their bars — feels weird for the cell
        // to be empty when you can see the appt is there, just outside the month.
        $appointments = TenantAppointment::query()
            ->where('tenant_id', $tenant->id)
            ->whereBetween('appointment_date', [$gridStart->toDateString(), $gridEnd->toDateString()])
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->whereNotNull('appointment_time')
            ->whereIn('resource_id', $visibleIds)
            ->with(['items:id,appointment_id,item_name_snapshot'])
            ->orderBy('appointment_date')
            ->orderBy('appointment_time')
            ->get([
                'id', 'resource_id', 'customer_first_name', 'customer_last_name',
                'appointment_date', 'appointment_time', 'status',
            ]);

        // Group: date => collection. Resource is preserved on each appt so the
        // bar color matches its resource. Up to 4 visible per cell.
        $byDate = [];
        foreach ($appointments as $a) {
            $ds = is_string($a->appointment_date)
                ? $a->appointment_date
                : $a->appointment_date->toDateString();
            $byDate[$ds][] = $a;
        }

        // Resource color lookup — keep small, used by the bar renderer.
        $resourceColors = $allResources->pluck('color_hex', 'id')->all();
        $resourceNames  = $allResources->pluck('name', 'id')->all();

        return view('tenant.calendar.index', [
            'viewMode'       => 'month',
            'monthAnchor'    => $anchor,
            'monthLabel'     => $anchor->format('F Y'),
            'cells'          => $cells,
            'prevDate'       => $prevDate,
            'nextDate'       => $nextDate,
            'todayStr'       => $todayStr,
            'resources'      => $resources,
            'allResources'   => $allResources,
            'myResource'     => $myResource,
            'filterMode'     => $filterMode,
            'byDate'         => $byDate,
            'resourceColors' => $resourceColors,
            'resourceNames'  => $resourceNames,
        ]);
    }

    /* ============================================================
     * Helpers — shared across all three views
     * ============================================================ */

    /**
     * Parse a YYYY-MM-DD string (or null) into a Carbon date in tenant TZ.
     * Falls back to "today" on any parse error or empty input.
     */
    protected function resolveDate(?string $param, $tenant): Carbon
    {
        $tz = $tenant->timezone();
        try {
            return $param
                ? Carbon::parse($param, $tz)->startOfDay()
                : $tenant->localToday();
        } catch (\Throwable $e) {
            return $tenant->localToday();
        }
    }

    /**
     * Resolve which resources to show based on the ?resources= query param.
     * Returns: [allResources, visibleResources, visibleIds, myResource, filterMode].
     *
     * - "all"            → every active resource
     * - "uuid1,uuid2"    → just those (intersected with active)
     * - absent + linked  → just the user's own resource
     * - absent + no link → all
     * - empty result     → falls back to all
     */
    protected function resolveResources($tenant, Request $request): array
    {
        $allResources = TenantResource::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $userId = Auth::guard('tenant')->id();
        $myResource = $userId
            ? $allResources->firstWhere('staff_user_id', $userId)
            : null;

        $filterParam = trim((string) $request->query('resources', ''));
        if ($filterParam === '') {
            $visibleIds = $myResource
                ? [$myResource->id]
                : $allResources->pluck('id')->all();
            $filterMode = $myResource ? 'self' : 'all';
        } elseif ($filterParam === 'all') {
            $visibleIds = $allResources->pluck('id')->all();
            $filterMode = 'all';
        } else {
            $requestedIds = array_filter(array_map('trim', explode(',', $filterParam)));
            $visibleIds = $allResources->whereIn('id', $requestedIds)->pluck('id')->all();
            if (empty($visibleIds)) {
                $visibleIds = $allResources->pluck('id')->all();
            }
            $filterMode = 'custom';
        }

        $resources = $allResources->whereIn('id', $visibleIds)->values();

        return [$allResources, $resources, $visibleIds, $myResource, $filterMode];
    }

    protected function timeToMinutes(string $hms): int
    {
        $parts = explode(':', $hms);
        return ((int) ($parts[0] ?? 0)) * 60 + ((int) ($parts[1] ?? 0));
    }

    protected function collectBreakWindows(string $tenantId, Carbon $date): array
    {
        $records = TenantCalendarBreak::where('tenant_id', $tenantId)
            ->where(function ($q) use ($date) {
                $q->where(function ($q2) use ($date) {
                    $q2->where('is_recurring', false)
                       ->whereDate('starts_at', $date->toDateString());
                })->orWhere(function ($q2) use ($date) {
                    $q2->where('is_recurring', true)
                       ->where('starts_at', '<=', $date->copy()->endOfDay())
                       ->where(function ($q3) use ($date) {
                           $q3->whereNull('recurrence_until')
                              ->orWhere('recurrence_until', '>=', $date->toDateString());
                       });
                });
            })
            ->get(['resource_id','label','starts_at','ends_at','is_recurring','recurrence_type','recurrence_config']);

        return $this->expandWindows($records, $date, 'label');
    }

    protected function collectHoldWindows(string $tenantId, Carbon $date): array
    {
        $now = now();
        $records = TenantWalkinHold::where('tenant_id', $tenantId)
            ->whereNull('converted_at')
            ->where(function ($q) use ($now) {
                $q->whereNull('auto_release_at')->orWhere('auto_release_at', '>', $now);
            })
            ->where(function ($q) use ($date) {
                $q->where(function ($q2) use ($date) {
                    $q2->where('is_recurring', false)
                       ->whereDate('starts_at', $date->toDateString());
                })->orWhere(function ($q2) use ($date) {
                    $q2->where('is_recurring', true)
                       ->where('starts_at', '<=', $date->copy()->endOfDay())
                       ->where(function ($q3) use ($date) {
                           $q3->whereNull('recurrence_until')
                              ->orWhere('recurrence_until', '>=', $date->toDateString());
                       });
                });
            })
            ->get(['resource_id','starts_at','ends_at','is_recurring','recurrence_type','recurrence_config','notes']);

        return $this->expandWindows($records, $date, 'notes');
    }

    protected function expandWindows($records, Carbon $target, string $labelField): array
    {
        $windows = [];
        foreach ($records as $r) {
            if (!$r->is_recurring) {
                $start = Carbon::parse($r->starts_at);
                $end   = Carbon::parse($r->ends_at);
                $windows[] = [
                    'resource_id' => $r->resource_id,
                    'starts_min'  => $start->hour * 60 + $start->minute,
                    'ends_min'    => $end->hour * 60 + $end->minute,
                    'label'       => $r->{$labelField} ?? '',
                ];
                continue;
            }

            if (!$this->recurrenceAppliesOnDate($r->recurrence_type, $r->recurrence_config, $target)) {
                continue;
            }

            $origStart = Carbon::parse($r->starts_at);
            $origEnd   = Carbon::parse($r->ends_at);
            $windows[] = [
                'resource_id' => $r->resource_id,
                'starts_min'  => $origStart->hour * 60 + $origStart->minute,
                'ends_min'    => $origEnd->hour * 60 + $origEnd->minute,
                'label'       => $r->{$labelField} ?? '',
            ];
        }
        return $windows;
    }

    protected function recurrenceAppliesOnDate(?string $type, $config, Carbon $target): bool
    {
        if ($type === 'daily') return true;

        if ($type === 'weekly') {
            $days = is_array($config) ? ($config['days'] ?? []) : [];
            if (!is_array($days) || empty($days)) return false;
            $targetDow = strtolower(substr($target->format('D'), 0, 3));
            $normalized = array_map(fn($d) => strtolower(substr((string) $d, 0, 3)), $days);
            return in_array($targetDow, $normalized, true);
        }

        if ($type === 'monthly') {
            $dayOfMonth = is_array($config) ? (int) ($config['day_of_month'] ?? 0) : 0;
            if ($dayOfMonth < 1 || $dayOfMonth > 31) return false;
            return (int) $target->format('j') === $dayOfMonth;
        }

        return false;
    }
}
