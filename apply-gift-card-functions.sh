#!/bin/bash
# apply-gift-card-functions.sh
#
# MARKER-GC-FUNCTIONS — the three gift card behaviors that were deferred in
# the original build, now that the settings exist to control them.
#
#  1. REFUND TO GIFT CARD (setting: gift_card_refund_to_card). Adds a
#     "Gift card" refund tender at the register. Staff can credit the
#     customer's existing card by entering its code, or leave the box empty
#     to issue a new card for the refund amount — the code comes back in the
#     commit response so it can be written on a blank card. Previously a
#     gift tender on a refund was rejected outright and the manager had to
#     hand-adjust a balance.
#
#  2. ABANDONED PURCHASE PURGE (setting: gift_card_pending_retention_days).
#     An online checkout that never completes leaves a status=pending row
#     holding a code. gift-cards:reap-pending clears them nightly. It only
#     ever deletes rows that are pending, older than the retention, have no
#     ledger history and no delivered_at — a card that took money or moved
#     a balance is never in scope, whatever the row says.
#
#  3. PREPRINTED CARD BINDING. Physical cards bought online get a generated
#     code; the shop hands over a preprinted card at pickup. The manager
#     screen can now bind that printed code to the card, replacing the
#     generated one. Uniqueness is enforced, the balance is untouched, and
#     the swap is written to the ledger as a zero-amount entry so the
#     history shows what happened rather than a code silently changing.
#
# Requires MARKER-GC-SETTINGS.
set -e

MARKER="MARKER-GC-FUNCTIONS"

grep -q "MARKER-GC-SETTINGS" app/Services/Tenant/GiftCardService.php || { echo "ERROR: requires apply-gift-card-settings.sh"; exit 1; }
if grep -q "$MARKER" app/Services/Tenant/GiftCardService.php 2>/dev/null; then
  echo "ok: already applied ($MARKER present) — no-op"
  exit 0
fi

# ---------------------------------------------------------------
# 1. Service: refundToCard + bindPrintedCode
# ---------------------------------------------------------------
python3 - <<'PY'
import io
p = 'app/Services/Tenant/GiftCardService.php'
src = io.open(p, encoding='utf-8').read()

a = "    /** Manual balance adjustment (± cents). Requires a reason. */"
assert src.count(a) == 1
src = src.replace(a, """    /**
     * MARKER-GC-FUNCTIONS -- put a refund onto a gift card.
     *
     * With a code: credits that card (must exist and not be deactivated; a
     * fully-used card comes back to life, which is the point of handing it
     * back). Without one: issues a new physical card for the refund amount.
     * Returns the card so the register can show or print its code.
     *
     * Runs inside the caller's transaction: if anything downstream of the
     * refund fails, the credit rolls back with it rather than leaving money
     * on a card for goods that were never returned.
     */
    public function refundToCard(TenantSale $refund, ?string $code, ?string $userId = null): TenantGiftCard
    {
        $tenant = \\App\\Models\\Tenant::find($refund->tenant_id);
        $amount = abs((int) $refund->total_cents);

        if ($amount < 1) {
            throw new SaleValidationException('A gift card refund needs an amount.');
        }
        if (! $tenant || ! self::config($tenant)['refund_to_card']) {
            throw new SaleValidationException('Refunding to a gift card is turned off for this shop.');
        }

        $note = 'Refund from ' . ($refund->sale_number ?? $refund->id);

        if (filled($code)) {
            $norm = TenantGiftCard::normalizeCode((string) $code);
            $card = TenantGiftCard::where('tenant_id', $refund->tenant_id)
                ->where('code', $norm)
                ->lockForUpdate()
                ->first();

            if (! $card) {
                throw new SaleValidationException('Gift card ' . ($norm ?: '(blank)') . ' was not found.');
            }
            if ($card->status === 'deactivated') {
                throw new SaleValidationException('Gift card ' . $card->maskedCode() . ' is deactivated — issue a new one instead.');
            }
            if ($card->status === 'pending') {
                throw new SaleValidationException('Gift card ' . $card->maskedCode() . ' has not been paid for yet.');
            }

            $card->balance_cents += $amount;
            $card->status = 'active'; // a used card is spendable again once credited
            $card->save();

            $this->ledger($card, 'refund', $amount, $refund->id, $note, $userId);

            return $card;
        }

        $card = TenantGiftCard::create([
            'tenant_id'       => $refund->tenant_id,
            'code'            => $this->generateCode($refund->tenant_id),
            'type'            => 'physical',
            'status'          => 'active',
            'original_cents'  => $amount,
            'balance_cents'   => $amount,
            'issued_sale_id'  => $refund->id,
        ]);

        $this->ledger($card, 'issue', $amount, $refund->id, $note, $userId);

        return $card;
    }

    /**
     * MARKER-GC-FUNCTIONS -- bind a preprinted card to an online physical
     * purchase, replacing the generated code at pickup. Balance and history
     * are untouched; the swap itself is recorded, because a code changing
     * with no trace is exactly the kind of thing a manager needs to be able
     * to explain later.
     */
    public function bindPrintedCode(TenantGiftCard $card, string $printedCode, ?string $userId): void
    {
        $norm = TenantGiftCard::normalizeCode($printedCode);

        if ($norm === '') {
            throw new SaleValidationException('Enter the code printed on the card.');
        }
        if ($card->type !== 'physical') {
            throw new SaleValidationException('Only a physical card can be bound to a printed code.');
        }
        if ($card->status === 'deactivated') {
            throw new SaleValidationException('This card is deactivated.');
        }

        $taken = TenantGiftCard::where('tenant_id', $card->tenant_id)
            ->where('code', $norm)
            ->where('id', '!=', $card->id)
            ->exists();
        if ($taken) {
            throw new SaleValidationException('That code is already in use on another card.');
        }

        DB::transaction(function () use ($card, $norm, $userId) {
            $locked = TenantGiftCard::whereKey($card->id)->lockForUpdate()->first();
            $was = $locked->maskedCode();
            $locked->update(['code' => $norm]);

            $this->ledger($locked, 'adjust', 0, null, 'Code bound to printed card (was ' . $was . ')', $userId);
        });
    }

    /** Manual balance adjustment (± cents). Requires a reason. */""", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: refundToCard + bindPrintedCode')
PY

# ---------------------------------------------------------------
# 2. Register: refund tender + wiring
# ---------------------------------------------------------------
python3 - <<'PY'
import io
p = 'app/Http/Controllers/Tenant/RegisterController.php'
src = io.open(p, encoding='utf-8').read()

a = "            'refund.refund_method'         => 'required|string|in:cash,card,check,store_credit,mark_paid,even_exchange',"
assert src.count(a) == 1
src = src.replace(a, """            // MARKER-GC-FUNCTIONS
            'refund.refund_method'         => 'required|string|in:cash,card,check,store_credit,mark_paid,even_exchange,gift_card',
            'refund.gift_card_code'        => 'nullable|string|max:40',""", 1)

b = """            $stripeRefundError = null;
            if ($refund && ($validated['refund']['refund_method'] ?? null) === 'card') {
                $stripeRefundError = $this->fireStripeRefund($tenant, $refund);
            }
"""
assert src.count(b) == 1
src = src.replace(b, """            $stripeRefundError = null;
            if ($refund && ($validated['refund']['refund_method'] ?? null) === 'card') {
                $stripeRefundError = $this->fireStripeRefund($tenant, $refund);
            }

            // MARKER-GC-FUNCTIONS -- put the refund onto a gift card. A failure
            // here is loud: the refund row exists, so silently not crediting the
            // card would hand the customer nothing and tell them it worked.
            $giftRefundCard = null;
            if ($refund && ($validated['refund']['refund_method'] ?? null) === 'gift_card') {
                try {
                    $giftRefundCard = \\Illuminate\\Support\\Facades\\DB::transaction(
                        fn () => app(\\App\\Services\\Tenant\\GiftCardService::class)->refundToCard(
                            $refund,
                            $validated['refund']['gift_card_code'] ?? null,
                            auth('tenant')->id()
                        )
                    );
                } catch (\\Throwable $e) {
                    \\Illuminate\\Support\\Facades\\Log::error('gift_card.refund_failed', [
                        'tenant_id' => $tenant->id,
                        'refund_id' => $refund->id,
                        'error'     => $e->getMessage(),
                    ]);

                    return response()->json([
                        'ok'    => false,
                        'error' => 'The refund was recorded but could not be put on a gift card: '
                                   . $e->getMessage()
                                   . ' Credit the card from Gift Cards, or refund another way.',
                        'transaction_id' => $result['transaction_id'],
                    ], 422);
                }
            }
""", 1)

c = """                'refund_total'   => $refund?->total_cents ?? 0,"""
assert src.count(c) == 1
src = src.replace(c, """                'refund_total'   => $refund?->total_cents ?? 0,
                // MARKER-GC-FUNCTIONS -- the card to write on / hand back.
                'gift_refund_card' => $giftRefundCard ? [
                    'code'          => $giftRefundCard->code,
                    'balance_cents' => (int) $giftRefundCard->balance_cents,
                    'is_new'        => (string) $giftRefundCard->issued_sale_id === (string) $refund?->id,
                ] : null,""", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: register refund-to-card wiring')
PY

python3 - <<'PY'
import io
p = 'resources/views/tenant/register/index.blade.php'
src = io.open(p, encoding='utf-8').read()

a = """      <button type="button" class="reg-tender-btn" data-refund-tender="store_credit">Store credit</button>
    </div>"""
assert src.count(a) == 1
src = src.replace(a, """      <button type="button" class="reg-tender-btn" data-refund-tender="store_credit">Store credit</button>
      {{-- MARKER-GC-FUNCTIONS --}}
      @if(tenant()->gift_cards_enabled && $gcCfg['refund_to_card'])
      <button type="button" class="reg-tender-btn" data-refund-tender="gift_card">Gift card</button>
      @endif
    </div>
    {{-- MARKER-GC-FUNCTIONS --}}
    <div id="refundGiftRow" style="display:none;margin-top:12px">
      <label style="display:block;font-size:12px;color:var(--ia-text-muted);margin-bottom:6px;font-weight:500">Existing card code <span style="font-weight:400;color:var(--ia-text-dim)">(optional)</span></label>
      <input type="text" id="refundGiftCode" placeholder="Scan the customer's card, or leave blank" style="font-family:var(--ia-font-mono)">
      <div style="font-size:11.5px;color:var(--ia-text-dim);margin-top:6px">Leave blank to issue a new card for the refund amount — the code appears on the receipt screen.</div>
    </div>""", 1)

b = """    cart.payment_method = btn.dataset.refundTender;
    document.getElementById('refundTenderConfirmBtn').disabled = false;"""
assert src.count(b) == 1
src = src.replace(b, """    cart.payment_method = btn.dataset.refundTender;
    // MARKER-GC-FUNCTIONS -- reveal the code box only for the gift tender.
    const rgr = document.getElementById('refundGiftRow');
    if (rgr) rgr.style.display = cart.payment_method === 'gift_card' ? '' : 'none';
    document.getElementById('refundTenderConfirmBtn').disabled = false;""", 1)

c = """          refund_method: cart.payment_method,
        },"""
assert src.count(c) == 1
src = src.replace(c, """          refund_method: cart.payment_method,
          // MARKER-GC-FUNCTIONS
          gift_card_code: (cart.payment_method === 'gift_card'
            ? (document.getElementById('refundGiftCode')?.value || '').trim() || null
            : null),
        },""", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: refund tender UI')
PY

# ---------------------------------------------------------------
# 3. Pending purge
# ---------------------------------------------------------------
cat > app/Console/Commands/GiftCardsReapPending.php <<'EOF'
<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\TenantGiftCard;
use App\Models\Tenant\TenantGiftCardTransaction;
use App\Services\Tenant\GiftCardService;
use Illuminate\Console\Command;

/**
 * MARKER-GC-FUNCTIONS — clear abandoned online gift card purchases.
 *
 * A card row is created before payment so the Stripe intent has something to
 * point at; a checkout the customer walks away from leaves it pending
 * forever, holding a code. This deletes those rows per the shop's retention
 * setting.
 *
 * Deliberately narrow: pending only, past the retention, with no ledger row
 * and no delivered_at. Anything that ever took money or moved a balance is
 * out of scope no matter how old, because a wrongly reaped card is a
 * customer holding a code for money we can no longer see.
 */
class GiftCardsReapPending extends Command
{
    protected $signature = 'gift-cards:reap-pending {--dry-run : Report what would be deleted without deleting}';
    protected $description = 'Delete abandoned (never paid) online gift card purchases per tenant retention.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $total = 0;

        Tenant::query()->where('is_active', true)->orderBy('id')->chunkById(50, function ($tenants) use (&$total, $dry) {
            foreach ($tenants as $tenant) {
                $days = GiftCardService::config($tenant)['pending_days'];
                if ($days < 1) {
                    continue; // "keep forever"
                }

                $cutoff = now()->subDays($days);

                $ids = TenantGiftCard::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('status', 'pending')
                    ->whereNull('delivered_at')
                    ->where('created_at', '<', $cutoff)
                    ->whereNotExists(function ($q) {
                        $q->select(\Illuminate\Support\Facades\DB::raw(1))
                          ->from('tenant_gift_card_transactions')
                          ->whereColumn('tenant_gift_card_transactions.gift_card_id', 'tenant_gift_cards.id');
                    })
                    ->limit(500)
                    ->pluck('id');

                if ($ids->isEmpty()) {
                    continue;
                }

                $total += $ids->count();

                if (! $dry) {
                    TenantGiftCard::whereIn('id', $ids)->delete();
                }
            }
        });

        $this->info(($dry ? '[dry-run] ' : '') . "gift-cards:reap-pending cleared {$total} abandoned purchases");

        return self::SUCCESS;
    }
}
EOF

cat >> routes/console.php <<'EOF'
// ----------------------------------------------------------------
// MARKER-GC-FUNCTIONS — clear abandoned (never paid) online gift card
// purchases per each shop's retention setting. Rows with any ledger
// history are never in scope.
// ----------------------------------------------------------------
Schedule::command('gift-cards:reap-pending')
    ->dailyAt('03:25')
    ->withoutOverlapping()
    ->runInBackground();
EOF
echo "ok: pending purge command + schedule"

# ---------------------------------------------------------------
# 4. Preprinted card binding on the manager screen
# ---------------------------------------------------------------
python3 - <<'PY'
import io

p = 'app/Http/Controllers/Tenant/GiftCardController.php'
src = io.open(p, encoding='utf-8').read()

a = "    public function deactivate(Request $request, string $cardId)"
assert src.count(a) == 1
src = src.replace(a, """    /** MARKER-GC-FUNCTIONS -- bind a preprinted card at pickup. */
    public function bindCode(Request $request, string $cardId)
    {
        abort_unless(auth('tenant')->user()?->can('giftcards.manage'), 403);
        $tenant = tenant();
        abort_unless($tenant->gift_cards_visible, 404);

        $data = $request->validate(['printed_code' => 'required|string|max:40']);

        $card = TenantGiftCard::where('tenant_id', $tenant->id)->findOrFail($cardId);

        try {
            app(GiftCardService::class)->bindPrintedCode($card, $data['printed_code'], auth('tenant')->id());
        } catch (\\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Card bound to the printed code.');
    }

    public function deactivate(Request $request, string $cardId)""", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: bindCode action')

# route
p2 = 'routes/web.php'
s2 = io.open(p2, encoding='utf-8').read()
# NOTE: these routes sit in a name-prefixed group -- the stored name is
# relative ('gift-cards.deactivate'), resolving to tenant.gift-cards.*
b = "->name('gift-cards.deactivate');"
assert s2.count(b) == 1
i = s2.find(b) + len(b)
line_end = s2.find('\n', i) + 1
s2 = s2[:line_end] + """                // MARKER-GC-FUNCTIONS -- bind a preprinted card at pickup
                Route::post('/gift-cards/{cardId}/bind-code', [TenantControllers\\GiftCardController::class, 'bindCode'])->name('gift-cards.bind-code');
""" + s2[line_end:]
io.open(p2, 'w', encoding='utf-8').write(s2)
print('ok: bind route')

# manager detail UI
p3 = 'resources/views/tenant/gift-cards/show.blade.php'
s3 = io.open(p3, encoding='utf-8').read()
c = '<div class="ia-card">\n  <div class="ia-card-head"><div class="ia-card-title">Ledger</div></div>'
assert s3.count(c) == 1
s3 = s3.replace(c, """{{-- MARKER-GC-FUNCTIONS -- physical cards bought online carry a generated
     code until a preprinted card is handed over at pickup. --}}
@if($card->type === 'physical' && $card->status !== 'deactivated')
<div class="ia-card" style="margin-bottom:18px">
  <div class="ia-card-head"><div class="ia-card-title">Printed card</div></div>
  <div style="padding:16px">
    <div style="font-size:12.5px;color:var(--ia-text-dim);margin-bottom:12px">
      Handing this customer a preprinted card? Scan it here and it takes over from
      the generated code. The balance and history stay with the card.
    </div>
    <form method="POST" action="{{ route('tenant.gift-cards.bind-code', $card->id) }}" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      @csrf
      <input type="text" name="printed_code" class="ia-input" placeholder="Scan or type the printed code"
             style="font-family:var(--ia-font-mono);min-width:260px" required>
      <button type="submit" class="ia-btn ia-btn--secondary">Bind card</button>
    </form>
  </div>
</div>
@endif

""" + c, 1)

# MARKER-GC-FUNCTIONS -- the ledger gained a 'refund' kind; without this it
# falls to the default badge and reads as pending.
d = "    'deactivate' => ['cancelled', 'Deactivated'],"
assert s3.count(d) == 1
s3 = s3.replace(d, d + "\n    'refund' => ['completed', 'Refunded'], // MARKER-GC-FUNCTIONS", 1)
io.open(p3, 'w', encoding='utf-8').write(s3)
print('ok: bind UI on the card detail')
PY

echo ""
echo "== gift card functions applied =="
echo "Post-deploy: php artisan optimize:clear + queue:restart"
