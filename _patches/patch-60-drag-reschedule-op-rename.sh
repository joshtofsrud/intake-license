#!/bin/bash
# ============================================================================
# patch-60-drag-reschedule-op-rename.sh
# ----------------------------------------------------------------------------
# Fixes drag-to-reschedule on the time-slot calendar. The JS posts op=reschedule
# with appointment_time + resource_id (no date), expecting to hit a drag-friendly
# branch in AppointmentController. But there's an earlier branch with the same
# condition `if ($op === 'reschedule')` that requires appointment_date and
# returns 422 if it's missing. First branch wins — drag handler 422s, second
# branch is dead code.
#
# Fix: rename the drag-specific path to op=reschedule_time so both branches
# can coexist. JS sends the new op, controller's second branch matches it.
#
# Files touched:
#   - public/js/tenant/calendar.js                  (drag handler op value)
#   - app/Http/Controllers/Tenant/AppointmentController.php  (drag branch condition)
#
# Bug class lesson: same $op value in two if-branches of the same method.
# First match wins, rest are dead. Worth a one-off audit of every $op ===
# check across controllers — see RUNBOOK or run:
#   grep -rn "\$op === '" app/Http/Controllers/ | sort -t: -k3
# ============================================================================

set -euo pipefail
cd "${REPO_ROOT:-$(pwd)}"

if [ ! -f "public/js/tenant/calendar.js" ]; then
  echo "ERROR: public/js/tenant/calendar.js not found." >&2
  exit 1
fi
if [ ! -f "app/Http/Controllers/Tenant/AppointmentController.php" ]; then
  echo "ERROR: AppointmentController.php not found." >&2
  exit 1
fi

# ─── 1. JS: rename op in the drag-handler fetch payload ────────────────
python3 <<'PYEOF'
from pathlib import Path
p = Path("public/js/tenant/calendar.js")
s = p.read_text()

# Anchor on the surrounding lines (the FormData appends) so we don't accidentally
# match an unrelated 'reschedule' string elsewhere in the file. The whole
# 5-line block is what we replace.
old = """      fd.append('_method', 'PATCH');
      fd.append('_token', getCsrf());
      fd.append('op', 'reschedule');
      fd.append('appointment_time', newTime);
      fd.append('resource_id', newResource);"""

new = """      fd.append('_method', 'PATCH');
      fd.append('_token', getCsrf());
      fd.append('op', 'reschedule_time');
      fd.append('appointment_time', newTime);
      fd.append('resource_id', newResource);"""

if "'op', 'reschedule_time'" in s:
    print("    SKIP JS — op already renamed to reschedule_time")
elif old not in s:
    raise SystemExit("ABORT JS: FormData block anchor not found")
else:
    s = s.replace(old, new, 1)
    p.write_text(s)
    print("    UPDATED — calendar.js drag handler posts op=reschedule_time")
PYEOF

# ─── 2. Controller: rename the drag branch condition ────────────────────
python3 <<'PYEOF'
from pathlib import Path
p = Path("app/Http/Controllers/Tenant/AppointmentController.php")
s = p.read_text()

# Anchor on the if + the unique comment on the next line. The comment is
# distinctive enough that this can't match the other reschedule branch.
old = """        if ($op === 'reschedule') {
            // Drag-to-reschedule: change appointment_time and optionally resource_id"""

new = """        if ($op === 'reschedule_time') {
            // Drag-to-reschedule: change appointment_time and optionally resource_id"""

if "if ($op === 'reschedule_time')" in s:
    print("    SKIP controller — op already renamed to reschedule_time")
elif old not in s:
    raise SystemExit("ABORT controller: drag-branch anchor not found")
else:
    s = s.replace(old, new, 1)
    p.write_text(s)
    print("    UPDATED — AppointmentController drag branch now matches op=reschedule_time")
PYEOF

cat <<EONOTE

==> Patch 60 applied locally.

Deploy:
  git add public/js/tenant/calendar.js \\
          app/Http/Controllers/Tenant/AppointmentController.php \\
          _patches/patch-60-drag-reschedule-op-rename.sh
  git commit -m "fix: drag-to-reschedule uses distinct op (was dead-code shadowed by v1) (patch 60)"
  git push

On server:
  cd /var/www/intake
  git pull
  php artisan view:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

Verify:
  1. Open /admin/calendar on a tenant with appointments (Mountainview Fitness)
  2. Drag an appointment to a new time slot — should succeed with "Rescheduled."
     toast, page reloads, appointment is in the new slot.
  3. NOT verified by this fix: drawer-based full reschedule (date change).
     That still uses op=reschedule and validates appointment_date — untouched.

If drag-and-drop still 422s after deploy: hard-refresh the browser (Cmd+Shift+R).
The JS is a static asset and browsers cache it aggressively.

NOTE: this patch should live in _patches/ from the start since root /patch-*.sh
is now gitignored. Move it before commit if you ran it from the project root:
  mv patch-60-drag-reschedule-op-rename.sh _patches/
EONOTE
