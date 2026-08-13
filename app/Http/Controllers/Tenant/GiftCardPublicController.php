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
