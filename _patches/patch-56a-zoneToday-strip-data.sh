#!/bin/bash
# ============================================================================
# patch-56a-zoneToday-strip-data.sh
# ----------------------------------------------------------------------------
# Followup to patch 56: the initial dashboard render uses zoneToday(), but
# zoneToday() didn't include the 7-day strip — only dayData() did. So the
# blade's `$today['strip'][$i]['load_level'] ?? 0` always fell to 0, and
# every day's bars rendered empty until the user tapped a day (which would
# then refresh via dayData()).
#
# Fix: extract a shared build7DayStripCenteredOn() helper and call it from
# both zoneToday() and dayData(). Initial render now has load levels.
# ============================================================================

set -euo pipefail
cd "${REPO_ROOT:-$(pwd)}"

python3 <<'PYEOF'
from pathlib import Path
p = Path("app/Services/Tenant/DashboardDataService.php")
s = p.read_text()

# 1. Add strip to zoneToday return.
old_return = """        return [
            'appointments'        => $todayAppointments,
            'today_count'         => $todayAppointments->count(),
            'next_up'             => $nextUp,
            'last_24h_bookings'   => $last24hNewBookings,
            'week_bookings'       => $weekBookings,
            'week_revenue_cents'  => $weekRevenue,
            'week_new_customers'  => $weekNewCustomers,
            'week_cancellations'  => $weekCancellations,
        ];
    }"""

new_return = """        return [
            'appointments'        => $todayAppointments,
            'today_count'         => $todayAppointments->count(),
            'next_up'             => $nextUp,
            'last_24h_bookings'   => $last24hNewBookings,
            'week_bookings'       => $weekBookings,
            'week_revenue_cents'  => $weekRevenue,
            'week_new_customers'  => $weekNewCustomers,
            'week_cancellations'  => $weekCancellations,
            'strip'               => $this->build7DayStripCenteredOn($this->tnow()->startOfDay()),
        ];
    }"""

if "build7DayStripCenteredOn($this->tnow()" in s:
    print("    SKIP zoneToday-return — strip already added")
elif old_return not in s:
    raise SystemExit("ABORT zoneToday-return: anchor not found")
else:
    s = s.replace(old_return, new_return, 1)
    print("    UPDATED — zoneToday() now returns 'strip' key")

# 2. Refactor dayData to call the shared helper.
old_daydata_strip = """        // 7-day strip: 3 days before, target, 3 days after.
        // Each day gets a load level 0-3 derived from appointment count vs.
        // theoretical max slots for that day-of-week × active resources.
        // Level powers the three-bar heatmap indicator on each day card.
        $activeResourceCount = max(1, TenantResource::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count());

        // Pre-fetch all capacity rules keyed by day_of_week for the week.
        $rulesByDow = TenantCapacityRule::where('tenant_id', $tenantId)
            ->where('rule_type', 'default')
            ->get()
            ->keyBy('day_of_week');

        $strip = [];
        for ($i = -3; $i <= 3; $i++) {
            $d = $target->copy()->addDays($i);
            $count = TenantAppointment::where('tenant_id', $tenantId)
                ->whereDate('appointment_date', $d->toDateString())
                ->whereNotIn('status', ['cancelled', 'refunded'])
                ->count();

            $level = $this->loadLevelForDay($d, $count, $rulesByDow, $activeResourceCount);

            $strip[] = [
                'date'       => $d->toDateString(),
                'day_short'  => $d->format('D'),
                'day_num'    => (int) $d->format('j'),
                'is_today'   => $d->isToday(),
                'is_target'  => $i === 0,
                'count'      => $count,
                'load_level' => $level,
            ];
        }"""

new_daydata_strip = """        // 7-day strip: 3 days before, target, 3 days after.
        // Level (0-3) powers the heatmap-style load indicator on each day card.
        $strip = $this->build7DayStripCenteredOn($target);"""

# Idempotency: refactor is done when the helper call exists AND the inline
# loop is gone.
if "$strip = $this->build7DayStripCenteredOn($target);" in s:
    print("    SKIP daydata-refactor — already refactored")
elif old_daydata_strip not in s:
    raise SystemExit("ABORT daydata-refactor: anchor not found")
else:
    s = s.replace(old_daydata_strip, new_daydata_strip, 1)
    print("    UPDATED — dayData() now uses shared helper")

# 3. Add build7DayStripCenteredOn helper before loadLevelForDay.
old_helper_anchor = """    /**
     * Compute a 0-3 load level for a given day based on appointment count"""

new_helper = """    /**
     * Build the 7-day strip array (3 days before, target, 3 days after)
     * with appointment counts and a 0-3 load level for each day. Used by
     * both the initial dashboard render (zoneToday) and the AJAX day-swap
     * endpoint (dayData).
     */
    private function build7DayStripCenteredOn(\\Illuminate\\Support\\Carbon $target): array
    {
        $tenantId = $this->tenant->id;

        $activeResourceCount = max(1, TenantResource::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count());

        $rulesByDow = TenantCapacityRule::where('tenant_id', $tenantId)
            ->where('rule_type', 'default')
            ->get()
            ->keyBy('day_of_week');

        $strip = [];
        for ($i = -3; $i <= 3; $i++) {
            $d = $target->copy()->addDays($i);
            $count = TenantAppointment::where('tenant_id', $tenantId)
                ->whereDate('appointment_date', $d->toDateString())
                ->whereNotIn('status', ['cancelled', 'refunded'])
                ->count();

            $strip[] = [
                'date'       => $d->toDateString(),
                'day_short'  => $d->format('D'),
                'day_num'    => (int) $d->format('j'),
                'is_today'   => $d->isToday(),
                'is_target'  => $i === 0,
                'count'      => $count,
                'load_level' => $this->loadLevelForDay($d, $count, $rulesByDow, $activeResourceCount),
            ];
        }
        return $strip;
    }

    /**
     * Compute a 0-3 load level for a given day based on appointment count"""

if "private function build7DayStripCenteredOn(" in s:
    print("    SKIP helper-shared — build7DayStripCenteredOn already present")
elif old_helper_anchor not in s:
    raise SystemExit("ABORT helper-shared: anchor not found")
else:
    s = s.replace(old_helper_anchor, new_helper, 1)
    print("    UPDATED — build7DayStripCenteredOn helper added")

p.write_text(s)
PYEOF

cat <<EONOTE

==> Patch 56a applied locally.

Deploy together with patch 56 — see patch 56 notes for full deploy steps.
EONOTE
