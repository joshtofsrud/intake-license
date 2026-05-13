#!/bin/bash
# ============================================================================
# patch-51-walkin-debug-availability.sh
# ----------------------------------------------------------------------------
# TEMPORARY DEBUG PATCH — revert after we figure out why times aren't showing.
#
# Adds visible on-screen diagnostic output to the time step so we can see
# what loadAvailability() is actually doing without needing browser devtools.
# Shows: full URL fetched, response status, response body shape, slot count,
# and any thrown errors — all rendered into the page.
# ============================================================================

set -euo pipefail
cd "${REPO_ROOT:-$(pwd)}"

python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/walkin/index.blade.php")
s = p.read_text()

old = """  // ─── Availability ─────────────────────────────────────────────────
  async function loadAvailability() {
    const container = $('#wiTimesContainer');
    container.innerHTML = `<div class="wi-empty"><span class="wi-spinner"></span> Loading times…</div>`;
    try {
      const params = new URLSearchParams({
        service_id: state.service.id,
        resource_id: $('#wiResourceSelectReal').value || '',
        start_date: new Date().toISOString().slice(0, 10),
      });
      const res = await fetch(`${ROUTE_AVAILABILITY}?${params}`, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      });
      if (!res.ok) {
        // Fallback: synthesize "next 8 slots starting now+15m, every 30m" so
        // the flow is testable even if the endpoint isn't wired yet.
        container.innerHTML = renderFallbackTimes();
        wireTimeRows();
        return;
      }
      const json = await res.json();
      const slots = json.slots || json.times || [];
      if (slots.length === 0) {
        container.innerHTML = `<div class="wi-empty">No available times. Try a different resource or service.</div>`;
        return;
      }
      container.innerHTML = `<div class="wi-times">${slots.slice(0, 12).map(renderSlot).join('')}</div>`;
      wireTimeRows();
    } catch (err) {
      console.error('availability failed', err);
      container.innerHTML = renderFallbackTimes();
      wireTimeRows();
    }
  }"""

new = """  // ─── Availability ─────────────────────────────────────────────────
  // PATCH 51 DEBUG: dumps every step to the page so we can see what's happening
  // without devtools. Revert this block after we find the bug.
  async function loadAvailability() {
    const container = $('#wiTimesContainer');
    const dbg = (msg) => {
      const pre = document.createElement('pre');
      pre.style.cssText = 'background:#1a1a1a;color:#BEF264;padding:8px 12px;margin:4px 16px;border-radius:6px;font-size:11px;white-space:pre-wrap;word-break:break-all;font-family:monospace;border:1px solid rgba(190,242,100,.2);';
      pre.textContent = msg;
      container.appendChild(pre);
    };
    container.innerHTML = '';
    dbg('▶ loadAvailability() called');

    try {
      const rid = $('#wiResourceSelectReal').value || '';
      dbg('resource_id from hidden input: ' + (rid || '(EMPTY!)'));
      dbg('state.service: ' + JSON.stringify({id: state.service?.id, name: state.service?.name}));
      dbg('state.chosenResource: ' + JSON.stringify(state.chosenResource));

      const params = new URLSearchParams({
        service_id: state.service.id,
        resource_id: rid,
        start_date: new Date().toISOString().slice(0, 10),
      });
      const fullUrl = `${ROUTE_AVAILABILITY}?${params}`;
      dbg('Fetching: ' + fullUrl);

      const res = await fetch(fullUrl, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      });
      dbg('Response status: ' + res.status + ' ' + res.statusText);
      dbg('Response content-type: ' + (res.headers.get('content-type') || '(none)'));

      const bodyText = await res.text();
      dbg('Response body (first 400 chars): ' + bodyText.slice(0, 400));

      if (!res.ok) {
        dbg('✗ Not ok — would fall back to synthesized slots');
        return;
      }

      let json;
      try { json = JSON.parse(bodyText); }
      catch (e) { dbg('✗ JSON parse failed: ' + e.message); return; }

      const slots = json.slots || json.times || [];
      dbg('Parsed slots count: ' + slots.length);
      if (slots.length > 0) dbg('First slot: ' + JSON.stringify(slots[0]));

      if (slots.length === 0) {
        dbg('✗ Zero slots returned. required_minutes: ' + (json.required_minutes ?? 'unknown'));
        return;
      }

      dbg('✓ Rendering ' + Math.min(slots.length, 12) + ' time rows');
      const timesDiv = document.createElement('div');
      timesDiv.className = 'wi-times';
      timesDiv.innerHTML = slots.slice(0, 12).map(renderSlot).join('');
      container.appendChild(timesDiv);
      wireTimeRows();
    } catch (err) {
      dbg('✗ Exception caught: ' + (err.message || err));
      dbg('Stack: ' + (err.stack || '(no stack)').slice(0, 300));
    }
  }"""

if "PATCH 51 DEBUG" in s:
    print("    SKIP — debug already applied")
elif old not in s:
    raise SystemExit("ABORT: loadAvailability anchor not found")
elif s.count(old) != 1:
    raise SystemExit(f"ABORT: anchor count = {s.count(old)}")
else:
    s = s.replace(old, new, 1)
    p.write_text(s)
    print("    UPDATED loadAvailability with on-page debug output")
PYEOF

cat <<EONOTE

==> Patch 51 (DEBUG) applied locally.

This is TEMPORARY — revert with patch 51r when done.

Deploy:
  git add resources/views/tenant/walkin/index.blade.php patch-51-walkin-debug-availability.sh
  git commit -m "debug: walk-in time loading visible diagnostics (patch 51)"
  git push

On server:
  cd /var/www/intake
  git pull
  php artisan view:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

Test on phone:
  1. Tap FAB → pick customer → Book → service → resource → Continue
  2. Time step now shows lime-bordered diagnostic boxes instead of (or alongside) times
  3. Screenshot ALL the diagnostic boxes and send them — they'll tell us exactly
     where the chain is breaking.

What we're looking for:
  - "resource_id from hidden input: (EMPTY!)" → picker isn't setting it
  - "Response status: 404" or "302" → route/redirect issue
  - "Response status: 200" + "Parsed slots count: 0" → endpoint returns empty
    (need to look at what params were sent)
  - "Parsed slots count: 7" + no time rows below → render bug
  - "Exception caught: ..." → some earlier code threw

After we find it, run a 51r revert patch that restores the original
loadAvailability (or we just write the real fix).
EONOTE
