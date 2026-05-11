#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Today / Dashboard — mobile polish.
#
# Five changes, all scoped to mobile (≤600px) or additive markup that's
# desktop-hidden:
#
#   1. Add @section('mobile-fab', 'walk-in') so dashboard gets the FAB.
#   2. Add a mobile-only "Next up" hero card right after page-head — punchy
#      time, customer name, service. Uses the existing $today['next_up'] model.
#      Desktop continues to see the friendly inline-sentence version below
#      (unchanged).
#   3. Mobile-only "at-a-glance" 3-stat row (Today / Booked $ / New customers)
#      that surfaces the key numbers above the fold without scrolling.
#   4. CSS rules at ≤600px to:
#      - Stack page head vertically + demote the two big action buttons
#      - Tighten the today-list time column (88px → 64px) and inner type
#      - Slightly increase date-strip chip touch target
#   5. Loosens .ia-content padding on mobile so the hero cards aren't crowded.
#
# Approach: no DOM moves on existing markup. Only add new mobile-only nodes
# (display:none on desktop) and append mobile-specific CSS. Desktop UX is
# pixel-identical to before.
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: not in intake-license repo root"; exit 1; }

echo "=== dashboard mobile polish starting ==="

# ─────────────────────────────────────────────────────────────────────────────
# 1. Add mobile-fab section to dashboard.blade.php (top of the file).
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/dashboard.blade.php')
s = p.read_text()
if "@section('mobile-fab'" in s:
    print("SKIP 1 (mobile-fab already declared)")
else:
    old = "@section('content')\n\n@php"
    new = "@section('mobile-fab', 'walk-in')\n\n@section('content')\n\n@php"
    assert s.count(old) == 1, f"section-content anchor count={s.count(old)}, expected 1"
    p.write_text(s.replace(old, new))
    print("OK 1 (mobile-fab section declared)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# 2 + 3. Mobile-only hero card + at-a-glance stat row.
# Inserted between @section('content') / @php and the existing page-head.
# Wrapped in .ia-dash-mobile-only — display:none on desktop via CSS.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/dashboard.blade.php')
s = p.read_text()
marker = "DASH-MOBILE v1"
if marker in s:
    print("SKIP 2 (mobile hero already present)")
else:
    old = """<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">{{ $greetingLine }}</h1>
    <p class="ia-page-subtitle">{{ $greeting['date_long'] }}</p>
  </div>"""
    new = """{{-- DASH-MOBILE v1 — mobile-only hero + at-a-glance stats. Hidden on desktop. --}}
<div class="ia-dash-mobile-only">
  {{-- 3-stat row --}}
  <div class="ia-dash-m-stats">
    <div class="ia-dash-m-stat">
      <div class="ia-dash-m-stat-num">{{ $today['today_count'] }}</div>
      <div class="ia-dash-m-stat-lbl">Today</div>
    </div>
    <div class="ia-dash-m-stat">
      <div class="ia-dash-m-stat-num">{{ format_money($today['week_revenue_cents']) }}</div>
      <div class="ia-dash-m-stat-lbl">Wk revenue</div>
    </div>
    <div class="ia-dash-m-stat">
      <div class="ia-dash-m-stat-num">{{ $today['week_new_customers'] }}</div>
      <div class="ia-dash-m-stat-lbl">New cust.</div>
    </div>
  </div>

  {{-- Next-up hero card. Renders only when there's a next_up appointment with a time. --}}
  @php
    $nu = $today['next_up'] ?? null;
    $nuTime = ($nu && $nu->appointment_time)
      ? \Carbon\Carbon::parse($nu->appointment_time)
      : null;
    $nuMinutesAway = null;
    if ($nuTime) {
      try {
        $nuStart = \Carbon\Carbon::parse($nu->appointment_date->toDateString() . ' ' . $nu->appointment_time);
        $diff = (int) now()->diffInMinutes($nuStart, false);
        $nuMinutesAway = $diff;
      } catch (\Throwable $e) { $nuMinutesAway = null; }
    }
    $nuService = $nu && $nu->items->isNotEmpty() ? $nu->items->first()->item_name_snapshot : null;
  @endphp
  @if($nu && $nuTime)
    <a href="{{ route('tenant.appointments.show', $nu->id) }}" class="ia-dash-m-hero">
      <div class="ia-dash-m-hero-when">
        @if($nuMinutesAway !== null && $nuMinutesAway >= 0 && $nuMinutesAway < 120)
          @if($nuMinutesAway === 0)
            Right now
          @elseif($nuMinutesAway < 60)
            In {{ $nuMinutesAway }} {{ \Illuminate\Support\Str::plural('minute', $nuMinutesAway) }}
          @else
            In {{ floor($nuMinutesAway / 60) }}h {{ $nuMinutesAway % 60 }}m
          @endif
          · {{ $nuTime->format('g:i A') }}
        @else
          Next up · {{ $nuTime->format('g:i A') }}
        @endif
      </div>
      <div class="ia-dash-m-hero-cust">{{ $nu->customerName() }}</div>
      @if($nuService)
        <div class="ia-dash-m-hero-svc">{{ $nuService }}@if($nu->total_duration_minutes) · {{ $nu->total_duration_minutes }} min @endif</div>
      @endif
      <div class="ia-dash-m-hero-cta">View →</div>
    </a>
  @elseif($today['today_count'] === 0)
    <div class="ia-dash-m-empty">
      No appointments today. Open the calendar to book one.
    </div>
  @endif
</div>

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">{{ $greetingLine }}</h1>
    <p class="ia-page-subtitle">{{ $greeting['date_long'] }}</p>
  </div>"""
    assert s.count(old) == 1, f"page-head anchor count={s.count(old)}, expected 1"
    p.write_text(s.replace(old, new))
    print("OK 2 (mobile hero + stats inserted)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# 4. Append all the mobile CSS to dashboard.css.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('public/css/tenant/dashboard.css')
s = p.read_text()
marker = '/* DASH-MOBILE-CSS v1 */'
if marker in s:
    print("SKIP 4 (mobile CSS already present)")
else:
    addition = '''

/* DASH-MOBILE-CSS v1 — Today dashboard polish for phones */

/* Hide the mobile-only block on desktop */
.ia-dash-mobile-only { display: none; }

@media (max-width: 600px) {
  .ia-dash-mobile-only {
    display: block;
    margin: 4px 0 16px;
  }

  /* 3-stat row */
  .ia-dash-m-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    margin-bottom: 12px;
  }
  .ia-dash-m-stat {
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: var(--ia-r-md);
    padding: 10px 6px;
    text-align: center;
  }
  .ia-dash-m-stat-num {
    font-size: 18px;
    font-weight: 600;
    letter-spacing: -.01em;
    line-height: 1.1;
    font-variant-numeric: tabular-nums;
  }
  .ia-dash-m-stat-lbl {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .05em;
    opacity: .5;
    margin-top: 3px;
  }

  /* Next-up hero card */
  .ia-dash-m-hero {
    display: block;
    background: linear-gradient(135deg, var(--ia-accent-soft), transparent 80%);
    border: 1px solid var(--ia-accent);
    border-radius: var(--ia-r-lg);
    padding: 14px 16px;
    text-decoration: none;
    color: inherit;
    position: relative;
    -webkit-tap-highlight-color: transparent;
  }
  .ia-dash-m-hero:active { transform: scale(0.99); }
  .ia-dash-m-hero-when {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--ia-accent);
    margin-bottom: 6px;
  }
  .ia-dash-m-hero-cust {
    font-size: 17px;
    font-weight: 500;
    letter-spacing: -.01em;
    line-height: 1.2;
  }
  .ia-dash-m-hero-svc {
    font-size: 13px;
    opacity: .65;
    margin-top: 4px;
  }
  .ia-dash-m-hero-cta {
    position: absolute;
    top: 12px;
    right: 14px;
    font-size: 12px;
    font-weight: 500;
    color: var(--ia-accent);
    opacity: .8;
  }

  /* Empty-day state */
  .ia-dash-m-empty {
    padding: 14px 16px;
    border: 0.5px dashed var(--ia-border);
    border-radius: var(--ia-r-md);
    font-size: 13px;
    opacity: .7;
    text-align: center;
  }

  /* Stack page head + demote the action buttons */
  .ia-page-head {
    flex-direction: column;
    align-items: stretch;
    gap: 10px;
  }
  .ia-page-head .ia-page-title {
    font-size: 20px;
    line-height: 1.2;
  }
  .ia-page-head .ia-page-subtitle {
    font-size: 12px;
  }
  .ia-page-actions {
    display: flex;
    flex-direction: row;
    gap: 8px;
  }
  .ia-page-actions .ia-btn {
    flex: 1;
    padding: 9px 10px;
    font-size: 13px;
    justify-content: center;
  }

  /* Hide the desktop greet card on phones — the mobile hero above replaces it.
     Also hide the verbose week-stats grid (we have 3-stat row above). */
  .ia-dash-greet-card { display: none; }
  .ia-dash-weekstats { display: none; }

  /* Tighten today list rows for narrow phone width */
  .ia-dash-today-row {
    grid-template-columns: 72px 1fr;
    gap: 12px;
    padding: 12px 4px;
  }
  .ia-dash-today-time-hm { font-size: 14px; }
  .ia-dash-today-time-ap { font-size: 10px; }
  .ia-dash-today-service { font-size: 13px; }
  .ia-dash-today-customer { font-size: 11px; }
  .ia-dash-today-status { grid-column: 1 / -1; margin-top: 4px; }

  /* Slightly bigger date-strip chips for touch target */
  .ia-dash-date-chip { padding: 8px 2px; }
  .ia-dash-date-num { font-size: 15px; }
  .ia-dash-date-day, .ia-dash-date-count { font-size: 9px; }

  /* Reduce mobile content side-padding so cards have more room */
  .ia-content { padding-left: 12px !important; padding-right: 12px !important; }
}
'''
    p.write_text(s + addition)
    print("OK 4 (mobile CSS appended)")
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
verify_count() {
  local file="$1" needle="$2" expect="$3" label="$4"
  local n
  n=$(grep -c -F -- "$needle" "$file" 2>/dev/null | tr -d '\n' || true)
  : "${n:=0}"
  if [ "${n:-0}" -eq "$expect" ] 2>/dev/null; then
    echo "  ✓ $label  (${n}× = $expect)"
  else
    echo "  ✗ $label MISMATCH (got ${n}, expected $expect)"
    fail=1
  fi
}

verify       "resources/views/tenant/dashboard.blade.php"  "mobile-fab"              "FAB declared"
verify       "resources/views/tenant/dashboard.blade.php"  "DASH-MOBILE v1"          "mobile hero markup"
verify       "resources/views/tenant/dashboard.blade.php"  "ia-dash-m-hero"          "hero class"
verify       "resources/views/tenant/dashboard.blade.php"  "ia-dash-m-stats"         "stats class"
verify       "public/css/tenant/dashboard.css"             "DASH-MOBILE-CSS v1"      "mobile CSS"
verify       "public/css/tenant/dashboard.css"             ".ia-dash-m-hero {"       "hero CSS rule"
verify       "public/css/tenant/dashboard.css"             ".ia-dash-greet-card { display: none; }" "greet hidden on mobile"

# Blade balance
python3 <<'PY'
src = open('resources/views/tenant/dashboard.blade.php').read()
checks = [('@if','@endif'), ('@php','@endphp'), ('@foreach','@endforeach'), ('@section','@endsection'), ('@push','@endpush')]
import sys
ok = True
for o, c in checks:
    no, nc = src.count(o), src.count(c)
    # @section can be one-arg (no closer) — count only multi-line variants
    if o == '@section':
        # rough check: skip
        continue
    if no != nc:
        print(f'  ✗ {o}={no} {c}={nc}')
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
echo "  git add -A && git commit -m 'Today dashboard: mobile hero card, 3-stat row, polish'"
echo "  git push"
echo "  Server: git pull && php artisan view:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "=== dashboard mobile polish complete ==="
