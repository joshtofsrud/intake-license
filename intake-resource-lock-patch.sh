#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────────
# Intake — Resource-aware day strip
#
# Clicking an alternative resource now SCOPES the day strip to that resource:
#   - "Showing availability for Sage Whitman" indicator above the strip
#   - All counts, times, and resolve calls scoped to that resource
#   - "Show all resources" link clears the lock
#
# Backend changes are pure additive parameters — no data model changes.
#
# Usage on Mac:  bash intake-resource-lock-patch.sh
# ──────────────────────────────────────────────────────────────────────────────

set -euo pipefail
[ -f artisan ] || { echo "ABORT: not a Laravel root"; exit 1; }

# ──────────────────────────────────────────────────────────────────────────────
# Phase 1: BookingService — dayCounts accepts resourceId
# ──────────────────────────────────────────────────────────────────────────────
echo "==> Phase 1: dayCounts accepts resourceId"

python3 <<'PY'
from pathlib import Path
p = Path("app/Services/BookingService.php")
s = p.read_text()

if "?string $resourceId = null\n    ): array {\n        $cacheKey = sprintf(\n            'avail:counts" in s:
    print("    skip: already patched")
else:
    old_sig = """    public function dayCounts(
        Tenant $tenant,
        int $requiredMinutes,
        string $startDate,
        int $days = 7
    ): array {
        $cacheKey = sprintf(
            'avail:counts:%s:%d:%s:%d',
            $tenant->id,
            $requiredMinutes,
            $startDate,
            $days
        );"""
    new_sig = """    public function dayCounts(
        Tenant $tenant,
        int $requiredMinutes,
        string $startDate,
        int $days = 7,
        ?string $resourceId = null
    ): array {
        $cacheKey = sprintf(
            'avail:counts:%s:%d:%s:%d:%s',
            $tenant->id,
            $requiredMinutes,
            $startDate,
            $days,
            $resourceId ?? 'any'
        );"""
    n = s.count(old_sig)
    if n != 1:
        print(f"    ABORT: dayCounts signature matched {n}")
    else:
        s = s.replace(old_sig, new_sig)
        # Also update inner call from null to $resourceId
        old_call = """                // Get all available slot times for this date (any resource)
                $slots = $this->availableSlotsForDate($tenant, $date, null, $requiredMinutes);"""
        new_call = """                // Get all available slot times for this date (any resource if $resourceId is null,
                // otherwise scoped to that specific resource).
                $slots = $this->availableSlotsForDate($tenant, $date, $resourceId, $requiredMinutes);"""
        if s.count(old_call) != 1:
            print(f"    ABORT: dayCounts inner call matched {s.count(old_call)}")
        else:
            s = s.replace(old_call, new_call)
            p.write_text(s)
            print("    patched: BookingService.php")
PY

# ──────────────────────────────────────────────────────────────────────────────
# Phase 2: Controller endpoints accept resource_id
# ──────────────────────────────────────────────────────────────────────────────
echo "==> Phase 2: dayStrip + dayTimes + resolveResource accept resource_id"

python3 <<'PY'
from pathlib import Path
p = Path("app/Http/Controllers/Tenant/AppointmentController.php")
s = p.read_text()

if "$resourceId = $request->query('resource_id') ?: null" in s:
    print("    skip: already patched")
else:
    # dayStrip
    old1 = """        $startDate = (string) $request->query('start_date', now()->toDateString());
        $days      = max(1, min(14, (int) $request->query('days', 7)));

        $bookingService = app(\\App\\Services\\BookingService::class);
        $dayData = $bookingService->dayCounts($tenant, $required, $startDate, $days);"""
    new1 = """        $startDate = (string) $request->query('start_date', now()->toDateString());
        $days      = max(1, min(14, (int) $request->query('days', 7)));
        $resourceId = $request->query('resource_id') ?: null;

        $bookingService = app(\\App\\Services\\BookingService::class);
        $dayData = $bookingService->dayCounts($tenant, $required, $startDate, $days, $resourceId);"""
    if s.count(old1) != 1:
        print(f"    ABORT: dayStrip body matched {s.count(old1)}"); exit()
    s = s.replace(old1, new1)

    # dayTimes
    old2 = """        $bookingService = app(\\App\\Services\\BookingService::class);
        $times = $bookingService->availableSlotsForDate($tenant, $date, null, $required);

        if (\\Carbon\\Carbon::parse($date)->isToday()) {"""
    new2 = """        $resourceId = $request->query('resource_id') ?: null;

        $bookingService = app(\\App\\Services\\BookingService::class);
        $times = $bookingService->availableSlotsForDate($tenant, $date, $resourceId, $required);

        if (\\Carbon\\Carbon::parse($date)->isToday()) {"""
    if s.count(old2) != 1:
        print(f"    ABORT: dayTimes body matched {s.count(old2)}"); exit()
    s = s.replace(old2, new2)

    # resolveResource
    old3 = """        $bookingService = app(\\App\\Services\\BookingService::class);
        $resourceId = $bookingService->resolveResourceForSlot($tenant, $date, $time, $required);

        return response()->json(['resource_id' => $resourceId]);
    }"""
    new3 = """        $bookingService = app(\\App\\Services\\BookingService::class);

        // If a specific resource is requested, verify it's free at that time.
        // Otherwise auto-resolve the first available active resource.
        $requestedResourceId = $request->query('resource_id') ?: null;
        if ($requestedResourceId) {
            $slots = $bookingService->availableSlotsForDate($tenant, $date, $requestedResourceId, $required);
            $resourceId = in_array($time, $slots, true) ? $requestedResourceId : null;
        } else {
            $resourceId = $bookingService->resolveResourceForSlot($tenant, $date, $time, $required);
        }

        return response()->json(['resource_id' => $resourceId]);
    }"""
    if s.count(old3) != 1:
        print(f"    ABORT: resolveResource body matched {s.count(old3)}"); exit()
    s = s.replace(old3, new3)

    p.write_text(s)
    print("    patched: AppointmentController.php")
PY

# ──────────────────────────────────────────────────────────────────────────────
# Phase 3: Modal — lock state, lock indicator, lock-clear, scoped fetches
# ──────────────────────────────────────────────────────────────────────────────
echo "==> Phase 3: modal resource-lock UI"

python3 <<'PY'
from pathlib import Path
p = Path("resources/views/tenant/appointments/_create_modal.blade.php")
s = p.read_text()

if "lockedResourceId" in s:
    print("    skip: already patched")
else:
    n_changes = 0

    # 1. Add lock fields to stripState
    old_state = """  var stripState = {
    startDate: null,    // 'Y-m-d' — Monday-aligned would be ideal, today for now
    days: [],           // array of {date, count, status}
    selectedDate: null,
    times: [],          // array of 'HH:MM' strings for selectedDate
    selectedTime: null,
    resolvedResourceId: null,
    resolvedResourceName: '',
  };"""
    new_state = """  var stripState = {
    startDate: null,    // 'Y-m-d' — Monday-aligned would be ideal, today for now
    days: [],           // array of {date, count, status}
    selectedDate: null,
    times: [],          // array of 'HH:MM' strings for selectedDate
    selectedTime: null,
    resolvedResourceId: null,
    resolvedResourceName: '',
    lockedResourceId: null,    // when set, strip + times + resolve all scope to this resource
    lockedResourceName: '',
  };"""
    if s.count(old_state) == 1: s = s.replace(old_state, new_state); n_changes += 1
    else: print(f"    state anchor: {s.count(old_state)}")

    # 2. fetchDayStrip — append resource_id
    old1 = """  function fetchDayStrip() {
    var qs = state.cart.map(function (l) { return 'service_ids[]=' + encodeURIComponent(l.service_item_id); }).join('&')
      + '&start_date=' + encodeURIComponent(stripState.startDate)
      + '&days=7';
    fetch(routes.dayStrip + '?' + qs"""
    new1 = """  function fetchDayStrip() {
    var qs = state.cart.map(function (l) { return 'service_ids[]=' + encodeURIComponent(l.service_item_id); }).join('&')
      + '&start_date=' + encodeURIComponent(stripState.startDate)
      + '&days=7';
    if (stripState.lockedResourceId) qs += '&resource_id=' + encodeURIComponent(stripState.lockedResourceId);
    fetch(routes.dayStrip + '?' + qs"""
    if s.count(old1) == 1: s = s.replace(old1, new1); n_changes += 1
    else: print(f"    fetchDayStrip: {s.count(old1)}")

    # 3. fetchDayTimes — append resource_id
    old2 = """  function fetchDayTimes() {
    var qs = state.cart.map(function (l) { return 'service_ids[]=' + encodeURIComponent(l.service_item_id); }).join('&')
      + '&date=' + encodeURIComponent(stripState.selectedDate);
    fetch(routes.dayTimes + '?' + qs"""
    new2 = """  function fetchDayTimes() {
    var qs = state.cart.map(function (l) { return 'service_ids[]=' + encodeURIComponent(l.service_item_id); }).join('&')
      + '&date=' + encodeURIComponent(stripState.selectedDate);
    if (stripState.lockedResourceId) qs += '&resource_id=' + encodeURIComponent(stripState.lockedResourceId);
    fetch(routes.dayTimes + '?' + qs"""
    if s.count(old2) == 1: s = s.replace(old2, new2); n_changes += 1
    else: print(f"    fetchDayTimes: {s.count(old2)}")

    # 4. fetchResolvedResource — append resource_id
    old3 = """      + '&date=' + encodeURIComponent(stripState.selectedDate)
      + '&time=' + encodeURIComponent(stripState.selectedTime);
    fetch(routes.resolveResource + '?' + qs"""
    new3 = """      + '&date=' + encodeURIComponent(stripState.selectedDate)
      + '&time=' + encodeURIComponent(stripState.selectedTime);
    if (stripState.lockedResourceId) qs += '&resource_id=' + encodeURIComponent(stripState.lockedResourceId);
    fetch(routes.resolveResource + '?' + qs"""
    if s.count(old3) == 1: s = s.replace(old3, new3); n_changes += 1
    else: print(f"    resolveResource: {s.count(old3)}")

    # 5. renderStripContainer — lock indicator
    old4 = """  function renderStripContainer() {
    var box = document.getElementById('appt-strip-container');
    if (!box) return;

    var html = '<div class=\"appt-strip-wrap\">';"""
    new4 = """  function renderStripContainer() {
    var box = document.getElementById('appt-strip-container');
    if (!box) return;

    var html = '';
    if (stripState.lockedResourceId) {
      html += '<div style=\"font-size:11px;opacity:.85;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center\">'
        + '<span>Showing availability for <strong>' + escapeHtml(stripState.lockedResourceName) + '</strong></span>'
        + '<span id=\"appt-strip-clear-lock\" style=\"color:var(--ia-accent,#BEF264);cursor:pointer\">Show all resources</span>'
        + '</div>';
    }
    html += '<div class=\"appt-strip-wrap\">';"""
    if s.count(old4) == 1: s = s.replace(old4, new4); n_changes += 1
    else: print(f"    render header: {s.count(old4)}")

    # 6. Wire clear-lock handler
    old5 = """    // Wire arrows
    box.querySelectorAll('.appt-strip-arrow').forEach(function (a) {"""
    new5 = """    // Wire clear-lock if present
    var clearLock = document.getElementById('appt-strip-clear-lock');
    if (clearLock) {
      clearLock.addEventListener('click', function () {
        stripState.lockedResourceId = null;
        stripState.lockedResourceName = '';
        stripState.selectedTime = null;
        stripState.times = [];
        fetchDayStrip();
      });
    }

    // Wire arrows
    box.querySelectorAll('.appt-strip-arrow').forEach(function (a) {"""
    if s.count(old5) == 1: s = s.replace(old5, new5); n_changes += 1
    else: print(f"    wire arrows: {s.count(old5)}")

    # 7. Alt-row click — set lock
    old6 = """    // Wire alt rows
    box.querySelectorAll('.appt-when-alt-row').forEach(function (row) {
      row.addEventListener('click', function () {
        state.selectedSlot = {
          date: row.dataset.date,
          time: row.dataset.time,
          resource_id: row.dataset.resource,
        };
        // Visual: re-render with the new selection highlighted
        box.querySelectorAll('.appt-when-alt-row').forEach(function (r) { r.classList.remove('selected'); });
        row.classList.add('selected');
        // Also dim the suggested card so it's clear this is the active pick
        el('appt-when-suggested').style.opacity = '.65';
      });
    });"""
    new6 = """    // Wire alt rows. Click = pick that resource AND lock the strip to it.
    box.querySelectorAll('.appt-when-alt-row').forEach(function (row) {
      row.addEventListener('click', function () {
        state.selectedSlot = {
          date: row.dataset.date,
          time: row.dataset.time,
          resource_id: row.dataset.resource,
        };
        // Lock the strip to this resource so manual override scopes correctly.
        stripState.lockedResourceId = row.dataset.resource;
        var nameMatch = state.resources.find(function (r) { return r.id === row.dataset.resource; });
        stripState.lockedResourceName = nameMatch ? nameMatch.name : '';
        box.querySelectorAll('.appt-when-alt-row').forEach(function (r) { r.classList.remove('selected'); });
        row.classList.add('selected');
        el('appt-when-suggested').style.opacity = '.65';
      });
    });"""
    if s.count(old6) == 1: s = s.replace(old6, new6); n_changes += 1
    else: print(f"    alt-row: {s.count(old6)}")

    # 8. Reset lock on open()
    old7 = """  function open() {
    clearError();
    state.cart = [];
    state.customerId = null;
    state.availability = null;
    state.selectedSlot = null;
    state.manualOverride = false;"""
    new7 = """  function open() {
    clearError();
    state.cart = [];
    state.customerId = null;
    state.availability = null;
    state.selectedSlot = null;
    state.manualOverride = false;
    stripState.lockedResourceId = null;
    stripState.lockedResourceName = '';"""
    if s.count(old7) == 1: s = s.replace(old7, new7); n_changes += 1
    else: print(f"    open(): {s.count(old7)}")

    p.write_text(s)
    print(f"    patched: _create_modal.blade.php ({n_changes}/8 changes)")
PY

# ──────────────────────────────────────────────────────────────────────────────
# Lint
# ──────────────────────────────────────────────────────────────────────────────
echo ""
echo "==> Linting"
for f in app/Services/BookingService.php app/Http/Controllers/Tenant/AppointmentController.php; do
  if command -v php >/dev/null 2>&1; then php -l "$f"; else echo "    (no php — skip lint $f)"; fi
done

echo ""
echo "==> Patch complete."
echo ""
echo "Files touched:"
echo "  app/Services/BookingService.php"
echo "  app/Http/Controllers/Tenant/AppointmentController.php"
echo "  resources/views/tenant/appointments/_create_modal.blade.php"
echo ""
echo "Next:"
echo "  git add -A && git commit -m 'Day strip scopes to picked resource'"
echo "  git push"
echo ""
echo "Server:"
echo "  cd /var/www/intake && git pull"
echo "  php artisan optimize:clear && php artisan view:clear && php artisan cache:clear"
echo "  sudo systemctl restart php8.3-fpm"
