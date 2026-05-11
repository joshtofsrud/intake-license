#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Mobile schedule rebuild.
#
# Adds a parallel mobile-first schedule view that renders at ≤900px.
# Desktop time-axis × resource-columns calendar is untouched (it still
# renders, just hidden via CSS on mobile). Same controller data — no
# backend changes.
#
# What's added:
#   1. New partial _mobile-schedule.blade.php — handles both day + week views
#      using the same $appointments / $byResourceDate the controller passes.
#   2. Hide-desktop / show-mobile CSS at ≤900px.
#   3. New mobile-schedule.css with day strip, list rendering, mode toggle.
#   4. Include the partial from calendar/index.blade.php right before the
#      desktop view dispatcher.
#   5. Add @section('mobile-fab', 'walk-in') to calendar/index.
#
# Why a parallel render and not a viewport-aware single render?
#   - The desktop calendar has 1500+ lines of JS for drag/resize/QuickBook
#     hooked to specific DOM. Touching that markup risks breaking working
#     desktop UX. A parallel render is zero-risk to desktop.
#   - CSS hide+show is fast; the cost is rendering markup that's never
#     painted on the wrong device. Markup is cheap.
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: not in intake-license repo root"; exit 1; }

echo "=== mobile schedule starting ==="

# ─────────────────────────────────────────────────────────────────────────────
# 1. Create the mobile schedule partial.
# ─────────────────────────────────────────────────────────────────────────────
mkdir -p resources/views/tenant/calendar
cat > resources/views/tenant/calendar/_mobile-schedule.blade.php <<'BLADE'
{{-- ================================================================
     Mobile schedule view (≤900px only)
     Renders day-list or week-grouped-list using the same controller
     data as the desktop calendar. Hidden via CSS on desktop.

     Day mode: vertical scroll, time column + body card per appointment,
               resource-color left border. Day-strip up top to jump days.
     Week mode: grouped-by-day list, denser rows.

     Single tap on any row navigates to the appointment detail page.
     ================================================================ --}}
@php
  use Carbon\Carbon as Cb;

  // Resolve "current date" for the mobile view's day-strip and header,
  // regardless of which mode the controller resolved to.
  if ($viewMode === 'day') {
    $msAnchorDateStr = $dateStr;
    $msAppointments  = $appointments;
  } elseif ($viewMode === 'week') {
    // In week mode controller doesn't pass a single "anchor day"; default to today
    // if it's inside the week, else weekStart.
    $msAnchorDateStr = ($todayStr >= $weekStartStr && $todayStr <= $weekEndStr) ? $todayStr : $weekStartStr;
    // Flatten byResourceDate to a single date-keyed map for the day view.
    $msAppointments = collect();
    foreach (($byResourceDate ?? []) as $rid => $byDate) {
      foreach ($byDate as $ds => $appts) {
        if ($ds === $msAnchorDateStr) {
          foreach ($appts as $a) $msAppointments->push($a);
        }
      }
    }
    $msAppointments = $msAppointments->sortBy('appointment_time')->values();
  } else {
    // Month mode — just pick today.
    $msAnchorDateStr = $todayStr;
    $msAppointments  = collect();
  }

  $msAnchorDate = Cb::parse($msAnchorDateStr);

  // Build 7-day strip centered on anchor: anchor-3 ... anchor+3
  $msStripStart = $msAnchorDate->copy()->subDays(3);
  $msStripDays = [];
  for ($i = 0; $i < 7; $i++) {
    $d = $msStripStart->copy()->addDays($i);
    $msStripDays[] = [
      'date'      => $d->toDateString(),
      'dow'       => $d->format('D'),
      'num'       => (int) $d->format('j'),
      'is_today'  => $d->toDateString() === $todayStr,
      'is_anchor' => $d->toDateString() === $msAnchorDateStr,
    ];
  }

  // Resource color lookup
  $msResourceById = [];
  foreach (($allResources ?? $resources ?? []) as $r) {
    $msResourceById[$r->id] = $r;
  }

  // Helpers
  $msFmtTime = function ($appt) {
    if (!$appt->appointment_time) return ['hm' => 'Any', 'ap' => 'time'];
    $c = Cb::parse($appt->appointment_time);
    return ['hm' => $c->format('g:i'), 'ap' => $c->format('A')];
  };
  $msStatusClass = function ($status) {
    return 'is-' . str_replace('_', '-', $status);
  };
@endphp

<div class="ia-msched">

  {{-- Header: title + mode toggle --}}
  <div class="ia-msched-head">
    <div class="ia-msched-title-block">
      <div class="ia-msched-title">{{ $msAnchorDate->format('l, M j') }}</div>
      <div class="ia-msched-sub">
        @if($viewMode === 'week')
          Week of {{ Cb::parse($weekStartStr)->format('M j') }}
        @elseif($msAppointments->isEmpty())
          No appointments
        @else
          {{ $msAppointments->count() }} {{ \Illuminate\Support\Str::plural('appointment', $msAppointments->count()) }}
        @endif
      </div>
    </div>
    <div class="ia-msched-mode">
      <a href="{{ route('tenant.calendar.index', ['view' => 'day', 'date' => $msAnchorDateStr]) }}"
         class="ia-msched-mode-btn {{ $viewMode === 'day' ? 'is-active' : '' }}">Day</a>
      <a href="{{ route('tenant.calendar.index', ['view' => 'week', 'date' => $msAnchorDateStr]) }}"
         class="ia-msched-mode-btn {{ $viewMode === 'week' ? 'is-active' : '' }}">Week</a>
    </div>
  </div>

  {{-- 7-day strip — always visible for quick jumps --}}
  <div class="ia-msched-strip" role="tablist">
    @foreach($msStripDays as $sd)
      <a href="{{ route('tenant.calendar.index', ['view' => $viewMode === 'week' ? 'week' : 'day', 'date' => $sd['date']]) }}"
         class="ia-msched-strip-chip {{ $sd['is_anchor'] ? 'is-active' : '' }} {{ $sd['is_today'] ? 'is-today' : '' }}"
         role="tab" aria-selected="{{ $sd['is_anchor'] ? 'true' : 'false' }}">
        <span class="ia-msched-strip-dow">{{ $sd['dow'] }}</span>
        <span class="ia-msched-strip-num">{{ $sd['num'] }}</span>
      </a>
    @endforeach
  </div>

  {{-- Prev / Next day shortcut buttons --}}
  <div class="ia-msched-nav">
    <a href="{{ route('tenant.calendar.index', ['view' => 'day', 'date' => $msAnchorDate->copy()->subDay()->toDateString()]) }}"
       class="ia-msched-nav-btn">‹ Prev day</a>
    @unless($msAnchorDateStr === $todayStr)
      <a href="{{ route('tenant.calendar.index', ['view' => $viewMode === 'week' ? 'week' : 'day', 'date' => $todayStr]) }}"
         class="ia-msched-nav-btn is-today">Today</a>
    @endunless
    <a href="{{ route('tenant.calendar.index', ['view' => 'day', 'date' => $msAnchorDate->copy()->addDay()->toDateString()]) }}"
       class="ia-msched-nav-btn">Next day ›</a>
  </div>

  {{-- ─── Mode-specific body ─── --}}
  @if($viewMode === 'week')
    {{-- WEEK MODE: grouped-by-day list --}}
    @php
      // Build day-grouped appointments. Iterate $days from controller for header order.
      $weekGroups = [];
      foreach (($days ?? []) as $day) {
        $ds = $day['dateStr'];
        $dayAppts = collect();
        foreach (($byResourceDate ?? []) as $rid => $byDate) {
          foreach (($byDate[$ds] ?? []) as $a) $dayAppts->push($a);
        }
        $weekGroups[] = [
          'dateStr'  => $ds,
          'date'     => $day['date'] ?? Cb::parse($ds),
          'is_today' => $ds === $todayStr,
          'appts'    => $dayAppts->sortBy('appointment_time')->values(),
        ];
      }
    @endphp

    @foreach($weekGroups as $g)
      <div class="ia-msched-day-group">
        <div class="ia-msched-day-header">
          <a href="{{ route('tenant.calendar.index', ['view' => 'day', 'date' => $g['dateStr']]) }}" class="ia-msched-day-header-link">
            @if($g['is_today'])
              <span class="ia-msched-day-name">Today</span>
              <span class="ia-msched-day-meta">{{ $g['date']->format('D · M j') }}</span>
            @else
              <span class="ia-msched-day-name">{{ $g['date']->format('l') }}</span>
              <span class="ia-msched-day-meta">{{ $g['date']->format('M j') }}</span>
            @endif
          </a>
          <span class="ia-msched-day-count">{{ $g['appts']->count() }} {{ \Illuminate\Support\Str::plural('appt', $g['appts']->count()) }}</span>
        </div>
        @if($g['appts']->isEmpty())
          <div class="ia-msched-empty-row">No appointments</div>
        @else
          @foreach($g['appts']->take(8) as $appt)
            @php
              $t = $msFmtTime($appt);
              $r = $msResourceById[$appt->resource_id] ?? null;
              $rColor = $r?->color_hex ?: '#888';
            @endphp
            <a href="{{ route('tenant.appointments.show', $appt->id) }}" class="ia-msched-row">
              <div class="ia-msched-row-time">
                <div class="ia-msched-row-time-hm">{{ $t['hm'] }}</div>
                <div class="ia-msched-row-time-ap">{{ $t['ap'] }}</div>
              </div>
              <div class="ia-msched-row-stripe" style="background:{{ $rColor }}"></div>
              <div class="ia-msched-row-body">
                <div class="ia-msched-row-cust">{{ trim(($appt->customer_first_name ?? '') . ' ' . ($appt->customer_last_name ?? '')) ?: 'Customer' }}</div>
                <div class="ia-msched-row-meta">
                  @if($r)
                    <span class="ia-msched-row-res">{{ $r->name }}</span>
                  @endif
                  @if($appt->items->isNotEmpty())
                    <span class="ia-msched-row-svc">· {{ $appt->items->first()->item_name_snapshot }}</span>
                  @endif
                </div>
              </div>
              <span class="ia-msched-row-status {{ $msStatusClass($appt->status) }}"></span>
            </a>
          @endforeach
          @if($g['appts']->count() > 8)
            <a href="{{ route('tenant.calendar.index', ['view' => 'day', 'date' => $g['dateStr']]) }}" class="ia-msched-more">
              + {{ $g['appts']->count() - 8 }} more — tap for day view
            </a>
          @endif
        @endif
      </div>
    @endforeach

  @else
    {{-- DAY MODE: vertical list for one day --}}
    @if($msAppointments->isEmpty())
      <div class="ia-msched-empty">
        <div class="ia-msched-empty-h">Nothing on the books</div>
        <div class="ia-msched-empty-sub">Tap the + button to start a walk-in, or pick a different day above.</div>
      </div>
    @else
      <div class="ia-msched-list">
        @foreach($msAppointments as $appt)
          @php
            $t = $msFmtTime($appt);
            $r = $msResourceById[$appt->resource_id] ?? null;
            $rColor = $r?->color_hex ?: '#888';
          @endphp
          <a href="{{ route('tenant.appointments.show', $appt->id) }}" class="ia-msched-row">
            <div class="ia-msched-row-time">
              <div class="ia-msched-row-time-hm">{{ $t['hm'] }}</div>
              <div class="ia-msched-row-time-ap">{{ $t['ap'] }}</div>
              @if($appt->total_duration_minutes)
                <div class="ia-msched-row-time-dur">{{ $appt->total_duration_minutes }}m</div>
              @endif
            </div>
            <div class="ia-msched-row-stripe" style="background:{{ $rColor }}"></div>
            <div class="ia-msched-row-body">
              <div class="ia-msched-row-cust">{{ trim(($appt->customer_first_name ?? '') . ' ' . ($appt->customer_last_name ?? '')) ?: 'Customer' }}</div>
              @if($appt->items->isNotEmpty())
                <div class="ia-msched-row-svc-main">{{ $appt->items->first()->item_name_snapshot }}</div>
              @endif
              <div class="ia-msched-row-meta">
                @if($r)
                  <span class="ia-msched-row-res-dot" style="background:{{ $rColor }}"></span>
                  <span class="ia-msched-row-res">{{ $r->name }}</span>
                @endif
              </div>
            </div>
            <span class="ia-msched-row-status {{ $msStatusClass($appt->status) }}" aria-label="{{ ucfirst(str_replace('_', ' ', $appt->status)) }}"></span>
          </a>
        @endforeach
      </div>
    @endif

  @endif

</div>
BLADE
echo "OK 1 (mobile schedule partial created)"

# ─────────────────────────────────────────────────────────────────────────────
# 2. Create the CSS file.
# ─────────────────────────────────────────────────────────────────────────────
cat > public/css/tenant/mobile-schedule.css <<'CSS'
/* ================================================================
   Mobile schedule (≤900px). Hidden on desktop.
   The desktop calendar (.ia-cal-shell) is hidden at the same
   breakpoint via the rule at the bottom of this file.
   ================================================================ */

.ia-msched { display: none; }

@media (max-width: 900px) {
  .ia-msched {
    display: block;
    padding: 0;
  }

  /* Hide the desktop calendar entirely on mobile. */
  .ia-cal-shell { display: none !important; }

  /* ── Header: title + mode toggle ── */
  .ia-msched-head {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 12px;
    padding: 4px 0 12px;
  }
  .ia-msched-title-block { min-width: 0; flex: 1; }
  .ia-msched-title {
    font-size: 20px;
    font-weight: 600;
    letter-spacing: -.02em;
    line-height: 1.15;
    color: var(--ia-text);
  }
  .ia-msched-sub {
    font-size: 12px;
    opacity: .55;
    margin-top: 2px;
  }
  .ia-msched-mode {
    display: inline-flex;
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: var(--ia-r-md);
    padding: 3px;
    flex-shrink: 0;
  }
  .ia-msched-mode-btn {
    padding: 5px 12px;
    border-radius: calc(var(--ia-r-md) - 2px);
    font-size: 12px;
    font-weight: 500;
    color: var(--ia-text-muted);
    text-decoration: none;
  }
  .ia-msched-mode-btn.is-active {
    background: var(--ia-accent);
    color: var(--ia-accent-text);
  }

  /* ── 7-day strip ── */
  .ia-msched-strip {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 4px;
    margin-bottom: 12px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }
  .ia-msched-strip-chip {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 3px;
    padding: 8px 2px;
    border-radius: var(--ia-r-md);
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    text-decoration: none;
    color: inherit;
    -webkit-tap-highlight-color: transparent;
  }
  .ia-msched-strip-dow {
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: .05em;
    opacity: .55;
    font-weight: 500;
  }
  .ia-msched-strip-num {
    font-size: 15px;
    font-weight: 500;
    line-height: 1;
    color: var(--ia-text);
  }
  .ia-msched-strip-chip.is-today {
    border-color: rgba(190, 242, 100, .3);
  }
  .ia-msched-strip-chip.is-today .ia-msched-strip-num {
    color: var(--ia-accent);
  }
  .ia-msched-strip-chip.is-active {
    background: var(--ia-accent);
    border-color: var(--ia-accent);
  }
  .ia-msched-strip-chip.is-active .ia-msched-strip-dow,
  .ia-msched-strip-chip.is-active .ia-msched-strip-num {
    color: var(--ia-accent-text);
    opacity: 1;
  }

  /* ── Prev / today / next quick nav ── */
  .ia-msched-nav {
    display: flex;
    gap: 6px;
    margin-bottom: 14px;
  }
  .ia-msched-nav-btn {
    flex: 1;
    padding: 7px 8px;
    text-align: center;
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: var(--ia-r-md);
    color: var(--ia-text-muted);
    font-size: 12px;
    text-decoration: none;
    -webkit-tap-highlight-color: transparent;
  }
  .ia-msched-nav-btn.is-today {
    color: var(--ia-accent);
    border-color: rgba(190, 242, 100, .35);
    font-weight: 500;
  }

  /* ── Appointment row (used in both day + week modes) ── */
  .ia-msched-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }
  .ia-msched-row {
    display: grid;
    grid-template-columns: 56px 3px 1fr auto;
    gap: 10px;
    padding: 10px 12px 10px 8px;
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: var(--ia-r-md);
    text-decoration: none;
    color: inherit;
    -webkit-tap-highlight-color: transparent;
    align-items: center;
  }
  .ia-msched-row:active { transform: scale(0.99); }

  .ia-msched-row-time {
    text-align: right;
    align-self: stretch;
    display: flex;
    flex-direction: column;
    justify-content: center;
  }
  .ia-msched-row-time-hm {
    font-size: 14px;
    font-weight: 600;
    line-height: 1.1;
    font-variant-numeric: tabular-nums;
  }
  .ia-msched-row-time-ap {
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: .04em;
    opacity: .55;
    margin-top: 1px;
  }
  .ia-msched-row-time-dur {
    font-size: 9px;
    opacity: .45;
    margin-top: 2px;
  }
  .ia-msched-row-stripe {
    align-self: stretch;
    width: 3px;
    border-radius: 2px;
  }
  .ia-msched-row-body { min-width: 0; }
  .ia-msched-row-cust {
    font-size: 14px;
    font-weight: 500;
    color: var(--ia-text);
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .ia-msched-row-svc-main {
    font-size: 12px;
    color: var(--ia-text-muted);
    margin-top: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .ia-msched-row-meta {
    display: flex;
    align-items: center;
    gap: 4px;
    margin-top: 3px;
    font-size: 11px;
    opacity: .55;
  }
  .ia-msched-row-res-dot {
    display: inline-block;
    width: 6px; height: 6px;
    border-radius: 50%;
  }
  .ia-msched-row-status {
    width: 8px; height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
    align-self: center;
    background: var(--ia-text-dim, #888);
  }
  .ia-msched-row-status.is-confirmed { background: #34D399; }
  .ia-msched-row-status.is-pending   { background: #F59E0B; }
  .ia-msched-row-status.is-in-progress { background: var(--ia-accent, #BEF264); }
  .ia-msched-row-status.is-completed { background: #6b7280; }
  .ia-msched-row-status.is-cancelled,
  .ia-msched-row-status.is-refunded  { background: #EF4444; opacity: .6; }

  /* Status colors ARE the only chrome — keep it minimal. The colored
     left stripe handles resource identity. The status dot is the only
     other signal. */

  /* ── Empty states ── */
  .ia-msched-empty {
    padding: 32px 20px;
    text-align: center;
    color: var(--ia-text-muted);
  }
  .ia-msched-empty-h {
    font-size: 14px;
    font-weight: 500;
    color: var(--ia-text);
  }
  .ia-msched-empty-sub {
    font-size: 12px;
    margin-top: 4px;
    opacity: .7;
  }
  .ia-msched-empty-row {
    padding: 10px 12px;
    font-size: 12px;
    opacity: .45;
    font-style: italic;
    text-align: center;
  }

  /* ── Week-mode day groups ── */
  .ia-msched-day-group {
    margin-bottom: 18px;
  }
  .ia-msched-day-header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    padding: 6px 4px 8px;
    border-bottom: 0.5px solid var(--ia-border);
    margin-bottom: 8px;
  }
  .ia-msched-day-header-link {
    text-decoration: none;
    color: inherit;
    display: flex;
    align-items: baseline;
    gap: 8px;
    flex: 1;
    min-width: 0;
  }
  .ia-msched-day-name {
    font-size: 14px;
    font-weight: 600;
    color: var(--ia-text);
  }
  .ia-msched-day-meta {
    font-size: 11px;
    opacity: .55;
  }
  .ia-msched-day-count {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .05em;
    opacity: .5;
  }
  .ia-msched-more {
    display: block;
    padding: 8px 12px;
    margin-top: 4px;
    text-align: center;
    background: transparent;
    border: 0.5px dashed var(--ia-border);
    border-radius: var(--ia-r-md);
    color: var(--ia-text-muted);
    font-size: 12px;
    text-decoration: none;
  }
}

/* On desktop the mobile schedule never renders. */
@media (min-width: 901px) {
  .ia-msched { display: none !important; }
}
CSS
echo "OK 2 (mobile schedule CSS created)"

# ─────────────────────────────────────────────────────────────────────────────
# 3. Wire the partial into calendar/index.blade.php.
#    Include it right BEFORE the desktop dispatcher (.ia-cal-shell wraps the
#    desktop view, which gets hidden on mobile). Position: right after the
#    closing of .ia-cal-shell so the mobile view appears in document flow
#    where the desktop one would.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/calendar/index.blade.php')
s = p.read_text()
if "_mobile-schedule" in s:
    print("SKIP 3 (mobile schedule already included)")
else:
    # Inject the include right after the closing of .ia-cal-shell.
    # That close is at line ~238 — anchor on the comment that follows.
    old = """</div>

{{-- Drag-to-reschedule ghost preview (hidden by default; JS shows during drag) --}}"""
    new = """</div>

{{-- MOBILE-SCHEDULE v1 — renders below desktop calendar; CSS hides whichever
     view isn't appropriate for the current viewport. Both views use the same
     controller data. --}}
@include('tenant.calendar._mobile-schedule')

{{-- Drag-to-reschedule ghost preview (hidden by default; JS shows during drag) --}}"""
    assert s.count(old) == 1, f"include-anchor count={s.count(old)}, expected 1"
    p.write_text(s.replace(old, new))
    print("OK 3 (mobile schedule included)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# 4. Add mobile-schedule.css to the stylesheet stack in app.blade.php.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/layouts/tenant/app.blade.php')
s = p.read_text()
if "mobile-schedule.css" in s:
    print("SKIP 4 (CSS already linked)")
else:
    old = '<link rel="stylesheet" href="{{ asset(\'css/tenant/mobile-nav.css\') }}?v={{ filemtime(public_path(\'css/tenant/mobile-nav.css\')) }}">'
    new = old + '\n  <link rel="stylesheet" href="{{ asset(\'css/tenant/mobile-schedule.css\') }}?v={{ filemtime(public_path(\'css/tenant/mobile-schedule.css\')) }}">'
    assert s.count(old) == 1, f"mobile-nav CSS link count={s.count(old)}, expected 1"
    p.write_text(s.replace(old, new))
    print("OK 4 (CSS linked)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# 5. Declare mobile-fab on calendar/index.blade.php.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/calendar/index.blade.php')
s = p.read_text()
if "@section('mobile-fab'" in s:
    print("SKIP 5 (mobile-fab already declared)")
else:
    old = "@section('content')"
    new = "@section('mobile-fab', 'walk-in')\n\n@section('content')"
    assert s.count(old) == 1, f"section-content count={s.count(old)}, expected 1"
    p.write_text(s.replace(old, new))
    print("OK 5 (mobile-fab declared)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# Verification
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
    echo "  ✓ $label  (${n}×)"
  else
    echo "  ✗ MISSING: $label"
    fail=1
  fi
}

verify "resources/views/tenant/calendar/_mobile-schedule.blade.php" "ia-msched"                  "partial markup"
verify "resources/views/tenant/calendar/_mobile-schedule.blade.php" "msAnchorDateStr"            "anchor logic"
verify "public/css/tenant/mobile-schedule.css"                     "Mobile schedule"            "CSS file"
verify "public/css/tenant/mobile-schedule.css"                     ".ia-msched-strip-chip"      "strip chip CSS"
verify "public/css/tenant/mobile-schedule.css"                     ".ia-cal-shell { display: none !important; }" "desktop hidden on mobile"
verify "resources/views/tenant/calendar/index.blade.php"           "_mobile-schedule"           "partial included"
verify "resources/views/tenant/calendar/index.blade.php"           "mobile-fab"                 "FAB declared"
verify "resources/views/layouts/tenant/app.blade.php"              "mobile-schedule.css"        "CSS linked"

# Blade balance on the partial
python3 <<'PY'
src = open('resources/views/tenant/calendar/_mobile-schedule.blade.php').read()
checks = [('@if','@endif'), ('@unless','@endunless'), ('@foreach','@endforeach'), ('@php','@endphp')]
import sys
ok = True
for o, c in checks:
    no, nc = src.count(o), src.count(c)
    if o == '@if':
        # @if pairs are tricky if @elseif/@else exist — just check rough balance
        if no != nc:
            print(f'  ✗ {o}({no}) != {c}({nc})')
            ok = False
        else:
            print(f'  ✓ {o}/{c}: {no}')
    else:
        if no != nc:
            print(f'  ✗ {o}({no}) != {c}({nc})')
            ok = False
        else:
            print(f'  ✓ {o}/{c}: {no}')
if not ok: sys.exit(1)
PY

if [ "$fail" -ne 0 ]; then
  echo ""
  echo "✗ FAIL"
  exit 1
fi

echo ""
echo "✓ all green"
echo ""
echo "Next:"
echo "  git add -A && git commit -m 'mobile schedule: agenda day + week views, day-strip, mode toggle'"
echo "  git push"
echo "  Server: git pull && php artisan view:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "=== mobile schedule complete ==="
