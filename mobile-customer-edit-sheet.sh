#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Mobile customer edit — bottom-sheet form.
#
# Bug: the mobile edit pencil triggers a click on #edit-toggle which lives
# inside .cust-desktop-only. The desktop wrapper has display: none on phones,
# so even though the click fires and #info-edit gets shown, it's still inside
# a display:none parent — invisible to the user.
#
# Fix: build a dedicated mobile bottom-sheet edit form that posts to the same
# PATCH endpoint with op=update_info. Matches the bottom-sheet pattern used
# elsewhere (sort sheet, reschedule modal). The desktop edit form stays
# unchanged.
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: not in intake-license repo root"; exit 1; }

echo "=== mobile customer edit sheet starting ==="

# ─────────────────────────────────────────────────────────────────────────────
# 1. Rewire the mobile edit button to open the sheet instead of clicking
#    the hidden desktop button.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/customers/show.blade.php')
s = p.read_text()
old = '''<button type="button" class="cmd-edit-btn" onclick="document.getElementById('edit-toggle').click()" aria-label="Edit customer info">'''
new = '''<button type="button" class="cmd-edit-btn" onclick="CustEditSheet.open()" aria-label="Edit customer info">'''
assert s.count(old) == 1, f"anchor count = {s.count(old)}"
s = s.replace(old, new)
p.write_text(s)
print("OK 1 (button onclick rewired)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# 2. Inject the bottom-sheet markup right after the closing </div> of
#    .cust-mobile (so it's a sibling, not nested, and renders as a fixed
#    overlay on top of everything).
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/customers/show.blade.php')
s = p.read_text()
marker = "CUST-EDIT-SHEET v1"
if marker in s:
    print("SKIP 2 (sheet already present)")
else:
    # Anchor: the closing </div> of the mobile wrapper, right before the
    # second-pass comment for the desktop layout marker.
    old = """</div>

{{-- ============================================================
     CUSTOMER-DETAIL-MOBILE-REBUILD v1 — parallel mobile render below."""
    new = """</div>

{{-- CUST-EDIT-SHEET v1 — mobile-only bottom sheet for editing customer info.
     Posts to the same PATCH endpoint as the desktop form (op=update_info).
     Hidden on desktop via CSS @media (min-width: 601px). --}}
<div class="cust-edit-backdrop" id="cust-edit-backdrop" onclick="CustEditSheet.close()" aria-hidden="true"></div>
<div class="cust-edit-sheet" id="cust-edit-sheet" role="dialog" aria-modal="true" aria-label="Edit customer" aria-hidden="true">
  <div class="cust-edit-handle" aria-hidden="true"></div>
  <div class="cust-edit-header">
    <span class="cust-edit-title">Edit customer</span>
    <button type="button" class="cust-edit-close" onclick="CustEditSheet.close()" aria-label="Close">
      <svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true">
        <path d="M4 4l10 10M14 4L4 14" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
      </svg>
    </button>
  </div>

  <form method="POST" action="{{ $updateUrl }}" id="cust-edit-form" class="cust-edit-body">
    @csrf @method('PATCH')
    <input type="hidden" name="op" value="update_info">

    <div class="cust-edit-field">
      <label class="cust-edit-label">First name <span style="color:#F47373">*</span></label>
      <input type="text" name="first_name" class="cust-edit-input" required value="{{ $customer->first_name }}">
    </div>
    <div class="cust-edit-field">
      <label class="cust-edit-label">Last name <span style="color:#F47373">*</span></label>
      <input type="text" name="last_name" class="cust-edit-input" required value="{{ $customer->last_name }}">
    </div>
    <div class="cust-edit-field">
      <label class="cust-edit-label">Email</label>
      <input type="email" name="email" class="cust-edit-input" value="{{ $customer->email }}" inputmode="email" autocapitalize="none" autocorrect="off">
    </div>
    <div class="cust-edit-field">
      <label class="cust-edit-label">Phone</label>
      <input type="tel" name="phone" class="cust-edit-input" value="{{ $customer->phone }}" inputmode="tel">
    </div>
    <div class="cust-edit-field">
      <label class="cust-edit-label">Street address</label>
      <input type="text" name="address_line1" class="cust-edit-input" value="{{ $customer->address_line1 }}">
    </div>
    <div class="cust-edit-field">
      <label class="cust-edit-label">City</label>
      <input type="text" name="city" class="cust-edit-input" value="{{ $customer->city }}">
    </div>
    <div class="cust-edit-row-2">
      <div class="cust-edit-field">
        <label class="cust-edit-label">State</label>
        <input type="text" name="state" class="cust-edit-input" value="{{ $customer->state }}">
      </div>
      <div class="cust-edit-field">
        <label class="cust-edit-label">ZIP</label>
        <input type="text" name="postcode" class="cust-edit-input" value="{{ $customer->postcode }}" inputmode="numeric">
      </div>
    </div>

    <div class="cust-edit-actions">
      <button type="button" class="cust-edit-btn-cancel" onclick="CustEditSheet.close()">Cancel</button>
      <button type="submit" class="cust-edit-btn-save">Save</button>
    </div>
    <p id="cust-edit-error" class="cust-edit-error" style="display:none"></p>
  </form>
</div>

{{-- ============================================================
     CUSTOMER-DETAIL-MOBILE-REBUILD v1 — parallel mobile render below."""
    assert s.count(old) == 1
    s = s.replace(old, new)
    p.write_text(s)
    print("OK 2 (sheet markup injected)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# 3. Append CSS + JS to the existing style/script blocks.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/customers/show.blade.php')
s = p.read_text()
marker = "/* CUST-EDIT-SHEET-CSS v1 */"
if marker in s:
    print("SKIP 3 (CSS already present)")
else:
    css = '''

/* CUST-EDIT-SHEET-CSS v1 */
.cust-edit-backdrop {
  position: fixed; inset: 0;
  background: rgba(0,0,0,.5);
  z-index: 200;
  opacity: 0;
  pointer-events: none;
  transition: opacity 180ms ease;
}
.cust-edit-backdrop.is-open { opacity: 1; pointer-events: auto; }

.cust-edit-sheet {
  position: fixed;
  left: 0; right: 0; bottom: 0;
  background: var(--ia-surface);
  border-radius: 18px 18px 0 0;
  z-index: 201;
  border: 0.5px solid var(--ia-border);
  border-bottom: 0;
  transform: translateY(100%);
  transition: transform 220ms cubic-bezier(.2, .8, .2, 1);
  max-height: 90vh;
  display: flex;
  flex-direction: column;
}
.cust-edit-sheet.is-open { transform: translateY(0); }

.cust-edit-handle {
  width: 36px; height: 4px;
  background: rgba(255,255,255,.18);
  border-radius: 2px;
  margin: 12px auto 8px;
  flex-shrink: 0;
}
body.ia-theme-b .cust-edit-handle { background: rgba(0,0,0,.18); }

.cust-edit-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 4px 20px 14px;
  border-bottom: 0.5px solid var(--ia-border);
  flex-shrink: 0;
}
.cust-edit-title {
  font-size: 16px;
  font-weight: 600;
  color: var(--ia-text);
}
.cust-edit-close {
  background: transparent;
  border: none;
  color: var(--ia-text-muted);
  width: 32px; height: 32px;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer;
  -webkit-tap-highlight-color: transparent;
}

.cust-edit-body {
  padding: 16px 20px calc(20px + env(safe-area-inset-bottom, 0px));
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
  flex: 1;
}

.cust-edit-field {
  margin-bottom: 14px;
}
.cust-edit-label {
  display: block;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .07em;
  color: var(--ia-text-muted);
  font-weight: 500;
  margin-bottom: 5px;
}
.cust-edit-input {
  width: 100%;
  background: var(--ia-input-bg, var(--ia-surface-2));
  border: 0.5px solid var(--ia-border);
  border-radius: 8px;
  padding: 10px 12px;
  color: var(--ia-text);
  font-size: 15px;
  font-family: inherit;
  -webkit-appearance: none;
  appearance: none;
}
.cust-edit-input:focus {
  outline: none;
  border-color: var(--ia-accent);
}

.cust-edit-row-2 {
  display: grid;
  grid-template-columns: 1.4fr 1fr;
  gap: 10px;
  margin-bottom: 14px;
}
.cust-edit-row-2 .cust-edit-field {
  margin-bottom: 0;
}

.cust-edit-actions {
  display: grid;
  grid-template-columns: 1fr 2fr;
  gap: 8px;
  margin-top: 8px;
  padding-top: 16px;
  border-top: 0.5px solid var(--ia-border);
}
.cust-edit-btn-cancel {
  background: transparent;
  border: 0.5px solid var(--ia-border);
  border-radius: 8px;
  padding: 12px;
  color: var(--ia-text);
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
  font-family: inherit;
  -webkit-tap-highlight-color: transparent;
}
.cust-edit-btn-save {
  background: var(--ia-accent);
  color: var(--ia-bg, #0a0a0a);
  border: none;
  border-radius: 8px;
  padding: 12px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  font-family: inherit;
  -webkit-tap-highlight-color: transparent;
}
.cust-edit-btn-save:disabled {
  opacity: .5;
  cursor: wait;
}
.cust-edit-error {
  margin-top: 10px;
  padding: 8px 12px;
  background: rgba(244,115,115,.10);
  border: 0.5px solid rgba(244,115,115,.30);
  border-radius: 8px;
  color: #F47373;
  font-size: 13px;
}

/* Hide the edit sheet entirely on desktop — unreachable. */
@media (min-width: 601px) {
  .cust-edit-sheet,
  .cust-edit-backdrop { display: none !important; }
}
'''
    # Append before the final </style>@endpush
    old_close = '</style>\n@endpush'
    n = s.count(old_close)
    if n != 1:
        # Use the last occurrence
        idx = s.rfind(old_close)
        s = s[:idx] + css + s[idx:]
    else:
        s = s.replace(old_close, css + old_close)
    p.write_text(s)
    print("OK 3 (CSS appended)")
PY

# JS — append CustEditSheet handlers before the final @endsection or @endpush
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/customers/show.blade.php')
s = p.read_text()
if "CUST-EDIT-SHEET-JS v1" in s:
    print("SKIP 4 (JS already present)")
else:
    js = '''

@push('scripts')
<script>
// CUST-EDIT-SHEET-JS v1 — mobile bottom-sheet edit form.
(function () {
  window.CustEditSheet = {
    open: function () {
      var b = document.getElementById('cust-edit-backdrop');
      var s = document.getElementById('cust-edit-sheet');
      if (!b || !s) return;
      b.classList.add('is-open');
      s.classList.add('is-open');
      b.setAttribute('aria-hidden', 'false');
      s.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      // Focus first input after the slide-up settles
      setTimeout(function () {
        var firstInput = s.querySelector('.cust-edit-input');
        if (firstInput) firstInput.focus();
      }, 240);
    },
    close: function () {
      var b = document.getElementById('cust-edit-backdrop');
      var s = document.getElementById('cust-edit-sheet');
      if (!b || !s) return;
      b.classList.remove('is-open');
      s.classList.remove('is-open');
      b.setAttribute('aria-hidden', 'true');
      s.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      var err = document.getElementById('cust-edit-error');
      if (err) err.style.display = 'none';
    },
  };

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') CustEditSheet.close();
  });

  // Submit handler — submit via fetch, reload on success
  var form = document.getElementById('cust-edit-form');
  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var saveBtn = form.querySelector('.cust-edit-btn-save');
      var errEl = document.getElementById('cust-edit-error');
      if (errEl) errEl.style.display = 'none';
      saveBtn.disabled = true;
      saveBtn.textContent = 'Saving…';

      var fd = new FormData(form);
      fetch(form.action, {
        method: 'POST',
        body: fd,
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      })
      .then(function (r) {
        return r.json().then(function (data) {
          return { ok: r.ok && data && data.ok !== false, status: r.status, data: data };
        });
      })
      .then(function (result) {
        if (result.ok) {
          // Reload to reflect the new values across hero name, contact tiles, page-head, etc.
          window.location.reload();
        } else {
          saveBtn.disabled = false;
          saveBtn.textContent = 'Save';
          var msg = (result.data && (result.data.message || (result.data.errors && Object.values(result.data.errors)[0]))) || 'Could not save. Please try again.';
          if (Array.isArray(msg)) msg = msg[0];
          if (errEl) {
            errEl.textContent = msg;
            errEl.style.display = 'block';
          }
        }
      })
      .catch(function () {
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save';
        if (errEl) {
          errEl.textContent = 'Network error. Please try again.';
          errEl.style.display = 'block';
        }
      });
    });
  }
})();
</script>
@endpush
'''
    # Append before the final @endsection
    old = "@endsection"
    if old not in s:
        print("ABORT (no @endsection)")
        raise SystemExit(1)
    idx = s.rfind(old)
    s = s[:idx] + js + "\n" + s[idx:]
    p.write_text(s)
    print("OK 4 (JS appended)")
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

verify "resources/views/tenant/customers/show.blade.php" "CustEditSheet.open"              "button rewired"
verify "resources/views/tenant/customers/show.blade.php" "CUST-EDIT-SHEET v1"              "sheet markup"
verify "resources/views/tenant/customers/show.blade.php" "cust-edit-input"                 "input class present"
verify "resources/views/tenant/customers/show.blade.php" "/* CUST-EDIT-SHEET-CSS v1 */"    "CSS marker"
verify "resources/views/tenant/customers/show.blade.php" "CUST-EDIT-SHEET-JS v1"           "JS marker"
verify "resources/views/tenant/customers/show.blade.php" "name=\"first_name\""             "first_name field"
verify "resources/views/tenant/customers/show.blade.php" "name=\"postcode\""               "postcode field"

# Blade balance
python3 <<'PY'
import sys
src = open('resources/views/tenant/customers/show.blade.php').read()
checks = [('@if','@endif'), ('@foreach','@endforeach'), ('@php','@endphp'), ('@push','@endpush'), ('@forelse','@endforelse')]
ok = True
for o, c in checks:
    no, nc = src.count(o), src.count(c)
    if no != nc:
        print(f'  ✗ {o}({no}) != {c}({nc})')
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
echo "Deploy:"
echo "  git add -A && git commit -m 'fix: mobile customer edit — dedicated bottom-sheet form (was inside hidden desktop wrapper)'"
echo "  git push"
echo "  Server: git pull && php artisan view:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "=== mobile edit sheet complete ==="
