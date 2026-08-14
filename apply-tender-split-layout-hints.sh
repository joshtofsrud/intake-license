#!/bin/bash
# apply-tender-split-layout-hints.sh
#
# MARKER-TENDERUX — two register-modal changes from Josh's Aug 13 testing.
# Requires MARKER-TENDERFIX (uses its #tenderModalErr element).
#
#  1. APPLIED PAYMENTS MOVE BELOW THE TENDER GRID. They were stacking above
#     it, so every leg added pushed the whole grid — and the Cancel/Confirm
#     row — further down. On a touchscreen that means the button under the
#     cashier's finger moves between taps, which is how someone hits Check
#     when they meant Card. The grid now stays put and the list grows
#     downward, with Remaining sitting directly above the button that
#     consumes it.
#
#  2. DISABLED TENDERS EXPLAIN THEMSELVES. "Send payment link" and "No
#     tender (already paid)" grey out mid-split for a real reason: neither
#     can zero out a remainder at the register (a link is paid later, after
#     the customer has left; "already paid" asserts the money arrived
#     somewhere else entirely). Nothing said so.
#
#     Note pointer-events:none had to go — it suppresses the native title
#     tooltip AND swallows the tap, so on a touchscreen (no hover at all)
#     there was no way to discover the reason. The click is now guarded in
#     JS instead, and pressing a greyed tender states the reason in the
#     modal rather than doing nothing.
set -e

MARKER="MARKER-TENDERUX"
IDX="resources/views/tenant/register/index.blade.php"

[ -f "$IDX" ] || { echo "ERROR: missing $IDX — run from the repo root"; exit 1; }
grep -q "MARKER-TENDERFIX" "$IDX" || { echo "ERROR: requires apply-tender-modal-state-and-labels.sh"; exit 1; }
if grep -q "$MARKER" "$IDX" 2>/dev/null; then
  echo "ok: already applied ($MARKER present) — no-op"
  exit 0
fi

python3 - <<'PY'
import io
p = 'resources/views/tenant/register/index.blade.php'
src = io.open(p, encoding='utf-8').read()

# ---------------------------------------------------------------
# 1. Relocate the remaining row + leg list below the tender grid
# ---------------------------------------------------------------
old_top = """    {{-- MARKER-SPLIT-TENDER — running remaining + recorded split payments --}}
    <div class="reg-split-remaining" id="splitRemainRow" style="display:none">
      <span>Remaining</span><b id="splitRemain"></b>
    </div>
    <div id="splitPayList"></div>
"""
assert src.count(old_top) == 1, 'split header block not found'
src = src.replace(old_top, "", 1)

# land it immediately before the modal error + actions (added by TENDERFIX)
anchor = """    {{-- MARKER-TENDERFIX --}}
    <div id="tenderModalErr" style="display:none;font-size:12.5px;color:#f87171;margin-bottom:10px"></div>"""
assert src.count(anchor) == 1, 'TENDERFIX error element not found'
src = src.replace(anchor, """    {{-- MARKER-SPLIT-TENDER — running remaining + recorded split payments.
         MARKER-TENDERUX — moved BELOW the tender grid: stacking legs above it
         pushed the grid and the action buttons down as payments were added,
         moving the target under the cashier's finger mid-transaction. --}}
    <div id="splitPayList"></div>
    <div class="reg-split-remaining" id="splitRemainRow" style="display:none">
      <span>Remaining</span><b id="splitRemain"></b>
    </div>

""" + anchor, 1)

# ---------------------------------------------------------------
# 2. Disabled tenders: keep them tappable so they can explain
# ---------------------------------------------------------------
old_css = "  .reg-tender-btn.split-disabled{opacity:.35;pointer-events:none}"
assert src.count(old_css) == 1, 'split-disabled css not found'
src = src.replace(old_css, """  /* MARKER-TENDERUX — no pointer-events:none: it kills the title tooltip and
     swallows the tap, leaving a touchscreen user no way to learn why. */
  .reg-tender-btn.split-disabled{opacity:.35;cursor:not-allowed}""", 1)

# reason text lives in one place, applied when the class is toggled
# MARKER-TENDERUX -- declare the reason map BEFORE renderSplit(): const is not
# hoisted, so leaving it further down the file risks a temporal-dead-zone
# ReferenceError the first time renderSplit runs.
rs = "function renderSplit() {"
assert src.count(rs) == 1, 'renderSplit not found'
src = src.replace(rs, """// MARKER-TENDERUX -- why a tender can't join a split that still owes money.
const SPLIT_BLOCK_REASON = {
  payment_link: "The customer pays this from their phone later, after they've left — it can't cover the rest of a split here. Take the remainder another way, or clear the payments above and send the link for the whole sale.",
  mark_paid:    "This records the sale as already paid elsewhere, so it can't cover a balance the register is still asking for. Clear the payments above to use it for the whole sale.",
};

""" + rs, 1)

old_toggle = """    const stage2 = t === 'payment_link' || t === 'mark_paid';
    b.classList.toggle('split-disabled', active && stage2);"""
assert src.count(old_toggle) == 1, 'split-disabled toggle not found'
src = src.replace(old_toggle, """    const stage2 = t === 'payment_link' || t === 'mark_paid';
    b.classList.toggle('split-disabled', active && stage2);
    // MARKER-TENDERUX — say why, on hover and on tap.
    if (active && stage2) {
      b.setAttribute('title', SPLIT_BLOCK_REASON[t] || '');
    } else {
      b.removeAttribute('title');
    }""", 1)

old_click = """document.querySelectorAll('#tenderModal .reg-tender-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('#tenderModal .reg-tender-btn').forEach(b => b.classList.remove('selected'));"""
assert src.count(old_click) == 1, 'tender button click handler not found'
src = src.replace(old_click, """document.querySelectorAll('#tenderModal .reg-tender-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    // MARKER-TENDERUX — the button stays clickable so a tap can explain
    // itself; a touchscreen has no hover to reveal the title.
    if (btn.classList.contains('split-disabled')) {
      tenderModalError(SPLIT_BLOCK_REASON[btn.dataset.tender] || 'That tender is not available while a split is open.');
      return;
    }
    document.querySelectorAll('#tenderModal .reg-tender-btn').forEach(b => b.classList.remove('selected'));""", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: split list relocated + disabled tenders explain themselves')
PY

echo ""
echo "== tender split layout + hints applied =="
echo "Post-deploy: php artisan optimize:clear"
