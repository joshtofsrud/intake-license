#!/bin/bash
# apply-tender-modal-state-and-labels.sh
#
# MARKER-TENDERFIX — three defects Josh found testing gift cards at the
# register on Aug 13. Redemption itself was never broken (a $48 sale did
# debit $50 -> $2); everything around it was.
#
#  1. GIFT CARD STATE SURVIVED THE SALE. The tender modal reset cleared
#     cart.payments, the reference row and the manual row, but never the
#     gift code input, the balance box or window.gcTender. A brand new
#     $525 sale opened showing "test ... $50.00" — a balance from before
#     $48 came off it. The server would still reject an over-redemption,
#     so no money could be lost, but a cashier reading a stale balance
#     tells the customer something untrue out loud. Now reset in one
#     place, called from every cart clear and both modal opens.
#
#  2. CONFIRM WAS ENABLED WHEN IT COULDN'T WORK, THEN FAILED SILENTLY.
#     Selecting any tender ran `tenderConfirmBtn.disabled = false`,
#     overriding renderSplit()'s rule that Confirm stays disabled while a
#     balance remains. Pressing it hit `if (splitRemaining() !== 0) return;`
#     — a bare return: no message, no shake, nothing. That is how a
#     $50 gift + $35 Venmo split ended up recorded as a single $85 Venmo
#     sale with the gift card never sent to the server. Now renderSplit()
#     alone owns the button's state, and no path returns silently.
#
#  3. RECEIPTS SAID "Other". methodLabel() had no case for gift_card and
#     none for manual tenders, so both printed as "Other". Manual tenders
#     are tenant-defined — any key, any name ("custom_zelle" displayed as
#     "Zelle — shop account") — so the label is resolved from
#     tenant_payment_methods.name rather than prettified from the key.
#     Not filtered on `enabled`: a retired tender must still print the
#     name it was taken under on an old receipt.
#
# Also: when a checked card can't cover the sale, the primary button now
# reads "Add gift card $50.00 — $35.00 left" and pressing it starts the
# split, instead of an error banner rendered on the page BEHIND the modal.
#
# NOT included, because I was wrong about it: I told Josh the "Send payment
# link" grey-out was a bug that only reapplied on renderSplit(). It isn't.
# renderSplit() runs on every add, every remove and on modal open, and
# reopening the modal clears the split — so the second time he looked,
# nothing was grayed because no split was active. Correct behavior.
set -e

MARKER="MARKER-TENDERFIX"
IDX="resources/views/tenant/register/index.blade.php"
PAY="app/Models/Tenant/TenantSalePayment.php"

for f in "$IDX" "$PAY"; do
  [ -f "$f" ] || { echo "ERROR: missing $f — run from the repo root"; exit 1; }
done
grep -q "MARKER-GIFTCARDS" "$IDX" || { echo "ERROR: requires the gift card patches"; exit 1; }
if grep -q "$MARKER" "$IDX" 2>/dev/null; then
  echo "ok: already applied ($MARKER present) — no-op"
  exit 0
fi

# ---------------------------------------------------------------
# 1. Payment labels
# ---------------------------------------------------------------
python3 - <<'PY'
import io
p = 'app/Models/Tenant/TenantSalePayment.php'
src = io.open(p, encoding='utf-8').read()

a = """    public function methodLabel(): string
    {
        return match ($this->method) {
            'cash'          => 'Cash',
            'card_terminal' => 'Card terminal',
            'card'          => 'Card', // MARKER-PATCH-463 — manual/recorded card refunds & payments
            'check'         => 'Check',
            'store_credit'  => 'Store credit',
            'mark_paid'     => 'Marked paid (no charge)',
            'stripe'        => 'Stripe',
            'paypal'        => 'PayPal',
            default         => 'Other',
        };
    }"""
assert src.count(a) == 1, 'methodLabel not found'

src = src.replace(a, """    public function methodLabel(): string
    {
        return match ($this->method) {
            'cash'          => 'Cash',
            'card_terminal' => 'Card terminal',
            'card'          => 'Card', // MARKER-PATCH-463 — manual/recorded card refunds & payments
            'check'         => 'Check',
            'store_credit'  => 'Store credit',
            'mark_paid'     => 'Marked paid (no charge)',
            'stripe'        => 'Stripe',
            'paypal'        => 'PayPal',
            'gift_card'     => 'Gift card',   // MARKER-TENDERFIX
            'split'         => 'Split tender', // MARKER-TENDERFIX -- only appears if legs failed to record
            default         => $this->manualMethodLabel(),
        };
    }

    /**
     * MARKER-TENDERFIX -- manual tenders are tenant-defined: any method_key,
     * any display name ('custom_zelle' shown as 'Zelle — shop account'), so
     * the name has to come from the shop's own row, not from prettifying the
     * key. Deliberately NOT filtered on `enabled` — a shop that retires a
     * tender still needs old receipts to print the name it was taken under.
     * Cached per tenant per request: a receipt renders one row per leg.
     */
    protected function manualMethodLabel(): string
    {
        $key = (string) $this->method;
        if ($key === '') {
            return 'Other';
        }

        static $cache = [];
        $tenantId = (string) $this->tenant_id;

        if (! array_key_exists($tenantId, $cache)) {
            $cache[$tenantId] = \\App\\Models\\Tenant\\TenantPaymentMethod::query()
                ->where('tenant_id', $tenantId)
                ->pluck('name', 'method_key')
                ->all();
        }

        if (! empty($cache[$tenantId][$key])) {
            return (string) $cache[$tenantId][$key];
        }

        // No row at all (deleted method, or a key from an older build):
        // a readable version of the key beats the word "Other".
        return \\Illuminate\\Support\\Str::of($key)->replace('_', ' ')->title()->toString();
    }""", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: methodLabel resolves gift cards + tenant-named manual tenders')
PY

# ---------------------------------------------------------------
# 2. Register modal: state reset, honest button, no silent returns
# ---------------------------------------------------------------
python3 - <<'PY'
import io
p = 'resources/views/tenant/register/index.blade.php'
src = io.open(p, encoding='utf-8').read()

# --- 2a. one reset helper + a modal-level error line -----------------
a = "function gcSellError(msg) {"
assert src.count(a) == 1
src = src.replace(a, """// MARKER-TENDERFIX -- clear every trace of a checked gift card. The balance
// shown in this modal is a snapshot taken at Check time; carrying it into the
// next sale shows a cashier money that may already be spent.
function resetGiftTender() {
  window.gcTender = null;
  const code = document.getElementById('gcTenderCode');
  if (code) code.value = '';
  const row = document.getElementById('gcTenderRow');
  if (row) row.style.display = 'none';
  const bal = document.getElementById('gcTenderBalance');
  if (bal) bal.style.display = 'none';
  const amt = document.getElementById('gcTenderBalanceAmt');
  if (amt) amt.textContent = '';
  const err = document.getElementById('gcTenderErr');
  if (err) { err.textContent = ''; err.style.display = 'none'; }
}

// MARKER-TENDERFIX -- errors raised while the tender modal is open must land
// INSIDE it. showError() writes to #errBanner on the page behind the dialog,
// where it is invisible to whoever is looking at the modal.
function tenderModalError(msg) {
  const el = document.getElementById('tenderModalErr');
  if (!el) { showError(msg); return; }
  el.textContent = msg;
  el.style.display = msg ? '' : 'none';
}

function gcSellError(msg) {""", 1)

b = """    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" data-close-modal="tenderModal">Cancel</button>
      <button type="button" class="reg-btn-primary" id="tenderConfirmBtn" disabled>Continue</button>
    </div>"""
assert src.count(b) == 1
src = src.replace(b, """    {{-- MARKER-TENDERFIX --}}
    <div id="tenderModalErr" style="display:none;font-size:12.5px;color:#f87171;margin-bottom:10px"></div>
    <div class="reg-modal-actions">
      <button type="button" class="reg-btn-secondary" data-close-modal="tenderModal">Cancel</button>
      <button type="button" class="reg-btn-primary" id="tenderConfirmBtn" disabled>Continue</button>
    </div>""", 1)

# --- 2b. call the reset from every cart clear + both modal opens ------
c = """  cart.po_number = null; // MARKER-BIZ-REGISTER
  (function(){ var r = document.getElementById('taxExemptRow'); if (r) r.style.display = 'none'; })();
  cart.payment_method = null; cart.payments = []; if (typeof renderSplit === 'function') renderSplit(); /* MARKER-SPLIT-TENDER */ cart.payment_reference = null;"""
assert src.count(c) == 1
src = src.replace(c, c + """
  if (typeof resetGiftTender === 'function') resetGiftTender(); // MARKER-TENDERFIX""", 1)

d = """  cart.tipCents = 0; cart.discountCents = 0;
  cart.payment_method = null; cart.payments = []; if (typeof renderSplit === 'function') renderSplit(); /* MARKER-SPLIT-TENDER */ cart.payment_reference = null;
  closeModal('receiptModal');"""
assert src.count(d) == 1
src = src.replace(d, """  cart.tipCents = 0; cart.discountCents = 0;
  cart.payment_method = null; cart.payments = []; if (typeof renderSplit === 'function') renderSplit(); /* MARKER-SPLIT-TENDER */ cart.payment_reference = null;
  if (typeof resetGiftTender === 'function') resetGiftTender(); // MARKER-TENDERFIX
  closeModal('receiptModal');""", 1)

e = """  document.getElementById('tenderConfirmBtn').disabled = true;
  document.querySelectorAll('#tenderModal .reg-tender-btn').forEach(b => b.classList.remove('selected'));
  openModal('tenderModal');"""
assert src.count(e) == 1
src = src.replace(e, """  document.getElementById('tenderConfirmBtn').disabled = true;
  document.querySelectorAll('#tenderModal .reg-tender-btn').forEach(b => b.classList.remove('selected'));
  resetGiftTender();          // MARKER-TENDERFIX -- fresh card check every sale
  tenderModalError('');
  openModal('tenderModal');""", 1)

f = """      openModal('tenderModal');"""
assert src.count(f) == 1  # the second (indented) open site
src = src.replace(f, """      resetGiftTender();      // MARKER-TENDERFIX
      tenderModalError('');
      openModal('tenderModal');""", 1)

# --- 2c. renderSplit owns the Confirm button -------------------------
g = """    document.getElementById('tenderConfirmBtn').disabled = false;
    // MARKER-PATCH-170C — reference field only meaningful for checks now."""
assert src.count(g) == 1
src = src.replace(g, """    // MARKER-TENDERFIX -- do NOT force-enable: while a split is open,
    // renderSplit() owns this button (disabled until the remainder is
    // covered). Force-enabling it produced a button that looked ready and
    // then did nothing when pressed.
    if (cart.payments.length > 0) {
      renderSplit();
    } else {
      document.getElementById('tenderConfirmBtn').disabled = false;
      document.getElementById('tenderConfirmBtn').textContent = 'Confirm';
    }
    tenderModalError('');
    // MARKER-PATCH-170C — reference field only meaningful for checks now.""", 1)

# --- 2d. gift check updates the button to say what it will do --------
h = """    window.gcTender = { code: code, balance: data.balance_cents };
    document.getElementById('gcTenderBalanceAmt').textContent = fmt(data.balance_cents);
    bal.style.display = 'flex';
    const inp = document.getElementById('splitAmountInput');
    if (inp) { inp.value = (Math.min(data.balance_cents, splitRemaining()) / 100).toFixed(2); }"""
assert src.count(h) == 1
src = src.replace(h, h + """
    // MARKER-TENDERFIX -- if the card can't cover what's left, say so on the
    // button itself and make pressing it start the split.
    gcSyncTenderButton();""", 1)

# --- 2e. the button-label helper + short-card handling ---------------
i = """document.getElementById('tenderConfirmBtn').addEventListener('click', () => {"""
assert src.count(i) == 1
src = src.replace(i, """// MARKER-TENDERFIX -- what is the sale still asking for right now?
function tenderDueCents() {
  return (calcSubtotal() - cart.discountCents + calcTax() + calcSurcharge() + cart.tipCents)
       - (calcRefundSubtotal() + calcRefundTax());
}

// MARKER-TENDERFIX -- a checked card that can't cover the remainder is not an
// error, it's a split waiting to happen. Label the button with the action.
function gcSyncTenderButton() {
  const btn = document.getElementById('tenderConfirmBtn');
  if (!btn) return;
  const due = cart.payments.length > 0 ? splitRemaining() : tenderDueCents();
  if (cart.payment_method === 'gift_card' && window.gcTender && gcTender.balance < due) {
    btn.disabled = false;
    btn.textContent = 'Add gift card ' + fmt(gcTender.balance) + ' — ' + fmt(due - gcTender.balance) + ' left';
  } else if (cart.payments.length > 0) {
    renderSplit();
  } else {
    btn.disabled = false;
    btn.textContent = 'Confirm';
  }
}

document.getElementById('tenderConfirmBtn').addEventListener('click', () => {""", 1)

# --- 2f. short card starts the split; splits never fail silently -----
j = """  if (cart.payment_method === 'gift_card' && cart.payments.length === 0) {
    if (!window.gcTender || !gcTender.code) { showError('Check the gift card balance first.'); return; }
    const gcDue = (calcSubtotal() - cart.discountCents + calcTax() + calcSurcharge() + cart.tipCents) - (calcRefundSubtotal() + calcRefundTax());
    if (gcTender.balance < gcDue) {
      showError('Gift card covers ' + fmt(gcTender.balance) + ' of ' + fmt(gcDue) + ' — add it as a split payment for the part it covers.');
      return;
    }
    cart.payment_reference = gcTender.code;
  }"""
assert src.count(j) == 1
src = src.replace(j, """  // MARKER-TENDERFIX -- gift tender. A short balance now STARTS the split
  // (the button already said it would) instead of erroring behind the modal.
  if (cart.payment_method === 'gift_card') {
    if (!window.gcTender || !gcTender.code) { tenderModalError('Check the gift card balance first.'); return; }
    const gcDue = cart.payments.length > 0 ? splitRemaining() : tenderDueCents();
    if (gcTender.balance < gcDue) {
      const amtEl = document.getElementById('splitAmountInput');
      if (amtEl) amtEl.value = (gcTender.balance / 100).toFixed(2);
      document.getElementById('splitAddBtn').click();
      tenderModalError('');
      return;
    }
    if (cart.payments.length === 0) {
      cart.payment_reference = gcTender.code;
    }
  }""", 1)

k = """  if (cart.payments.length > 0) {
    if (splitRemaining() !== 0) return;"""
assert src.count(k) == 1
src = src.replace(k, """  if (cart.payments.length > 0) {
    // MARKER-TENDERFIX -- was a bare `return`: the button did nothing and
    // never said why, so the split got abandoned and re-rung as one tender.
    if (splitRemaining() !== 0) {
      tenderModalError(fmt(splitRemaining()) + ' still to collect — add a payment for the rest, or remove a line above.');
      return;
    }""", 1)

# --- 2g. adding a leg re-labels the button honestly ------------------
l = """  document.getElementById('tenderRefInput').value = '';
  renderSplit();
});"""
assert src.count(l) == 1
src = src.replace(l, """  document.getElementById('tenderRefInput').value = '';
  resetGiftTender();   // MARKER-TENDERFIX -- the leg holds the code now
  tenderModalError('');
  renderSplit();
});""", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: register modal state, button and errors')
PY

echo ""
echo "== tender modal state + labels applied =="
echo "Post-deploy: php artisan optimize:clear"
