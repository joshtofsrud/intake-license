#!/bin/bash
# ============================================================================
# patch-56-dashboard-day-load-bars.sh
# ----------------------------------------------------------------------------
# Replaces the confusing count under each day card on the admin dashboard
# with a three-bar heatmap-style load indicator (Variant B from the
# day-strip-load-indicator mockup).
#
# Load level (0/1/2/3 bars filled) derived from:
#   appointments_count / max_slots_for_day
# where max_slots_for_day = ((close - open) / slot_interval) * active_resources
# for the day's TenantCapacityRule.
#
# Thresholds:
#   0%      → 0 bars  (closed day or no appts)
#   1-33%   → 1 bar
#   34-66%  → 2 bars
#   67-100% → 3 bars
#
# Files touched:
#   - app/Services/Tenant/DashboardDataService.php  (compute level per day)
#   - resources/views/tenant/dashboard/_zone_today.blade.php  (render bars)
#   - resources/views/tenant/dashboard.blade.php  (JS swap counts→bars)
#   - public/css/tenant/dashboard.css  (load-bar styles)
#
# NOTE: patch 56a follows this one — it adds the strip data to zoneToday()
# so the INITIAL render has load levels (otherwise bars only update after
# tapping a day). Run 56 then 56a.
#
# Mirrors mockup B in day-strip-load-indicator-mockup.html.
# ============================================================================

set -euo pipefail
cd "${REPO_ROOT:-$(pwd)}"

# ─── 1. DashboardDataService: compute load level ───────────────────────
python3 <<'PYEOF'
from pathlib import Path
p = Path("app/Services/Tenant/DashboardDataService.php")
s = p.read_text()

# Add TenantResource import.
old_import = "use App\\Models\\Tenant\\TenantServiceItem;"
new_import = "use App\\Models\\Tenant\\TenantResource;\nuse App\\Models\\Tenant\\TenantServiceItem;"
if "use App\\Models\\Tenant\\TenantResource;" in s:
    print("    SKIP import — TenantResource already imported")
elif old_import not in s:
    raise SystemExit("ABORT import: TenantServiceItem anchor not found")
else:
    s = s.replace(old_import, new_import, 1)
    print("    UPDATED — TenantResource import added")

# Replace strip-building loop with load-aware version.
old_loop = """        // 7-day strip: 3 days before, target, 3 days after
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
            ];
        }"""

new_loop = """        // 7-day strip: 3 days before, target, 3 days after.
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

if "'load_level'" in s:
    print("    SKIP strip-loop — load_level already in strip data")
elif old_loop not in s:
    raise SystemExit("ABORT strip-loop: anchor not found")
else:
    s = s.replace(old_loop, new_loop, 1)
    print("    UPDATED — strip loop now computes load_level per day")

# Add loadLevelForDay helper before workOrderBanner.
old_helper_anchor = "        public function workOrderBanner(bool $dismissed): ?array"

new_helper = """    /**
     * Compute a 0-3 load level for a given day based on appointment count
     * vs. theoretical max slots (capacity rule's open hours × resources).
     * 0 = closed or zero appointments
     * 1 = 1-33% full
     * 2 = 34-66% full
     * 3 = 67-100% full
     */
    private function loadLevelForDay(
        \\Illuminate\\Support\\Carbon $date,
        int $count,
        \\Illuminate\\Support\\Collection $rulesByDow,
        int $activeResourceCount
    ): int {
        if ($count === 0) {
            return 0;
        }
        $rule = $rulesByDow->get($date->dayOfWeek);
        if (!$rule || !$rule->open_time || !$rule->close_time) {
            // Day with bookings but no capacity rule: show light load.
            return 1;
        }
        $open  = \\Illuminate\\Support\\Carbon::parse($date->toDateString() . ' ' . $rule->open_time);
        $close = \\Illuminate\\Support\\Carbon::parse($date->toDateString() . ' ' . $rule->close_time);
        $intervalMin = max(1, (int) ($rule->slot_interval_minutes ?? 30));
        $minutesOpen = max(0, $close->diffInMinutes($open));
        $slotsPerResource = intdiv($minutesOpen, $intervalMin);
        $maxSlots = max(1, $slotsPerResource * $activeResourceCount);
        $ratio = $count / $maxSlots;
        if ($ratio >= 0.67) return 3;
        if ($ratio >= 0.34) return 2;
        return 1;
    }

        public function workOrderBanner(bool $dismissed): ?array"""

# Idempotency: match the function DEFINITION, not just the name (the strip
# loop above contains a $this->loadLevelForDay(...) call which would
# false-match if we only checked for "loadLevelForDay").
if "private function loadLevelForDay(" in s:
    print("    SKIP helper — loadLevelForDay already present")
elif old_helper_anchor not in s:
    raise SystemExit("ABORT helper: workOrderBanner anchor not found")
else:
    s = s.replace(old_helper_anchor, new_helper, 1)
    print("    UPDATED — loadLevelForDay helper added")

p.write_text(s)
PYEOF

# ─── 2. _zone_today.blade.php: replace count span with bars ──────────────
python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/dashboard/_zone_today.blade.php")
s = p.read_text()

old_strip_php = """    $stripStart = now()->subDays(3)->startOfDay();
    $stripDays = [];
    for ($i = 0; $i < 7; $i++) {
        $d = $stripStart->copy()->addDays($i);
        $stripDays[] = [
            'date'      => $d->toDateString(),
            'day_short' => $d->format('D'),
            'day_num'   => (int) $d->format('j'),
            'is_today'  => $d->isToday(),
        ];
    }"""

new_strip_php = """    $stripStart = now()->subDays(3)->startOfDay();
    $stripDays = [];
    for ($i = 0; $i < 7; $i++) {
        $d = $stripStart->copy()->addDays($i);
        $stripDays[] = [
            'date'       => $d->toDateString(),
            'day_short'  => $d->format('D'),
            'day_num'    => (int) $d->format('j'),
            'is_today'   => $d->isToday(),
            'load_level' => $today['strip'][$i]['load_level'] ?? 0,
        ];
    }"""

if "'load_level' =>" in s:
    print("    SKIP zone-today-php — load_level already in stripDays")
elif old_strip_php not in s:
    raise SystemExit("ABORT zone-today-php: stripDays anchor not found")
else:
    s = s.replace(old_strip_php, new_strip_php, 1)
    print("    UPDATED — load_level passed into stripDays")

old_chip = """      <button type=\"button\" class=\"ia-dash-date-chip {{ $sd['is_today'] ? 'is-target' : '' }}\" data-date=\"{{ $sd['date'] }}\" role=\"tab\">
        <span class=\"ia-dash-date-day\">{{ $sd['day_short'] }}</span>
        <span class=\"ia-dash-date-num\">{{ $sd['day_num'] }}</span>
        <span class=\"ia-dash-date-count\" data-count-for=\"{{ $sd['date'] }}\">·</span>
      </button>"""

new_chip = """      <button type=\"button\" class=\"ia-dash-date-chip {{ $sd['is_today'] ? 'is-target' : '' }}\" data-date=\"{{ $sd['date'] }}\" role=\"tab\">
        <span class=\"ia-dash-date-day\">{{ $sd['day_short'] }}</span>
        <span class=\"ia-dash-date-num\">{{ $sd['day_num'] }}</span>
        <span class=\"ia-dash-date-load\" data-load-for=\"{{ $sd['date'] }}\" data-level=\"{{ $sd['load_level'] }}\" aria-label=\"Day load\">
          <span class=\"ia-dash-date-load-bar\"></span>
          <span class=\"ia-dash-date-load-bar\"></span>
          <span class=\"ia-dash-date-load-bar\"></span>
        </span>
      </button>"""

if "ia-dash-date-load" in s:
    print("    SKIP zone-today-html — load bars already present")
elif old_chip not in s:
    raise SystemExit("ABORT zone-today-html: chip anchor not found")
else:
    s = s.replace(old_chip, new_chip, 1)
    print("    UPDATED — count span replaced with 3-bar load indicator")

p.write_text(s)
PYEOF

# ─── 3. dashboard.blade.php JS ──────────────────────────────────────────
python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/dashboard.blade.php")
s = p.read_text()

old_fn = """  function updateStripCounts(stripData) {
    stripData.forEach(function(d){
      var el = document.querySelector('[data-count-for=\"' + d.date + '\"]');
      if (el) el.textContent = d.count > 0 ? d.count : '·';
    });
  }"""

new_fn = """  function updateStripCounts(stripData) {
    // Updates the 3-bar load indicator on each day chip. Backend supplies
    // load_level (0-3) computed from appt_count vs. day capacity.
    stripData.forEach(function(d){
      var el = document.querySelector('[data-load-for=\"' + d.date + '\"]');
      if (el && typeof d.load_level !== 'undefined') {
        el.setAttribute('data-level', String(d.load_level));
      }
    });
  }"""

if "data-load-for" in s and "setAttribute('data-level'" in s:
    print("    SKIP dashboard-js — updateStripCounts already swapped")
elif old_fn not in s:
    raise SystemExit("ABORT dashboard-js: updateStripCounts anchor not found")
else:
    s = s.replace(old_fn, new_fn, 1)
    print("    UPDATED — updateStripCounts now sets data-level on bars")

p.write_text(s)
PYEOF

# ─── 4. dashboard.css ────────────────────────────────────────────────────
python3 <<'PYEOF'
from pathlib import Path
p = Path("public/css/tenant/dashboard.css")
s = p.read_text()

old_css = """.ia-dash-date-count {
  font-size: 10px;
  margin-top: 3px;
  color: var(--ia-text-dim);
  font-variant-numeric: tabular-nums;
}"""

new_css = """.ia-dash-date-count {
  /* legacy — kept for any callers still emitting the count span. New chips use .ia-dash-date-load below. */
  font-size: 10px;
  margin-top: 3px;
  color: var(--ia-text-dim);
  font-variant-numeric: tabular-nums;
}

/* Three-bar load indicator on day chips (heatmap style). */
.ia-dash-date-load {
  display: inline-flex;
  gap: 2px;
  margin-top: 4px;
  height: 5px;
  align-items: stretch;
}
.ia-dash-date-load-bar {
  width: 7px;
  background: rgba(255,255,255,.08);
  border-radius: 1.5px;
}
.ia-dash-date-load[data-level=\"1\"] .ia-dash-date-load-bar:nth-child(1),
.ia-dash-date-load[data-level=\"2\"] .ia-dash-date-load-bar:nth-child(-n+2),
.ia-dash-date-load[data-level=\"3\"] .ia-dash-date-load-bar {
  background: var(--ia-accent);
}"""

if ".ia-dash-date-load {" in s:
    print("    SKIP css — load-bar styles already present")
elif old_css not in s:
    raise SystemExit("ABORT css: date-count anchor not found")
else:
    s = s.replace(old_css, new_css, 1)
    print("    UPDATED — load-bar CSS added")

old_mobile = "  .ia-dash-date-day, .ia-dash-date-count { font-size: 9px; }"
new_mobile = """  .ia-dash-date-day, .ia-dash-date-count { font-size: 9px; }
  .ia-dash-date-load { height: 4px; gap: 1.5px; }
  .ia-dash-date-load-bar { width: 6px; border-radius: 1px; }"""

if ".ia-dash-date-load { height: 4px" in s:
    print("    SKIP css-mobile — load-bar mobile sizing already present")
elif old_mobile not in s:
    raise SystemExit("ABORT css-mobile: anchor not found")
else:
    s = s.replace(old_mobile, new_mobile, 1)
    print("    UPDATED — mobile load-bar sizing added")

p.write_text(s)
PYEOF

cat <<EONOTE

==> Patch 56 applied locally.

This adds the bars but the INITIAL dashboard render won't show non-zero
levels until you also apply patch 56a (which adds strip data to zoneToday).

Deploy 56 + 56a together:
  bash patch-56-dashboard-day-load-bars.sh
  bash patch-56a-zoneToday-strip-data.sh
  git add app/Services/Tenant/DashboardDataService.php \\
          resources/views/tenant/dashboard/_zone_today.blade.php \\
          resources/views/tenant/dashboard.blade.php \\
          public/css/tenant/dashboard.css \\
          patch-56-dashboard-day-load-bars.sh \\
          patch-56a-zoneToday-strip-data.sh
  git commit -m "feat: dashboard day-strip uses 3-bar load indicator (patches 56+56a)"
  git push

On server:
  cd /var/www/intake
  git pull
  php artisan optimize:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

Verify on dashboard:
  - 3 thin bars under each date instead of a number
  - Empty/closed days → all gray
  - Light (1-33%) → 1 lime + 2 gray
  - Steady (34-66%) → 2 lime + 1 gray
  - Full (67-100%) → 3 lime
EONOTE
