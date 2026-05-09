#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────────
# Intake — Day-strip picker for "Pick another time"
#
# Replaces the manual date/time/resource form with a smart day strip:
#   - 7 days at a time, with "N open" availability counts
#   - Closed days dimmed, past/beyond-window dimmed
#   - Click a day → see times → click a time → system auto-assigns resource
#   - Forward/back week arrows
#
# What ships:
#   1. BookingService.dayCounts(tenant, duration, startDate, days) — Redis cached
#   2. BookingService.resolveResourceForSlot(tenant, date, time, duration)
#   3. New controller endpoints: dayStrip, dayTimes, resolveResource
#   4. Three new routes
#   5. Modal manual-override section replaced with the day-strip UI
#
# Usage on Mac:  bash intake-day-strip-patch.sh
# ──────────────────────────────────────────────────────────────────────────────

set -euo pipefail
[ -f artisan ] || { echo "ABORT: not a Laravel root"; exit 1; }

# ──────────────────────────────────────────────────────────────────────────────
# Phase 1: BookingService — dayCounts + resolveResourceForSlot
# ──────────────────────────────────────────────────────────────────────────────
echo "==> Phase 1: BookingService — dayCounts + resolveResourceForSlot"

python3 <<'PY'
from pathlib import Path
p = Path("app/Services/BookingService.php")
s = p.read_text()

if "public function dayCounts" in s:
    print("    skip: already patched")
else:
    anchor = """        return $out;
    }

    /**
     * Expand break records into concrete time windows for a given date."""

    new_block = """        return $out;
    }

    /**
     * For a window of N days starting from $startDate, return the count of
     * available slots per day plus a status (open/closed/past/full/beyond_window).
     */
    public function dayCounts(
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
        );

        $cached = \\Illuminate\\Support\\Facades\\Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $minNoticeHours = (int) ($tenant->min_notice_hours ?? 0);
        $minNoticeAt = now()->addHours($minNoticeHours);
        $windowDays = (int) ($tenant->booking_window_days ?? 60);
        $windowEnd = now()->addDays($windowDays);

        $cursor = \\Carbon\\Carbon::parse($startDate)->startOfDay();
        $out = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $cursor->toDateString();
            $entry = ['date' => $date, 'count' => 0, 'status' => 'open'];

            if ($cursor->isPast() && !$cursor->isToday()) {
                $entry['status'] = 'past';
            }
            elseif ($cursor->gt($windowEnd)) {
                $entry['status'] = 'beyond_window';
            }
            else {
                $slots = $this->availableSlotsForDate($tenant, $date, null, $requiredMinutes);

                if ($cursor->isToday()) {
                    $earliestTime = $minNoticeAt->format('H:i');
                    $slots = array_values(array_filter($slots, fn($t) => $t >= $earliestTime));
                }

                $count = count($slots);
                $entry['count'] = $count;

                if ($count === 0) {
                    $entry['status'] = $cursor->isToday() ? 'full' : 'closed';
                }
            }

            $out[] = $entry;
            $cursor->addDay();
        }

        \\Illuminate\\Support\\Facades\\Cache::put($cacheKey, $out, 60);
        return $out;
    }

    /**
     * Find the first active resource that's free for the given window.
     * Used by the day-strip auto-assign flow.
     */
    public function resolveResourceForSlot(
        Tenant $tenant,
        string $date,
        string $time,
        int $requiredMinutes
    ): ?string {
        $resources = \\App\\Models\\Tenant\\TenantResource::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id']);

        foreach ($resources as $r) {
            $slots = $this->availableSlotsForDate($tenant, $date, $r->id, $requiredMinutes);
            if (in_array($time, $slots, true)) {
                return $r->id;
            }
        }

        return null;
    }

    /**
     * Expand break records into concrete time windows for a given date."""

    n = s.count(anchor)
    if n != 1:
        print(f"    ABORT: BookingService anchor matched {n}")
    else:
        p.write_text(s.replace(anchor, new_block))
        print("    patched: BookingService.php")
PY

# ──────────────────────────────────────────────────────────────────────────────
# Phase 2: AppointmentController — three new endpoints
# ──────────────────────────────────────────────────────────────────────────────
echo "==> Phase 2: dayStrip + dayTimes + resolveResource endpoints"

python3 <<'PY'
from pathlib import Path
p = Path("app/Http/Controllers/Tenant/AppointmentController.php")
s = p.read_text()

if "public function dayStrip" in s:
    print("    skip: already patched")
else:
    anchor = """            'availability' => $availability,
        ]);
    }"""

    new_block = anchor + """

    public function dayStrip(Request $request)
    {
        $tenant = tenant();

        $serviceIds = (array) $request->query('service_ids', []);
        $serviceIds = array_values(array_filter($serviceIds, fn($id) => is_string($id) && $id !== ''));

        if (empty($serviceIds)) {
            return response()->json(['days' => [], 'required_minutes' => 0]);
        }

        $services = \\App\\Models\\Tenant\\TenantServiceItem::where('tenant_id', $tenant->id)
            ->whereIn('id', $serviceIds)
            ->get(['duration_minutes', 'prep_before_minutes', 'cleanup_after_minutes']);

        $required = 0;
        foreach ($services as $svc) {
            $required += (int) ($svc->prep_before_minutes ?? 0)
                       + (int) ($svc->duration_minutes ?? 0)
                       + (int) ($svc->cleanup_after_minutes ?? 0);
        }

        if ($required === 0) {
            return response()->json(['days' => [], 'required_minutes' => 0]);
        }

        $startDate = (string) $request->query('start_date', now()->toDateString());
        $days      = max(1, min(14, (int) $request->query('days', 7)));

        $bookingService = app(\\App\\Services\\BookingService::class);
        $dayData = $bookingService->dayCounts($tenant, $required, $startDate, $days);

        return response()->json([
            'days'             => $dayData,
            'required_minutes' => $required,
        ]);
    }

    public function dayTimes(Request $request)
    {
        $tenant = tenant();

        $serviceIds = (array) $request->query('service_ids', []);
        $serviceIds = array_values(array_filter($serviceIds, fn($id) => is_string($id) && $id !== ''));
        $date = (string) $request->query('date', '');

        if (empty($serviceIds) || $date === '') {
            return response()->json(['times' => [], 'required_minutes' => 0]);
        }

        $services = \\App\\Models\\Tenant\\TenantServiceItem::where('tenant_id', $tenant->id)
            ->whereIn('id', $serviceIds)
            ->get(['duration_minutes', 'prep_before_minutes', 'cleanup_after_minutes']);

        $required = 0;
        foreach ($services as $svc) {
            $required += (int) ($svc->prep_before_minutes ?? 0)
                       + (int) ($svc->duration_minutes ?? 0)
                       + (int) ($svc->cleanup_after_minutes ?? 0);
        }

        if ($required === 0) {
            return response()->json(['times' => [], 'required_minutes' => 0]);
        }

        $bookingService = app(\\App\\Services\\BookingService::class);
        $times = $bookingService->availableSlotsForDate($tenant, $date, null, $required);

        if (\\Carbon\\Carbon::parse($date)->isToday()) {
            $minNoticeHours = (int) ($tenant->min_notice_hours ?? 0);
            $cutoff = now()->addHours($minNoticeHours)->format('H:i');
            $times = array_values(array_filter($times, fn($t) => $t >= $cutoff));
        }

        return response()->json([
            'times'            => $times,
            'required_minutes' => $required,
        ]);
    }

    public function resolveResource(Request $request)
    {
        $tenant = tenant();

        $serviceIds = (array) $request->query('service_ids', []);
        $serviceIds = array_values(array_filter($serviceIds, fn($id) => is_string($id) && $id !== ''));
        $date = (string) $request->query('date', '');
        $time = (string) $request->query('time', '');

        if (empty($serviceIds) || $date === '' || $time === '') {
            return response()->json(['resource_id' => null]);
        }

        $services = \\App\\Models\\Tenant\\TenantServiceItem::where('tenant_id', $tenant->id)
            ->whereIn('id', $serviceIds)
            ->get(['duration_minutes', 'prep_before_minutes', 'cleanup_after_minutes']);

        $required = 0;
        foreach ($services as $svc) {
            $required += (int) ($svc->prep_before_minutes ?? 0)
                       + (int) ($svc->duration_minutes ?? 0)
                       + (int) ($svc->cleanup_after_minutes ?? 0);
        }

        if ($required === 0) {
            return response()->json(['resource_id' => null]);
        }

        $bookingService = app(\\App\\Services\\BookingService::class);
        $resourceId = $bookingService->resolveResourceForSlot($tenant, $date, $time, $required);

        return response()->json(['resource_id' => $resourceId]);
    }
"""

    n = s.count(anchor)
    if n != 1:
        print(f"    ABORT: pickerData anchor matched {n}")
    else:
        p.write_text(s.replace(anchor, new_block))
        print("    patched: AppointmentController.php")
PY

# ──────────────────────────────────────────────────────────────────────────────
# Phase 3: routes
# ──────────────────────────────────────────────────────────────────────────────
echo "==> Phase 3: routes"

python3 <<'PY'
from pathlib import Path
p = Path("routes/web.php")
s = p.read_text()

if "appointments.day-strip" in s:
    print("    skip: already patched")
else:
    anchor = """            Route::get('/appointments/picker-data', [TenantControllers\\AppointmentController::class, 'pickerData'])->name('appointments.picker-data');"""

    new = anchor + """
            Route::get('/appointments/day-strip',   [TenantControllers\\AppointmentController::class, 'dayStrip'])->name('appointments.day-strip');
            Route::get('/appointments/day-times',   [TenantControllers\\AppointmentController::class, 'dayTimes'])->name('appointments.day-times');
            Route::get('/appointments/resolve-resource', [TenantControllers\\AppointmentController::class, 'resolveResource'])->name('appointments.resolve-resource');"""

    n = s.count(anchor)
    if n != 1:
        print(f"    ABORT: routes anchor matched {n}")
    else:
        p.write_text(s.replace(anchor, new))
        print("    patched: routes/web.php")
PY

# ──────────────────────────────────────────────────────────────────────────────
# Phase 4: modal — replace manual block with day strip
# ──────────────────────────────────────────────────────────────────────────────
echo "==> Phase 4: modal day-strip integration"

python3 <<'PY'
from pathlib import Path
p = Path("resources/views/tenant/appointments/_create_modal.blade.php")
s = p.read_text()

if "day-strip" in s or "renderStripContainer" in s:
    print("    skip: already patched")
else:
    css_anchor = """    /* Availability section */"""
    css_addition = """    /* Day strip picker */
    .appt-strip-wrap { display: flex; align-items: center; gap: 4px; margin-bottom: 12px; }
    .appt-strip-arrow { font-size: 18px; opacity: .5; cursor: pointer; padding: 4px 8px; user-select: none; }
    .appt-strip-arrow:hover { opacity: 1; }
    .appt-strip-arrow.disabled { opacity: .2; cursor: not-allowed; }
    .appt-strip { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; flex: 1; }
    .appt-strip-day {
      text-align: center;
      padding: 8px 4px;
      background: var(--ia-surface-2, #222);
      border-radius: 6px;
      cursor: pointer;
      border: 0.5px solid transparent;
      transition: border-color .12s;
    }
    .appt-strip-day:hover { border-color: var(--ia-border-strong, rgba(255,255,255,.2)); }
    .appt-strip-day.selected {
      background: rgba(190, 242, 100, 0.08);
      border-color: var(--ia-accent, #BEF264);
    }
    .appt-strip-day.disabled { opacity: .35; cursor: not-allowed; }
    .appt-strip-day.disabled:hover { border-color: transparent; }
    .appt-strip-dow { font-size: 10px; text-transform: uppercase; opacity: .55; letter-spacing: .04em; }
    .appt-strip-num { font-size: 14px; font-weight: 500; margin: 1px 0; }
    .appt-strip-meta { font-size: 9px; opacity: .55; }
    .appt-strip-day.selected .appt-strip-dow,
    .appt-strip-day.selected .appt-strip-meta { color: var(--ia-accent, #BEF264); opacity: 1; }
    .appt-strip-day.selected .appt-strip-num { color: var(--ia-accent, #BEF264); }

    .appt-times-label { font-size: 11px; opacity: .55; margin-bottom: 6px; }
    .appt-times-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; }
    .appt-time-btn {
      padding: 8px 4px;
      text-align: center;
      background: var(--ia-surface-2, #222);
      border-radius: 6px;
      font-size: 13px;
      cursor: pointer;
      border: 0.5px solid transparent;
      transition: border-color .12s;
    }
    .appt-time-btn:hover { border-color: var(--ia-border-strong, rgba(255,255,255,.2)); }
    .appt-time-btn.selected {
      background: rgba(190, 242, 100, 0.08);
      border-color: var(--ia-accent, #BEF264);
      color: var(--ia-accent, #BEF264);
      font-weight: 500;
    }
    .appt-times-empty { font-size: 12px; opacity: .55; padding: 12px; text-align: center; background: var(--ia-surface-2, #222); border-radius: 6px; }
    .appt-resolved-resource { font-size: 11px; opacity: .65; margin-top: 10px; }
    .appt-resolved-resource a { color: var(--ia-accent, #BEF264); cursor: pointer; }

    /* Availability section */"""
    if s.count(css_anchor) != 1:
        print(f"    ABORT: CSS anchor matched {s.count(css_anchor)}")
    else:
        s = s.replace(css_anchor, css_addition)

        routes_anchor = """    store:      \"{{ route('tenant.appointments.store') }}\","""
        routes_new = """    store:      \"{{ route('tenant.appointments.store') }}\",
    dayStrip:   \"{{ route('tenant.appointments.day-strip') }}\",
    dayTimes:   \"{{ route('tenant.appointments.day-times') }}\",
    resolveResource: \"{{ route('tenant.appointments.resolve-resource') }}\","""
        if s.count(routes_anchor) != 1:
            print(f"    ABORT: routes anchor matched {s.count(routes_anchor)}")
        else:
            s = s.replace(routes_anchor, routes_new)

            old_manual = """  function renderManualBlock(isOnlyOption) {
    var defaultDate = state.selectedSlot ? state.selectedSlot.date : new Date().toISOString().split('T')[0];
    var defaultTime = state.selectedSlot ? state.selectedSlot.time : '';
    var defaultResource = state.selectedSlot ? state.selectedSlot.resource_id : '';
    var resourceOpts = '<option value=\"\">Pick a resource…</option>';
    state.resources.forEach(function (r) {
      resourceOpts += '<option value=\"' + escapeHtml(r.id) + '\"' + (defaultResource === r.id ? ' selected' : '') + '>'
        + escapeHtml(r.name + (r.subtitle ? ' · ' + r.subtitle : '')) + '</option>';
    });

    return '<div class=\"appt-when-manual\">'
      + (isOnlyOption ? '' : '<div style=\"font-size:11px;opacity:.55;margin-bottom:8px\">Manual override:</div>')
      + '<div class=\"appt-row-3\">'
      +   '<div><label class=\"appt-label\">Date *</label><input type=\"date\" id=\"appt-manual-date\" class=\"appt-input\" value=\"' + escapeHtml(defaultDate) + '\"></div>'
      +   '<div><label class=\"appt-label\">Time *</label><input type=\"time\" id=\"appt-manual-time\" class=\"appt-input\" value=\"' + escapeHtml(defaultTime) + '\"></div>'
      +   '<div><label class=\"appt-label\">Resource</label><select id=\"appt-manual-resource\" class=\"appt-input\">' + resourceOpts + '</select></div>'
      + '</div>'
      + '</div>';
  }

  function wireManualHandlers() {
    ['appt-manual-date', 'appt-manual-time', 'appt-manual-resource'].forEach(function (id) {
      var node = el(id);
      if (node) node.addEventListener('change', function () {
        state.selectedSlot = {
          date: el('appt-manual-date').value,
          time: el('appt-manual-time').value,
          resource_id: el('appt-manual-resource').value || null,
        };
      });
    });
  }"""

            new_manual = """  // Day-strip state, lives inside ApptModal closure scope
  var stripState = {
    startDate: null,
    days: [],
    selectedDate: null,
    times: [],
    selectedTime: null,
    resolvedResourceId: null,
    resolvedResourceName: '',
  };

  function renderManualBlock(isOnlyOption) {
    return '<div class=\"appt-when-manual\">'
      + (isOnlyOption ? '' : '<div style=\"font-size:11px;opacity:.55;margin-bottom:8px\">Pick any time:</div>')
      + '<div id=\"appt-strip-container\"><div class=\"appt-when-loading\"><span class=\"appt-spin\"></span>Loading availability…</div></div>'
      + '</div>';
  }

  function wireManualHandlers() {
    var startFrom = (state.selectedSlot && state.selectedSlot.date) || new Date().toISOString().split('T')[0];
    stripState.startDate = startFrom;
    stripState.selectedDate = state.selectedSlot ? state.selectedSlot.date : null;
    stripState.selectedTime = state.selectedSlot ? state.selectedSlot.time : null;
    stripState.resolvedResourceId = state.selectedSlot ? state.selectedSlot.resource_id : null;
    fetchDayStrip();
  }

  function fetchDayStrip() {
    var qs = state.cart.map(function (l) { return 'service_ids[]=' + encodeURIComponent(l.service_item_id); }).join('&')
      + '&start_date=' + encodeURIComponent(stripState.startDate)
      + '&days=7';
    fetch(routes.dayStrip + '?' + qs, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        stripState.days = data.days || [];
        var inWindow = stripState.selectedDate && stripState.days.some(function (d) { return d.date === stripState.selectedDate; });
        if (!inWindow) {
          var firstOpen = stripState.days.find(function (d) { return d.status === 'open' && d.count > 0; });
          stripState.selectedDate = firstOpen ? firstOpen.date : null;
          stripState.selectedTime = null;
          stripState.times = [];
        }
        renderStripContainer();
        if (stripState.selectedDate) fetchDayTimes();
      })
      .catch(function () { showError('Could not load availability strip.'); });
  }

  function fetchDayTimes() {
    var qs = state.cart.map(function (l) { return 'service_ids[]=' + encodeURIComponent(l.service_item_id); }).join('&')
      + '&date=' + encodeURIComponent(stripState.selectedDate);
    fetch(routes.dayTimes + '?' + qs, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        stripState.times = data.times || [];
        renderStripContainer();
      })
      .catch(function () { showError('Could not load times for that day.'); });
  }

  function fetchResolvedResource() {
    if (!stripState.selectedDate || !stripState.selectedTime) return;
    var qs = state.cart.map(function (l) { return 'service_ids[]=' + encodeURIComponent(l.service_item_id); }).join('&')
      + '&date=' + encodeURIComponent(stripState.selectedDate)
      + '&time=' + encodeURIComponent(stripState.selectedTime);
    fetch(routes.resolveResource + '?' + qs, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        stripState.resolvedResourceId = data.resource_id;
        var match = state.resources.find(function (r) { return r.id === data.resource_id; });
        stripState.resolvedResourceName = match ? match.name : '';
        state.selectedSlot = {
          date: stripState.selectedDate,
          time: stripState.selectedTime,
          resource_id: data.resource_id,
        };
        renderStripContainer();
      });
  }

  function renderStripContainer() {
    var box = document.getElementById('appt-strip-container');
    if (!box) return;

    var html = '<div class=\"appt-strip-wrap\">';
    html += '<span class=\"appt-strip-arrow' + (canStripGoBack() ? '' : ' disabled') + '\" data-dir=\"back\">‹</span>';
    html += '<div class=\"appt-strip\">';
    stripState.days.forEach(function (day) {
      var d = new Date(day.date + 'T00:00:00');
      var dow = d.toLocaleDateString(undefined, { weekday: 'short' });
      var num = d.getDate();
      var disabled = (day.status === 'past' || day.status === 'closed' || day.status === 'beyond_window' || day.count === 0);
      var selected = day.date === stripState.selectedDate;
      var meta;
      if (day.status === 'closed') meta = 'closed';
      else if (day.status === 'past') meta = 'past';
      else if (day.status === 'beyond_window') meta = '—';
      else if (day.status === 'full' || day.count === 0) meta = 'full';
      else meta = day.count + ' open';

      html += '<div class=\"appt-strip-day' + (disabled ? ' disabled' : '') + (selected ? ' selected' : '') + '\" data-date=\"' + escapeHtml(day.date) + '\" data-disabled=\"' + (disabled ? '1' : '0') + '\">';
      html += '<div class=\"appt-strip-dow\">' + escapeHtml(dow) + '</div>';
      html += '<div class=\"appt-strip-num\">' + num + '</div>';
      html += '<div class=\"appt-strip-meta\">' + escapeHtml(meta) + '</div>';
      html += '</div>';
    });
    html += '</div>';
    html += '<span class=\"appt-strip-arrow\" data-dir=\"fwd\">›</span>';
    html += '</div>';

    if (stripState.selectedDate) {
      var dStr = new Date(stripState.selectedDate + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
      html += '<div class=\"appt-times-label\">Available times on ' + escapeHtml(dStr) + '</div>';

      if (stripState.times.length === 0) {
        html += '<div class=\"appt-times-empty\">No times available for that day.</div>';
      } else {
        html += '<div class=\"appt-times-grid\">';
        stripState.times.forEach(function (t) {
          var label = formatTimeLabel(t);
          var isSelected = t === stripState.selectedTime;
          html += '<div class=\"appt-time-btn' + (isSelected ? ' selected' : '') + '\" data-time=\"' + escapeHtml(t) + '\">' + escapeHtml(label) + '</div>';
        });
        html += '</div>';
      }
    }

    if (stripState.selectedDate && stripState.selectedTime && stripState.resolvedResourceId) {
      html += '<div class=\"appt-resolved-resource\">Will book with <strong>' + escapeHtml(stripState.resolvedResourceName) + '</strong></div>';
    }

    box.innerHTML = html;

    box.querySelectorAll('.appt-strip-arrow').forEach(function (a) {
      a.addEventListener('click', function () {
        if (a.classList.contains('disabled')) return;
        var dir = a.dataset.dir;
        var newStart = new Date(stripState.startDate + 'T00:00:00');
        newStart.setDate(newStart.getDate() + (dir === 'fwd' ? 7 : -7));
        stripState.startDate = newStart.toISOString().split('T')[0];
        fetchDayStrip();
      });
    });

    box.querySelectorAll('.appt-strip-day').forEach(function (d) {
      d.addEventListener('click', function () {
        if (d.dataset.disabled === '1') return;
        stripState.selectedDate = d.dataset.date;
        stripState.selectedTime = null;
        stripState.times = [];
        renderStripContainer();
        fetchDayTimes();
      });
    });

    box.querySelectorAll('.appt-time-btn').forEach(function (t) {
      t.addEventListener('click', function () {
        stripState.selectedTime = t.dataset.time;
        renderStripContainer();
        fetchResolvedResource();
      });
    });
  }

  function canStripGoBack() {
    if (!stripState.startDate) return false;
    var today = new Date().toISOString().split('T')[0];
    return stripState.startDate > today;
  }

  function formatTimeLabel(t) {
    var parts = t.split(':');
    var h = parseInt(parts[0], 10);
    var m = parts[1];
    var ampm = h >= 12 ? 'PM' : 'AM';
    var h12 = h % 12 === 0 ? 12 : h % 12;
    return h12 + ':' + m + ' ' + ampm;
  }"""

            if s.count(old_manual) != 1:
                print(f"    ABORT: manual block matched {s.count(old_manual)}")
            else:
                p.write_text(s.replace(old_manual, new_manual))
                print("    patched: _create_modal.blade.php")
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
echo "  routes/web.php"
echo "  resources/views/tenant/appointments/_create_modal.blade.php"
echo ""
echo "Next:"
echo "  git add -A && git commit -m 'Day-strip picker for create-appointment manual override'"
echo "  git push"
echo ""
echo "Server:"
echo "  cd /var/www/intake && git pull"
echo "  php artisan optimize:clear && php artisan view:clear && php artisan route:clear && php artisan cache:clear"
echo "  sudo systemctl restart php8.3-fpm"
