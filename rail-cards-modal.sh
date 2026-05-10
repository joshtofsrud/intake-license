#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Layout B — move Resource + Capacity to rail; customer details modal.
#
# Changes:
#   1. Move Resource card from main column → rail (after Customer card).
#   2. Move Capacity card from main column → rail (after Resource).
#      Capacity's override UI wrapped in <details> for collapsible behavior.
#   3. Customer card in rail gets new "View details" button beside profile link.
#      Existing "View customer profile" link kept.
#   4. Customer details (intake responses) section in main column relocates
#      to a modal that opens when "View details" is clicked.
#   5. Bonus: fix `you'''ll` triple-apostrophe typo in Resource card hint.
#
# Per Day-18 lessons: every patch verifies s.count(old)==1 before write.
# JS hooks (data-appt-resource-card, data-appt-slot-weight-card) preserved —
# JS queries by document.querySelector, finds them wherever they live.
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: must run from intake-license repo root (no artisan in $(pwd))"; exit 1; }

echo "=== rail-cards + customer-modal patch starting ==="

# ─────────────────────────────────────────────────────────────────────────────
# Step 0 (bonus): fix `you'''ll` typo. Pre-existing Blade-quoting bug.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()
old = "If the new resource is busy at this time, you'''ll get a warning before the change is saved."
new = "If the new resource is busy at this time, you'll get a warning before the change is saved."
n = s.count(old)
if n == 0:
    print("SKIP 0 (typo already fixed)")
else:
    assert n == 1, f"typo count={n}, expected 1"
    p.write_text(s.replace(old, new))
    print("OK 0 (you'''ll typo fixed)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# Step 1: Extract Resource card markup, store it in a temp file, delete from main.
# We do this with Python so we can grab the exact Blade block including the
# leading comment and the trailing `</div>` / blank line.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()

# The Resource card block — from the descriptive comment to the closing </div>.
old = '''    {{-- Resource — change which staff member or station owns this appointment.
         Soft-warns on conflicts with an override path. Auto-notes on change. --}}
    {{-- LAYOUT-B-PROMOTE-ORDER 20 --}}
    <div class="ia-card ia-card--tight" style="order:20" data-appt-resource-card data-appt-id="{{ $appointment->id }}">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;font-weight:500;opacity:.4;margin-bottom:12px">
        Resource
      </div>

      @php
        $currentResourceId = $appointment->resource_id;
        $currentResource   = $availableResources->firstWhere('id', $currentResourceId);
      @endphp

      <div class="sidebar-stat" style="border-bottom:none;padding-bottom:4px">
        <span class="sidebar-stat-label">Currently assigned</span>
        <span class="sidebar-stat-value" style="display:flex;align-items:center;gap:6px">
          @if($currentResource)
            <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:{{ $currentResource->color_hex ?: '#888' }}"></span>
            {{ $currentResource->name }}
          @else
            <span style="opacity:.5">Unassigned</span>
          @endif
        </span>
      </div>

      <label class="ia-form-label" style="margin-top:12px">Change to</label>
      <select class="ia-input" data-appt-resource-select style="margin-bottom:8px">
        @foreach($availableResources as $r)
          <option value="{{ $r->id }}" @selected($r->id === $currentResourceId)>
            {{ $r->name }}@if($r->subtitle) · {{ $r->subtitle }}@endif
          </option>
        @endforeach
      </select>
      <button type="button"
              class="ia-btn ia-btn--ghost"
              data-appt-resource-save
              style="width:100%">Save resource</button>
      <p style="font-size:11px;opacity:.4;margin-top:8px;line-height:1.4">
        If the new resource is busy at this time, you'll get a warning before the change is saved.
      </p>
    </div>'''

assert s.count(old) == 1, f"resource-extract count={s.count(old)}, expected 1"

# Remove the order:20 inline style since it's going to a flex rail without ordering needs.
new_resource = old.replace('style="order:20" ', '')

# Save it to a temp file so the next step can re-insert it into the rail.
Path('/tmp/intake-resource-block.txt').write_text(new_resource)
# Remove from main column (replace with empty string, with a marker for traceability).
s = s.replace(old, '    {{-- LAYOUT-B-RAIL-MOVE v1: Resource card moved to rail --}}')
p.write_text(s)
print("OK 1 (Resource extracted from main)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# Step 2: Extract Capacity card from main, wrap its override UI in <details>.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()

old = '''    {{-- Slot weight · LAYOUT-B-PROMOTE-ORDER 30 --}}
    <div class="ia-card ia-card--tight" style="order:30">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;font-weight:500;opacity:.4;margin-bottom:12px">
        Capacity slots
      </div>
      <div class="sidebar-stat">
        <span class="sidebar-stat-label">Auto-calculated</span>
        <span class="sidebar-stat-value">{{ $appointment->slot_weight_auto ?? 1 }}</span>
      </div>
      @if($appointment->slot_weight_overridden)
      <div class="sidebar-stat">
        <span class="sidebar-stat-label" style="color:#EF9F27">Overridden by staff</span>
        <span class="sidebar-stat-value" style="color:#EF9F27">{{ $appointment->slot_weight }}</span>
      </div>
      @endif
      <div data-appt-slot-weight-card style="margin-top:12px">
        <input type="hidden" data-appt-slot-weight-current value="{{ (int) ($appointment->slot_weight ?? 1) }}">
        <label class="ia-form-label">Override slot weight</label>
        <select class="ia-input" data-appt-slot-weight-select style="margin-bottom:8px">
          @foreach([1,2,3,4] as $w)
            <option value="{{ $w }}" @selected($appointment->slot_weight == $w)>
              {{ $w }} slot{{ $w > 1 ? 's' : '' }}
              @if($w == 1) — normal job
              @elseif($w == 2) — bigger job
              @elseif($w == 3) — large job
              @elseif($w == 4) — full day job
              @endif
            </option>
          @endforeach
        </select>
        <button type="button"
                class="ia-btn ia-btn--ghost"
                data-appt-slot-weight-save
                data-appt-id="{{ $appointment->id }}"
                style="width:100%">Save slot weight</button>
        <p style="font-size:11px;opacity:.4;margin-top:8px;line-height:1.4">
          Override how many capacity slots this appointment occupies.
        </p>
      </div>
    </div>'''

assert s.count(old) == 1, f"capacity-extract count={s.count(old)}, expected 1"

# Build the rail version — collapsible override section.
new_capacity = '''    {{-- Capacity slots · LAYOUT-B-RAIL v1 (collapsible override) --}}
    <div class="ia-card ia-card--tight">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;font-weight:500;opacity:.4;margin-bottom:10px">
        Capacity slots
      </div>
      <div class="sidebar-stat" style="margin-bottom:8px">
        <span class="sidebar-stat-label">Auto-calculated</span>
        <span class="sidebar-stat-value">{{ $appointment->slot_weight_auto ?? 1 }}</span>
      </div>
      @if($appointment->slot_weight_overridden)
      <div class="sidebar-stat" style="margin-bottom:4px">
        <span class="sidebar-stat-label" style="color:#EF9F27">Overridden</span>
        <span class="sidebar-stat-value" style="color:#EF9F27">{{ $appointment->slot_weight }}</span>
      </div>
      @endif
      <details class="appt-b-cap-override" style="margin-top:8px">
        <summary style="cursor:pointer;font-size:12px;color:var(--ia-accent);padding:4px 0;list-style:none">
          Override slot weight ▾
        </summary>
        <div data-appt-slot-weight-card style="margin-top:10px">
          <input type="hidden" data-appt-slot-weight-current value="{{ (int) ($appointment->slot_weight ?? 1) }}">
          <select class="ia-input" data-appt-slot-weight-select style="margin-bottom:8px">
            @foreach([1,2,3,4] as $w)
              <option value="{{ $w }}" @selected($appointment->slot_weight == $w)>
                {{ $w }} slot{{ $w > 1 ? 's' : '' }}
                @if($w == 1) — normal job
                @elseif($w == 2) — bigger job
                @elseif($w == 3) — large job
                @elseif($w == 4) — full day job
                @endif
              </option>
            @endforeach
          </select>
          <button type="button"
                  class="ia-btn ia-btn--ghost"
                  data-appt-slot-weight-save
                  data-appt-id="{{ $appointment->id }}"
                  style="width:100%">Save slot weight</button>
          <p style="font-size:11px;opacity:.4;margin-top:8px;line-height:1.4">
            Override how many capacity slots this appointment occupies.
          </p>
        </div>
      </details>
    </div>'''

# Save to temp for next step.
from pathlib import Path
Path('/tmp/intake-capacity-block.txt').write_text(new_capacity)
s = s.replace(old, '    {{-- LAYOUT-B-RAIL-MOVE v1: Capacity card moved to rail --}}')
p.write_text(s)
print("OK 2 (Capacity extracted from main; rail version built)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# Step 3: Insert Resource + Capacity into the rail, after the Customer card.
# Anchor on the Customer card's closing div in the rail.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()
res_block = Path('/tmp/intake-resource-block.txt').read_text()
cap_block = Path('/tmp/intake-capacity-block.txt').read_text()

# Anchor: the closing of the rail Customer card.
# Pattern: the View customer profile link, then </div> (close ia-card), then </aside>.
old = '''      @if($appointment->customer_id)
        <a href="{{ route('tenant.customers.show', $appointment->customer_id) }}"
           class="ia-btn ia-btn--secondary ia-btn--sm" style="width:100%;justify-content:center">
          View customer profile →
        </a>
      @endif
    </div>

  </aside>'''

# Convert the rail-extracted blocks: indent by 2 extra spaces because they were
# at main-column indentation (4 spaces) and rail children are at 4 spaces too.
# Actually the indentation is already correct — both rail and main are 4-space
# children. No re-indent needed.

# But Customer card already has "View customer details" button below profile link
# (we add it in step 5 below). For now, just insert Resource + Capacity after
# the customer card closes.

new = '''      @if($appointment->customer_id)
        <a href="{{ route('tenant.customers.show', $appointment->customer_id) }}"
           class="ia-btn ia-btn--secondary ia-btn--sm" style="width:100%;justify-content:center">
          View customer profile →
        </a>
        @if($appointment->responses->isNotEmpty())
          <button type="button"
                  class="ia-btn ia-btn--ghost ia-btn--sm appt-b-cust-details-btn"
                  style="width:100%;justify-content:center;margin-top:6px">
            View customer details →
          </button>
        @endif
      @endif
    </div>

''' + res_block + '''

''' + cap_block + '''

  </aside>'''

assert s.count(old) == 1, f"rail-insert count={s.count(old)}, expected 1"
p.write_text(s.replace(old, new))
print("OK 3 (Resource + Capacity inserted in rail; details button added)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# Step 4: Replace the in-main-column Customer details section with hidden modal markup.
# The intake responses now render INSIDE the modal, opened by the rail button.
# Modal sits at the page level (after .appt-b-shell closes).
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()

# Remove the in-main-column Customer details section.
old1 = '''    {{-- Form responses --}}
    @if($appointment->responses->isNotEmpty())
    {{-- LAYOUT-B-PROMOTE-ORDER 60 --}}
    <div class="ia-card" style="order:60">
      <div class="appt-section-label">Customer details</div>
      @foreach($appointment->responses as $r)
        <div class="appt-response">
          <div class="appt-response-label">{{ $r->field_label_snapshot }}</div>
          <div class="appt-response-value">{{ $r->response_value ?: '—' }}</div>
        </div>
      @endforeach
    </div>
    @endif'''
new1 = '''    {{-- LAYOUT-B-CUSTDETAIL-MOVED v1: Customer details now render in the modal at end of page --}}'''
assert s.count(old1) == 1, f"main-detail-remove count={s.count(old1)}, expected 1"
s = s.replace(old1, new1)

# Inject the modal markup right before @endsection.
# Anchor: the close of .appt-b-shell + endsection.
old2 = '''</div>{{-- /.appt-b-shell --}}

@endsection'''
new2 = '''</div>{{-- /.appt-b-shell --}}

{{-- LAYOUT-B-CUSTDETAIL-MODAL v1 --}}
@if($appointment->responses->isNotEmpty())
<div class="appt-b-cust-modal" id="appt-b-cust-modal" hidden role="dialog" aria-modal="true" aria-labelledby="appt-b-cust-modal-title">
  <div class="appt-b-cust-modal-backdrop" data-cust-modal-close></div>
  <div class="appt-b-cust-modal-card">
    <div class="appt-b-cust-modal-head">
      <h2 class="appt-b-cust-modal-title" id="appt-b-cust-modal-title">Customer details</h2>
      <button type="button" class="appt-b-cust-modal-close" data-cust-modal-close aria-label="Close">×</button>
    </div>
    <div class="appt-b-cust-modal-body">
      @foreach($appointment->responses as $r)
        <div class="appt-response">
          <div class="appt-response-label">{{ $r->field_label_snapshot }}</div>
          <div class="appt-response-value">{{ $r->response_value ?: '—' }}</div>
        </div>
      @endforeach
    </div>
  </div>
</div>
@endif

@endsection'''
assert s.count(old2) == 1, f"modal-inject count={s.count(old2)}, expected 1"
p.write_text(s.replace(old2, new2))
print("OK 4 (modal markup; main details section relocated)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# Step 5: Add modal CSS and the JS to open/close it.
# CSS appended before </style>; JS appended before @endpush.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()
marker = '/* LAYOUT-B-CUST-MODAL-CSS v1 */'
if marker in s:
    print("SKIP 5a (CSS marker already present)")
else:
    closer = '</style>'
    assert s.count(closer) == 1, f"</style> count={s.count(closer)}, expected 1"
    css = '''
/* LAYOUT-B-CUST-MODAL-CSS v1 */
.appt-b-cust-modal {
  position: fixed; inset: 0; z-index: 1000;
  display: flex; align-items: center; justify-content: center;
  padding: 20px;
}
.appt-b-cust-modal[hidden] { display: none; }
.appt-b-cust-modal-backdrop {
  position: absolute; inset: 0;
  background: rgba(0,0,0,.55);
  backdrop-filter: blur(4px);
}
.appt-b-cust-modal-card {
  position: relative;
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-lg, 12px);
  width: 100%; max-width: 560px;
  max-height: 80vh;
  display: flex; flex-direction: column;
  box-shadow: 0 20px 60px rgba(0,0,0,.4);
}
.appt-b-cust-modal-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px 20px;
  border-bottom: 0.5px solid var(--ia-border);
}
.appt-b-cust-modal-title {
  margin: 0;
  font-size: 15px; font-weight: 500; letter-spacing: -.01em;
}
.appt-b-cust-modal-close {
  background: none; border: none; color: inherit;
  font-size: 22px; line-height: 1; cursor: pointer;
  padding: 4px 8px; border-radius: 4px;
  opacity: .6;
}
.appt-b-cust-modal-close:hover { opacity: 1; background: rgba(255,255,255,.06); }
.appt-b-cust-modal-body {
  padding: 18px 20px;
  overflow-y: auto;
}

/* Capacity collapsible — hide default disclosure triangle */
.appt-b-cap-override summary { list-style: none; }
.appt-b-cap-override summary::-webkit-details-marker { display: none; }
.appt-b-cap-override[open] summary { color: var(--ia-text-muted); }
'''
    s = s.replace(closer, css + '</style>')
    p.write_text(s)
    print("OK 5a (modal CSS appended)")
PY

python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/show.blade.php')
s = p.read_text()
marker = 'LAYOUT-B-CUST-MODAL-JS v1'
if marker in s:
    print("SKIP 5b (JS marker already present)")
else:
    old = '@endpush'
    addition = '''
<script>
// LAYOUT-B-CUST-MODAL-JS v1
document.addEventListener('DOMContentLoaded', function () {
  var modal     = document.getElementById('appt-b-cust-modal');
  var openBtn   = document.querySelector('.appt-b-cust-details-btn');
  if (!modal || !openBtn) return;

  function open()  { modal.hidden = false;  document.body.style.overflow = 'hidden'; }
  function close() { modal.hidden = true;   document.body.style.overflow = ''; }

  openBtn.addEventListener('click', open);
  modal.querySelectorAll('[data-cust-modal-close]').forEach(function (el) {
    el.addEventListener('click', close);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !modal.hidden) close();
  });
});
</script>
'''
    # There may be multiple @endpush — replace only the LAST one.
    if s.count(old) == 1:
        s = s.replace(old, addition + old)
    else:
        idx = s.rfind(old)
        s = s[:idx] + addition + s[idx:]
    p.write_text(s)
    print("OK 5b (modal JS injected)")
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

verify       "resources/views/tenant/appointments/show.blade.php" "LAYOUT-B-RAIL-MOVE v1: Resource"  "Resource moved comment"
verify       "resources/views/tenant/appointments/show.blade.php" "LAYOUT-B-RAIL-MOVE v1: Capacity"  "Capacity moved comment"
verify       "resources/views/tenant/appointments/show.blade.php" "LAYOUT-B-CUSTDETAIL-MOVED v1"     "main details relocated"
verify       "resources/views/tenant/appointments/show.blade.php" "LAYOUT-B-CUSTDETAIL-MODAL v1"     "modal markup"
verify       "resources/views/tenant/appointments/show.blade.php" "LAYOUT-B-CUST-MODAL-CSS v1"       "modal CSS"
verify       "resources/views/tenant/appointments/show.blade.php" "LAYOUT-B-CUST-MODAL-JS v1"        "modal JS"
verify       "resources/views/tenant/appointments/show.blade.php" "appt-b-cust-details-btn"          "details button class"
verify       "resources/views/tenant/appointments/show.blade.php" "appt-b-cap-override"              "collapsible details el"

# Critical: only ONE data-appt-resource-card in DOM (JS uses querySelector single).
verify_count "resources/views/tenant/appointments/show.blade.php" "data-appt-resource-card" 1 "single resource card"
verify_count "resources/views/tenant/appointments/show.blade.php" "data-appt-slot-weight-card" 1 "single slot-weight card"

# <div balance must be preserved.
python3 <<'PY'
src = open('resources/views/tenant/appointments/show.blade.php').read()
opens = src.count('<div')
closes = src.count('</div')
import sys
if opens == closes:
    print(f'  ✓ <div balance: {opens}/{closes}')
else:
    print(f'  ✗ <div MISMATCH: {opens}/{closes}')
    sys.exit(1)
PY

if [ "$fail" -ne 0 ]; then
  echo ""
  echo "✗ FAIL — STOP, do not commit"
  exit 1
fi

echo ""
echo "✓ all verification markers green"
echo ""
echo "Next:"
echo "  git add -A && git commit -m 'layout B: Resource+Capacity to rail; customer details modal'"
echo "  git push"
echo "  Server: git pull && php artisan view:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "=== rail-cards + customer-modal complete ==="
