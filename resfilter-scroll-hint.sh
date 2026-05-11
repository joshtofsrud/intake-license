#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Mobile schedule — resource filter scroll hint.
#
# When the calendar page loads on mobile, briefly scroll the resource filter
# pill bar right and back to signal that it's horizontally scrollable. Only
# runs when the bar actually overflows (more chips than fit on screen).
# Respects prefers-reduced-motion. Cancels if user touches the bar first.
#
# Implementation: small inline <script> at the end of _mobile-schedule.blade.php
# so it's loaded only when the mobile schedule renders. Uses scrollTo with
# behavior:'smooth' for the bounce.
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: not in intake-license repo root"; exit 1; }

echo "=== resource filter scroll hint starting ==="

python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/calendar/_mobile-schedule.blade.php')
s = p.read_text()
marker = "RESFILTER-SCROLL-HINT v1"
if marker in s:
    print("SKIP (hint already present)")
else:
    # Append the script block at the very end of the file.
    # No anchor needed — just append.
    hint = '''

{{-- RESFILTER-SCROLL-HINT v1 — nudge the resource filter pill bar on page load
     to signal it's horizontally scrollable. Only fires if the bar actually
     overflows. Respects prefers-reduced-motion. Cancels on user touch. --}}
<script>
(function () {
  // Wait for layout to settle so scrollWidth is accurate
  function init() {
    var bar = document.querySelector('.ia-msched-resfilter');
    if (!bar) return;

    // Skip if user prefers reduced motion
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    // Only hint when there's actually horizontal overflow.
    // +2 tolerance to avoid running for sub-pixel rounding cases.
    if (bar.scrollWidth <= bar.clientWidth + 2) return;

    // Cancel if user already interacted before the hint runs
    var cancelled = false;
    function cancel() { cancelled = true; }
    bar.addEventListener('touchstart', cancel, { passive: true, once: true });
    bar.addEventListener('mousedown',  cancel, { passive: true, once: true });

    // Wait a beat after paint so it doesn't feel like a layout glitch.
    // Then: scroll to ~45px, then back to 0.
    setTimeout(function () {
      if (cancelled) return;
      bar.scrollTo({ left: 45, behavior: 'smooth' });
      setTimeout(function () {
        if (cancelled) return;
        bar.scrollTo({ left: 0, behavior: 'smooth' });
      }, 380);
    }, 280);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
</script>
'''
    p.write_text(s + hint)
    print("OK (hint script appended)")
PY

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

verify "resources/views/tenant/calendar/_mobile-schedule.blade.php"  "RESFILTER-SCROLL-HINT v1"  "marker"
verify "resources/views/tenant/calendar/_mobile-schedule.blade.php"  "prefers-reduced-motion"    "reduced-motion check"
verify "resources/views/tenant/calendar/_mobile-schedule.blade.php"  "scrollWidth"               "overflow check"
verify "resources/views/tenant/calendar/_mobile-schedule.blade.php"  "touchstart"                "cancel on touch"

if [ "$fail" -ne 0 ]; then
  echo ""
  echo "✗ FAIL"
  exit 1
fi

echo ""
echo "✓ all green"
echo ""
echo "Deploy:"
echo "  git add -A && git commit -m 'mobile: scroll-hint nudge on resource filter pill bar to signal scrollability'"
echo "  git push"
echo "  Server: git pull && php artisan view:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "=== scroll hint complete ==="
