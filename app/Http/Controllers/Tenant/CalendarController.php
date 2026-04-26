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

class CalendarController extends Controller
{
    /**
     * Admin calendar — day view with live data.
     *
     * Loads: business hours for the weekday, active resources, appointments
     * for the date (with prep/cleanup snapshots), breaks + holds overlapping
     * the date (with recurrence expansion).
     *
     * At scale this is the highest-traffic admin page — shop owners leave
     * it open all day. Every query tenant-scoped + date-indexed.
     */
    public function index(Request $request)
    {
        $tenant = tenant();

        // Resolve target date in the TENANT's timezone, not server UTC.
        // A 5pm Pacific request shouldn't show "tomorrow" because the server is in UTC.
        $tz = $tenant->timezone();
        $dateParam = $request->query('date');
        try {
            $date = $dateParam
                ? \Carbon\Carbon::parse($dateParam, $tz)->startOfDay()
                : $tenant->localToday();
        } catch (\Throwable $e) {
            $date = $tenant->localToday();
        }
        $dateStr = $date->toDateString();

        $prevDate = $date->copy()->subDay()->toDateString();
        $nextDate = $date->copy()->addDay()->toDateString();
        $todayStr = $tenant->localToday()->toDateString();
        $isToday  = $date->toDateString() === $todayStr;

        // Resources — ordered, active only. These are the column headers
        // in the calendar grid.
        $allResources = TenantResource::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        // Filter resolution.
        // URL ?resources=all          → show every resource
        // URL ?resources=uuid1,uuid2  → show only those (intersected with active)
        // URL absent + user has a linked resource → default to just their own
        // URL absent + user has no linked resource → default to all
        $userId = \Illuminate\Support\Facades\Auth::guard('tenant')->id();
        $myResource = $userId
            ? $allResources->firstWhere('staff_user_id', $userId)
            : null;

        $filterParam = trim((string) $request->query('resources', ''));
        if ($filterParam === '') {
            // No URL param → smart default
            $visibleResourceIds = $myResource
                ? [$myResource->id]
                : $allResources->pluck('id')->all();
            $filterMode = $myResource ? 'self' : 'all';
        } elseif ($filterParam === 'all') {
            $visibleResourceIds = $allResources->pluck('id')->all();
            $filterMode = 'all';
        } else {
            $requestedIds = array_filter(array_map('trim', explode(',', $filterParam)));
            $visibleResourceIds = $allResources
                ->whereIn('id', $requestedIds)
                ->pluck('id')
                ->all();
            // If filter wiped out everything (stale UUID, etc.), fall back to all
            if (empty($visibleResourceIds)) {
                $visibleResourceIds = $allResources->pluck('id')->all();
            }
            $filterMode = 'custom';
        }

        $resources = $allResources->whereIn('id', $visibleResourceIds)->values();

        // Business hours for this weekday. If none set, the day is treated as
        // closed — the grid still renders but with a "We're closed" message.
        $rule = TenantCapacityRule::where('tenant_id', $tenant->id)
            ->where('rule_type', 'default')
            ->where('day_of_week', $date->dayOfWeek)
            ->first();

        $hasRule   = $rule && $rule->open_time && $rule->close_time;
        $openMin   = $hasRule ? $this->timeToMinutes($rule->open_time)  : 9 * 60;
        $closeMin  = $hasRule ? $this->timeToMinutes($rule->close_time) : 17 * 60;
        $slotMin   = (int) ($rule->slot_interval_minutes ?? 30);
        $slotMin   = max($slotMin, 15);  // never allow a 0 or tiny interval

        // Appointments for the date, including the prep/cleanup snapshot columns
        // we added in M2. Joined with customer for display.
        $appointments = TenantAppointment::query()
            ->where('tenant_id', $tenant->id)
            ->where('appointment_date', $dateStr)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->whereNotNull('appointment_time')
            ->whereIn('resource_id', $visibleResourceIds)
            ->with(['items:id,appointment_id,item_name_snapshot,duration_minutes_snapshot,prep_before_minutes_snapshot,cleanup_after_minutes_snapshot'])
            ->orderBy('appointment_time')
            ->get([
                'id', 'resource_id', 'customer_first_name', 'customer_last_name',
                'appointment_time', 'appointment_end_time', 'total_duration_minutes',
                'status', 'total_cents', 'needs_time_review',
            ]);

        // Breaks + holds. We use the same logic as BookingService — inline here
        // rather than calling that service to avoid loading its full dependency
        // graph just for read-side rendering.
        $breakWindows = $this->collectBreakWindows($tenant->id, $date);
        $holdWindows  = $this->collectHoldWindows($tenant->id, $date);

        return view('tenant.calendar.index', [
            'date'          => $date,
            'dateStr'       => $dateStr,
            'prevDate'      => $prevDate,
            'nextDate'      => $nextDate,
            'todayStr'      => $todayStr,
            'isToday'       => $isToday,
            'viewMode'      => 'day',
            'resources'     => $resources,
            'hasRule'       => $hasRule,
            'openMin'       => $openMin,
            'closeMin'      => $closeMin,
            'slotMin'       => $slotMin,
            'appointments'  => $appointments,
            'breakWindows'  => $breakWindows,
            'holdWindows'   => $holdWindows,
            'allResources'  => $allResources,
            'myResource'    => $myResource,
            'filterMode'    => $filterMode,
        ]);
    }

    protected function timeToMinutes(string $hms): int
    {
        $parts = explode(':', $hms);
        return ((int) ($parts[0] ?? 0)) * 60 + ((int) ($parts[1] ?? 0));
    }

    /**
     * Collect break windows for the date — one-offs + expanded recurring.
     * Returns array of ['resource_id', 'starts_min', 'ends_min', 'label'].
     * Minutes-since-midnight format matches what the Blade grid math uses.
     */
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
