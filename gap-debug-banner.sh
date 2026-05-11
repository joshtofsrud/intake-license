#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# TEMPORARY DEBUG: surface mobile-schedule filter + gap state on the page.
# Renders a small yellow banner at the top of the mobile schedule view
# showing the values of filterMode, visible resource count, single-resource
# detection, and (for the first appointment pair) what the gap calc returned.
#
# Run this, deploy, take a screenshot of the calendar page with a single
# resource filter active. The banner will tell me exactly why gaps aren't
# rendering. I'll then ship a real fix + a separate patch to revert this
# debug banner.
#
# DOES NOT remove gap-rendering logic — just adds visibility.
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: not in intake-license repo root"; exit 1; }

echo "=== adding gap-debug banner ==="

python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/calendar/_mobile-schedule.blade.php')
s = p.read_text()
marker = "MSCHED-DEBUG-BANNER"
if marker in s:
    print("SKIP (already added)")
else:
    # Inject right after the opening <div class="ia-msched"> so it sits at
    # the top of the mobile schedule view, before everything else.
    old = '<div class="ia-msched">'
    new = '''<div class="ia-msched">

  {{-- MSCHED-DEBUG-BANNER — temporary diagnostic, remove via revert patch --}}
  @php
    $dbgVis = ($resources ?? collect())->pluck('id')->all();
    $dbgFM  = $filterMode ?? '(null)';
    $dbgVMode = $viewMode ?? '(null)';
    $dbgApptCount = $msAppointments->count();
    $dbgSingle = $msSingleResource ? $msSingleResource->name : 'NO';
    $dbgFirstGap = 'n/a';
    if ($viewMode === 'day' && $dbgApptCount >= 2) {
      $a = $msAppointments[0];
      $b = $msAppointments[1];
      $g = $msComputeGap($a, $b);
      if ($g === null) {
        // Run the same math but without the < 15 threshold so we can see what value we get
        try {
          $prevStart = \\Carbon\\Carbon::parse($a->appointment_date->toDateString() . ' ' . $a->appointment_time);
          $prevDur = (int) ($a->total_duration_minutes ?? 0);
          $prevEnd = $a->appointment_end_time
            ? \\Carbon\\Carbon::parse($a->appointment_date->toDateString() . ' ' . $a->appointment_end_time)
            : $prevStart->copy()->addMinutes($prevDur);
          $currStart = \\Carbon\\Carbon::parse($b->appointment_date->toDateString() . ' ' . $b->appointment_time);
          $rawGap = (int) round(($currStart->getTimestamp() - $prevEnd->getTimestamp()) / 60);
          $dbgFirstGap = 'null (raw=' . $rawGap . 'min, threshold 15)';
        } catch (\\Throwable $e) {
          $dbgFirstGap = 'THROW: ' . $e->getMessage();
        }
      } else {
        $dbgFirstGap = $g['minutes'] . 'min';
      }
    }
  @endphp
  <div style="background:#3a2f0a;color:#fde68a;padding:8px 10px;font-size:11px;font-family:monospace;line-height:1.4;border-radius:6px;margin-bottom:10px;word-break:break-all">
    <strong>GAP DEBUG</strong><br>
    viewMode: {{ $dbgVMode }}<br>
    filterMode: {{ $dbgFM }}<br>
    visibleIds count: {{ count($dbgVis) }}<br>
    visibleIds: {{ implode(',', array_map(fn($x) => substr($x, 0, 8), $dbgVis)) }}<br>
    msSingleResource: {{ $dbgSingle }}<br>
    msAppointments count: {{ $dbgApptCount }}<br>
    first pair gap: {{ $dbgFirstGap }}
  </div>'''
    assert s.count(old) == 1, f"anchor count={s.count(old)}, expected 1"
    p.write_text(s.replace(old, new))
    print("OK (debug banner injected)")
PY

echo ""
echo "=== verifying ==="
grep -c "MSCHED-DEBUG-BANNER" resources/views/tenant/calendar/_mobile-schedule.blade.php

echo ""
echo "✓ done"
echo ""
echo "Next:"
echo "  git add -A && git commit -m 'TEMP DEBUG: gap state banner — REMOVE before launch'"
echo "  git push"
echo "  Server: git pull && php artisan view:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "Then: take a screenshot of the calendar page with single-resource filter."
echo "The banner will tell me exactly what's happening."
