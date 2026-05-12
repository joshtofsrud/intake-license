#!/bin/bash
# ============================================================================
# patch-50-walkin-resource-picker.sh
# ----------------------------------------------------------------------------
# Adds a smart resource-picker step to the walk-in flow.
#
# Logic:
#   - After service pick, call /appointments/eligible-resources?service_id=X
#   - If 1 eligible resource → skip picker, auto-select, show banner on time step
#   - If 2+ eligible resources → show picker step ("Step 2 of 3"), then time
#   - Falls back gracefully: if endpoint fails or returns empty, use ALL active
#     resources (matches the backend convention that empty pivot = all eligible).
#
# Why this exists: Mountainview Fitness (and likely every tenant) has services
# with empty service↔resource pivot rows because the convention is
# "empty = all". The walk-in flow's hidden <select> picked the first active
# resource silently with no UI to change. weekTimes returned slots for that
# resource only, but if it had no matching availability rule for the DOW, the
# picker showed empty and booking was impossible.
#
# Files touched:
#   - app/Http/Controllers/Tenant/WalkInController.php
#       Restore subtitle field on resources passed to the view.
#   - resources/views/tenant/walkin/index.blade.php
#       Add CSS for picker rows, add <section data-step="resource">, remove
#       the broken hidden-select hack, rework service-pick handler to fetch
#       eligible resources, add resource-pick handler, update step labels.
#
# Mirrors mockup B in walkin-flow-mockup.html.
# ============================================================================

set -euo pipefail
cd "${REPO_ROOT:-$(pwd)}"

if [ ! -f "resources/views/tenant/walkin/index.blade.php" ]; then
  echo "ERROR: walk-in blade not found." >&2
  exit 1
fi
if [ ! -f "app/Http/Controllers/Tenant/WalkInController.php" ]; then
  echo "ERROR: WalkInController not found." >&2
  exit 1
fi

# ─── 1. Controller: restore subtitle on resources ───────────────────────
python3 <<'PYEOF'
from pathlib import Path
p = Path("app/Http/Controllers/Tenant/WalkInController.php")
s = p.read_text()

old = """            ->get(['id', 'name', 'subtitle', 'color_hex'])
            ->map(fn ($r) => [
                'id'    => $r->id,
                'name'  => $r->name,
                'color' => $r->color_hex,
            ]);"""
new = """            ->get(['id', 'name', 'subtitle', 'color_hex'])
            ->map(fn ($r) => [
                'id'       => $r->id,
                'name'     => $r->name,
                'subtitle' => $r->subtitle,
                'color'    => $r->color_hex,
            ]);"""

if "'subtitle' => $r->subtitle" in s:
    print("    SKIP controller — subtitle already present")
elif old not in s:
    raise SystemExit("ABORT controller: resource map anchor not found")
elif s.count(old) != 1:
    raise SystemExit(f"ABORT controller: anchor count = {s.count(old)}")
else:
    s = s.replace(old, new, 1)
    p.write_text(s)
    print("    UPDATED WalkInController.php — subtitle restored on resources")
PYEOF

# ─── 2. Blade: CSS for picker rows ──────────────────────────────────────
python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/walkin/index.blade.php")
s = p.read_text()

anchor_css = """  .wi-svc-price {
    font-size: 14.5px; font-weight: 600;
    color: var(--ia-text, #f0f0f0);
  }"""

new_css = """  .wi-svc-price {
    font-size: 14.5px; font-weight: 600;
    color: var(--ia-text, #f0f0f0);
  }

  /* Resource picker (Mockup B) */
  .wi-res-row {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 20px;
    background: var(--ia-surface, #131313);
    border-top: 1px solid var(--ia-border, rgba(255,255,255,.08));
    cursor: pointer;
  }
  .wi-res-row:first-of-type { border-top: 0; }
  .wi-res-row:active { background: var(--ia-surface-2, #1a1a1a); }
  .wi-res-row.selected { background: rgba(190,242,100,.08); }
  .wi-res-swatch {
    width: 28px; height: 28px;
    border-radius: 8px;
    flex-shrink: 0;
    background: var(--ia-surface-2, #1a1a1a);
  }
  .wi-res-body { flex: 1; min-width: 0; }
  .wi-res-name { font-size: 14.5px; font-weight: 500; }
  .wi-res-sub { font-size: 12px; color: var(--ia-muted, #888); margin-top: 2px; }
  .wi-res-check {
    width: 22px; height: 22px;
    border-radius: 50%;
    border: 1.5px solid var(--ia-border-2, rgba(255,255,255,.14));
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 700;
    color: transparent;
    flex-shrink: 0;
  }
  .wi-res-row.selected .wi-res-check {
    border-color: var(--ia-accent, #BEF264);
    background: var(--ia-accent, #BEF264);
    color: var(--ia-bg, #0d0d0d);
  }
  .wi-res-row.selected .wi-res-check::before { content: \"\\2713\"; }

  /* Single-resource banner on time step */
  .wi-banner {
    display: flex; align-items: flex-start; gap: 8px;
    margin: 0 16px 12px;
    padding: 10px 12px;
    background: rgba(190,242,100,.08);
    border: 1px solid rgba(190,242,100,.2);
    border-radius: 8px;
    font-size: 12.5px;
    line-height: 1.4;
  }
  .wi-banner-icon { color: var(--ia-accent, #BEF264); flex-shrink: 0; }
  .wi-banner strong { font-weight: 500; }

  /* Recap on time step (multi-resource path) */
  .wi-recap {
    margin: 0 16px 12px;
    padding: 10px 12px;
    background: var(--ia-surface, #131313);
    border-radius: 8px;
    font-size: 12.5px;
    color: var(--ia-muted, #888);
  }
  .wi-recap strong { color: var(--ia-text, #f0f0f0); font-weight: 500; }"""

if ".wi-res-row {" in s:
    print("    SKIP blade-css — picker styles already present")
elif anchor_css not in s:
    raise SystemExit("ABORT blade-css: CSS anchor not found")
elif s.count(anchor_css) != 1:
    raise SystemExit(f"ABORT blade-css: anchor count = {s.count(anchor_css)}")
else:
    s = s.replace(anchor_css, new_css, 1)
    p.write_text(s)
    print("    UPDATED blade — picker CSS inserted")
PYEOF

# ─── 3. Blade: HTML — add resource step, simplify time step ──────────────
python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/walkin/index.blade.php")
s = p.read_text()

# Service step label: dynamic
old_svc_label = '''  <section class="wi-step" data-step="service">
    <div class="wi-step-label">Step 1 of 2</div>'''
new_svc_label = '''  <section class="wi-step" data-step="service">
    <div class="wi-step-label" id="wiSvcStepLabel">Step 1</div>'''
if 'id="wiSvcStepLabel"' in s:
    print("    SKIP service step label — already dynamic")
elif old_svc_label in s:
    s = s.replace(old_svc_label, new_svc_label, 1)
    print("    UPDATED service step label → dynamic")
else:
    raise SystemExit("ABORT: service step label anchor not found")

# Replace broken time block with new resource step + clean time step
old_time_block = '''  {{-- ======================== STEP 5: TIME PICK ======================== --}}
  <section class="wi-step" data-step="time">
    <div class="wi-step-label">Step 2 of 2</div>
    <div class="wi-hero" style="padding-top:8px">
      <h2>Pick a time</h2>
      <p class="wi-hero-sub" id="wiTimeSub">—</p>
    </div>

    @if(count($resources) > 1)
      <div class="wi-field">
        <label for="wiResourceSelect">Resource</label>
        <input type="text" id="wiResourceSelect" readonly style="cursor:pointer">
        <select id="wiResourceSelectReal" style="display:none">
          @foreach($resources as $r)
            <option value="{{ $r['id'] }}">{{ $r['name'] }}</option>
          @endforeach
        </select>
      </div>
    @else
      <input type="hidden" id="wiResourceSelectReal" value="{{ $resources[0]['id'] ?? '' }}">
    @endif

    <div id="wiTimesContainer"></div>

    <div class="wi-bottom">
      <button class="wi-bottom-btn" id="wiBookConfirm" disabled>Confirm booking →</button>
    </div>
  </section>'''

new_time_block = '''  {{-- ======================== STEP 4b: RESOURCE PICK (multi only) ======================== --}}
  <section class="wi-step" data-step="resource">
    <div class="wi-step-label">Step 2 of 3</div>
    <div class="wi-hero" style="padding-top:8px">
      <h2 id="wiResHeading">Pick a resource</h2>
      <p class="wi-hero-sub" id="wiResSub">—</p>
    </div>
    <div id="wiResContainer">
      <div class="wi-empty"><span class="wi-spinner"></span> Loading…</div>
    </div>
    <div class="wi-bottom">
      <button class="wi-bottom-btn" id="wiResContinue" disabled>Continue →</button>
    </div>
  </section>

  {{-- ======================== STEP 5: TIME PICK ======================== --}}
  <section class="wi-step" data-step="time">
    <div class="wi-step-label" id="wiTimeStepLabel">Step 2 of 2</div>
    <div class="wi-hero" style="padding-top:8px">
      <h2>Pick a time</h2>
      <p class="wi-hero-sub" id="wiTimeSub">—</p>
    </div>

    {{-- Either a single-resource banner OR a multi-resource recap, populated by JS --}}
    <div id="wiTimeContext"></div>

    {{-- Hidden field still here so existing JS that reads it keeps working --}}
    <input type="hidden" id="wiResourceSelectReal" value="">

    <div id="wiTimesContainer"></div>

    <div class="wi-bottom">
      <button class="wi-bottom-btn" id="wiBookConfirm" disabled>Confirm booking →</button>
    </div>
  </section>'''

if 'data-step="resource"' in s:
    print("    SKIP blade-html — resource step already present")
elif old_time_block not in s:
    raise SystemExit("ABORT blade-html: time block anchor not found")
elif s.count(old_time_block) != 1:
    raise SystemExit(f"ABORT blade-html: anchor count = {s.count(old_time_block)}")
else:
    s = s.replace(old_time_block, new_time_block, 1)
    print("    UPDATED blade — resource step inserted, time step simplified")

p.write_text(s)
PYEOF

# ─── 4. Blade: JS ───────────────────────────────────────────────────────
python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/walkin/index.blade.php")
s = p.read_text()

# Add ROUTE_ELIGIBLE
old_routes = "const ROUTE_AVAILABILITY = `/appointments/week-times`;"
new_routes = """const ROUTE_AVAILABILITY = `/appointments/week-times`;
  const ROUTE_ELIGIBLE    = `/appointments/eligible-resources`;"""
if "ROUTE_ELIGIBLE" in s:
    print("    SKIP JS routes — ROUTE_ELIGIBLE already present")
elif old_routes not in s:
    raise SystemExit("ABORT JS routes: ROUTE_AVAILABILITY anchor not found")
else:
    s = s.replace(old_routes, new_routes, 1)
    print("    UPDATED JS — ROUTE_ELIGIBLE added")

# Expose RESOURCES_BY_ID lookup
old_constants = """  const CSRF = @json($csrf);
  const SUBDOMAIN = @json($tenant->subdomain);"""
new_constants = """  const CSRF = @json($csrf);
  const SUBDOMAIN = @json($tenant->subdomain);
  const RESOURCES_BY_ID = @json($resources->keyBy('id'));"""
if "RESOURCES_BY_ID" in s:
    print("    SKIP JS constants — RESOURCES_BY_ID already present")
elif old_constants not in s:
    raise SystemExit("ABORT JS constants: CSRF anchor not found")
else:
    s = s.replace(old_constants, new_constants, 1)
    print("    UPDATED JS — RESOURCES_BY_ID lookup exposed")

# Replace service handler
old_svc_handler = """  // ─── Service selection ────────────────────────────────────────────
  $$('.wi-svc-row').forEach(row => {
    row.addEventListener('click', () => {
      $$('.wi-svc-row').forEach(r => r.classList.remove('selected'));
      row.classList.add('selected');
      try {
        state.service = JSON.parse(row.dataset.svc);
        $('#wiTimeSub').textContent =
          `${state.customer.name} · ${state.service.name} · ${state.service.duration} min`;
        loadAvailability();
        goto('time');
      } catch (err) { console.error(err); }
    });
  });"""

new_svc_handler = """  // ─── Service selection ────────────────────────────────────────────
  // After a service is picked, fetch the eligible resources for it.
  // - 1 result → auto-select, skip picker, show single-resource banner on time
  // - 2+ results → show resource picker step (Step 2 of 3)
  // - fetch fails or returns empty → fall back to ALL active resources
  //   (matches backend convention: empty pivot = all eligible)
  $$('.wi-svc-row').forEach(row => {
    row.addEventListener('click', async () => {
      $$('.wi-svc-row').forEach(r => r.classList.remove('selected'));
      row.classList.add('selected');
      try {
        state.service = JSON.parse(row.dataset.svc);
      } catch (err) { console.error(err); return; }

      const subline = `${state.customer.name} · ${state.service.name} · ${state.service.duration} min`;
      $('#wiTimeSub').textContent = subline;

      // Fetch eligible resources for this service.
      let eligibleIds = [];
      try {
        const res = await fetch(`${ROUTE_ELIGIBLE}?service_id=${encodeURIComponent(state.service.id)}`, {
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (res.ok) {
          const json = await res.json();
          eligibleIds = (json.resources || []).map(r => r.id);
        }
      } catch (err) { console.error('eligible-resources fetch failed', err); }

      // Fallback: if fetch returned empty or failed, use ALL active resources.
      // Matches backend convention that empty pivot means "all eligible".
      if (eligibleIds.length === 0) {
        eligibleIds = Object.keys(RESOURCES_BY_ID);
      }

      // Hydrate from local lookup to get name/color/subtitle.
      const eligible = eligibleIds
        .map(id => RESOURCES_BY_ID[id])
        .filter(Boolean);

      state.eligibleResources = eligible;

      if (eligible.length <= 1) {
        // Single resource path: auto-select, skip picker.
        const chosen = eligible[0] || null;
        state.chosenResource = chosen;
        $('#wiResourceSelectReal').value = chosen ? chosen.id : '';
        renderTimeContext('single');
        $('#wiTimeStepLabel').textContent = 'Step 2 of 2';
        loadAvailability();
        goto('time');
      } else {
        // Multi-resource path: show picker.
        $('#wiResSub').textContent = subline;
        renderResourcePicker(eligible);
        goto('resource');
      }
    });
  });

  // ─── Resource picker (multi-resource path only) ───────────────────
  function renderResourcePicker(resources) {
    const container = $('#wiResContainer');
    container.innerHTML = resources.map(r => `
      <div class=\"wi-res-row\" data-rid=\"${escapeHtml(r.id)}\">
        <div class=\"wi-res-swatch\" style=\"background:${escapeHtml(r.color || '#1a1a1a')}\"></div>
        <div class=\"wi-res-body\">
          <div class=\"wi-res-name\">${escapeHtml(r.name)}</div>
          ${r.subtitle ? `<div class=\"wi-res-sub\">${escapeHtml(r.subtitle)}</div>` : ''}
        </div>
        <div class=\"wi-res-check\"></div>
      </div>
    `).join('');

    state.chosenResource = null;
    $('#wiResContinue').disabled = true;

    container.querySelectorAll('.wi-res-row').forEach(row => {
      row.addEventListener('click', () => {
        container.querySelectorAll('.wi-res-row').forEach(r => r.classList.remove('selected'));
        row.classList.add('selected');
        const rid = row.dataset.rid;
        state.chosenResource = RESOURCES_BY_ID[rid] || null;
        $('#wiResourceSelectReal').value = rid;
        $('#wiResContinue').disabled = false;
      });
    });
  }

  $('#wiResContinue').addEventListener('click', () => {
    if (!state.chosenResource) return;
    renderTimeContext('multi');
    $('#wiTimeStepLabel').textContent = 'Step 3 of 3';
    loadAvailability();
    goto('time');
  });

  function renderTimeContext(mode) {
    const ctx = $('#wiTimeContext');
    if (mode === 'single' && state.chosenResource) {
      ctx.innerHTML = `<div class=\"wi-banner\"><span class=\"wi-banner-icon\">\u2713</span><span>Booking with <strong>${escapeHtml(state.chosenResource.name)}</strong>${state.chosenResource.subtitle ? ' \u00b7 ' + escapeHtml(state.chosenResource.subtitle) : ''}.</span></div>`;
    } else if (mode === 'multi' && state.chosenResource) {
      const sub = state.chosenResource.subtitle ? ' \u00b7 ' + escapeHtml(state.chosenResource.subtitle) : '';
      ctx.innerHTML = `<div class=\"wi-recap\"><strong>${escapeHtml(state.chosenResource.name)}</strong>${sub}</div>`;
    } else {
      ctx.innerHTML = '';
    }
  }"""

if "state.eligibleResources" in s:
    print("    SKIP JS svc handler — already updated")
elif old_svc_handler not in s:
    raise SystemExit("ABORT JS svc handler: anchor not found")
else:
    s = s.replace(old_svc_handler, new_svc_handler, 1)
    print("    UPDATED JS — service handler routes via eligible-resources")

p.write_text(s)
PYEOF

cat <<EONOTE

==> Patch 50 applied locally.

Deploy:
  git add app/Http/Controllers/Tenant/WalkInController.php \\
          resources/views/tenant/walkin/index.blade.php \\
          patch-50-walkin-resource-picker.sh
  git commit -m "feat: walk-in resource picker with smart single/multi default (patch 50)"
  git push

On server:
  cd /var/www/intake
  git pull
  php artisan optimize:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

Verify end-to-end on phone (fitnesstest, 5 resources, empty pivot):
  1. Tap FAB → walk-in page renders
  2. Tap a recent customer (Joshua Tofsrud)
  3. Choose "Book appointment"
  4. Pick a service (e.g. "Personal Training (30 min)")
  5. EXPECTED: Resource picker step shows all 5 resources
     (Josh, Sage, Marcus, Theo, River) with swatch + name + check circle.
  6. Tap a resource → check fills in, Continue button enables
  7. Tap Continue
  8. EXPECTED: Time step shows recap with selected resource name at top,
     then real time slots.
  9. Pick a time → Confirm booking
  10. EXPECTED: lands on /admin/appointments/{id}
  11. Open the appointment — verify resource_id matches what you picked.

Single-resource path (any service with exactly one eligible resource):
  - After service pick → straight to time step
  - Banner: "Booking with <Name>"
  - Step label: "Step 2 of 2"

Debug if needed:
  - DevTools network → /appointments/eligible-resources response
  - If empty for all services, the fallback (RESOURCES_BY_ID) kicks in
    and picker shows all 5 anyway.
EONOTE
