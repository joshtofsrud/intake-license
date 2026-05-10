#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Intake — calendar-first appointment placement
# Day 19 build script.
#
# Replaces the day-strip + carousel + alternatives "When" section of the
# create-appointment modal with the calendar-itself-as-picker pattern:
#
#   1. data-slot-min on calendar shell (so JS knows tenant slot interval)
#   2. + New Appointment button in calendar toolbar (day view only)
#   3. Placement banner + ghost block markup at end of @section('content')
#   4. Placement JS module + ghost tracking in calendar.js
#   5. Slot-click intercept: if armed → open ApptModal with prefill, else QuickBook
#   6. Modal include in calendar/index.blade.php (was only on appointments index)
#   7. Locked-time pill + open(prefill) wiring in _create_modal.blade.php
#
# Run from repo root (where artisan lives). Mac- and server-safe.
# Per Day-18 lessons: every patch verifies s.count(old)==1 before write.
# Final pass greps for unique post-patch markers and aborts if any missing.
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: must run from intake-license repo root (no artisan found in $(pwd))"; exit 1; }

echo "=== calendar-first patch starting ==="

# ─────────────────────────────────────────────────────────────────────────────
# PIECE 1: data-slot-min on .ia-cal-shell (day view only)
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/calendar/index.blade.php')
s = p.read_text()
old = '''       data-cal-px-per-min="1.4"
       data-cal-is-today="{{ $isToday ? '1' : '0' }}"
     @endif>'''
new = '''       data-cal-px-per-min="1.4"
       data-cal-is-today="{{ $isToday ? '1' : '0' }}"
       data-cal-slot-min="{{ $slotMin ?? 30 }}"
     @endif>'''
assert s.count(old) == 1, f"piece-1 anchor count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK piece-1 (data-slot-min on shell)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# PIECE 2: + New Appointment button + armed state, in calendar toolbar
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/calendar/index.blade.php')
s = p.read_text()
old = '''    <div class="ia-cal-toolbar-right">
      <button type="button" class="ia-cal-legend-trigger" id="ia-cal-legend-trigger"'''
new = '''    <div class="ia-cal-toolbar-right">
      @if($viewMode === 'day')
        <button type="button" class="ia-cal-new-appt-btn" id="ia-cal-new-appt-btn"
                data-armed="0"
                aria-label="Place a new appointment on the calendar">
          <span class="ia-cal-new-appt-btn-idle">+ New Appointment</span>
          <span class="ia-cal-new-appt-btn-armed">◉ Placing appointment</span>
        </button>
      @endif
      <button type="button" class="ia-cal-legend-trigger" id="ia-cal-legend-trigger"'''
assert s.count(old) == 1, f"piece-2 anchor count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK piece-2 (+ New Appointment button)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# PIECE 3: placement banner + ghost block markup, before @endsection
# Also includes the create-appointment modal on the calendar page (day view).
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/calendar/index.blade.php')
s = p.read_text()
old = '''@if($viewMode === 'day' && !empty($prefillCustomer ?? null))
<script>
  window.IntakeCalendarPrefill = @json($prefillCustomer);
</script>
@endif

@endsection'''
new = '''@if($viewMode === 'day' && !empty($prefillCustomer ?? null))
<script>
  window.IntakeCalendarPrefill = @json($prefillCustomer);
</script>
@endif

@if($viewMode === 'day')
{{-- ===== Placement-mode banner (hidden until armed) ===== --}}
<div class="ia-cal-placement-banner" id="ia-cal-placement-banner" hidden>
  <div class="ia-cal-placement-banner-left">
    <span class="ia-cal-placement-banner-icon">+</span>
    <span class="ia-cal-placement-banner-text">
      Click any open slot to place a <strong id="ia-cal-placement-duration">{{ $slotMin ?? 30 }}-minute</strong> appointment
    </span>
    <span class="ia-cal-placement-banner-meta">· duration adjusts when you pick services</span>
  </div>
  <button type="button" class="ia-cal-placement-banner-cancel" id="ia-cal-placement-cancel-btn">
    Cancel (Esc)
  </button>
</div>

{{-- ===== Ghost block (placeholder; JS positions/hides) ===== --}}
<div class="ia-cal-ghost-block" id="ia-cal-ghost-block" hidden>
  <div class="ia-cal-ghost-title">+ New appointment</div>
  <div class="ia-cal-ghost-meta" id="ia-cal-ghost-meta">—</div>
</div>

{{-- ===== Create-appointment modal (calendar-first entry point) ===== --}}
@include('tenant.appointments._create_modal')
@endif

@endsection'''
assert s.count(old) == 1, f"piece-3 anchor count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK piece-3 (placement banner + ghost block + modal include)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# PIECE 4: CSS for new-appt button, placement banner, ghost block.
# Appended to public/css/tenant/calendar.css (single concatenated block).
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('public/css/tenant/calendar.css')
s = p.read_text()
marker = '/* CALENDAR-FIRST-PLACEMENT v1 */'
if marker in s:
    print("SKIP piece-4 (CSS marker already present)")
else:
    addition = '''

/* CALENDAR-FIRST-PLACEMENT v1 */
/* + New Appointment button (toolbar) */
.ia-cal-new-appt-btn {
  background: var(--ia-accent, #BEF264);
  color: var(--ia-accent-text, #0a0a0a);
  border: 1px solid var(--ia-accent, #BEF264);
  padding: 6px 12px;
  font-size: 13px;
  font-weight: 600;
  border-radius: 6px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-family: inherit;
  transition: all .15s ease;
}
.ia-cal-new-appt-btn:hover { filter: brightness(1.08); }
.ia-cal-new-appt-btn[data-armed="1"] {
  background: rgba(190,242,100,0.08);
  color: var(--ia-accent, #BEF264);
}
.ia-cal-new-appt-btn-armed { display: none; }
.ia-cal-new-appt-btn[data-armed="1"] .ia-cal-new-appt-btn-idle { display: none; }
.ia-cal-new-appt-btn[data-armed="1"] .ia-cal-new-appt-btn-armed { display: inline; }

/* Placement banner */
.ia-cal-placement-banner {
  background: var(--ia-accent, #BEF264);
  color: var(--ia-accent-text, #0a0a0a);
  padding: 10px 18px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  font-size: 13px;
  font-weight: 500;
  margin: -1px 0 12px;
  border-radius: 0;
}
.ia-cal-placement-banner[hidden] { display: none; }
.ia-cal-placement-banner-left { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.ia-cal-placement-banner-icon {
  width: 18px; height: 18px;
  border-radius: 50%;
  background: var(--ia-accent-text, #0a0a0a);
  color: var(--ia-accent, #BEF264);
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 11px; font-weight: 700;
}
.ia-cal-placement-banner-meta { font-weight: 400; opacity: .7; }
.ia-cal-placement-banner-cancel {
  background: transparent;
  color: inherit;
  border: 1px solid rgba(10,10,10,0.3);
  padding: 4px 12px;
  font-size: 12px;
  font-weight: 500;
  border-radius: 6px;
  cursor: pointer;
  font-family: inherit;
}
.ia-cal-placement-banner-cancel:hover { background: rgba(10,10,10,0.08); }

/* Ghost block — absolute positioned, shown by JS */
.ia-cal-ghost-block {
  position: absolute;
  background: rgba(190,242,100,0.18);
  border: 1.5px dashed var(--ia-accent, #BEF264);
  border-radius: 6px;
  padding: 6px 8px;
  pointer-events: none;
  z-index: 50;
  color: var(--ia-accent, #BEF264);
  font-size: 11px;
  font-weight: 500;
  box-sizing: border-box;
  transition: top .04s linear;
}
.ia-cal-ghost-block[hidden] { display: none; }
.ia-cal-ghost-title { font-weight: 600; }
.ia-cal-ghost-meta { opacity: .8; margin-top: 2px; font-size: 10px; }

/* Placement-mode active: cell hover hint */
body.ia-cal-placement-armed .ia-cal-resource-col { cursor: crosshair; }
body.ia-cal-placement-armed .ia-cal-appt { cursor: crosshair; }
'''
    p.write_text(s + addition)
    print("OK piece-4 (CSS for placement UI appended)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# PIECE 5: Placement JS module — registers init, ghost tracking, Esc handler.
# Inserted just before the IIFE closing console.log line.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('public/js/tenant/calendar.js')
s = p.read_text()
old = "  console.log('[calendar] module loaded');\n})();"
new = '''  // ==========================================================================
  // Placement mode — calendar-first appointment placement
  // ==========================================================================
  // CALENDAR-FIRST-PLACEMENT-JS v1
  var Placement = {
    armed: false,
    slotMin: 30,
    serviceDurationMin: 30,    // mirrors slotMin until services chosen
    pxPerMin: 1.4,
    openMin: 0,
    closeMin: 1440,
    dateStr: null,
    ghost: null,
    ghostMeta: null,
    button: null,
    banner: null,
    cancelBtn: null,
    durationLabel: null,
    activeCol: null,
    preserved: null,           // stashed form state when "Change time" round-trips

    init: function () {
      var shell = document.querySelector('.ia-cal-shell');
      if (!shell) return;
      if (shell.getAttribute('data-view-mode') !== 'day') return;

      this.openMin  = parseInt(shell.getAttribute('data-cal-open-min'), 10) || 0;
      this.closeMin = parseInt(shell.getAttribute('data-cal-close-min'), 10) || 1440;
      this.pxPerMin = parseFloat(shell.getAttribute('data-cal-px-per-min')) || 1.4;
      this.slotMin  = parseInt(shell.getAttribute('data-cal-slot-min'), 10) || 30;
      this.serviceDurationMin = this.slotMin;

      var u = new URL(window.location.href);
      this.dateStr = u.searchParams.get('date') || new Date().toISOString().slice(0, 10);

      this.button         = document.getElementById('ia-cal-new-appt-btn');
      this.banner         = document.getElementById('ia-cal-placement-banner');
      this.cancelBtn      = document.getElementById('ia-cal-placement-cancel-btn');
      this.ghost          = document.getElementById('ia-cal-ghost-block');
      this.ghostMeta      = document.getElementById('ia-cal-ghost-meta');
      this.durationLabel  = document.getElementById('ia-cal-placement-duration');
      if (!this.button || !this.banner || !this.ghost) return;

      var self = this;
      this.button.addEventListener('click', function () {
        if (self.armed) self.disarm();
        else self.arm();
      });
      this.cancelBtn.addEventListener('click', function () { self.disarm(); });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && self.armed) {
          self.disarm();
          e.preventDefault();
        }
      });

      // Ghost tracking — bind mousemove on each resource column.
      document.querySelectorAll('.ia-cal-resource-col').forEach(function (col) {
        col.addEventListener('mousemove', function (e) {
          if (!self.armed) return;
          self.activeCol = col;
          self.positionGhost(col, e.clientY);
        });
        col.addEventListener('mouseleave', function () {
          if (!self.armed) return;
          self.ghost.hidden = true;
          self.activeCol = null;
        });
      });
    },

    arm: function () {
      this.armed = true;
      this.button.setAttribute('data-armed', '1');
      this.banner.hidden = false;
      document.body.classList.add('ia-cal-placement-armed');
      // (Ghost stays hidden until cursor enters a resource column.)
    },

    disarm: function () {
      this.armed = false;
      this.button.setAttribute('data-armed', '0');
      this.banner.hidden = true;
      this.ghost.hidden = true;
      document.body.classList.remove('ia-cal-placement-armed');
      this.activeCol = null;
    },

    isArmed: function () { return this.armed; },

    /** Compute snapped time + position for ghost given clientY in a column. */
    positionGhost: function (col, clientY) {
      var rect = col.getBoundingClientRect();
      var y = clientY - rect.top;
      var minutesFromOpen = Math.round(y / this.pxPerMin);
      var snap = this.slotMin;
      var snappedMin = Math.round(minutesFromOpen / snap) * snap;
      var totalMin = this.openMin + snappedMin;

      // Don't overflow past close time.
      if (totalMin + this.serviceDurationMin > this.closeMin) {
        totalMin = this.closeMin - this.serviceDurationMin;
        snappedMin = totalMin - this.openMin;
      }
      if (totalMin < this.openMin) {
        totalMin = this.openMin;
        snappedMin = 0;
      }

      var top    = Math.round(snappedMin * this.pxPerMin);
      var height = Math.round(this.serviceDurationMin * this.pxPerMin);

      // Anchor to column.
      this.ghost.style.left   = (rect.left + window.scrollX + 1) + 'px';
      this.ghost.style.top    = (rect.top  + window.scrollY + top) + 'px';
      this.ghost.style.width  = (rect.width - 2) + 'px';
      this.ghost.style.height = height + 'px';
      this.ghost.style.position = 'absolute';
      this.ghost.hidden = false;

      var endMin = totalMin + this.serviceDurationMin;
      this.ghostMeta.textContent = formatRange(totalMin, endMin);
    },

    /** Resolve current snapped time for a click event in a column. Returns {time, resourceId, resourceName}. */
    resolveClick: function (col, clientY) {
      var rect = col.getBoundingClientRect();
      var y = clientY - rect.top;
      var minutesFromOpen = Math.round(y / this.pxPerMin);
      var snap = this.slotMin;
      var snappedMin = Math.round(minutesFromOpen / snap) * snap;
      var totalMin = this.openMin + snappedMin;
      if (totalMin + this.serviceDurationMin > this.closeMin) {
        totalMin = this.closeMin - this.serviceDurationMin;
      }
      if (totalMin < this.openMin) totalMin = this.openMin;

      var hh = Math.floor(totalMin / 60);
      var mm = totalMin % 60;
      var time = (hh < 10 ? '0' + hh : hh) + ':' + (mm < 10 ? '0' + mm : mm);
      var resourceId = col.getAttribute('data-resource-id');
      var resourceName = 'Resource';
      try {
        var headers = document.querySelectorAll('.ia-cal-resource-head');
        var cols = document.querySelectorAll('.ia-cal-resource-col');
        var idx = Array.prototype.indexOf.call(cols, col);
        if (headers[idx]) {
          var nameEl = headers[idx].querySelector('.ia-cal-resource-name');
          if (nameEl) resourceName = nameEl.textContent.trim();
        }
      } catch (err) { /* fall back */ }
      return { time: time, resourceId: resourceId, resourceName: resourceName, totalMin: totalMin };
    },
  };
  window.IntakePlacement = Placement;

  function formatRange(startMin, endMin) {
    function fmt(m) {
      var h = Math.floor(m / 60), mm = m % 60;
      var ampm = h < 12 ? 'am' : 'pm';
      var h12 = h === 0 ? 12 : (h > 12 ? h - 12 : h);
      return h12 + (mm === 0 ? '' : ':' + (mm < 10 ? '0' + mm : mm)) + ampm;
    }
    return fmt(startMin) + '–' + fmt(endMin);
  }

  // Hook Placement init into existing boot flow.
  var _origBoot = boot;
  boot = function () {
    _origBoot();
    Placement.init();
  };
  if (document.readyState !== 'loading') Placement.init();

  console.log('[calendar] module loaded');
})();'''
assert s.count(old) == 1, f"piece-5 anchor count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK piece-5 (Placement JS module)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# PIECE 6: Slot-click intercept — if armed, open ApptModal with prefill;
# otherwise fall through to existing QuickBook.open.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('public/js/tenant/calendar.js')
s = p.read_text()
old = '''        QuickBook.open({
          date: dateStr,
          time: time,
          resourceId: resourceId,
          resourceName: resourceName,
        });
      });
    });
  }'''
new = '''        // CALENDAR-FIRST-INTERCEPT v1: armed placement mode bypasses QuickBook.
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
        });
      });
    });
  }'''
assert s.count(old) == 1, f"piece-6 anchor count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK piece-6 (slot-click intercept for armed mode)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# PIECE 7a: Locked-time pill markup, conditionally shown over the When section.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/_create_modal.blade.php')
s = p.read_text()
old = '''        {{-- When (availability-first) --}}
        <div class="appt-section">
          <div class="appt-section-h">When</div>
          <div id="appt-when-content">
            <div class="appt-when-empty">Add a service to see available times.</div>
          </div>
        </div>'''
new = '''        {{-- When (calendar-first locked-time pill, hidden by default) --}}
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
assert s.count(old) == 1, f"piece-7a anchor count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK piece-7a (locked-time pill markup)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# PIECE 7b: CSS for the locked-time pill, appended to modal <style>.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/_create_modal.blade.php')
s = p.read_text()
marker = '/* CALENDAR-FIRST-LOCKED-TIME v1 */'
if marker in s:
    print("SKIP piece-7b (CSS marker present)")
else:
    # Insert CSS just before the closing </style>. There's exactly one </style>
    # in this file (in the modal's <style> block).
    closer = '</style>'
    assert s.count(closer) == 1, f"piece-7b closer count={s.count(closer)}, expected 1"
    addition = '''
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
    p.write_text(s.replace(closer, addition + '  </style>'))
    print("OK piece-7b (locked-time CSS)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# PIECE 7c: openPlaced(prefill) function in ApptModal + Change time round-trip
# + submit-payload bypass when prefill is locked.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/_create_modal.blade.php')
s = p.read_text()

# Add prefilled flag + lockedSlot to state.
old1 = '''    selectedSlot: null,        // {date, time, resource_id}
    manualOverride: false,
    // Manual override fields (read at submit if manualOverride is true)
  };'''
new1 = '''    selectedSlot: null,        // {date, time, resource_id}
    manualOverride: false,
    // CALENDAR-FIRST-PREFILL v1: when set, skip availability UI, use these values
    lockedPrefill: null,       // {date, time, resourceId, resourceName}
    preservedForm: null,       // {customerId, cart, notes, custFields} stashed on Change-time
    // Manual override fields (read at submit if manualOverride is true)
  };'''
assert s.count(old1) == 1, f"piece-7c-state count={s.count(old1)}, expected 1"
s = s.replace(old1, new1)

# Add openPlaced + Change-time round-trip helpers immediately after `function close()`.
old2 = '''  function close() { el('new-appt-modal').style.display = 'none'; }'''
new2 = '''  function close() { el('new-appt-modal').style.display = 'none'; }

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
assert s.count(old2) == 1, f"piece-7c-funcs count={s.count(old2)}, expected 1"
s = s.replace(old2, new2)

# Wire Change-time click after open() function returns module.
old3 = '''  return {
    open: open, close: close, clearCustomer: clearCustomer,
    toggleServicePicker: toggleServicePicker, submit: submit,
  };
})();

window.openApptModal  = function () { ApptModal.open(); };
window.closeApptModal = function () { ApptModal.close(); };'''
new3 = '''  // Wire Change-time click (idempotent).
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
  };
})();

window.openApptModal  = function () { ApptModal.open(); };
window.closeApptModal = function () { ApptModal.close(); };'''
assert s.count(old3) == 1, f"piece-7c-export count={s.count(old3)}, expected 1"
s = s.replace(old3, new3)

p.write_text(s)
print("OK piece-7c (openPlaced + changeTime + module exports)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# Also: when the modal is reset (open() with no prefill), un-hide the
# availability section and hide the locked pill. Otherwise a calendar-first
# placement leaves the next list-page open() in a weird state.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/_create_modal.blade.php')
s = p.read_text()
old = '''    el('new-appt-modal').style.display = 'block';
    if (state.services.length === 0) loadInitialData();
    el('appt-cust-search').focus();
  }'''
new = '''    // CALENDAR-FIRST: reset locked-time UI when re-opening from list page.
    state.lockedPrefill = null;
    var lockedSec = el('appt-when-locked-section');
    var availSec = el('appt-when-availability-section');
    if (lockedSec) lockedSec.style.display = 'none';
    if (availSec)  availSec.style.display  = 'block';
    el('new-appt-modal').style.display = 'block';
    if (state.services.length === 0) loadInitialData();
    el('appt-cust-search').focus();
  }'''
assert s.count(old) == 1, f"piece-7d count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK piece-7d (open() resets locked-time UI)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# VERIFICATION: unique post-patch markers must all be present (per Day-18 lesson).
# Each grep checks a string that didn't exist before this script ran.
# ─────────────────────────────────────────────────────────────────────────────
echo ""
echo "=== verifying patches applied ==="
fail=0
verify() {
  local file="$1" needle="$2" label="$3"
  local n
  n=$(grep -c -F -- "$needle" "$file" 2>/dev/null || echo 0)
  if [ "$n" -ge 1 ]; then
    echo "  ✓ $label  ($n× in $file)"
  else
    echo "  ✗ MISSING: $label  in $file"
    fail=1
  fi
}

verify "resources/views/tenant/calendar/index.blade.php" 'data-cal-slot-min="{{ $slotMin'                "piece-1 data-slot-min"
verify "resources/views/tenant/calendar/index.blade.php" 'ia-cal-new-appt-btn-armed'                       "piece-2 new-appt button"
verify "resources/views/tenant/calendar/index.blade.php" 'ia-cal-placement-banner-text'                    "piece-3 placement banner"
verify "resources/views/tenant/calendar/index.blade.php" "@include('tenant.appointments._create_modal')"   "piece-3 modal include"
verify "public/css/tenant/calendar.css"                  'CALENDAR-FIRST-PLACEMENT v1'                     "piece-4 CSS"
verify "public/js/tenant/calendar.js"                    'CALENDAR-FIRST-PLACEMENT-JS v1'                  "piece-5 Placement JS"
verify "public/js/tenant/calendar.js"                    'CALENDAR-FIRST-INTERCEPT v1'                     "piece-6 slot-click intercept"
verify "resources/views/tenant/appointments/_create_modal.blade.php" 'appt-when-locked-section'            "piece-7a locked pill markup"
verify "resources/views/tenant/appointments/_create_modal.blade.php" 'CALENDAR-FIRST-LOCKED-TIME v1'       "piece-7b locked-time CSS"
verify "resources/views/tenant/appointments/_create_modal.blade.php" 'CALENDAR-FIRST-OPENPLACED v1'        "piece-7c openPlaced fn"
verify "resources/views/tenant/appointments/_create_modal.blade.php" 'CALENDAR-FIRST-CHANGE-TIME v1'       "piece-7c changeTime fn"
verify "resources/views/tenant/appointments/_create_modal.blade.php" "openPlaced: openPlaced"              "piece-7c export"

if [ "$fail" -ne 0 ]; then
  echo ""
  echo "✗ FAIL: one or more patches did not land. STOP — do not commit."
  exit 1
fi

echo ""
echo "✓ all 12 verification markers present."
echo ""
echo "Next steps:"
echo "  1. Test locally: open /admin/calendar?view=day, click '+ New Appointment'"
echo "  2. git add -A && git commit -m 'calendar-first appointment placement (Day 19)'"
echo "  3. git push"
echo "  4. On server: git pull && composer install --no-interaction && \\"
echo "     php artisan optimize:clear && php artisan view:clear && \\"
echo "     sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "=== calendar-first patch complete ==="
