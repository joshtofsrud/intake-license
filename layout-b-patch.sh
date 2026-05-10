#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Intake — Appointment detail page redesign (Layout B)
#
# Layout B: 280px left rail (sticky) + main column.
#
# Left rail (top to bottom):
#   1. Time/date hero band (NEW — appointment_time was nowhere on the page before)
#   2. Vertical status pipeline (existing horizontal one, re-CSS'd to vertical;
#      JS bindings unchanged because we only swap CSS, not DOM)
#   3. Action button stack (Reschedule [coming soon], +Note, Cancel)
#   4. Customer card (relocated from old right rail)
#
# Main column (top to bottom):
#   - Services (was already there)
#   - Products & add-ons (was already there)
#   - Work order details (was already there)
#   - Customer intake responses (was already there)
#   - Additional charges (was already there)
#   - Notes (was already there)
#   - Resource picker (relocated from right rail — important inline)
#   - Capacity slots (relocated from right rail — important inline)
#   - Payment ledger (relocated from right rail)
#
# IMPORTANT design constraint: do NOT move DOM elements that have data-attribute
# JS hooks (data-appt-resource-card, data-appt-slot-weight-save, .appt-progress-bar,
# .appt-cancel-btn, etc). The existing 800 lines of JS bind by querySelector.
# Moving DOM = breaking JS. Use CSS grid + a single layout wrapper instead.
#
# Strategy: wrap everything inside @section('content') with a new structure that
# uses CSS grid to position the rail on the left. The cards themselves keep
# their data-attribute hooks. New hero band + new vertical pipeline CSS rules
# + new action stack are additions only.
#
# Per Day-18 lessons: every patch verifies s.count(old)==1 before write. Final
# pass greps for unique post-patch markers.
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: must run from intake-license repo root (no artisan found in $(pwd))"; exit 1; }

echo "=== Layout B patch starting ==="

# ─────────────────────────────────────────────────────────────────────────────
# Phase 1: ADD new CSS for Layout B (appended to the existing <style> block).
# Keeps existing styles intact; new rules use .appt-b-* prefix and override the
# .appt-progress-bar to render vertically.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()
marker = '/* LAYOUT-B-CSS v1 */'
if marker in s:
    print("SKIP layout-b css (already present)")
else:
    closer = '</style>'
    assert s.count(closer) == 1, f"</style> count={s.count(closer)}, expected 1"
    addition = '''
/* LAYOUT-B-CSS v1 */
.appt-b-shell { display: grid; grid-template-columns: 280px 1fr; gap: 20px; align-items: start; }
.appt-b-rail { display: flex; flex-direction: column; gap: 14px; position: sticky; top: 16px; }
.appt-b-main { display: flex; flex-direction: column; gap: 18px; }
@media (max-width: 900px) { .appt-b-shell { grid-template-columns: 1fr; } .appt-b-rail { position: static; } }

/* Time/date hero band — left rail, accent border-left */
.appt-b-when {
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-left: 3px solid var(--ia-accent);
  border-radius: var(--ia-r-md);
  padding: 14px 16px;
}
.appt-b-when-time {
  font-size: 22px; font-weight: 500; letter-spacing: -.01em; line-height: 1.15;
  color: var(--ia-text);
}
.appt-b-when-date { font-size: 12px; color: var(--ia-text-muted); margin-top: 4px; }
.appt-b-when-dur  { font-size: 11px; color: var(--ia-text-muted); margin-top: 6px; opacity: .7; }
.appt-b-when-resource {
  margin-top: 12px; padding-top: 10px;
  border-top: 0.5px solid var(--ia-border);
  font-size: 12px; color: var(--ia-text-muted);
}
.appt-b-when-resource .who { color: var(--ia-text); font-weight: 500; }
.appt-b-when-resource .swatch {
  display: inline-block; width: 8px; height: 8px;
  border-radius: 50%; margin-right: 6px; vertical-align: 1px;
}

/* Vertical status pipeline — overrides the horizontal one when wrapped in .appt-b-rail */
.appt-b-rail .appt-progress-card { padding: 14px 16px; }
.appt-b-rail .appt-progress-bar {
  flex-direction: column;
  align-items: stretch;
  gap: 0;
}
.appt-b-rail .appt-progress-bar::before,
.appt-b-rail .appt-progress-bar::after { display: none; }
.appt-b-rail .appt-progress-step {
  flex-direction: row;
  justify-content: flex-start;
  gap: 12px;
  padding: 8px 0;
  text-align: left;
  position: relative;
}
.appt-b-rail .appt-progress-step:not(:last-child)::after {
  content: ''; position: absolute;
  left: 11.25px; top: calc(50% + 12px); bottom: -8px;
  width: 1.5px; background: var(--ia-border);
  z-index: 0;
}
.appt-b-rail .appt-progress-step.is-done:not(:last-child)::after { background: var(--ia-accent); }
.appt-b-rail .appt-progress-step.is-done + .appt-progress-step.is-current::after,
.appt-b-rail .appt-progress-step.is-done + .appt-progress-step.is-done::after { background: var(--ia-accent); }
.appt-b-rail .appt-progress-dot { flex-shrink: 0; }
.appt-b-rail .appt-progress-label {
  font-size: 13px; line-height: 1.3;
}

/* Action button stack */
.appt-b-actions {
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-md);
  padding: 8px;
  display: flex; flex-direction: column; gap: 4px;
}
.appt-b-actions .ia-btn { width: 100%; justify-content: flex-start; padding: 8px 12px; font-size: 13px; }
.appt-b-actions-divider { height: 0.5px; background: var(--ia-border); margin: 4px 4px; }
.appt-b-action-coming-soon {
  font-size: 11px; color: var(--ia-text-muted);
  padding: 0 12px 6px; opacity: .55;
}

/* Rail customer card — slightly tighter than the original */
.appt-b-rail .ia-card { padding: 14px 16px; }
.appt-b-rail .appt-section-label { margin-bottom: 8px; }

/* Time/date inline meta on hero (re-shows at bottom of band) */
.appt-b-meta-grid {
  display: grid; grid-template-columns: auto 1fr; gap: 4px 10px;
  font-size: 12px; color: var(--ia-text-muted);
  margin-top: 10px;
}
.appt-b-meta-grid .lbl { opacity: .65; }

/* Inline capacity-in-work-order grid (3-up) */
.appt-b-wo-grid {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px;
  margin-top: 8px;
}
.appt-b-wo-cell .lbl { font-size: 11px; color: var(--ia-text-muted); margin-bottom: 4px; }
.appt-b-wo-cell .val { font-size: 13px; }

/* Hide the original right-rail cancel button when in B layout — JS still finds it,
   but visually the new rail action handles it. */
.appt-b-shell .appt-cancel-btn-original { display: none !important; }
'''
    s = s.replace(closer, addition + '\n</style>')
    p.write_text(s)
    print("OK 1 (LAYOUT-B-CSS appended)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# Phase 2: Replace the .appt-layout container with the new B shell.
# Wraps the LEFT rail (new) and the MAIN (existing).
# Move the existing right-rail content (lines 627+) into the main column AT THE END,
# so resource/capacity/payment cards are the last things in the main column instead
# of in a right rail.
#
# We do this in two steps:
#   2a. Replace `<div class="appt-layout">` opening with the B shell.
#   2b. Insert the new left rail just inside the shell (before the existing main column).
#   2c. Append the right-rail content's wrapper div.
# ─────────────────────────────────────────────────────────────────────────────

# 2a — replace shell + insert left rail
python3 <<'PY'
from pathlib import Path
import re
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()

old = '''<div class="appt-layout">

  <div style="display:flex;flex-direction:column;gap:20px">'''
new = '''<div class="appt-b-shell">

  {{-- LAYOUT-B-RAIL v1 — left rail: time/date, status, actions, customer --}}
  <aside class="appt-b-rail">

    {{-- Time/date hero band --}}
    @php
      try {
        $apptStartC = $appointment->appointment_time
          ? \\Carbon\\Carbon::parse($appointment->appointment_date->toDateString() . ' ' . $appointment->appointment_time)
          : null;
        $durationMin = (int) ($appointment->total_duration_minutes ?? 0);
        $apptEndC   = ($apptStartC && $durationMin > 0) ? $apptStartC->copy()->addMinutes($durationMin) : null;
      } catch (\\Throwable $e) {
        $apptStartC = null; $apptEndC = null; $durationMin = 0;
      }
    @endphp
    @if($apptStartC)
      <div class="appt-b-when">
        <div class="appt-b-when-time">
          {{ $apptStartC->format('g:i A') }}@if($apptEndC) – {{ $apptEndC->format('g:i A') }}@endif
        </div>
        <div class="appt-b-when-date">
          {{ $appointment->appointment_date->format('l, M j, Y') }}
        </div>
        @if($durationMin > 0)
          <div class="appt-b-when-dur">{{ $durationMin }} min</div>
        @endif
        @if($currentResource ?? null)
          <div class="appt-b-when-resource">
            <span class="swatch" style="background: {{ ($availableResources->firstWhere('id', $appointment->resource_id))->color_hex ?? '#888' }}"></span>
            <span class="who">{{ ($availableResources->firstWhere('id', $appointment->resource_id))->name ?? 'Unassigned' }}</span>
          </div>
        @else
          @php $rr = $availableResources->firstWhere('id', $appointment->resource_id); @endphp
          @if($rr)
            <div class="appt-b-when-resource">
              <span class="swatch" style="background: {{ $rr->color_hex ?? '#888' }}"></span>
              <span class="who">{{ $rr->name }}</span>
            </div>
          @endif
        @endif
      </div>
    @else
      <div class="appt-b-when">
        <div class="appt-b-when-time" style="font-size:15px;font-weight:500">
          {{ $appointment->appointment_date->format('l, M j, Y') }}
        </div>
        <div class="appt-b-when-dur">No time set</div>
      </div>
    @endif

    {{-- Status pipeline (markup is fed into vertical CSS by .appt-b-rail wrapper) --}}
    @if($isTerminal)
      {{-- Terminal state — show compact card --}}
      <div class="ia-card appt-terminal-card" style="padding:12px 14px">
        <div class="appt-terminal-icon appt-terminal-icon--{{ $appointment->status }}">
          @if($appointment->status === 'cancelled')
            <svg width="14" height="14" viewBox="0 0 10 10" fill="none"><path d="M2.5 2.5l5 5M7.5 2.5l-5 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
          @else
            <svg width="14" height="14" viewBox="0 0 10 10" fill="none"><path d="M2 5h6M5 2v6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
          @endif
        </div>
        <div>
          <div class="appt-terminal-title" style="font-size:13px">{{ $statusLabels[$appointment->status] }}</div>
        </div>
        <button type="button" class="ia-btn ia-btn--secondary ia-btn--sm appt-reopen-btn" data-status="pending" style="margin-left:auto">
          Reopen
        </button>
      </div>
    @else
      <div class="ia-card appt-progress-card">
        <div class="appt-progress-bar" data-current-index="{{ $currentIndex }}" data-update-url="{{ $updateUrl }}">
          @foreach($pipelineSteps as $idx => $step)
            @php
              $stepLabel = $statusLabels[$step];
              $isDone    = $idx < $currentIndex;
              $isCurrent = $idx === $currentIndex;
            @endphp
            <button type="button"
                    class="appt-progress-step {{ $isDone ? 'is-done' : '' }} {{ $isCurrent ? 'is-current' : '' }}"
                    data-status="{{ $step }}"
                    data-step-index="{{ $idx }}"
                    data-label="{{ $stepLabel }}">
              <span class="appt-progress-dot">
                @if($isDone)
                  <svg width="12" height="12" viewBox="0 0 10 10" fill="none"><path d="M2 5l2 2 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                @elseif($isCurrent)
                  <span class="appt-progress-dot-inner"></span>
                @endif
              </span>
              <span class="appt-progress-label">{{ $stepLabel }}</span>
            </button>
          @endforeach
        </div>
      </div>
    @endif

    {{-- Action stack --}}
    @unless($isTerminal)
    <div class="appt-b-actions">
      <button type="button" class="ia-btn ia-btn--secondary appt-b-reschedule-btn">↻ Reschedule</button>
      <div class="appt-b-action-coming-soon">Reschedule shipping tomorrow</div>
      <div class="appt-b-actions-divider"></div>
      <button type="button" class="ia-btn ia-btn--ghost ia-btn--danger appt-b-cancel-btn">Cancel appointment</button>
    </div>
    @endunless

    {{-- Customer card --}}
    <div class="ia-card ia-card--tight">
      <div class="appt-section-label">Customer</div>
      <div style="font-weight:500;margin-bottom:4px">
        {{ $appointment->customerName() }}
      </div>
      <div style="font-size:13px;opacity:.6;margin-bottom:2px">
        {{ $appointment->customer_email }}
      </div>
      @if($appointment->customer_phone)
        <div style="font-size:13px;opacity:.6;margin-bottom:10px">
          {{ $appointment->customer_phone }}
        </div>
      @else
        <div style="margin-bottom:10px"></div>
      @endif
      @if($appointment->customer_id)
        <a href="{{ route('tenant.customers.show', $appointment->customer_id) }}"
           class="ia-btn ia-btn--secondary ia-btn--sm" style="width:100%;justify-content:center">
          View customer profile →
        </a>
      @endif
    </div>

  </aside>

  {{-- Main column starts here (existing content unchanged) --}}
  <div class="appt-b-main" style="display:flex;flex-direction:column;gap:20px">'''

assert s.count(old) == 1, f"shell-open count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK 2a (B shell + left rail inserted)")
PY

# 2b — Now we need to handle the OLD right-rail container.
# Original file had two children inside .appt-layout: the main column and the right rail.
# We need to: (a) move the right-rail content INTO the main column (at the bottom),
# but stripped of the wrapping flex container. (b) remove the now-empty right rail.
#
# The original right rail wrapper is at line ~627: `<div style="display:flex;flex-direction:column;gap:16px">`
# Containing: customer card, resource card, slot weight card, payment ledger card, cancel button.
#
# Strategy: We DON'T move the customer card (we have a new one in left rail). We move
# resource, slot-weight, payment INTO main column. Cancel button gets hidden via class.
# ─────────────────────────────────────────────────────────────────────────────

python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()

# Match the right-rail wrapper start. The exact original pattern:
old = '''  <div style="display:flex;flex-direction:column;gap:16px">

    {{-- Customer --}}
    <div class="ia-card ia-card--tight">
      <div class="appt-section-label">Customer</div>'''

# We replace it with a NON-rendering wrapper for the customer card section
# (keeps the customer card in DOM for any potential JS but visually hidden,
# since we have the new rail customer card),
# and the rest of the right-rail cards continue as siblings inside the main column.
new = '''  {{-- LAYOUT-B-MOVED v1 — original right-rail content now lives at the bottom of main --}}
  <div style="display:flex;flex-direction:column;gap:16px;width:100%">

    {{-- Customer card (kept in DOM to avoid breaking any potential JS, hidden in B layout) --}}
    <div class="ia-card ia-card--tight" style="display:none" aria-hidden="true">
      <div class="appt-section-label">Customer</div>'''

assert s.count(old) == 1, f"rail-open count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK 2b (old customer card hidden; rail wrapper marked)")
PY

# 2c — Hide the cancel button at line 838 (still in DOM for JS hooks),
# add class so the rail's Cancel can wire to its handler.
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()
old = '''    {{-- Cancel appointment (destructive, separate from forward flow) --}}
    @unless(in_array($appointment->status, ['cancelled', 'refunded']))
      <button type="button" class="ia-btn ia-btn--danger ia-btn--sm appt-cancel-btn" style="width:100%">
        Cancel appointment
      </button>
    @endunless'''
new = '''    {{-- Cancel appointment — DOM kept, hidden in B layout; rail Cancel proxies to this --}}
    @unless(in_array($appointment->status, ['cancelled', 'refunded']))
      <button type="button" class="ia-btn ia-btn--danger ia-btn--sm appt-cancel-btn appt-cancel-btn-original" style="width:100%">
        Cancel appointment
      </button>
    @endunless'''
assert s.count(old) == 1, f"cancel-orig count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK 2c (original cancel marked hidden-class)")
PY

# 2d — The closing of the original .appt-layout div needs to also close .appt-b-shell.
# The original close pattern:
#     </div>                  (close right-rail wrapper)
#   </div>                    (close .appt-layout)
#
# We don't actually need to change the closing — `</div></div>` still closes our two
# new wrappers (.appt-b-main and .appt-b-shell). We replaced the OPEN tags only.
# Verify by matching the close pair stays intact.

# ─────────────────────────────────────────────────────────────────────────────
# Phase 3: Remove the OLD top-of-page status pipeline card and terminal card.
# They'd now appear DUPLICATED with the rail's pipeline. We delete the original
# block that runs from `@if($isTerminal)` through the `@endif` after the pipeline
# (lines 166-209). The rail's copy renders the same with same data attributes.
# JS uses querySelector (single) — so we must have only one. The rail's copy wins.
# ─────────────────────────────────────────────────────────────────────────────

python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()
old = '''@if($isTerminal)
  <div class="ia-card appt-terminal-card">
    <div class="appt-terminal-icon appt-terminal-icon--{{ $appointment->status }}">
      @if($appointment->status === 'cancelled')
        <svg width="14" height="14" viewBox="0 0 10 10" fill="none"><path d="M2.5 2.5l5 5M7.5 2.5l-5 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
      @else
        <svg width="14" height="14" viewBox="0 0 10 10" fill="none"><path d="M2 5h6M5 2v6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
      @endif
    </div>
    <div>
      <div class="appt-terminal-title">{{ $statusLabels[$appointment->status] }}</div>
      <div class="appt-terminal-sub">This appointment is {{ $appointment->status }}. Use Reopen to revert.</div>
    </div>
    <button type="button" class="ia-btn ia-btn--secondary ia-btn--sm appt-reopen-btn" data-status="pending">
      Reopen
    </button>
  </div>
@else
  <div class="ia-card appt-progress-card">
    <div class="appt-progress-bar" data-current-index="{{ $currentIndex }}" data-update-url="{{ $updateUrl }}">
      @foreach($pipelineSteps as $idx => $step)
        @php
          $stepLabel = $statusLabels[$step];
          $isDone    = $idx < $currentIndex;
          $isCurrent = $idx === $currentIndex;
        @endphp
        <button type="button"
                class="appt-progress-step {{ $isDone ? 'is-done' : '' }} {{ $isCurrent ? 'is-current' : '' }}"
                data-status="{{ $step }}"
                data-step-index="{{ $idx }}"
                data-label="{{ $stepLabel }}">
          <span class="appt-progress-dot">
            @if($isDone)
              <svg width="12" height="12" viewBox="0 0 10 10" fill="none"><path d="M2 5l2 2 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            @elseif($isCurrent)
              <span class="appt-progress-dot-inner"></span>
            @endif
          </span>
          <span class="appt-progress-label">{{ $stepLabel }}</span>
        </button>
      @endforeach
    </div>
  </div>
@endif

<div class="appt-b-shell">'''
new = '''{{-- LAYOUT-B-PIPELINE-RELOCATED v1: original full-width status pipeline removed.
     The rail (above) renders the same markup with the same JS hooks. --}}

<div class="appt-b-shell">'''
assert s.count(old) == 1, f"pipeline-removal count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK 3 (top-of-page pipeline removed; rail renders the only copy)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# Phase 4: Wire the rail's Cancel button to proxy to the original (hidden) one.
# Rail's Reschedule button shows a "coming soon" toast.
# Add a small JS block at the bottom of the @push('scripts') section.
# ─────────────────────────────────────────────────────────────────────────────

python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()
marker = 'LAYOUT-B-WIRING v1'
if marker in s:
    print("SKIP wiring (already present)")
else:
    # Find the </script> at the END of the @push('scripts') block.
    # There may be multiple <script> tags; we want the last one inside @push.
    # Pattern: @push('scripts') ... <script> ... </script> @endpush
    # The @endpush is the unique anchor.
    old = '@endpush'
    # Inject the JS just before @endpush
    addition = '''
<script>
// LAYOUT-B-WIRING v1
// Rail "Cancel" button proxies to the original cancel handler.
// Rail "Reschedule" shows a coming-soon toast.
document.addEventListener('DOMContentLoaded', function () {
  var railCancel = document.querySelector('.appt-b-cancel-btn');
  if (railCancel) {
    railCancel.addEventListener('click', function () {
      var orig = document.querySelector('.appt-cancel-btn-original');
      if (orig) orig.click();
    });
  }

  var railResch = document.querySelector('.appt-b-reschedule-btn');
  if (railResch) {
    railResch.addEventListener('click', function () {
      if (window.IntakeToast) {
        window.IntakeToast.info('Reschedule from this page ships tomorrow. For now, drag the appointment block on the calendar to move it.');
      }
    });
  }
});
</script>
'''
    assert s.count(old) >= 1, f"@endpush count={s.count(old)}, expected >= 1"
    # Replace the LAST @endpush only — but in this file there's only one @push('scripts')
    # so one @endpush. Let's verify:
    if s.count(old) == 1:
        s = s.replace(old, addition + old)
        p.write_text(s)
        print("OK 4 (wiring injected before @endpush)")
    else:
        # Replace only the last @endpush
        idx = s.rfind(old)
        s = s[:idx] + addition + s[idx:]
        p.write_text(s)
        print(f"OK 4 (wiring injected before LAST of {s.count(old)+1} @endpush)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# VERIFICATION
# ─────────────────────────────────────────────────────────────────────────────
echo ""
echo "=== verifying ==="
fail=0
verify() {
  local file="$1" needle="$2" label="$3"
  local n
  n=$(grep -c -F -- "$needle" "$file" 2>/dev/null | tr -d '\n' || true)
  : "${n:=0}"
  if [ "${n:-0}" -ge 1 ] 2>/dev/null; then
    echo "  ✓ $label  (${n}× in $file)"
  else
    echo "  ✗ MISSING: $label"
    fail=1
  fi
}
verify_count() {
  local file="$1" needle="$2" expect="$3" label="$4"
  local n
  n=$(grep -c -F -- "$needle" "$file" 2>/dev/null | tr -d '\n' || true)
  : "${n:=0}"
  if [ "${n:-0}" -eq "$expect" ] 2>/dev/null; then
    echo "  ✓ $label  (${n}× = $expect, expected)"
  else
    echo "  ✗ COUNT MISMATCH: $label  (got ${n}, expected $expect)"
    fail=1
  fi
}

verify       "resources/views/tenant/appointments/show.blade.php" "LAYOUT-B-CSS v1"             "1 CSS block"
verify       "resources/views/tenant/appointments/show.blade.php" "LAYOUT-B-RAIL v1"            "2 rail markup"
verify       "resources/views/tenant/appointments/show.blade.php" 'class="appt-b-shell"'        "2 shell"
verify       "resources/views/tenant/appointments/show.blade.php" "LAYOUT-B-MOVED v1"           "2b moved comment"
verify       "resources/views/tenant/appointments/show.blade.php" "appt-cancel-btn-original"    "2c original cancel marker"
verify       "resources/views/tenant/appointments/show.blade.php" "LAYOUT-B-PIPELINE-RELOCATED" "3 pipeline relocated"
verify       "resources/views/tenant/appointments/show.blade.php" "LAYOUT-B-WIRING v1"          "4 wiring"

# Critical: only ONE .appt-progress-bar (JS uses querySelector single).
verify_count "resources/views/tenant/appointments/show.blade.php" 'class="appt-progress-bar"' 1 "single progress-bar in DOM"
# Critical: only ONE .appt-cancel-btn-original (or the original was renamed).
verify_count "resources/views/tenant/appointments/show.blade.php" 'class="ia-btn ia-btn--danger ia-btn--sm appt-cancel-btn appt-cancel-btn-original"' 1 "single original cancel"

if [ "$fail" -ne 0 ]; then
  echo ""
  echo "✗ FAIL — STOP, do not commit"
  exit 1
fi

echo ""
echo "✓ all verification markers green"
echo ""
echo "Next:"
echo "  git add -A && git commit -m 'appt detail page: Layout B (left rail, hero band, vertical pipeline)'"
echo "  git push"
echo "  Server: git pull && php artisan view:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "=== Layout B patch complete ==="
