#!/bin/bash
# apply-gift-cards-public.sh
#
# MARKER-GIFTCARDS-PUBLIC — gift cards patch 3 of 3: the public surfaces,
# per the approved mockup. Requires MARKER-GIFTCARDS (patch 1); patch 2 is
# independent but expected.
#
#   - /gift-cards — chrome-wrapped buy page (same SiteChromeService pattern
#     as /shop): e-gift vs physical (physical = pick up in store), amount
#     presets + custom, recipient/message, deliver now or on a date, live
#     card preview, Stripe Payment Element checkout (DirectPaymentsService,
#     same rails as the shop). Gated by the online_store addon like the rest
#     of the storefront; purchasing disabled with honest copy when the
#     tenant has no Stripe keys.
#   - Card is created status=pending with the PI id; the return leg verifies
#     the PI succeeded, then activates + writes the issue ledger row +
#     queues delivery. Idempotent via the pending check. Confirmation page
#     never shows the code (it goes to the recipient / is read at pickup).
#   - /gift-cards/balance — public balance check. Throttled 10/min, full
#     code required, result shows balance + masked code ONLY (never
#     purchaser or recipient details). NOT addon-gated: cards sold in store
#     must be checkable even if the shop never buys the ecommerce addon.
#   - gift-cards:deliver hourly — sends e-gifts whose deliver_on date has
#     arrived (tenant-local), and backstops any immediate delivery whose
#     job failed (DeliverGiftCardJob is idempotent via delivered_at).
set -e

MARKER="MARKER-GIFTCARDS-PUBLIC"

if ! grep -q "MARKER-GIFTCARDS" app/Services/Tenant/GiftCardService.php 2>/dev/null; then
  echo "ERROR: requires apply-gift-cards-core.sh (MARKER-GIFTCARDS) first"
  exit 1
fi
if [ -f app/Http/Controllers/Tenant/GiftCardPublicController.php ]; then
  echo "ok: already applied ($MARKER present) — no-op"
  exit 0
fi

# ---------------------------------------------------------------
# 1. Public controller
# ---------------------------------------------------------------
cat > app/Http/Controllers/Tenant/GiftCardPublicController.php <<'EOF'
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantGiftCard;
use App\Models\Tenant\TenantGiftCardTransaction;
use App\Services\Tenant\DirectPaymentsService;
use App\Services\Tenant\GiftCardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// MARKER-GIFTCARDS-PUBLIC — public buy + balance-check pages.
class GiftCardPublicController extends Controller
{
    /** Buy page rides the storefront addon, like /shop. */
    protected function guardShop(): void
    {
        $ok = app(\App\Services\FeatureAccessService::class)->hasAddon(tenant(), 'online_store');
        abort_unless($ok, 404);
    }

    public function buy(Request $request)
    {
        $this->guardShop();
        $tenant = tenant();
        $pk = (new DirectPaymentsService($tenant))->publishableKey();

        return \App\Services\Tenant\SiteChromeService::render($tenant, 'gift_shop', [
            'tenant'    => $tenant,
            'stripePk'  => $pk,
        ], ['title' => 'Gift cards', 'description' => 'Buy a ' . $tenant->name . ' gift card.']);
    }

    public function purchase(Request $request)
    {
        $this->guardShop();
        $tenant = tenant();

        $svc = new DirectPaymentsService($tenant);
        if (! $svc->publishableKey()) {
            return response()->json(['ok' => false, 'message' => 'Online gift card purchase isn\'t available right now — give us a call.'], 422);
        }

        $data = $request->validate([
            'type'            => 'required|in:egift,physical',
            'amount'          => 'required|numeric|min:5|max:2000',
            'purchaser_name'  => 'required|string|max:120',
            'purchaser_email' => 'required|email|max:160',
            'recipient_name'  => 'nullable|string|max:120|required_if:type,egift',
            'recipient_email' => 'nullable|email|max:160|required_if:type,egift',
            'gift_message'    => 'nullable|string|max:200',
            'deliver_mode'    => 'nullable|in:now,date',
            'deliver_on'      => 'nullable|date|required_if:deliver_mode,date|after_or_equal:today|before:+1 year',
        ]);

        $amount = (int) round(((float) $data['amount']) * 100);
        $deliverOn = ($data['type'] === 'egift' && ($data['deliver_mode'] ?? 'now') === 'date')
            ? $data['deliver_on'] : null;

        try {
            $card = DB::transaction(function () use ($tenant, $data, $amount, $deliverOn) {
                return TenantGiftCard::create([
                    'tenant_id'       => $tenant->id,
                    'code'            => app(GiftCardService::class)->generateCode($tenant->id),
                    'type'            => $data['type'],
                    'status'          => 'pending',
                    'original_cents'  => $amount,
                    'balance_cents'   => $amount,
                    'purchaser_name'  => $data['purchaser_name'],
                    'purchaser_email' => $data['purchaser_email'],
                    'recipient_name'  => $data['recipient_name'] ?? null,
                    'recipient_email' => $data['recipient_email'] ?? null,
                    'gift_message'    => $data['gift_message'] ?? null,
                    'deliver_on'      => $deliverOn,
                ]);
            });

            $pi = $svc->createPaymentIntent($amount, 'usd', [
                'intake_gift_card_id' => $card->id,
                'intake_tenant_id'    => $tenant->id,
            ]);
            $card->update(['stripe_payment_intent_id' => $pi->id]);

            return response()->json([
                'ok'            => true,
                'client_secret' => $pi->client_secret,
                'return_url'    => route('tenant.gift-cards.public.return'),
            ]);
        } catch (\Throwable $e) {
            Log::error('gift_card.purchase_failed', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'message' => 'Could not start payment — try again or give us a call.'], 500);
        }
    }

    /** Stripe redirects here; verify then activate. */
    public function returnLeg(Request $request)
    {
        $this->guardShop();
        $tenant = tenant();
        $piId = (string) $request->query('payment_intent');

        $card = TenantGiftCard::query()
            ->where('tenant_id', $tenant->id)
            ->where('stripe_payment_intent_id', $piId)
            ->first();

        if ($card && $card->status === 'pending') {
            try {
                $pi = (new DirectPaymentsService($tenant))->retrievePaymentIntent($piId);
                if ($pi->status === 'succeeded') {
                    DB::transaction(function () use ($card) {
                        $locked = TenantGiftCard::whereKey($card->id)->lockForUpdate()->first();
                        if ($locked->status !== 'pending') {
                            return; // replayed return leg
                        }
                        $locked->update(['status' => 'active']);
                        TenantGiftCardTransaction::create([
                            'tenant_id'           => $locked->tenant_id,
                            'gift_card_id'        => $locked->id,
                            'kind'                => 'issue',
                            'amount_cents'        => $locked->original_cents,
                            'balance_after_cents' => $locked->balance_cents,
                            'note'                => 'Purchased online by ' . ($locked->purchaser_name ?: 'customer'),
                        ]);
                    });
                    $deliverToday = $card->deliver_on === null
                        || $card->deliver_on->lte($tenant->localToday());
                    if ($card->type === 'egift' && $deliverToday) {
                        \App\Jobs\DeliverGiftCardJob::dispatch($card->id);
                    }
                }
            } catch (\Throwable $e) {
                Log::error('gift_card.return_activate_failed', ['card' => $card->id, 'error' => $e->getMessage()]);
            }
        }

        return redirect()->route('tenant.gift-cards.public.thanks', ['c' => $card?->id]);
    }

    public function thanks(Request $request)
    {
        $this->guardShop();
        $tenant = tenant();
        $card = TenantGiftCard::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', (string) $request->query('c'))
            ->first();

        return \App\Services\Tenant\SiteChromeService::render($tenant, 'gift_confirmation', [
            'tenant' => $tenant,
            'card'   => $card,
        ], ['title' => 'Gift card']);
    }

    /** Balance check — deliberately NOT addon-gated; in-store cards must be checkable. */
    public function balance(Request $request)
    {
        $tenant = tenant();

        return \App\Services\Tenant\SiteChromeService::render($tenant, 'gift_balance', [
            'tenant' => $tenant,
            'result' => null,
            'error'  => null,
        ], ['title' => 'Gift card balance']);
    }

    public function balanceCheck(Request $request)
    {
        $tenant = tenant();
        $data = $request->validate(['code' => 'required|string|max:40']);

        $card = app(GiftCardService::class)->lookup($tenant->id, $data['code']);

        // Balance + masked code ONLY — never purchaser or recipient details,
        // and a pending (unpaid) card reads as not found.
        $result = ($card && $card->status !== 'pending') ? [
            'masked'        => $card->maskedCode(),
            'balance_cents' => (int) $card->balance_cents,
            'status'        => $card->status,
        ] : null;

        return \App\Services\Tenant\SiteChromeService::render($tenant, 'gift_balance', [
            'tenant' => $tenant,
            'result' => $result,
            'error'  => $result ? null : 'No gift card found for that code.',
        ], ['title' => 'Gift card balance']);
    }
}
EOF
echo "ok: GiftCardPublicController created"

# ---------------------------------------------------------------
# 2. Scheduled delivery command + schedule
# ---------------------------------------------------------------
cat > app/Console/Commands/GiftCardsDeliver.php <<'EOF'
<?php

namespace App\Console\Commands;

use App\Jobs\DeliverGiftCardJob;
use App\Models\Tenant;
use App\Models\Tenant\TenantGiftCard;
use Illuminate\Console\Command;

/**
 * MARKER-GIFTCARDS-PUBLIC — dispatch delivery for e-gifts that are due:
 * scheduled deliver_on dates that have arrived (tenant-local), plus any
 * immediate delivery whose issue-time job failed. DeliverGiftCardJob is
 * idempotent via delivered_at, so re-dispatching is harmless.
 */
class GiftCardsDeliver extends Command
{
    protected $signature = 'gift-cards:deliver';
    protected $description = 'Send e-gift cards whose delivery date has arrived.';

    public function handle(): int
    {
        $sent = 0;

        Tenant::query()->where('is_active', true)->orderBy('id')->chunkById(50, function ($tenants) use (&$sent) {
            foreach ($tenants as $tenant) {
                $today = $tenant->localToday()->toDateString();

                $due = TenantGiftCard::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('type', 'egift')
                    ->where('status', 'active')
                    ->whereNull('delivered_at')
                    ->whereNotNull('recipient_email')
                    ->where(function ($w) use ($today) {
                        $w->whereNull('deliver_on')->orWhere('deliver_on', '<=', $today);
                    })
                    ->limit(200)
                    ->pluck('id');

                foreach ($due as $id) {
                    DeliverGiftCardJob::dispatch($id);
                    $sent++;
                }
            }
        });

        $this->info("gift-cards:deliver dispatched {$sent} deliveries");

        return self::SUCCESS;
    }
}
EOF

cat >> routes/console.php <<'EOF'
// ----------------------------------------------------------------
// MARKER-GIFTCARDS-PUBLIC — e-gift delivery: scheduled deliver_on
// dates plus a backstop for failed issue-time sends. Idempotent.
// ----------------------------------------------------------------
Schedule::command('gift-cards:deliver')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
EOF
echo "ok: delivery command + schedule"

# ---------------------------------------------------------------
# 3. Public routes (host-based public group, next to /shop)
# ---------------------------------------------------------------
python3 - <<'PY'
import io
p = 'routes/web.php'
src = io.open(p, encoding='utf-8').read()
assert 'MARKER-GIFTCARDS-PUBLIC' not in src
a = "    Route::get('/rentals',               [TenantControllers\\RentalBrowseController::class, 'index'])->name('tenant.rentals.browse');"
assert src.count(a) == 1
src = src.replace(a, """    // MARKER-GIFTCARDS-PUBLIC -- public gift card pages
    Route::get('/gift-cards',                 [TenantControllers\\GiftCardPublicController::class, 'buy'])->name('tenant.gift-cards.public.buy');
    Route::post('/gift-cards/purchase',       [TenantControllers\\GiftCardPublicController::class, 'purchase'])->name('tenant.gift-cards.public.purchase')->middleware('throttle:10,1');
    Route::get('/gift-cards/return',          [TenantControllers\\GiftCardPublicController::class, 'returnLeg'])->name('tenant.gift-cards.public.return');
    Route::get('/gift-cards/thanks',          [TenantControllers\\GiftCardPublicController::class, 'thanks'])->name('tenant.gift-cards.public.thanks');
    Route::get('/gift-cards/balance',         [TenantControllers\\GiftCardPublicController::class, 'balance'])->name('tenant.gift-cards.public.balance');
    Route::post('/gift-cards/balance',        [TenantControllers\\GiftCardPublicController::class, 'balanceCheck'])->name('tenant.gift-cards.public.balance.check')->middleware('throttle:10,1');
""" + a, 1)
io.open(p, 'w', encoding='utf-8').write(src)
print('ok: public routes added')
PY

# ---------------------------------------------------------------
# 4. Section partials (chrome-wrapped, spg-* light vocabulary)
# ---------------------------------------------------------------
cat > resources/views/public/sections/_gift_shop.blade.php <<'EOF'
{{-- MARKER-GIFTCARDS-PUBLIC — chrome-wrapped gift card buy page, per the
     approved mockup. Same scoped-style pattern as _shop_checkout. --}}
@php
  $accent = $tenant->accent_color ?? '#BEF264';
@endphp
<script src="https://js.stripe.com/v3/"></script>
<style>
  :root { --acc: {{ $accent }}; }
  .spg-gift .wrap { max-width: 1080px; margin: 0 auto; padding: 28px 20px 80px; }
  .spg-gift h1 { font-size: 26px; font-weight: 650; letter-spacing: -.02em; }
  .spg-gift .sub { font-size: 14px; opacity: .55; margin-top: 4px; }
  .spg-gift .cols { display: grid; grid-template-columns: 1fr 340px; gap: 26px; align-items: start; margin-top: 24px; }
  @media (max-width: 820px) { .spg-gift .cols { grid-template-columns: 1fr; } }
  .spg-gift .panel { background: #fff; border: 1.5px solid rgba(0,0,0,.09); border-radius: 16px; padding: 20px; margin-bottom: 16px; }
  .spg-gift .panel h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .07em; opacity: .5; font-weight: 700; margin-bottom: 14px; }
  .spg-gift .ful { display: flex; gap: 10px; margin-bottom: 4px; }
  .spg-gift .ful label { flex: 1; border: 1.5px solid rgba(0,0,0,.13); border-radius: 12px; padding: 13px 15px; cursor: pointer; font-size: 13.5px; }
  .spg-gift .ful label.on { border-color: #161616; background: rgba(0,0,0,.025); }
  .spg-gift .ful b { display: block; font-size: 14px; }
  .spg-gift .ful .fee { font-size: 12px; opacity: .55; }
  .spg-gift .ful input { display: none; }
  .spg-gift .amounts { display: flex; gap: 8px; flex-wrap: wrap; }
  .spg-gift .amt { font-size: 14px; font-weight: 650; padding: 11px 20px; border-radius: 12px; border: 1.5px solid rgba(0,0,0,.13); background: #fff; cursor: pointer; font-family: inherit; }
  .spg-gift .amt.on { border-color: #161616; background: #161616; color: #fff; }
  .spg-gift input[type=text], .spg-gift input[type=email], .spg-gift input[type=date], .spg-gift select, .spg-gift textarea { width: 100%; font: inherit; font-size: 14px; padding: 11px 13px; border: 1.5px solid rgba(0,0,0,.13); border-radius: 10px; background: #fff; margin-bottom: 10px; }
  .spg-gift textarea { resize: vertical; min-height: 70px; }
  .spg-gift .lbl { display: block; font-size: 12.5px; font-weight: 650; margin-bottom: 5px; }
  .spg-gift .hint { font-size: 12px; opacity: .5; margin: -4px 0 10px; }
  .spg-gift .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  .spg-gift .char { font-size: 11.5px; opacity: .45; text-align: right; margin: -6px 0 8px; }
  .spg-gift .gcv { border-radius: 16px; padding: 22px 24px; background: #161616; color: #fff; position: relative; overflow: hidden; margin-bottom: 16px; }
  .spg-gift .gcv::after { content: ''; position: absolute; right: -40px; top: -40px; width: 160px; height: 160px; border-radius: 50%; background: var(--acc); opacity: .16; }
  .spg-gift .gcv .shop { font-size: 12px; text-transform: uppercase; letter-spacing: .1em; opacity: .55; font-weight: 700; }
  .spg-gift .gcv .bigamt { font-size: 34px; font-weight: 800; margin-top: 10px; letter-spacing: -.02em; }
  .spg-gift .gcv .cardlbl { font-size: 10.5px; text-transform: uppercase; letter-spacing: .08em; opacity: .4; margin-top: 14px; }
  .spg-gift .gcv .code { font-family: ui-monospace, monospace; font-size: 14px; letter-spacing: .14em; margin-top: 6px; opacity: .85; }
  .spg-gift .sum-line { display: flex; justify-content: space-between; font-size: 13.5px; padding: 5px 0; }
  .spg-gift .sum-line.total { font-size: 16px; font-weight: 800; border-top: 1.5px solid rgba(0,0,0,.09); margin-top: 8px; padding-top: 12px; }
  .spg-gift .pay { display: block; width: 100%; text-align: center; font: inherit; font-size: 15px; font-weight: 700; padding: 15px 0; border: 0; border-radius: 12px; background: var(--acc); cursor: pointer; margin-top: 16px; }
  .spg-gift .pay:disabled { opacity: .5; cursor: wait; }
  .spg-gift .err { color: #b3261e; font-size: 13px; margin-top: 10px; display: none; }
  .spg-gift #gp-pay-panel { display: none; }
  .spg-gift #gp-payment-element { margin-top: 4px; }
</style>
<div class="spg-gift">
  <div class="wrap">
    <h1>Gift cards</h1>
    <div class="sub">Good for anything — service, parts, rentals. Never expires. <a href="/gift-cards/balance" style="text-decoration:underline">Check a balance</a></div>

    @if(!$stripePk)
      <div class="panel" style="margin-top:24px;max-width:560px">
        Online gift card purchase isn't available right now — call or visit us in store to buy one.
      </div>
    @else
    <div class="cols">
      <div>
        <div class="panel">
          <h2>1 · Choose a type</h2>
          <div class="ful" id="gp-type">
            <label class="on" data-type="egift"><b>E-gift card</b><span class="fee">Emailed instantly, or on a date you pick</span><input type="radio" name="gp_type" value="egift" checked></label>
            <label data-type="physical"><b>Physical card</b><span class="fee">Pick up in store</span><input type="radio" name="gp_type" value="physical"></label>
          </div>
        </div>

        <div class="panel">
          <h2>2 · Amount</h2>
          <div class="amounts" id="gp-amounts">
            <button type="button" class="amt" data-cents="2500">$25</button>
            <button type="button" class="amt on" data-cents="5000">$50</button>
            <button type="button" class="amt" data-cents="10000">$100</button>
            <button type="button" class="amt" data-cents="15000">$150</button>
            <button type="button" class="amt" data-cents="">Custom</button>
          </div>
          <div id="gp-custom-wrap" style="display:none;margin-top:10px">
            <input type="text" id="gp-custom" inputmode="decimal" placeholder="Amount in dollars ($5–$2,000)">
          </div>
        </div>

        <div class="panel">
          <h2 id="gp-send-title">3 · Send it</h2>
          <div class="grid2">
            <div>
              <label class="lbl">Your name</label>
              <input type="text" id="gp-purchaser-name" maxlength="120">
            </div>
            <div>
              <label class="lbl">Your email</label>
              <input type="email" id="gp-purchaser-email" maxlength="160">
            </div>
          </div>
          <div id="gp-egift-fields">
            <label class="lbl">Recipient name</label>
            <input type="text" id="gp-recipient-name" maxlength="120">
            <label class="lbl">Recipient email</label>
            <input type="email" id="gp-recipient-email" maxlength="160">
            <label class="lbl">Gift message (optional)</label>
            <textarea id="gp-message" maxlength="200"></textarea>
            <div class="char"><span id="gp-message-count">0</span> / 200</div>
            <div class="grid2">
              <div>
                <label class="lbl">Deliver</label>
                <select id="gp-deliver-mode">
                  <option value="now">Right away</option>
                  <option value="date">On a date…</option>
                </select>
              </div>
              <div id="gp-date-wrap" style="display:none">
                <label class="lbl">Delivery date</label>
                <input type="date" id="gp-deliver-on">
              </div>
            </div>
            <div class="hint" id="gp-deliver-hint">We'll email the card as soon as your payment goes through, with a receipt to you.</div>
          </div>
          <div id="gp-physical-note" style="display:none" class="hint">
            We'll have your card ready at the shop — bring your confirmation.
          </div>
        </div>
      </div>

      <div>
        <div class="gcv">
          <div class="shop">{{ $tenant->name }}</div>
          <div class="bigamt" id="gp-preview-amt">$50</div>
          <div class="cardlbl">Gift card &middot; preview</div>
          <div class="code">GC-&bull;&bull;&bull;&bull;-&bull;&bull;&bull;&bull;-&bull;&bull;&bull;&bull;</div>
        </div>
        <div class="panel">
          <div class="sum-line"><span id="gp-sum-label">E-gift card</span><span id="gp-sum-amt">$50.00</span></div>
          <div class="sum-line"><span>Delivery</span><span>Free</span></div>
          <div class="sum-line total"><span>Total</span><span id="gp-sum-total">$50.00</span></div>
          <button type="button" class="pay" id="gp-continue">Continue to payment</button>
          <div class="err" id="gp-err"></div>
          <div class="hint" style="margin-top:10px;text-align:center">No expiration &middot; balance checkable any time</div>
        </div>
        <div class="panel" id="gp-pay-panel">
          <h2>Payment</h2>
          <div id="gp-payment-element"></div>
          <button type="button" class="pay" id="gp-pay-btn" disabled>Pay</button>
          <div class="err" id="gp-pay-err"></div>
        </div>
      </div>
    </div>
    @endif
  </div>
</div>
@if($stripePk)
<script>
(function () {
  var state = { type: 'egift', cents: 5000 };
  var stripe = null, elements = null;

  function fmt(c) { return '$' + (c / 100).toFixed(2); }
  function short(c) { return '$' + Math.round(c / 100); }

  function sync() {
    var cents = state.cents;
    if (cents === null) {
      var f = parseFloat((document.getElementById('gp-custom').value || '').replace(/[^0-9.]/g, ''));
      cents = (!isNaN(f) && f > 0) ? Math.round(f * 100) : 0;
    }
    document.getElementById('gp-preview-amt').textContent = cents ? short(cents) : '$—';
    document.getElementById('gp-sum-amt').textContent = cents ? fmt(cents) : '—';
    document.getElementById('gp-sum-total').textContent = cents ? fmt(cents) : '—';
    document.getElementById('gp-sum-label').textContent = state.type === 'egift' ? 'E-gift card' : 'Physical card';
    return cents;
  }

  document.querySelectorAll('#gp-type label').forEach(function (l) {
    l.addEventListener('click', function () {
      document.querySelectorAll('#gp-type label').forEach(function (x) { x.classList.remove('on'); });
      l.classList.add('on');
      state.type = l.dataset.type;
      var egift = state.type === 'egift';
      document.getElementById('gp-egift-fields').style.display = egift ? '' : 'none';
      document.getElementById('gp-physical-note').style.display = egift ? 'none' : '';
      document.getElementById('gp-send-title').textContent = egift ? '3 · Send it' : '3 · Your details';
      sync();
    });
  });

  document.querySelectorAll('#gp-amounts .amt').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('#gp-amounts .amt').forEach(function (x) { x.classList.remove('on'); });
      b.classList.add('on');
      state.cents = b.dataset.cents ? parseInt(b.dataset.cents, 10) : null;
      document.getElementById('gp-custom-wrap').style.display = state.cents === null ? '' : 'none';
      sync();
    });
  });
  document.getElementById('gp-custom').addEventListener('input', sync);

  document.getElementById('gp-message').addEventListener('input', function () {
    document.getElementById('gp-message-count').textContent = String(this.value.length);
  });
  document.getElementById('gp-deliver-mode').addEventListener('change', function () {
    var onDate = this.value === 'date';
    document.getElementById('gp-date-wrap').style.display = onDate ? '' : 'none';
    document.getElementById('gp-deliver-hint').textContent = onDate
      ? "We'll email the card the morning of your chosen date, with a receipt to you now."
      : "We'll email the card as soon as your payment goes through, with a receipt to you.";
  });

  function err(msg) {
    var el = document.getElementById('gp-err');
    el.textContent = msg; el.style.display = msg ? '' : 'none';
  }

  document.getElementById('gp-continue').addEventListener('click', function () {
    err('');
    var cents = sync();
    if (!cents || cents < 500) { err('Pick or enter an amount of at least $5.'); return; }
    var payload = {
      type: state.type,
      amount: (cents / 100).toFixed(2),
      purchaser_name: document.getElementById('gp-purchaser-name').value.trim(),
      purchaser_email: document.getElementById('gp-purchaser-email').value.trim(),
    };
    if (!payload.purchaser_name || !payload.purchaser_email) { err('Your name and email are required.'); return; }
    if (state.type === 'egift') {
      payload.recipient_name = document.getElementById('gp-recipient-name').value.trim();
      payload.recipient_email = document.getElementById('gp-recipient-email').value.trim();
      if (!payload.recipient_name || !payload.recipient_email) { err('Recipient name and email are required for an e-gift.'); return; }
      var msg = document.getElementById('gp-message').value.trim();
      if (msg) payload.gift_message = msg;
      payload.deliver_mode = document.getElementById('gp-deliver-mode').value;
      if (payload.deliver_mode === 'date') {
        payload.deliver_on = document.getElementById('gp-deliver-on').value;
        if (!payload.deliver_on) { err('Pick a delivery date.'); return; }
      }
    }

    var btn = document.getElementById('gp-continue');
    btn.disabled = true;
    fetch('/gift-cards/purchase', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
      body: JSON.stringify(payload)
    }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
      .then(function (res) {
        btn.disabled = false;
        if (!res.ok || !res.d.ok) { err((res.d && (res.d.message || (res.d.errors && Object.values(res.d.errors)[0][0]))) || 'Could not start payment.'); return; }
        stripe = Stripe(@json($stripePk));
        elements = stripe.elements({ clientSecret: res.d.client_secret });
        elements.create('payment').mount('#gp-payment-element');
        document.getElementById('gp-pay-panel').style.display = '';
        document.getElementById('gp-pay-btn').disabled = false;
        window.gpReturnUrl = res.d.return_url;
        document.getElementById('gp-pay-panel').scrollIntoView({ behavior: 'smooth' });
      })
      .catch(function () { btn.disabled = false; err('Network error — try again.'); });
  });

  document.getElementById('gp-pay-btn').addEventListener('click', function () {
    var btn = this;
    btn.disabled = true;
    var pe = document.getElementById('gp-pay-err');
    pe.style.display = 'none';
    stripe.confirmPayment({ elements: elements, confirmParams: { return_url: window.gpReturnUrl } })
      .then(function (result) {
        if (result && result.error) {
          pe.textContent = result.error.message || 'Payment failed.'; pe.style.display = '';
          btn.disabled = false;
        }
      });
  });

  sync();
})();
</script>
@endif
EOF

cat > resources/views/public/sections/_gift_balance.blade.php <<'EOF'
{{-- MARKER-GIFTCARDS-PUBLIC — public balance check, per the approved mockup.
     Rate-limited at the route; result shows balance + masked code ONLY. --}}
@php
  $accent = $tenant->accent_color ?? '#BEF264';
@endphp
<style>
  :root { --acc: {{ $accent }}; }
  .spg-gcbal .wrap { max-width: 560px; margin: 0 auto; padding: 28px 20px 80px; }
  .spg-gcbal h1 { font-size: 26px; font-weight: 650; letter-spacing: -.02em; }
  .spg-gcbal .sub { font-size: 14px; opacity: .55; margin-top: 4px; }
  .spg-gcbal .panel { background: #fff; border: 1.5px solid rgba(0,0,0,.09); border-radius: 16px; padding: 20px; margin-top: 24px; }
  .spg-gcbal .lbl { display: block; font-size: 12.5px; font-weight: 650; margin-bottom: 5px; }
  .spg-gcbal input { width: 100%; font: inherit; font-size: 14px; padding: 11px 13px; border: 1.5px solid rgba(0,0,0,.13); border-radius: 10px; background: #fff; margin-bottom: 10px; font-family: ui-monospace, monospace; letter-spacing: .1em; }
  .spg-gcbal .pay { display: block; width: 100%; text-align: center; font: inherit; font-size: 15px; font-weight: 700; padding: 15px 0; border: 0; border-radius: 12px; background: var(--acc); cursor: pointer; margin-top: 6px; }
  .spg-gcbal .result { text-align: center; padding: 28px 20px; }
  .spg-gcbal .result .rlbl { font-size: 12px; text-transform: uppercase; letter-spacing: .08em; opacity: .5; font-weight: 700; }
  .spg-gcbal .result .amt { font-size: 44px; font-weight: 800; letter-spacing: -.03em; margin-top: 8px; }
  .spg-gcbal .result .meta { font-size: 13px; opacity: .55; margin-top: 8px; }
  .spg-gcbal .errbox { color: #b3261e; font-size: 13.5px; padding: 14px 4px 0; }
</style>
<div class="spg-gcbal">
  <div class="wrap">
    <h1>Check a balance</h1>
    <div class="sub">Enter the code from your card or e-gift email.</div>

    <form class="panel" method="POST" action="/gift-cards/balance">
      @csrf
      <label class="lbl" for="gcbal-code">Gift card code</label>
      <input id="gcbal-code" name="code" value="{{ old('code') }}" placeholder="GC-0000-0000-0000" maxlength="40" required>
      <button type="submit" class="pay">Check balance</button>
      @if(!empty($error))
        <div class="errbox">{{ $error }}</div>
      @endif
    </form>

    @if(!empty($result))
      <div class="panel result">
        <div class="rlbl">Current balance</div>
        <div class="amt">${{ number_format($result['balance_cents'] / 100, 2) }}</div>
        <div class="meta">
          {{ $result['masked'] }}
          @if($result['status'] === 'active') &middot; Redeemable in store and online
          @elseif($result['status'] === 'used') &middot; Fully used
          @else &middot; This card has been deactivated — contact us
          @endif
        </div>
      </div>
    @endif
  </div>
</div>
EOF

cat > resources/views/public/sections/_gift_confirmation.blade.php <<'EOF'
{{-- MARKER-GIFTCARDS-PUBLIC — purchase confirmation. Never shows the code:
     e-gifts go to the recipient; physical cards are read out at pickup. --}}
@php
  $accent = $tenant->accent_color ?? '#BEF264';
@endphp
<style>
  :root { --acc: {{ $accent }}; }
  .spg-gcconf .wrap { max-width: 560px; margin: 0 auto; padding: 28px 20px 80px; }
  .spg-gcconf .panel { background: #fff; border: 1.5px solid rgba(0,0,0,.09); border-radius: 16px; padding: 26px; margin-top: 24px; }
  .spg-gcconf h1 { font-size: 24px; font-weight: 650; letter-spacing: -.02em; }
  .spg-gcconf .row { display: flex; justify-content: space-between; font-size: 13.5px; padding: 6px 0; border-bottom: 1px solid rgba(0,0,0,.05); }
  .spg-gcconf .row:last-child { border-bottom: 0; }
</style>
<div class="spg-gcconf">
  <div class="wrap">
    @if($card && $card->status === 'active')
      <h1>Gift card purchased 🎉</h1>
      <div class="panel">
        <div class="row"><span>Amount</span><b>${{ number_format($card->original_cents / 100, 2) }}</b></div>
        <div class="row"><span>Type</span><b>{{ $card->type === 'egift' ? 'E-gift card' : 'Physical card' }}</b></div>
        @if($card->type === 'egift')
          <div class="row"><span>Going to</span><b>{{ $card->recipient_email }}</b></div>
          <div class="row"><span>Delivery</span><b>@if($card->deliver_on){{ $card->deliver_on->format('M j, Y') }}@else On its way now @endif</b></div>
        @else
          <div class="row"><span>Pickup</span><b>Ready at the shop — bring this confirmation</b></div>
        @endif
        <p style="font-size:13px;opacity:.6;margin:14px 0 0">
          @if($card->type === 'egift')The card code is in the recipient's email. Balance can be checked any time at <a href="/gift-cards/balance" style="text-decoration:underline">/gift-cards/balance</a>.
          @else We'll match this purchase to your card when you pick it up.@endif
        </p>
      </div>
    @elseif($card)
      <h1>Almost there…</h1>
      <div class="panel">
        <p style="font-size:14px;margin:0">Your payment is still processing. This page will show the confirmation once it goes through — refresh in a moment, or contact us if it doesn't.</p>
      </div>
    @else
      <h1>Gift card</h1>
      <div class="panel">
        <p style="font-size:14px;margin:0">We couldn't find that purchase. If you were charged, contact us and we'll sort it out.</p>
      </div>
    @endif
  </div>
</div>
EOF
echo "ok: 3 section partials created"

echo ""
echo "== gift-cards public applied =="
echo "Post-deploy: php artisan optimize:clear + queue:restart"
