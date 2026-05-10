#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Layout B — fix grid overflow
#
# Bug: the relocated right-rail content (Resource, Capacity slots, Payment,
# Cancel button) was placed as a SIBLING of .appt-b-main inside .appt-b-shell.
# Since .appt-b-shell is grid-template-columns: 280px 1fr (2 columns), the
# THIRD child wraps to row 2 col 1, ending up stacked under the sticky rail
# and visually overlapping it.
#
# Fix: make the relocated content a CHILD of .appt-b-main (so it appears at
# the bottom of the main column where it belongs).
#
# Mechanism: remove the early </div> that closes .appt-b-main on line ~818,
# and add a closing </div> after the relocated content (~line 1037) before
# the </div> that closes .appt-b-shell.
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: must run from intake-license repo root (no artisan found in $(pwd))"; exit 1; }

echo "=== layout-b grid fix starting ==="

python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()

# ANCHOR 1: The premature close of .appt-b-main and the start of the moved block.
# This pattern is unique because of the LAYOUT-B-MOVED comment.
old1 = '''      </div>
    </div>

  </div>

  {{-- LAYOUT-B-MOVED v1 — original right-rail content now lives at the bottom of main --}}
  <div style="display:flex;flex-direction:column;gap:16px;width:100%">'''

new1 = '''      </div>
    </div>

    {{-- LAYOUT-B-MOVED-FIX v1 — relocated cards are now children of .appt-b-main --}}
    <div style="display:flex;flex-direction:column;gap:16px;width:100%">'''

assert s.count(old1) == 1, f"fix-anchor-1 count={s.count(old1)}, expected 1"
s = s.replace(old1, new1)

# ANCHOR 2: After @endunless of the cancel button, close .appt-b-main, then close .appt-b-shell.
# Original final divs: </div> (close moved-block) </div> (close .appt-b-shell)
# We need: </div> (close moved-block) </div> (close .appt-b-main) </div> (close .appt-b-shell)
# i.e. add ONE extra </div>.

old2 = '''      </button>
    @endunless

  </div>

</div>

@endsection'''

new2 = '''      </button>
    @endunless

    </div>{{-- /moved-block --}}

  </div>{{-- /.appt-b-main --}}

</div>{{-- /.appt-b-shell --}}

@endsection'''

assert s.count(old2) == 1, f"fix-anchor-2 count={s.count(old2)}, expected 1"
s = s.replace(old2, new2)

p.write_text(s)
print("OK grid fix applied")
PY

# Verify
echo ""
echo "=== verifying ==="
fail=0
needle='LAYOUT-B-MOVED-FIX v1'
n=$(grep -c -F -- "$needle" resources/views/tenant/appointments/show.blade.php 2>/dev/null | tr -d '\n' || true)
: "${n:=0}"
if [ "${n:-0}" -ge 1 ] 2>/dev/null; then
  echo "  ✓ fix marker present"
else
  echo "  ✗ MISSING fix marker"
  fail=1
fi

# Critical: balanced div count must still be balanced after this surgery.
python3 <<'PY'
from pathlib import Path
src = Path('resources/views/tenant/appointments/show.blade.php').read_text()
opens = src.count('<div')
closes = src.count('</div')
import sys
if opens == closes:
    print(f'  ✓ <div balance: {opens} open, {closes} close')
else:
    print(f'  ✗ <div MISMATCH: {opens} open, {closes} close')
    sys.exit(1)
PY

if [ "$fail" -ne 0 ]; then
  echo ""
  echo "✗ FAIL"
  exit 1
fi

echo ""
echo "✓ patch verified"
echo ""
echo "Next:"
echo "  git add -A && git commit -m 'fix: layout B relocated cards now child of main (not shell)'"
echo "  git push"
echo "  Server: git pull && php artisan view:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
