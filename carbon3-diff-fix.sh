#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Carbon 3 diffInMinutes bug fix.
#
# Carbon 3.x changed the sign convention for diffInMinutes() with false:
#   Carbon 2: $a->diffInMinutes($b, false) is POSITIVE when $b is after $a
#   Carbon 3: $a->diffInMinutes($b, false) is NEGATIVE when $b is after $a
#
# Two places in our recent code hit this:
#   1. Dashboard mobile hero — "$nuMinutesAway" was always negative, so the
#      "In 24 minutes" copy never rendered (always fell through to "Next up").
#   2. Mobile schedule gap calc — gap was always negative, so the < 15
#      threshold always returned null, so gap rows NEVER rendered.
#
# Fix: use raw timestamp math. Bulletproof across versions, no version-specific
# behaviour, no hidden traps.
#   $gap = (int) round(($later->getTimestamp() - $earlier->getTimestamp()) / 60);
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: not in intake-license repo root"; exit 1; }

echo "=== carbon-diff fix starting ==="

# 1. Fix dashboard hero
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/dashboard.blade.php')
s = p.read_text()
old = '''        $nuStart = \\Carbon\\Carbon::parse($nu->appointment_date->toDateString() . ' ' . $nu->appointment_time);
        $diff = (int) now()->diffInMinutes($nuStart, false);
        $nuMinutesAway = $diff;'''
new = '''        $nuStart = \\Carbon\\Carbon::parse($nu->appointment_date->toDateString() . ' ' . $nu->appointment_time);
        // CARBON3-DIFF-FIX v1: timestamp math instead of diffInMinutes(false)
        // because Carbon 3 returns negative for "$nuStart is later than now",
        // which broke the "In 24 minutes" branch (always fell through to "Next up").
        $nuMinutesAway = (int) round(($nuStart->getTimestamp() - now()->getTimestamp()) / 60);'''
n = s.count(old)
if n == 0:
    print("SKIP 1 (dashboard already fixed)")
else:
    assert n == 1, f"dashboard anchor count={n}, expected 1"
    p.write_text(s.replace(old, new))
    print("OK 1 (dashboard hero diff fixed)")
PY

# 2. Fix mobile schedule gap calc
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/calendar/_mobile-schedule.blade.php')
s = p.read_text()
old = '''      $currStart = Cb::parse($curr->appointment_date->toDateString() . ' ' . $curr->appointment_time);
      $gap = (int) $prevEnd->diffInMinutes($currStart, false);
      if ($gap < 15) return null;'''
new = '''      $currStart = Cb::parse($curr->appointment_date->toDateString() . ' ' . $curr->appointment_time);
      // CARBON3-DIFF-FIX v1: timestamp math — Carbon 3's diffInMinutes(false)
      // returns negative when the argument is later than $this. Using raw
      // timestamps avoids version-specific sign behaviour.
      $gap = (int) round(($currStart->getTimestamp() - $prevEnd->getTimestamp()) / 60);
      if ($gap < 15) return null;'''
n = s.count(old)
if n == 0:
    print("SKIP 2 (mobile schedule already fixed)")
else:
    assert n == 1, f"mobile-schedule anchor count={n}, expected 1"
    p.write_text(s.replace(old, new))
    print("OK 2 (mobile schedule gap diff fixed)")
PY

# Verification
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
verify_absent() {
  local file="$1" needle="$2" label="$3"
  local n
  n=$(grep -c -F -- "$needle" "$file" 2>/dev/null | tr -d '\n' || true)
  : "${n:=0}"
  if [ "${n:-0}" -eq 0 ] 2>/dev/null; then
    echo "  ✓ ABSENT: $label"
  else
    echo "  ✗ STILL PRESENT: $label  (${n}×)"
    fail=1
  fi
}

verify        "resources/views/tenant/dashboard.blade.php"                       "CARBON3-DIFF-FIX v1"        "dashboard fix marker"
verify_absent "resources/views/tenant/dashboard.blade.php"                       "now()->diffInMinutes(\$nuStart, false)" "old dashboard call removed"
verify        "resources/views/tenant/calendar/_mobile-schedule.blade.php"       "CARBON3-DIFF-FIX v1"        "mobile schedule fix marker"
verify_absent "resources/views/tenant/calendar/_mobile-schedule.blade.php"       "\$prevEnd->diffInMinutes(\$currStart, false)" "old gap call removed"

if [ "$fail" -ne 0 ]; then
  echo ""
  echo "✗ FAIL"
  exit 1
fi

echo ""
echo "✓ all green"
echo ""
echo "Next:"
echo "  git add -A && git commit -m 'fix: Carbon 3 diffInMinutes sign convention — dashboard hero + mobile schedule gaps'"
echo "  git push"
echo "  Server: git pull && php artisan view:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "=== fix complete ==="
