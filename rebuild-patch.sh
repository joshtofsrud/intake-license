#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Intake — calendar-first cleanup + sequential-picker rebuild + drag guard
#
# Three-phase patch:
#
#   PHASE A — Revert tonight's locked-time-pill overbuild:
#     A1  Remove openPlaced(), changeTime(), lockedPrefill/preservedForm state,
#         and the locked-time UI reset from open() in _create_modal.blade.php
#     A2  Remove locked-time pill markup in _create_modal.blade.php
#     A3  Remove locked-time CSS block from _create_modal.blade.php
#     A4  Remove the locked-slot guard (CALENDAR-FIRST-LOCK-GUARD), no longer needed
#     A5  Remove the create-modal include from calendar/index.blade.php
#     A6  Repoint the calendar slot-click intercept from ApptModal.openPlaced
#         to QuickBook.open (keep the ghost flow, just open QuickBook on click)
#
#   PHASE B — Rebuild the big modal as a sequential picker:
#     B1  Backend: GET /admin/appointments/eligible-resources?service_id=X
#     B2  Backend: GET /admin/appointments/week-times?service_id=X&resource_id=Y&start_date=Z
#     B3  Routes registered for both
#     B4  Frontend: rip out day-strip + carousel + alternatives + manual-override
#         + state.availability + auto-fetch + scheduleAvailabilityFetch +
#         availability-related rendering. Replace the When section with a
#         service-then-resource-then-times picker.
#
#   PHASE C — Drag/ghost coordination:
#     C1  Drag handler toggles body class .ia-cal-dragging-active
#     C2  Placement.positionGhost early-return when body has that class
#
# Per Day-18 lessons: every patch verifies s.count(old)==1 before write.
# Final pass greps for unique post-patch markers and aborts if any missing.
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: must run from intake-license repo root (no artisan found in $(pwd))"; exit 1; }

echo "=== rebuild patch starting ==="

# =============================================================================
# PHASE A — Revert calendar-first locked-time overbuild
# =============================================================================

# ─────────────────────────────────────────────────────────────────────────────
# A1. Strip openPlaced/changeTime/lockedPrefill from create modal JS.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/_create_modal.blade.php')
s = p.read_text()

# Remove lockedPrefill + preservedForm from state declaration.
old1 = '''    selectedSlot: null,        // {date, time, resource_id}
    manualOverride: false,
    // CALENDAR-FIRST-PREFILL v1: when set, skip availability UI, use these values
    lockedPrefill: null,       // {date, time, resourceId, resourceName}
    preservedForm: null,       // {customerId, cart, notes, custFields} stashed on Change-time
    // Manual override fields (read at submit if manualOverride is true)
  };'''
new1 = '''    selectedSlot: null,        // {date, time, resource_id}
    // Manual override fields (read at submit if manualOverride is true)
  };'''
assert s.count(old1) == 1, f"A1-state count={s.count(old1)}, expected 1"
s = s.replace(old1, new1)

# Remove the locked-time reset block in open().
old2 = '''    // CALENDAR-FIRST: reset locked-time UI when re-opening from list page.
    state.lockedPrefill = null;
    var lockedSec = el('appt-when-locked-section');
    var availSec = el('appt-when-availability-section');
    if (lockedSec) lockedSec.style.display = 'none';
    if (availSec)  availSec.style.display  = 'block';
    el('new-appt-modal').style.display = 'block';
    if (state.services.length === 0) loadInitialData();
    el('appt-cust-search').focus();
  }'''
new2 = '''    el('new-appt-modal').style.display = 'block';
    if (state.services.length === 0) loadInitialData();
    el('appt-cust-search').focus();
  }'''
assert s.count(old2) == 1, f"A1-open count={s.count(old2)}, expected 1"
s = s.replace(old2, new2)

# Remove openPlaced() + formatLockedTime() + changeTime() block entirely.
old3 = '''  function close() { el('new-appt-modal').style.display = 'none'; }

  // CALENDAR-FIRST-OPENPLACED v1
  // Opens the modal with a pre-locked time (set from the calendar ghost-block
  // click). Hides the availability section and shows the locked-time pill.
  // If `state.preservedForm` is set (from a prior "Change time" round-trip),
  // re-hydrate customer + cart + notes silently.
  function openPlaced(prefill) {
    open();
    state.lockedPrefill = prefill;
    state.selectedSlot = {
      date: prefill.date,
      time: prefill.time,
      resource_id: prefill.resourceId ? Number(prefill.resourceId) : null,
    };
    // Hide availability UI; show locked-time pill.
    el('appt-when-availability-section').style.display = 'none';
    el('appt-when-locked-section').style.display = 'block';
    el('appt-when-locked-time').textContent = formatLockedTime(prefill.date, prefill.time);
    el('appt-when-locked-resource').textContent = prefill.resourceName
      ? 'with ' + prefill.resourceName : '';

    // If we're round-tripping back from "Change time", re-hydrate.
    if (state.preservedForm) {
      var pf = state.preservedForm;
      if (pf.customer) {
        attachCustomer(pf.customer);
      } else if (pf.custFields) {
        el('appt-cust-search').value = pf.custFields.search || '';
        el('appt-cust-new-fields').style.display = 'block';
        el('appt-first').value = pf.custFields.first || '';
        el('appt-last').value  = pf.custFields.last  || '';
        el('appt-email').value = pf.custFields.email || '';
        el('appt-phone').value = pf.custFields.phone || '';
      }
      if (Array.isArray(pf.cart)) {
        state.cart = pf.cart;
        renderCart();
      }
      el('appt-notes').value = pf.notes || '';
      state.preservedForm = null;
    }
  }

  function formatLockedTime(dateStr, timeStr) {
    try {
      // dateStr: YYYY-MM-DD ; timeStr: HH:MM
      var d = new Date(dateStr + 'T' + timeStr + ':00');
      var dayName = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][d.getDay()];
      var monthName = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][d.getMonth()];
      var hh = d.getHours(), mm = d.getMinutes();
      var ampm = hh < 12 ? 'AM' : 'PM';
      var h12 = hh === 0 ? 12 : (hh > 12 ? hh - 12 : hh);
      var t = h12 + ':' + (mm < 10 ? '0' + mm : mm) + ' ' + ampm;
      return dayName + ' ' + monthName + ' ' + d.getDate() + ' · ' + t;
    } catch (e) {
      return dateStr + ' ' + timeStr;
    }
  }

  // CALENDAR-FIRST-CHANGE-TIME v1
  // "Change time" link: stash form state, close modal, re-arm placement so user
  // can click a different slot. The slot-click handler will call openPlaced again,
  // which re-hydrates from state.preservedForm.
  function changeTime() {
    state.preservedForm = {
      customer: state.customerId ? { id: state.customerId,
        first_name: el('appt-cust-name') ? el('appt-cust-name').textContent.split(' ')[0] : '',
        last_name:  el('appt-cust-name') ? el('appt-cust-name').textContent.split(' ').slice(1).join(' ') : '',
      } : null,
      custFields: state.customerId ? null : {
        search: el('appt-cust-search').value,
        first:  el('appt-first').value,
        last:   el('appt-last').value,
        email:  el('appt-email').value,
        phone:  el('appt-phone').value,
      },
      cart: state.cart.slice(),
      notes: el('appt-notes').value,
    };
    close();
    // Re-arm calendar placement mode.
    if (window.IntakePlacement && typeof window.IntakePlacement.arm === 'function') {
      window.IntakePlacement.arm();
    }
  }'''
new3 = '''  function close() { el('new-appt-modal').style.display = 'none'; }'''
assert s.count(old3) == 1, f"A1-funcs count={s.count(old3)}, expected 1"
s = s.replace(old3, new3)

# Remove the changeTime click-wire IIFEs and openPlaced/changeTime from the export.
old4 = '''  // Wire Change-time click (idempotent).
  document.addEventListener('DOMContentLoaded', function () {
    var ct = el('appt-when-change-time');
    if (ct && !ct.dataset.wired) { ct.addEventListener('click', changeTime); ct.dataset.wired = '1'; }
  });
  // Already-loaded fallback.
  (function () {
    var ct = el('appt-when-change-time');
    if (ct && !ct.dataset.wired) { ct.addEventListener('click', changeTime); ct.dataset.wired = '1'; }
  })();

  return {
    open: open, close: close, clearCustomer: clearCustomer,
    toggleServicePicker: toggleServicePicker, submit: submit,
    openPlaced: openPlaced, changeTime: changeTime,
  };'''
new4 = '''  return {
    open: open, close: close, clearCustomer: clearCustomer,
    toggleServicePicker: toggleServicePicker, submit: submit,
  };'''
assert s.count(old4) == 1, f"A1-export count={s.count(old4)}, expected 1"
s = s.replace(old4, new4)

p.write_text(s)
print("OK A1 (modal JS reverted)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# A2. Remove locked-time pill markup.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/_create_modal.blade.php')
s = p.read_text()
old = '''        {{-- When (calendar-first locked-time pill, hidden by default) --}}
        <div class="appt-section" id="appt-when-locked-section" style="display:none">
          <div class="appt-section-h">When</div>
          <div class="appt-when-locked">
            <div class="appt-when-locked-left">
              <div class="appt-when-locked-time" id="appt-when-locked-time">—</div>
              <div class="appt-when-locked-resource" id="appt-when-locked-resource">—</div>
            </div>
            <span class="appt-when-locked-change" id="appt-when-change-time">Change time</span>
          </div>
        </div>

        {{-- When (availability-first; hidden in calendar-first flow) --}}
        <div class="appt-section" id="appt-when-availability-section">
          <div class="appt-section-h">When</div>
          <div id="appt-when-content">
            <div class="appt-when-empty">Add a service to see available times.</div>
          </div>
        </div>'''
# We're going to replace this with the new sequential-picker UI in PHASE B,
# so for now, drop in a placeholder section. Phase B replaces it.
new = '''        {{-- SEQUENTIAL-PICKER-PLACEHOLDER --}}'''
assert s.count(old) == 1, f"A2 count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK A2 (locked-time markup removed; placeholder for Phase B)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# A3. Remove locked-time CSS block.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/_create_modal.blade.php')
s = p.read_text()
old = '''
    /* CALENDAR-FIRST-LOCKED-TIME v1 */
    .appt-when-locked {
      display: flex; align-items: center; justify-content: space-between;
      padding: 12px 14px;
      background: rgba(190,242,100,0.08);
      border: 1px solid rgba(190,242,100,0.25);
      border-radius: 8px;
      gap: 12px;
    }
    .appt-when-locked-left { display: flex; flex-direction: column; gap: 2px; }
    .appt-when-locked-time { font-size: 14px; font-weight: 600; color: var(--ia-text, #f0f0f0); }
    .appt-when-locked-resource { font-size: 12px; opacity: .65; }
    .appt-when-locked-change {
      font-size: 12px;
      color: var(--ia-accent, #BEF264);
      cursor: pointer;
      padding: 4px 8px;
      border-radius: 4px;
      white-space: nowrap;
    }
    .appt-when-locked-change:hover { background: rgba(190,242,100,0.12); }
'''
new = ''
assert s.count(old) == 1, f"A3 count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK A3 (locked-time CSS removed)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# A4. Remove the lock-guard line in scheduleAvailabilityFetch IF present
# (we're rewriting that whole function out in Phase B anyway). The lock-guard
# was committed in some sessions but not others; tolerate either state.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/_create_modal.blade.php')
s = p.read_text()
old = '''    clearTimeout(availTimer);
    // CALENDAR-FIRST-LOCK-GUARD v1: when a slot was placed via calendar-first,
    // skip availability lookup entirely. The placed slot is authoritative; we
    // must NOT overwrite state.selectedSlot with the server's "earliest".
    if (state.lockedPrefill) return;
    if (state.cart.length === 0) {'''
new = '''    clearTimeout(availTimer);
    if (state.cart.length === 0) {'''
n = s.count(old)
if n == 0:
    print("SKIP A4 (lock-guard not present in this state)")
elif n == 1:
    p.write_text(s.replace(old, new))
    print("OK A4 (lock-guard removed)")
else:
    raise AssertionError(f"A4 count={n}, expected 0 or 1")
PY

# ─────────────────────────────────────────────────────────────────────────────
# A5. Remove the modal include from calendar/index.blade.php.
# Keep the placement banner + ghost block + button; only remove the modal.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/calendar/index.blade.php')
s = p.read_text()
old = '''{{-- ===== Create-appointment modal (calendar-first entry point) ===== --}}
@include('tenant.appointments._create_modal')
@endif'''
new = '''@endif'''
assert s.count(old) == 1, f"A5 count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK A5 (modal include removed from calendar)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# A6. Repoint slot-click intercept: when armed, open QuickBook (not ApptModal).
# This is the cleanest landing for the calendar flow.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('public/js/tenant/calendar.js')
s = p.read_text()
old = '''        // CALENDAR-FIRST-INTERCEPT v1: armed placement mode bypasses QuickBook.
        if (window.IntakePlacement && window.IntakePlacement.isArmed()) {
          var placed = window.IntakePlacement.resolveClick(col, e.clientY);
          window.IntakePlacement.disarm();
          if (window.ApptModal && typeof window.ApptModal.openPlaced === 'function') {
            window.ApptModal.openPlaced({
              date: dateStr,
              time: placed.time,
              resourceId: placed.resourceId,
              resourceName: placed.resourceName,
            });
          }
          return;
        }
        QuickBook.open({
          date: dateStr,
          time: time,
          resourceId: resourceId,
          resourceName: resourceName,
        });'''
new = '''        // PLACEMENT-INTERCEPT v2: armed placement mode hands placed coords to QuickBook.
        // QuickBook is the single source of truth for calendar-side bookings.
        if (window.IntakePlacement && window.IntakePlacement.isArmed()) {
          var placed = window.IntakePlacement.resolveClick(col, e.clientY);
          window.IntakePlacement.disarm();
          QuickBook.open({
            date: dateStr,
            time: placed.time,
            resourceId: placed.resourceId,
            resourceName: placed.resourceName,
          });
          return;
        }
        QuickBook.open({
          date: dateStr,
          time: time,
          resourceId: resourceId,
          resourceName: resourceName,
        });'''
assert s.count(old) == 1, f"A6 count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK A6 (slot-click intercept now opens QuickBook)")
PY

# =============================================================================
# PHASE B — Sequential picker rebuild
# =============================================================================

# ─────────────────────────────────────────────────────────────────────────────
# B1. Backend: eligibleResources controller method.
# Insert it just before the existing dayStrip method.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('app/Http/Controllers/Tenant/AppointmentController.php')
s = p.read_text()
marker = 'SEQUENTIAL-PICKER-ENDPOINTS v1'
if marker in s:
    print("SKIP B1 (marker present)")
else:
    old = '''    public function dayStrip(Request $request)
    {'''
    new = '''    /**
     * SEQUENTIAL-PICKER-ENDPOINTS v1
     *
     * Returns the active resources eligible to perform a given service.
     * If the service has no eligibility rows, all active resources qualify.
     * Used by the rebuilt big-modal sequential picker (service → resource → times).
     */
    public function eligibleResources(Request $request)
    {
        $tenant = tenant();
        $serviceId = (string) $request->query('service_id', '');
        if ($serviceId === '') {
            return response()->json(['resources' => []]);
        }

        $bookingService = app(\\App\\Services\\BookingService::class);
        $eligibleIds = $bookingService->eligibleResourcesForService($tenant->id, $serviceId);

        if (empty($eligibleIds)) {
            return response()->json(['resources' => []]);
        }

        $resources = \\App\\Models\\Tenant\\TenantResource::where('tenant_id', $tenant->id)
            ->whereIn('id', $eligibleIds)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'subtitle']);

        return response()->json(['resources' => $resources]);
    }

    /**
     * Returns up to 7 days of available time slots starting from start_date.
     * Each result is a flat list across the week. The frontend paginates by
     * advancing/retreating start_date by 7 days on prev/next clicks.
     *
     * Required query params:
     *   service_id    — single service UUID (single-service modal at launch)
     *   resource_id   — single resource UUID (selected by user)
     *   start_date    — YYYY-MM-DD; results begin on this date
     *
     * Response:
     *   {
     *     slots: [{date: "YYYY-MM-DD", time: "HH:MM", date_label: "...", time_label: "..."}],
     *     required_minutes: int,
     *     start_date: "YYYY-MM-DD",
     *     end_date: "YYYY-MM-DD"
     *   }
     */
    public function weekTimes(Request $request)
    {
        $tenant = tenant();
        $serviceId  = (string) $request->query('service_id', '');
        $resourceId = (string) $request->query('resource_id', '');
        $startDate  = (string) $request->query('start_date', now()->toDateString());

        if ($serviceId === '' || $resourceId === '') {
            return response()->json(['slots' => [], 'required_minutes' => 0]);
        }

        $svc = \\App\\Models\\Tenant\\TenantServiceItem::where('tenant_id', $tenant->id)
            ->where('id', $serviceId)
            ->first(['duration_minutes', 'prep_before_minutes', 'cleanup_after_minutes']);

        if (!$svc) {
            return response()->json(['slots' => [], 'required_minutes' => 0]);
        }

        $required = (int) ($svc->prep_before_minutes ?? 0)
                  + (int) ($svc->duration_minutes ?? 0)
                  + (int) ($svc->cleanup_after_minutes ?? 0);

        if ($required === 0) {
            return response()->json(['slots' => [], 'required_minutes' => 0]);
        }

        $bookingService = app(\\App\\Services\\BookingService::class);

        $minNoticeHours = (int) ($tenant->min_notice_hours ?? 0);
        $cutoff = now()->addHours($minNoticeHours);

        $slots = [];
        $cursor = \\Carbon\\Carbon::parse($startDate);
        $endDate = $cursor->copy()->addDays(6);

        for ($i = 0; $i < 7; $i++) {
            $dateStr = $cursor->toDateString();
            $times = $bookingService->availableSlotsForDate($tenant, $dateStr, $resourceId, $required);

            // For today, drop any slots earlier than the min-notice cutoff.
            if ($cursor->isToday() && $minNoticeHours > 0) {
                $cutoffHi = $cutoff->format('H:i');
                $times = array_values(array_filter($times, fn($t) => $t >= $cutoffHi));
            }
            // Past dates: skip entirely.
            if ($cursor->isPast() && !$cursor->isToday()) {
                $cursor->addDay();
                continue;
            }

            $dateLabel = $cursor->format('D, M j');
            foreach ($times as $t) {
                $slots[] = [
                    'date'       => $dateStr,
                    'time'       => $t,
                    'date_label' => $dateLabel,
                    'time_label' => self::formatTimeLabel($t),
                ];
            }
            $cursor->addDay();
        }

        return response()->json([
            'slots'            => $slots,
            'required_minutes' => $required,
            'start_date'       => $startDate,
            'end_date'         => $endDate->toDateString(),
        ]);
    }

    private static function formatTimeLabel(string $hi): string
    {
        // "14:30" → "2:30 PM"
        $parts = explode(':', $hi);
        if (count($parts) < 2) return $hi;
        $h = (int) $parts[0];
        $m = $parts[1];
        $ampm = $h >= 12 ? 'PM' : 'AM';
        $h12 = $h % 12 === 0 ? 12 : $h % 12;
        $minPart = $m === '00' ? '' : ':' . $m;
        return $h12 . $minPart . ' ' . $ampm;
    }

    public function dayStrip(Request $request)
    {'''
    assert s.count(old) == 1, f"B1 count={s.count(old)}, expected 1"
    p.write_text(s.replace(old, new))
    print("OK B1 (eligibleResources + weekTimes controller methods)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# B2. Routes: eligible-resources + week-times.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('routes/web.php')
s = p.read_text()
old = "Route::get('/appointments/day-strip',   [TenantControllers\\AppointmentController::class, 'dayStrip'])->name('appointments.day-strip');"
new = '''Route::get('/appointments/day-strip',   [TenantControllers\\AppointmentController::class, 'dayStrip'])->name('appointments.day-strip');
            // SEQUENTIAL-PICKER-ROUTES v1
            Route::get('/appointments/eligible-resources', [TenantControllers\\AppointmentController::class, 'eligibleResources'])->name('appointments.eligible-resources');
            Route::get('/appointments/week-times',         [TenantControllers\\AppointmentController::class, 'weekTimes'])->name('appointments.week-times');'''
assert s.count(old) == 1, f"B2 count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK B2 (routes registered)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# B3. Frontend: rip out availability-first internals from create modal,
# replace the When section with a sequential picker (service→resource→times),
# add new state + handlers + render functions, simplify submit.
#
# This is the biggest single hunk. We do it as one create_file replace because
# the sections are deeply interleaved.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/_create_modal.blade.php')
s = p.read_text()

# B3a: Replace the placeholder we left in A2 with the new sequential-picker markup.
old_a = '''        {{-- SEQUENTIAL-PICKER-PLACEHOLDER --}}'''
new_a = '''        {{-- SEQUENTIAL-PICKER v1 --}}
        <div class="appt-section">
          <div class="appt-section-h">Service</div>
          <select id="appt-sp-service" class="appt-input">
            <option value="">Select a service…</option>
          </select>
          <p class="appt-sp-note" style="font-size:11px; opacity:.55; margin-top:6px;">
            You can add more services on the next page after creating the appointment.
          </p>
        </div>

        <div class="appt-section" id="appt-sp-resource-section" style="display:none">
          <div class="appt-section-h">Resource</div>
          <select id="appt-sp-resource" class="appt-input">
            <option value="">Select a resource…</option>
          </select>
        </div>

        <div class="appt-section" id="appt-sp-find-section" style="display:none">
          <button type="button" class="appt-btn appt-btn--cancel" id="appt-sp-find" style="width:100%; padding:10px;">
            Show available times
          </button>
        </div>

        <div class="appt-section" id="appt-sp-times-section" style="display:none">
          <div class="appt-sp-times-head">
            <div class="appt-section-h" style="margin-bottom:0">Available times</div>
            <div class="appt-sp-week-nav">
              <button type="button" class="appt-sp-week-btn" id="appt-sp-prev-week" disabled>← Prev week</button>
              <span class="appt-sp-week-label" id="appt-sp-week-label">—</span>
              <button type="button" class="appt-sp-week-btn" id="appt-sp-next-week">Next week →</button>
            </div>
          </div>
          <div class="appt-sp-times-list" id="appt-sp-times-list">
            <div class="appt-sp-times-empty">Loading…</div>
          </div>
        </div>'''
assert s.count(old_a) == 1, f"B3a count={s.count(old_a)}, expected 1"
s = s.replace(old_a, new_a)

# B3b: Append CSS for the sequential picker just before </style>.
closer = '</style>'
assert s.count(closer) == 1, f"B3b </style> count={s.count(closer)}, expected 1"
sp_css = '''
    /* SEQUENTIAL-PICKER-CSS v1 */
    .appt-sp-times-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; gap:8px; flex-wrap:wrap; }
    .appt-sp-week-nav { display:flex; align-items:center; gap:6px; font-size:11px; }
    .appt-sp-week-btn {
      background: rgba(255,255,255,.04);
      border: 0.5px solid var(--ia-border, rgba(255,255,255,.1));
      color: inherit;
      font-size: 11px;
      padding: 4px 8px;
      border-radius: 4px;
      cursor: pointer;
      font-family: inherit;
    }
    .appt-sp-week-btn:hover:not(:disabled) { background: rgba(255,255,255,.08); }
    .appt-sp-week-btn:disabled { opacity: .35; cursor: not-allowed; }
    .appt-sp-week-label { opacity: .65; min-width: 100px; text-align: center; }
    .appt-sp-times-list {
      max-height: 240px;       /* ~5 rows visible */
      overflow-y: auto;
      border: 0.5px solid var(--ia-border, rgba(255,255,255,.1));
      border-radius: 8px;
      background: rgba(255,255,255,.02);
    }
    .appt-sp-time-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 10px 14px;
      border-bottom: 0.5px solid var(--ia-border, rgba(255,255,255,.06));
      cursor: pointer;
      font-size: 13px;
    }
    .appt-sp-time-row:last-child { border-bottom: none; }
    .appt-sp-time-row:hover { background: rgba(190,242,100,0.06); }
    .appt-sp-time-row.selected { background: rgba(190,242,100,0.12); border-left: 2px solid var(--ia-accent, #BEF264); padding-left: 12px; }
    .appt-sp-time-date { opacity: .65; font-size: 12px; }
    .appt-sp-time-time { font-weight: 500; }
    .appt-sp-times-empty {
      padding: 18px 14px;
      text-align: center;
      font-size: 12px;
      opacity: .55;
    }
    .appt-sp-times-empty.error { color: #f39999; opacity: .8; }
'''
s = s.replace(closer, sp_css + '  </style>')

# B3c: Replace state initializers + ALL availability-related JS in one go.
# We keep customer + cart logic, replace availability+strip+manual sections.

# Find and replace the state block:
old_b = '''  var state = {
    services: [],
    resources: [],
    cart: [],
    customerId: null,
    pickerOpen: false,
    // Availability state
    availability: null,
    availLoading: false,
    selectedSlot: null,        // {date, time, resource_id}
    // Manual override fields (read at submit if manualOverride is true)
  };'''
new_b = '''  // SEQUENTIAL-PICKER-STATE v1
  var state = {
    services: [],
    resources: [],          // all active (loaded once for caching, but not used for picker)
    eligibleResources: [],  // narrowed by selected service
    cart: [],               // single-element at launch (one service); prepped for future multi
    customerId: null,
    pickerOpen: false,
    selectedSlot: null,     // {date, time, resource_id}
    selectedServiceId: null,
    selectedResourceId: null,
    selectedResourceName: '',
    weekStartDate: null,    // YYYY-MM-DD; advances on next/prev week
    availSlots: [],
    availLoading: false,
  };'''
assert s.count(old_b) == 1, f"B3c-state count={s.count(old_b)}, expected 1"
s = s.replace(old_b, new_b)

# Add eligible/week routes to the routes object:
old_r = '''  var routes = {
    pickerData: "{{ route('tenant.appointments.picker-data') }}",
    store:      "{{ route('tenant.appointments.store') }}",
    dayStrip:   "{{ route('tenant.appointments.day-strip') }}",
    dayTimes:   "{{ route('tenant.appointments.day-times') }}",
    resolveResource: "{{ route('tenant.appointments.resolve-resource') }}",
  };'''
new_r = '''  var routes = {
    pickerData: "{{ route('tenant.appointments.picker-data') }}",
    store:      "{{ route('tenant.appointments.store') }}",
    eligibleResources: "{{ route('tenant.appointments.eligible-resources') }}",
    weekTimes:         "{{ route('tenant.appointments.week-times') }}",
  };'''
assert s.count(old_r) == 1, f"B3c-routes count={s.count(old_r)}, expected 1"
s = s.replace(old_r, new_r)

# Replace open() body so it resets the new sequential-picker state.
old_o = '''  function open() {
    clearError();
    state.cart = [];
    state.customerId = null;
    state.availability = null;
    state.selectedSlot = null;
    state.manualOverride = false;
    stripState.lockedResourceId = null;
    stripState.lockedResourceName = '';
    el('appt-cust-attached').style.display = 'none';
    el('appt-cust-search-wrap').style.display = 'block';
    el('appt-cust-search').value = '';
    el('appt-cust-new-fields').style.display = 'none';
    ['appt-first','appt-last','appt-email','appt-phone','appt-notes'].forEach(function (id) { el(id).value = ''; });
    renderCart();
    renderAvailability();
    el('appt-svc-picker').style.display = 'none';
    state.pickerOpen = false;
    el('new-appt-modal').style.display = 'block';
    if (state.services.length === 0) loadInitialData();
    el('appt-cust-search').focus();
  }'''
new_o = '''  function open() {
    clearError();
    state.cart = [];
    state.customerId = null;
    state.selectedSlot = null;
    state.selectedServiceId = null;
    state.selectedResourceId = null;
    state.selectedResourceName = '';
    state.weekStartDate = todayStr();
    state.availSlots = [];
    state.availLoading = false;
    el('appt-cust-attached').style.display = 'none';
    el('appt-cust-search-wrap').style.display = 'block';
    el('appt-cust-search').value = '';
    el('appt-cust-new-fields').style.display = 'none';
    ['appt-first','appt-last','appt-email','appt-phone','appt-notes'].forEach(function (id) { el(id).value = ''; });
    // Reset sequential picker UI
    el('appt-sp-service').value = '';
    el('appt-sp-resource').innerHTML = '<option value="">Select a resource…</option>';
    el('appt-sp-resource-section').style.display = 'none';
    el('appt-sp-find-section').style.display = 'none';
    el('appt-sp-times-section').style.display = 'none';
    el('appt-sp-times-list').innerHTML = '<div class="appt-sp-times-empty">Loading…</div>';
    el('new-appt-modal').style.display = 'block';
    if (state.services.length === 0) loadInitialData();
    populateServices();
    el('appt-sp-service').focus();
  }

  function todayStr() {
    var d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
  }'''
assert s.count(old_o) == 1, f"B3c-open count={s.count(old_o)}, expected 1"
s = s.replace(old_o, new_o)

# Replace loadInitialData to NOT compute availability (we use its services + resources).
old_l = '''  function loadInitialData() {
    fetch(routes.pickerData, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        state.services  = data.services  || [];
        state.resources = data.resources || [];
      })
      .catch(function () { showError('Could not load services. Try again.'); });
  }'''
new_l = '''  function loadInitialData() {
    fetch(routes.pickerData, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        state.services  = data.services  || [];
        state.resources = data.resources || [];
        populateServices();
      })
      .catch(function () { showError('Could not load services. Try again.'); });
  }

  function populateServices() {
    var sel = el('appt-sp-service');
    if (!sel) return;
    var current = sel.value;
    sel.innerHTML = '<option value="">Select a service…</option>';
    state.services.forEach(function (svc) {
      var opt = document.createElement('option');
      opt.value = svc.id;
      var dur = svc.duration_minutes ? ' (' + svc.duration_minutes + ' min)' : '';
      var price = (svc.price_cents != null) ? ' · ' + fmt(svc.price_cents) : '';
      opt.textContent = svc.name + dur + price;
      sel.appendChild(opt);
    });
    if (current) sel.value = current;
  }'''
assert s.count(old_l) == 1, f"B3c-loadInitial count={s.count(old_l)}, expected 1"
s = s.replace(old_l, new_l)

# Submit: replace cart-array logic with single-service shape.
old_sub = '''  function submit() {
    clearError();
    if (state.cart.length === 0) return showError('Add at least one service.');
    if (!state.selectedSlot || !state.selectedSlot.date) return showError('Pick a time.');

    var btn = el('appt-submit');
    btn.disabled = true;
    btn.innerHTML = '<span class="appt-spin"></span>Creating…';

    var payload = {
      customer_id: state.customerId,
      appointment_date: state.selectedSlot.date,
      appointment_time: state.selectedSlot.time || null,
      resource_id: state.selectedSlot.resource_id || null,
      staff_notes: el('appt-notes').value || null,
      items: state.cart.map(function (l) {
        return {
          service_item_id: l.service_item_id,
          price_override_cents: l.override !== null && l.override !== l.price ? l.override : null,
        };
      }),
    };'''
new_sub = '''  function submit() {
    clearError();
    if (!state.selectedServiceId) return showError('Pick a service.');
    if (!state.selectedResourceId) return showError('Pick a resource.');
    if (!state.selectedSlot || !state.selectedSlot.date) return showError('Pick a time.');

    var btn = el('appt-submit');
    btn.disabled = true;
    btn.innerHTML = '<span class="appt-spin"></span>Creating…';

    var payload = {
      customer_id: state.customerId,
      appointment_date: state.selectedSlot.date,
      appointment_time: state.selectedSlot.time,
      resource_id: state.selectedResourceId,
      staff_notes: el('appt-notes').value || null,
      items: [
        { service_item_id: state.selectedServiceId, price_override_cents: null },
      ],
    };'''
assert s.count(old_sub) == 1, f"B3c-submit count={s.count(old_sub)}, expected 1"
s = s.replace(old_sub, new_sub)

# Replace the entire availability machinery (scheduleAvailabilityFetch,
# fetchAvailability, renderAvailability, day-strip helpers, manual override)
# with sequential-picker handlers. We anchor on a unique block from
# scheduleAvailabilityFetch start to right before formatSlotLabel which we keep.
# Then inject new handler block ABOVE the submit function.

# First: drop the old availability block (from "// ── Availability ──"
# down to just before "function formatTimeLabel" — but keep formatTimeLabel and submit).
import re
start_marker = '  // ── Availability ──\n'
# We need to find this block and the end where submit begins.
start_idx = s.index(start_marker)
# Find "  // ── Submit ──" which starts the next section
end_marker = '  // ── Submit ──\n'
end_idx = s.index(end_marker, start_idx)
old_block = s[start_idx:end_idx]
assert 'scheduleAvailabilityFetch' in old_block, "B3c-avail block missing scheduleAvailabilityFetch"
assert 'fetchAvailability' in old_block, "B3c-avail block missing fetchAvailability"
new_block = '''  // SEQUENTIAL-PICKER-HANDLERS v1
  // Service change → load eligible resources, reset downstream UI.
  function onServiceChange() {
    var sel = el('appt-sp-service');
    var serviceId = sel.value;
    state.selectedServiceId = serviceId || null;
    state.selectedResourceId = null;
    state.selectedResourceName = '';
    state.selectedSlot = null;
    el('appt-sp-resource').innerHTML = '<option value="">Loading resources…</option>';
    el('appt-sp-find-section').style.display = 'none';
    el('appt-sp-times-section').style.display = 'none';
    if (!serviceId) {
      el('appt-sp-resource-section').style.display = 'none';
      return;
    }
    el('appt-sp-resource-section').style.display = 'block';
    fetch(routes.eligibleResources + '?service_id=' + encodeURIComponent(serviceId), {
      headers: { 'Accept': 'application/json' }, credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var resources = data.resources || [];
        state.eligibleResources = resources;
        var rsel = el('appt-sp-resource');
        rsel.innerHTML = '<option value="">Select a resource…</option>';
        if (resources.length === 0) {
          rsel.innerHTML = '<option value="">No eligible resources for this service</option>';
          return;
        }
        resources.forEach(function (r) {
          var opt = document.createElement('option');
          opt.value = r.id;
          opt.textContent = r.name + (r.subtitle ? ' · ' + r.subtitle : '');
          rsel.appendChild(opt);
        });
      })
      .catch(function () { showError('Could not load resources.'); });
  }

  function onResourceChange() {
    var sel = el('appt-sp-resource');
    var resourceId = sel.value;
    state.selectedResourceId = resourceId || null;
    state.selectedResourceName = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : '';
    state.selectedSlot = null;
    el('appt-sp-times-section').style.display = 'none';
    if (resourceId) {
      el('appt-sp-find-section').style.display = 'block';
    } else {
      el('appt-sp-find-section').style.display = 'none';
    }
  }

  function onFindTimes() {
    if (!state.selectedServiceId || !state.selectedResourceId) return;
    state.weekStartDate = state.weekStartDate || todayStr();
    fetchWeekTimes();
  }

  function fetchWeekTimes() {
    var listEl = el('appt-sp-times-list');
    listEl.innerHTML = '<div class="appt-sp-times-empty">Loading…</div>';
    el('appt-sp-times-section').style.display = 'block';
    state.availLoading = true;
    el('appt-sp-week-label').textContent = formatWeekLabel(state.weekStartDate);
    el('appt-sp-prev-week').disabled = (state.weekStartDate <= todayStr());

    var url = routes.weekTimes
      + '?service_id='  + encodeURIComponent(state.selectedServiceId)
      + '&resource_id=' + encodeURIComponent(state.selectedResourceId)
      + '&start_date='  + encodeURIComponent(state.weekStartDate);
    fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        state.availLoading = false;
        state.availSlots = data.slots || [];
        renderTimes();
      })
      .catch(function () {
        state.availLoading = false;
        listEl.innerHTML = '<div class="appt-sp-times-empty error">Could not load available times.</div>';
      });
  }

  function renderTimes() {
    var listEl = el('appt-sp-times-list');
    if (!state.availSlots || state.availSlots.length === 0) {
      listEl.innerHTML = '<div class="appt-sp-times-empty">No available times this week. Try Next week →</div>';
      return;
    }
    var html = '';
    state.availSlots.forEach(function (slot, idx) {
      var isSel = state.selectedSlot
        && state.selectedSlot.date === slot.date
        && state.selectedSlot.time === slot.time;
      html += '<div class="appt-sp-time-row' + (isSel ? ' selected' : '') + '" data-idx="' + idx + '">'
        + '<span class="appt-sp-time-date">' + escapeHtml(slot.date_label) + '</span>'
        + '<span class="appt-sp-time-time">' + escapeHtml(slot.time_label) + '</span>'
        + '</div>';
    });
    listEl.innerHTML = html;
    listEl.querySelectorAll('.appt-sp-time-row').forEach(function (row) {
      row.addEventListener('click', function () {
        var idx = parseInt(row.getAttribute('data-idx'), 10);
        var slot = state.availSlots[idx];
        state.selectedSlot = {
          date: slot.date,
          time: slot.time,
          resource_id: state.selectedResourceId,
        };
        renderTimes();
      });
    });
  }

  function onPrevWeek() {
    if (!state.weekStartDate) return;
    var d = new Date(state.weekStartDate + 'T00:00:00');
    d.setDate(d.getDate() - 7);
    var ymd = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
    if (ymd < todayStr()) ymd = todayStr();
    state.weekStartDate = ymd;
    fetchWeekTimes();
  }

  function onNextWeek() {
    if (!state.weekStartDate) state.weekStartDate = todayStr();
    var d = new Date(state.weekStartDate + 'T00:00:00');
    d.setDate(d.getDate() + 7);
    state.weekStartDate = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
    fetchWeekTimes();
  }

  function formatWeekLabel(startDate) {
    if (!startDate) return '—';
    var s = new Date(startDate + 'T00:00:00');
    var e = new Date(s);
    e.setDate(e.getDate() + 6);
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return months[s.getMonth()] + ' ' + s.getDate() + ' – ' + months[e.getMonth()] + ' ' + e.getDate();
  }

  // Wire up sequential picker events (idempotent — only once).
  (function wireSequentialPicker() {
    var svcSel = el('appt-sp-service');
    if (!svcSel || svcSel.dataset.spWired) return;
    svcSel.dataset.spWired = '1';
    svcSel.addEventListener('change', onServiceChange);
    el('appt-sp-resource').addEventListener('change', onResourceChange);
    el('appt-sp-find').addEventListener('click', onFindTimes);
    el('appt-sp-prev-week').addEventListener('click', onPrevWeek);
    el('appt-sp-next-week').addEventListener('click', onNextWeek);
  })();

'''
s = s[:start_idx] + new_block + s[end_idx:]
print("OK B3c-avail-replace (avail machinery → sequential picker)")

p.write_text(s)
print("OK B3 (sequential picker rebuild)")
PY

# =============================================================================
# PHASE C — Drag/ghost coordination
# =============================================================================

# ─────────────────────────────────────────────────────────────────────────────
# C1. Drag handler toggles body class .ia-cal-dragging-active.
# Add on transition to dragging=true, remove on mouseup cleanup paths.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('public/js/tenant/calendar.js')
s = p.read_text()

old1 = '''        state.dragging = true;
        state.block.classList.add('ia-cal-appt-dragging');'''
new1 = '''        state.dragging = true;
        state.block.classList.add('ia-cal-appt-dragging');
        // DRAG-GHOST-COORD v1: signal placement to suspend its hover ghost.
        document.body.classList.add('ia-cal-dragging-active');'''
assert s.count(old1) == 1, f"C1-add count={s.count(old1)}, expected 1"
s = s.replace(old1, new1)

# Remove the body class on every place `state.block.classList.remove('ia-cal-appt-dragging')` runs.
# That string appears ~5x but only as appt-dragging. We chain via a helper. Simpler: hook into onMouseUp.
old2 = '''    function onMouseUp(e) {
      document.removeEventListener('mousemove', onMouseMove);
      document.removeEventListener('mouseup', onMouseUp);'''
new2 = '''    function onMouseUp(e) {
      document.removeEventListener('mousemove', onMouseMove);
      document.removeEventListener('mouseup', onMouseUp);
      // DRAG-GHOST-COORD v1
      document.body.classList.remove('ia-cal-dragging-active');'''
assert s.count(old2) == 1, f"C1-up count={s.count(old2)}, expected 1"
s = s.replace(old2, new2)

p.write_text(s)
print("OK C1 (drag toggles body class)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# C2. Placement.positionGhost early-return when dragging is active.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('public/js/tenant/calendar.js')
s = p.read_text()
old = '''    /** Compute snapped time + position for ghost given clientY in a column. */
    positionGhost: function (col, clientY) {
      var rect = col.getBoundingClientRect();'''
new = '''    /** Compute snapped time + position for ghost given clientY in a column. */
    positionGhost: function (col, clientY) {
      // DRAG-GHOST-COORD v1: don't show placement ghost while a drag is active.
      if (document.body.classList.contains('ia-cal-dragging-active')) {
        this.ghost.hidden = true;
        return;
      }
      var rect = col.getBoundingClientRect();'''
assert s.count(old) == 1, f"C2 count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK C2 (Placement guards on dragging-active class)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# B3d. Neutralize residual call sites of scheduleAvailabilityFetch in the
# (now-dead) cart helpers, and replace the lock_timeout recompute with
# fetchWeekTimes (the new equivalent in the sequential picker).
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/_create_modal.blade.php')
s = p.read_text()

# Cart helpers — drop the schedule call.
old1 = '''  function addServiceToCart(s) {
    state.cart.push({ service_item_id: s.id, name: s.name, duration: s.duration_minutes, price: s.price_cents, override: null });
    state.pickerOpen = false;
    el('appt-svc-picker').style.display = 'none';
    renderCart();
    scheduleAvailabilityFetch();
  }

  function removeFromCart(idx) {
    state.cart.splice(idx, 1);
    renderCart();
    scheduleAvailabilityFetch();
  }'''
new1 = '''  function addServiceToCart(s) {
    // SEQUENTIAL-PICKER-DEAD-PATH: cart helpers retained for compat, no-op in new flow.
    state.cart.push({ service_item_id: s.id, name: s.name, duration: s.duration_minutes, price: s.price_cents, override: null });
    state.pickerOpen = false;
    el('appt-svc-picker').style.display = 'none';
    renderCart();
  }

  function removeFromCart(idx) {
    state.cart.splice(idx, 1);
    renderCart();
  }'''
assert s.count(old1) == 1, f"B3d-cart count={s.count(old1)}, expected 1"
s = s.replace(old1, new1)

# Submit lock_timeout — call fetchWeekTimes instead of the removed scheduleAvailabilityFetch.
old2 = '''      // If the slot got taken between fetch and submit, refresh availability.
      if (res.body && res.body.code === 'lock_timeout') {
        showError('That slot was just taken. Recomputing…');
        scheduleAvailabilityFetch();
        btn.disabled = false; btn.innerHTML = 'Create Appointment';
        return;
      }'''
new2 = '''      // If the slot got taken between fetch and submit, refresh week-times.
      if (res.body && res.body.code === 'lock_timeout') {
        showError('That slot was just taken. Recomputing…');
        if (state.selectedServiceId && state.selectedResourceId) fetchWeekTimes();
        btn.disabled = false; btn.innerHTML = 'Create Appointment';
        return;
      }'''
assert s.count(old2) == 1, f"B3d-submit count={s.count(old2)}, expected 1"
s = s.replace(old2, new2)

p.write_text(s)
print("OK B3d (residual call sites neutralized)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# B3e. Remove the old multi-service "Services" cart markup. The new sequential
# picker renders its own Service section; leaving the old one in place would
# show two Service sections in the modal.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/_create_modal.blade.php')
s = p.read_text()
old = '''        {{-- Services --}}
        <div class="appt-section">
          <div class="appt-section-h">Services</div>
          <div id="appt-svc-list" class="appt-svc-list"></div>
          <button type="button" id="appt-svc-add-btn" class="appt-svc-add-btn" onclick="ApptModal.toggleServicePicker()">+ Add a service</button>
          <div id="appt-svc-picker" class="appt-svc-picker" style="display:none"></div>
          <div id="appt-svc-totals" class="appt-svc-totals" style="display:none">
            <span><span id="appt-svc-count">0 services</span> · <span id="appt-svc-duration">0 min</span></span>
            <strong id="appt-svc-total">$0.00</strong>
          </div>
        </div>

        '''
new = '        '
assert s.count(old) == 1, f"B3e count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK B3e (old multi-service cart markup removed)")
PY

# =============================================================================
# VERIFICATION
# =============================================================================
echo ""
echo "=== verifying patches applied ==="
fail=0
verify() {
  local file="$1" needle="$2" label="$3"
  local n
  n=$(grep -c -F -- "$needle" "$file" 2>/dev/null | tr -d '\n' || true)
  : "${n:=0}"
  if [ "${n:-0}" -ge 1 ] 2>/dev/null; then
    echo "  ✓ $label  (${n}× in $file)"
  else
    echo "  ✗ MISSING: $label  in $file"
    fail=1
  fi
}
verify_absent() {
  local file="$1" needle="$2" label="$3"
  local n
  n=$(grep -c -F -- "$needle" "$file" 2>/dev/null | tr -d '\n' || true)
  : "${n:=0}"
  if [ "${n:-0}" -eq 0 ] 2>/dev/null; then
    echo "  ✓ ABSENT: $label  (in $file)"
  else
    echo "  ✗ STILL PRESENT: $label  (${n}× in $file)"
    fail=1
  fi
}

# Phase A removals
verify_absent "resources/views/tenant/appointments/_create_modal.blade.php" 'CALENDAR-FIRST-OPENPLACED v1' "A1 openPlaced absent"
verify_absent "resources/views/tenant/appointments/_create_modal.blade.php" 'CALENDAR-FIRST-CHANGE-TIME v1' "A1 changeTime absent"
verify_absent "resources/views/tenant/appointments/_create_modal.blade.php" 'CALENDAR-FIRST-LOCKED-TIME v1' "A3 locked-CSS absent"
verify_absent "resources/views/tenant/appointments/_create_modal.blade.php" 'CALENDAR-FIRST-LOCK-GUARD v1' "A4 lock-guard absent"
verify_absent "resources/views/tenant/appointments/_create_modal.blade.php" 'appt-when-locked-section'    "A2 locked markup absent"
verify_absent "resources/views/tenant/appointments/_create_modal.blade.php" 'lockedPrefill'                "A1 lockedPrefill absent"
verify_absent "resources/views/tenant/calendar/index.blade.php"             "@include('tenant.appointments._create_modal')" "A5 modal include absent"
verify_absent "public/js/tenant/calendar.js"                                'CALENDAR-FIRST-INTERCEPT v1'  "A6 intercept v1 absent"

# Phase A keepers (should still be there)
verify "resources/views/tenant/calendar/index.blade.php" 'ia-cal-new-appt-btn-armed'                       "A keep new-appt button"
verify "resources/views/tenant/calendar/index.blade.php" 'ia-cal-placement-banner-text'                    "A keep banner"
verify "public/js/tenant/calendar.js"                    'CALENDAR-FIRST-PLACEMENT-JS v1'                  "A keep Placement JS"
verify "public/js/tenant/calendar.js"                    'PLACEMENT-INTERCEPT v2'                          "A6 new intercept"

# Phase B
verify "app/Http/Controllers/Tenant/AppointmentController.php" 'SEQUENTIAL-PICKER-ENDPOINTS v1'            "B1 controller methods"
verify "app/Http/Controllers/Tenant/AppointmentController.php" 'public function eligibleResources'         "B1 eligibleResources fn"
verify "app/Http/Controllers/Tenant/AppointmentController.php" 'public function weekTimes'                 "B1 weekTimes fn"
verify "routes/web.php"                                        'SEQUENTIAL-PICKER-ROUTES v1'              "B2 routes"
verify "routes/web.php"                                        "appointments.eligible-resources"           "B2 eligible route"
verify "routes/web.php"                                        "appointments.week-times"                    "B2 week-times route"
verify "resources/views/tenant/appointments/_create_modal.blade.php" 'SEQUENTIAL-PICKER v1'                 "B3 markup"
verify "resources/views/tenant/appointments/_create_modal.blade.php" 'SEQUENTIAL-PICKER-CSS v1'             "B3 css"
verify "resources/views/tenant/appointments/_create_modal.blade.php" 'SEQUENTIAL-PICKER-STATE v1'           "B3 state"
verify "resources/views/tenant/appointments/_create_modal.blade.php" 'SEQUENTIAL-PICKER-HANDLERS v1'        "B3 handlers"
verify "resources/views/tenant/appointments/_create_modal.blade.php" 'eligibleResources:'                   "B3 routes obj"
verify "resources/views/tenant/appointments/_create_modal.blade.php" 'weekTimes:'                            "B3 routes obj"

# Phase B removals
verify_absent "resources/views/tenant/appointments/_create_modal.blade.php" 'scheduleAvailabilityFetch'    "B3 sched fn absent"
verify_absent "resources/views/tenant/appointments/_create_modal.blade.php" 'function fetchAvailability'   "B3 fetchAvail absent"
verify_absent "resources/views/tenant/appointments/_create_modal.blade.php" 'function renderAvailability'  "B3 renderAvail absent"

# Phase C
verify "public/js/tenant/calendar.js" 'DRAG-GHOST-COORD v1' "C drag-ghost coord"
verify "public/js/tenant/calendar.js" "ia-cal-dragging-active" "C body class"

if [ "$fail" -ne 0 ]; then
  echo ""
  echo "✗ FAIL: one or more patches did not land. STOP — do not commit."
  exit 1
fi

echo ""
echo "✓ all verification markers green."
echo ""
echo "Next steps:"
echo "  git add -A && git commit -m 'rebuild: revert calendar-first overbuild + sequential picker + drag/ghost coord'"
echo "  git push"
echo "  On server: git pull && composer install --no-interaction && \\"
echo "    php artisan optimize:clear && php artisan view:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "=== rebuild patch complete ==="
