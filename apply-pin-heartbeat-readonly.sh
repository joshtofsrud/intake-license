#!/bin/bash
# pin-heartbeat-readonly — the idle timeout can actually expire now.
#
#   idle-lock.js fires heartbeat() on a 60-second TIMER, not on activity — it
#   only skips while the overlay is already up. PinGateController::heartbeat()
#   then treated "not yet stale" as "fresh" and bumped last_pin_activity_at to
#   now. So an unattended browser told the server it was still there once a
#   minute, forever.
#
#   At the 120s default: t=60 the stamp is 60s old, not stale, so it moves to
#   60. t=120 it's 60s old again, moves to 120. The server-side clock could
#   never reach the threshold, EnsurePinFresh never locked a page render, and
#   the heartbeat never returned 423.
#
#   What still locked was the in-tab JS timer, which tracks real activity
#   locally. But that is explicitly a UX accelerator — EnsurePinFresh's own
#   docblock says the server is the source of truth — and it dies on reload,
#   in a new tab, and on any page without the overlay. An idle shop iPad that
#   got refreshed was simply never locked.
#
#   The heartbeat is now read-only: it reports whether the session is stale
#   and stamps nothing. Real activity still stamps through EnsurePinFresh on
#   ordinary page loads and AJAX, which are actual user actions.
#
#   Checked the rest of the polling while here, and it's already correct:
#   EnsurePinFresh honours an X-Intake-Background: 1 header, and both the
#   staff-alerts bell and offline-sync.js send it. The calendar's 60s timer
#   only redraws a clock label, the pay display polls an unauthenticated
#   token route, and the register's payment-link polls run with someone
#   standing at the counter. The heartbeat was the only hole — it sits on the
#   middleware whitelist, so the background-header convention never reached it
#   and it did its own bump instead.
# NO MIGRATION. Server: optimize:clear.
set -e
if grep -q "MARKER-HEARTBEAT-READONLY" app/Http/Controllers/Tenant/PinGateController.php; then
  echo "pin-heartbeat-readonly already applied — aborting."; exit 1
fi

python3 - <<'PHR_0_EOF'
import io
p = 'app/Http/Controllers/Tenant/PinGateController.php'
s = io.open(p, encoding='utf-8').read()

old = """        // Fresh - bump (rate-limited to once per minute, same as middleware).
        if ($last->lt(now()->subMinute())) {
            $request->session()->put('last_pin_activity_at', now()->toIso8601String());
        }

        return response()->json(['ok' => true]);"""
assert s.count(old) == 1, s.count(old)

new = """        // MARKER-HEARTBEAT-READONLY \u2014 report only; stamp nothing.
        //
        // This used to bump last_pin_activity_at whenever the session wasn't
        // already stale. The client fires this on a 60s timer regardless of
        // activity, so an unattended browser pushed the timestamp forward
        // once a minute and the idle threshold could never be reached. The
        // configured timeout looked like it did nothing because, server-side,
        // it did nothing.
        //
        // Activity is stamped by EnsurePinFresh on real page loads and AJAX.
        // Background pollers opt out of that with X-Intake-Background: 1.
        // A heartbeat is the machine asking a question, not a person working.
        return response()->json(['ok' => true]);"""

io.open(p, 'w', encoding='utf-8').write(s.replace(old, new))
print('heartbeat read-only ok')
PHR_0_EOF

php -l app/Http/Controllers/Tenant/PinGateController.php

echo
echo "pin-heartbeat-readonly applied."
