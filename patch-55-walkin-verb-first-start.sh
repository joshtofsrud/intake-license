#!/bin/bash
# ============================================================================
# patch-55-walkin-verb-first-start.sh
# ----------------------------------------------------------------------------
# Restructures the walk-in start screen to be verb-first: three primary
# action tiles (Book appointment, Ring up sale, Add new customer) lead the
# screen, with search + recent customers below for discoverability.
#
# Why: The current start screen treats customer-selection as the primary
# affordance, but the most-frequent walk-in question is "what does this
# person want?" not "who is this person?". Verb-first matches counter
# staff mental model and makes booking an existing customer obvious.
#
# Flow changes:
#   - Tap "Book appointment" tile → new customer-pick step
#       (search + recent customers, no actions). Pick customer → skip
#       choice screen, go directly to service picker.
#   - Tap "Ring up sale" tile → new customer-pick step with
#       "Skip — anonymous sale" option at top. Pick = pre-attach.
#       Skip = anonymous register.
#   - Tap "Add new customer" tile → existing new-customer step (unchanged).
#   - Tap recent customer / search result on START screen → existing
#       choice screen (preserves "I see them already" path).
#   - Tap recent customer / search result on CUSTOMER-PICK screen →
#       commits directly to the pre-selected intent (book or sale).
#
# Files touched:
#   - resources/views/tenant/walkin/index.blade.php
#
# This is a UX-only patch — no controller or backend changes.
# ============================================================================

set -euo pipefail
cd "${REPO_ROOT:-$(pwd)}"

if [ ! -f "resources/views/tenant/walkin/index.blade.php" ]; then
  echo "ERROR: walk-in blade not found." >&2
  exit 1
fi

# ─── 1. Replace start screen HTML ───────────────────────────────────────
python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/walkin/index.blade.php")
s = p.read_text()

old_start = """  {{-- ======================== STEP 1: START ======================== --}}
  <section class="wi-step active" data-step="start">
    <div class="wi-hero">
      <h2>Who's at the counter?</h2>
      <p class="wi-hero-sub">Search by name, email, or phone — or start a new customer.</p>
    </div>

    <div class="wi-search">
      <input
        type="search"
        id="wiSearch"
        placeholder="Search customers…"
        autocomplete="off"
        spellcheck="false">
      <div class="wi-search-results" id="wiSearchResults"></div>
    </div>

    <div class="wi-choice" data-action="new-customer">
      <div class="wi-choice-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
          <circle cx="9" cy="7" r="4"/>
          <line x1="19" y1="8" x2="19" y2="14"/>
          <line x1="22" y1="11" x2="16" y2="11"/>
        </svg>
      </div>
      <div class="wi-choice-body">
        <div class="wi-choice-title">New customer</div>
        <div class="wi-choice-sub">Capture name + phone, then book or sell</div>
      </div>
      <div class="wi-choice-arrow">›</div>
    </div>

    <div class="wi-choice" data-action="anon-sale">
      <div class="wi-choice-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="9" cy="21" r="1"/>
          <circle cx="20" cy="21" r="1"/>
          <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
        </svg>
      </div>
      <div class="wi-choice-body">
        <div class="wi-choice-title">Quick retail sale</div>
        <div class="wi-choice-sub">No appointment, no customer record needed</div>
      </div>
      <div class="wi-choice-arrow">›</div>
    </div>"""

new_start = """  {{-- ======================== STEP 1: START (verb-first) ======================== --}}
  <section class="wi-step active" data-step="start">
    <div class="wi-hero">
      <h2>What can we help with?</h2>
      <p class="wi-hero-sub">Pick an action — we'll grab the customer next.</p>
    </div>

    <div class="wi-choice wi-choice-primary" data-action="book-intent">
      <div class="wi-choice-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="4" width="18" height="18" rx="2"/>
          <line x1="16" y1="2" x2="16" y2="6"/>
          <line x1="8" y1="2" x2="8" y2="6"/>
          <line x1="3" y1="10" x2="21" y2="10"/>
        </svg>
      </div>
      <div class="wi-choice-body">
        <div class="wi-choice-title">Book appointment</div>
        <div class="wi-choice-sub">Schedule a service for a customer</div>
      </div>
      <div class="wi-choice-arrow">›</div>
    </div>

    <div class="wi-choice" data-action="sale-intent">
      <div class="wi-choice-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="9" cy="21" r="1"/>
          <circle cx="20" cy="21" r="1"/>
          <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
        </svg>
      </div>
      <div class="wi-choice-body">
        <div class="wi-choice-title">Ring up sale</div>
        <div class="wi-choice-sub">Retail purchase, with or without a customer</div>
      </div>
      <div class="wi-choice-arrow">›</div>
    </div>

    <div class="wi-choice" data-action="new-customer">
      <div class="wi-choice-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
          <circle cx="9" cy="7" r="4"/>
          <line x1="19" y1="8" x2="19" y2="14"/>
          <line x1="22" y1="11" x2="16" y2="11"/>
        </svg>
      </div>
      <div class="wi-choice-body">
        <div class="wi-choice-title">Add new customer</div>
        <div class="wi-choice-sub">Capture name + phone, no booking yet</div>
      </div>
      <div class="wi-choice-arrow">›</div>
    </div>

    <div class="wi-search" style="padding-top:20px">
      <input
        type="search"
        id="wiSearch"
        placeholder="Search customers…"
        autocomplete="off"
        spellcheck="false">
      <div class="wi-search-results" id="wiSearchResults"></div>
    </div>"""

if 'data-action="book-intent"' in s:
    print("    SKIP start-html — verb-first tiles already in place")
elif old_start not in s:
    raise SystemExit("ABORT start-html: anchor not found")
elif s.count(old_start) != 1:
    raise SystemExit(f"ABORT start-html: anchor count = {s.count(old_start)}")
else:
    s = s.replace(old_start, new_start, 1)
    print("    UPDATED start screen — verb-first tiles + search moved below")

p.write_text(s)
PYEOF

# ─── 2. Add customer-pick step + lime-tint primary tile CSS ─────────────
python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/walkin/index.blade.php")
s = p.read_text()

# Add primary-tile CSS after the existing .wi-choice block.
# Find an anchor that's after the wi-choice rules.
anchor_css_primary = """  .wi-choice:active { transform: scale(.99); }
  .wi-choice:hover { border-color: var(--ia-border-2, rgba(255,255,255,.14)); }"""

new_css_primary = """  .wi-choice:active { transform: scale(.99); }
  .wi-choice:hover { border-color: var(--ia-border-2, rgba(255,255,255,.14)); }
  .wi-choice-primary {
    background: rgba(190,242,100,.10);
    border-color: rgba(190,242,100,.28);
  }
  .wi-choice-primary .wi-choice-icon {
    background: rgba(190,242,100,.18);
    color: var(--ia-accent, #BEF264);
  }"""

if ".wi-choice-primary" in s:
    print("    SKIP css-primary — primary-tile styles already present")
elif anchor_css_primary not in s:
    raise SystemExit("ABORT css-primary: anchor not found")
elif s.count(anchor_css_primary) != 1:
    raise SystemExit(f"ABORT css-primary: anchor count = {s.count(anchor_css_primary)}")
else:
    s = s.replace(anchor_css_primary, new_css_primary, 1)
    print("    UPDATED — primary-tile CSS added")

p.write_text(s)
PYEOF

# ─── 3. Insert the new customer-pick section after the start section ────
python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/walkin/index.blade.php")
s = p.read_text()

# Insert the new section after the start section's closing </section> and
# before the new-customer step. The start section ends with the recent
# customers @foreach @endforeach + @endif + </section>. We anchor on the
# section-opening of the new-customer step.
anchor_before_newcust = '''  {{-- ======================== STEP 2: NEW CUSTOMER ======================== --}}
  <section class="wi-step" data-step="new-customer">'''

new_picker_section = '''  {{-- ============ STEP 1b: CUSTOMER-PICK (after action tile, before customer chosen) ============ --}}
  <section class="wi-step" data-step="customer-pick">
    <div class="wi-hero">
      <h2 id="wiPickHeading">Pick a customer</h2>
      <p class="wi-hero-sub" id="wiPickSub">—</p>
    </div>

    <div class="wi-search">
      <input
        type="search"
        id="wiPickSearch"
        placeholder="Search customers…"
        autocomplete="off"
        spellcheck="false">
      <div class="wi-search-results" id="wiPickSearchResults"></div>
    </div>

    {{-- Sale-only: skip-customer / anonymous option. JS hides this when intent=book. --}}
    <div class="wi-choice" id="wiPickSkipRow" data-action="skip-customer" style="display:none">
      <div class="wi-choice-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="10"/>
          <line x1="8" y1="12" x2="16" y2="12"/>
        </svg>
      </div>
      <div class="wi-choice-body">
        <div class="wi-choice-title">Skip — anonymous sale</div>
        <div class="wi-choice-sub">Ring up without attaching a customer</div>
      </div>
      <div class="wi-choice-arrow">›</div>
    </div>

    @if(count($recentCustomers))
      <div class="wi-section-label">Recent customers</div>
      @foreach($recentCustomers as $cust)
        @php
          $custData = [
              "id"    => $cust["id"],
              "name"  => $cust["name"] ?: "(no name)",
              "email" => $cust["email"],
              "phone" => $cust["phone"],
          ];
        @endphp
        <div class="wi-cust-row" data-cust-pick='{{ json_encode($custData) }}'>
          <div class="wi-cust-avatar">{{ $cust['initials'] ?: '?' }}</div>
          <div class="wi-cust-body">
            <div class="wi-cust-name">{{ $cust['name'] ?: '(no name)' }}</div>
            <div class="wi-cust-meta">{{ $cust['phone'] ?: $cust['email'] ?: 'No contact' }} · {{ $cust['updated'] }}</div>
          </div>
          <div class="wi-cust-arrow">›</div>
        </div>
      @endforeach
    @endif

    <div style="padding:20px 20px 80px;text-align:center">
      <button type="button" data-back-to="start" style="background:none;border:0;color:var(--ia-muted,#888);font-size:13px;cursor:pointer;font-family:inherit">
        ← Back
      </button>
    </div>
  </section>

'''

if 'data-step="customer-pick"' in s:
    print("    SKIP picker-section — already present")
elif anchor_before_newcust not in s:
    raise SystemExit("ABORT picker-section: new-customer anchor not found")
elif s.count(anchor_before_newcust) != 1:
    raise SystemExit(f"ABORT picker-section: anchor count = {s.count(anchor_before_newcust)}")
else:
    # Insert new section BEFORE the new-customer section.
    s = s.replace(anchor_before_newcust, new_picker_section + anchor_before_newcust, 1)
    print("    UPDATED — customer-pick section inserted")

p.write_text(s)
PYEOF

# ─── 4. JS: add intent state, new handlers, route from action tiles ─────
python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/walkin/index.blade.php")
s = p.read_text()

# Add `intent` to state. Find the state literal and extend.
old_state = """    step: 'start',
    customer: null,    // {id?, name, phone, email}
    service: null,
    time: null,        // {date, appointment_time, resource_id}
  };"""
new_state = """    step: 'start',
    intent: null,      // 'book' | 'sale' — set when a verb-first action tile is tapped
    customer: null,    // {id?, name, phone, email}
    service: null,
    time: null,        // {date, appointment_time, resource_id}
  };"""

if "intent: null" in s:
    print("    SKIP js-state — intent already in state")
elif old_state not in s:
    raise SystemExit("ABORT js-state: state anchor not found")
else:
    s = s.replace(old_state, new_state, 1)
    print("    UPDATED — intent added to state")

# Replace handleAction so it routes book-intent / sale-intent / skip-customer.
old_handle = """  function handleAction(action) {
    if (action === 'new-customer') {
      goto('new-customer');
    } else if (action === 'anon-sale') {
      window.location.href = ROUTE_REGISTER;
    } else if (action === 'book') {
      $('#wiSvcCustName').textContent = state.customer.name || '(no name)';
      goto('service');
    } else if (action === 'sale') {
      // Already-known customer: go to register with customer pre-attached
      if (state.customer.id) {
        window.location.href = `${ROUTE_REGISTER}?customer_id=${state.customer.id}`;
      } else {"""

new_handle = """  function handleAction(action) {
    if (action === 'new-customer') {
      goto('new-customer');
    } else if (action === 'anon-sale') {
      // Legacy path (kept for back-compat with any external callers).
      window.location.href = ROUTE_REGISTER;
    } else if (action === 'book-intent') {
      // Verb-first: user wants to book, but hasn't picked a customer yet.
      state.intent = 'book';
      $('#wiPickHeading').textContent = 'Pick a customer to book';
      $('#wiPickSub').textContent = 'Search or pick from recent.';
      $('#wiPickSkipRow').style.display = 'none';
      goto('customer-pick');
    } else if (action === 'sale-intent') {
      // Verb-first: user wants to ring up, customer optional.
      state.intent = 'sale';
      $('#wiPickHeading').textContent = 'Attach a customer?';
      $('#wiPickSub').textContent = 'Pick a customer or skip for an anonymous sale.';
      $('#wiPickSkipRow').style.display = '';
      goto('customer-pick');
    } else if (action === 'skip-customer') {
      // Sale intent + no customer = anonymous register.
      window.location.href = ROUTE_REGISTER;
    } else if (action === 'book') {
      $('#wiSvcCustName').textContent = state.customer.name || '(no name)';
      goto('service');
    } else if (action === 'sale') {
      // Already-known customer: go to register with customer pre-attached
      if (state.customer.id) {
        window.location.href = `${ROUTE_REGISTER}?customer_id=${state.customer.id}`;
      } else {"""

if "action === 'book-intent'" in s:
    print("    SKIP js-handler — intent actions already wired")
elif old_handle not in s:
    raise SystemExit("ABORT js-handler: handleAction anchor not found")
else:
    s = s.replace(old_handle, new_handle, 1)
    print("    UPDATED — handleAction routes intent actions")

# Add customer-pick row click handler in the global delegated listener.
# Anchor on the existing [data-cust] handler.
old_click_delegate = """  document.addEventListener('click', (e) => {
    const row = e.target.closest('[data-cust]');
    if (row) {
      try {
        state.customer = JSON.parse(row.dataset.cust);
        showChoice();
      } catch (err) { console.error(err); }
      return;
    }"""

new_click_delegate = """  document.addEventListener('click', (e) => {
    // Customer-pick rows: customer pre-selected with a known intent, so skip
    // the choice screen and route to the action directly.
    const pickRow = e.target.closest('[data-cust-pick]');
    if (pickRow) {
      try {
        state.customer = JSON.parse(pickRow.dataset.custPick);
        if (state.intent === 'book') {
          $('#wiSvcCustName').textContent = state.customer.name || '(no name)';
          goto('service');
        } else if (state.intent === 'sale') {
          window.location.href = state.customer.id
            ? `${ROUTE_REGISTER}?customer_id=${state.customer.id}`
            : ROUTE_REGISTER;
        } else {
          // No intent set somehow — fall back to choice screen.
          showChoice();
        }
      } catch (err) { console.error(err); }
      return;
    }
    // Start-screen customer rows: no intent set yet, show the choice screen.
    const row = e.target.closest('[data-cust]');
    if (row) {
      try {
        state.customer = JSON.parse(row.dataset.cust);
        showChoice();
      } catch (err) { console.error(err); }
      return;
    }"""

if "[data-cust-pick]" in s:
    print("    SKIP js-click-delegate — pick-row handler already present")
elif old_click_delegate not in s:
    raise SystemExit("ABORT js-click-delegate: anchor not found")
else:
    s = s.replace(old_click_delegate, new_click_delegate, 1)
    print("    UPDATED — pick-row handler wired into delegated click")

# Wire the customer-pick search box. Mirrors the start-screen search but
# renders into the picker results container and uses [data-cust-pick] rows.
# Append after the existing search wiring; find an anchor near the bottom
# of the script.
old_search_init = """  // ─── Customer selection ───────────────────────────────────────────"""

new_search_init = """  // ─── Customer-pick search (for verb-first book/sale flows) ────────
  (function wirePickSearch() {
    const input = $('#wiPickSearch');
    if (!input) return;
    let timer;
    input.addEventListener('input', () => {
      clearTimeout(timer);
      const q = input.value.trim();
      if (q.length < 2) {
        $('#wiPickSearchResults').innerHTML = '';
        return;
      }
      timer = setTimeout(() => doPickSearch(q), 200);
    });
  })();

  async function doPickSearch(q) {
    try {
      const res = await fetch(`${ROUTE_SEARCH}?q=${encodeURIComponent(q)}`, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      });
      const json = await res.json();
      const out = $('#wiPickSearchResults');
      if (!json.customers || json.customers.length === 0) {
        out.innerHTML = `<div class="wi-empty">No matches.</div>`;
        return;
      }
      out.innerHTML = json.customers.map(c => {
        const initials = (
          (c.first_name || '').charAt(0) +
          (c.last_name || '').charAt(0)
        ).toUpperCase() || '?';
        const meta = c.phone || c.email || '';
        const cust = JSON.stringify({
          id: c.id,
          name: (c.label || `${c.first_name || ''} ${c.last_name || ''}`).trim() || '(no name)',
          phone: c.phone,
          email: c.email,
        });
        return `
          <div class="wi-cust-row" data-cust-pick='${cust.replace(/'/g, "&#39;")}'>
            <div class="wi-cust-avatar">${initials}</div>
            <div class="wi-cust-body">
              <div class="wi-cust-name">${escapeHtml(c.label || '(no name)')}</div>
              <div class="wi-cust-meta">${escapeHtml(meta)}</div>
            </div>
            <div class="wi-cust-arrow">›</div>
          </div>
        `;
      }).join('');
    } catch (err) {
      console.error('pick-search failed', err);
    }
  }

  // ─── Customer selection ───────────────────────────────────────────"""

if "doPickSearch" in s:
    print("    SKIP js-pick-search — already wired")
elif old_search_init not in s:
    raise SystemExit("ABORT js-pick-search: anchor not found")
else:
    s = s.replace(old_search_init, new_search_init, 1)
    print("    UPDATED — picker-search wired")

p.write_text(s)
PYEOF

cat <<EONOTE

==> Patch 55 applied locally.

Deploy:
  git add resources/views/tenant/walkin/index.blade.php patch-55-walkin-verb-first-start.sh
  git commit -m "feat: walk-in verb-first start screen (patch 55)"
  git push

On server:
  cd /var/www/intake
  git pull
  php artisan view:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

Verify on phone:
  1. Tap FAB → start screen now shows 3 action tiles:
       [Book appointment]  (lime-tinted, primary)
       [Ring up sale]
       [Add new customer]
     ...then search bar, then Recent customers list below.
  2. Tap "Book appointment" → customer-pick screen shows.
     - Heading: "Pick a customer to book"
     - No "skip" option visible
     - Tap a recent customer → goes directly to service picker (skips choice).
  3. Back → tap "Ring up sale" → customer-pick screen shows.
     - Heading: "Attach a customer?"
     - "Skip — anonymous sale" option visible above recent customers
     - Tap Skip → /admin/register (anonymous)
     - Or tap a recent customer → /admin/register?customer_id=X
  4. Tap "Add new customer" → existing new-customer step (no change).
  5. From the start screen directly: tap a recent customer → existing
     choice screen ("Book / Quick sale"). Preserves the path where you've
     identified the customer first.
EONOTE
