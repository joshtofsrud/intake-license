<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Tenant\TenantSale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * MARKER-PATCH-170 — Direct Payments Session 2A.
 *
 * Path-scoped webhook: /webhooks/stripe-direct/{tenantId}
 *
 * Each tenant has their own Stripe account with its own webhook signing
 * secret. The URL carries the tenant ID so we know which secret to verify
 * the signature against.
 *
 * For 2A we mostly use this as a safety net — the primary success path
 * is the front-end calling /payment-intent/confirm synchronously after
 * Stripe.js confirms the card. The webhook ensures that if the user
 * closes their browser at the wrong moment, the sale still gets marked
 * paid when Stripe asynchronously confirms.
 *
 * Handles: payment_intent.succeeded. More handlers in 2B/2C.
 */
class DirectPaymentsWebhookController extends Controller
{
    public function handle(Request $request, string $tenantId)
    {
        $tenant = Tenant::find($tenantId);
        if (! $tenant || ! $tenant->direct_payments_enabled) {
            Log::warning('direct_payments_webhook.tenant_not_found_or_disabled', [
                'tenant_id' => $tenantId,
            ]);
            return response()->json(['error' => 'unknown tenant'], 404);
        }

        $s = $tenant->settings ?? [];
        $secret = $s['register_payments_webhook_secret'] ?? null;
        if (! $secret) {
            Log::error('direct_payments_webhook.no_secret', ['tenant_id' => $tenantId]);
            return response()->json(['error' => 'webhook not configured'], 500);
        }

        $payload = $request->getContent();
        $sig = $request->header('Stripe-Signature', '');

        try {
            $event = Webhook::constructEvent($payload, $sig, $secret);
        } catch (SignatureVerificationException $e) {
            Log::warning('direct_payments_webhook.bad_signature', [
                'tenant_id' => $tenantId,
                'error'     => $e->getMessage(),
            ]);
            return response()->json(['error' => 'bad signature'], 400);
        } catch (\Throwable $e) {
            Log::error('direct_payments_webhook.parse_failed', [
                'tenant_id' => $tenantId,
                'error'     => $e->getMessage(),
            ]);
            return response()->json(['error' => 'parse failed'], 400);
        }

        try {
            $this->dispatch($event, $tenant);
        } catch (\Throwable $e) {
            Log::error('direct_payments_webhook.handler_failed', [
                'event_type' => $event->type ?? 'unknown',
                'event_id'   => $event->id ?? null,
                'tenant_id'  => $tenantId,
                'error'      => $e->getMessage(),
            ]);
        }

        return response()->json(['received' => true]);
    }

    protected function dispatch(\Stripe\Event $event, Tenant $tenant): void
    {
        switch ($event->type) {
            case 'payment_intent.succeeded':
                $this->onPaymentIntentSucceeded($event, $tenant);
                break;

            // MARKER-PATCH-171 — refunds initiated outside Intake (Stripe
            // dashboard, direct API call, our own refundCharge) all emit
            // charge.refunded. Sync state so a sale paid via card can\'t
            // show "paid" in Intake when Stripe says it\'s been refunded.
            case 'charge.refunded':
                $this->onChargeRefunded($event, $tenant);
                break;

            // MARKER-PATCH-172 — send-payment-link flow. When the customer
            // completes payment via the Stripe Checkout URL, we promote the
            // matching draft sale to paid.
            case 'checkout.session.completed':
                $this->onCheckoutSessionCompleted($event, $tenant);
                break;

            // Async payment success (Klarna, etc.) — same handler.
            case 'checkout.session.async_payment_succeeded':
                $this->onCheckoutSessionCompleted($event, $tenant);
                break;

            // MARKER-PATCH-193 — the link lapsed without payment. Mark the still-
            // unpaid sale expired so it stops showing as a live pending link.
            case 'checkout.session.expired':
                $this->onCheckoutSessionExpired($event, $tenant);
                break;

            default:
                Log::info('direct_payments_webhook.ignored_event', [
                    'type'      => $event->type,
                    'tenant_id' => $tenant->id,
                ]);
        }
    }

    /**
     * Safety net only. If the user\'s browser confirmed the payment and
     * called /payment-intent/confirm synchronously, the sale row already
     * has the stripe_payment_intent_id and we have nothing to do.
     *
     * If for some reason that flow didn\'t complete (browser closed, network
     * dropped) and the customer\'s card still authorized successfully, we
     * arrive here later. In that case the sale doesn\'t exist yet — we log
     * for investigation but don\'t create the sale automatically. Sales are
     * the source of truth and must originate from the register flow.
     */
    protected function onPaymentIntentSucceeded(\Stripe\Event $event, Tenant $tenant): void
    {
        $pi = $event->data->object;
        $piId = $pi->id;

        // Is this already linked to a sale?
        $sale = TenantSale::where('tenant_id', $tenant->id)
            ->where('stripe_payment_intent_id', $piId)
            ->first();

        if ($sale) {
            // Already recorded — nothing to do
            return;
        }

        // Not linked yet — log for investigation. Possibilities:
        //   - The browser flow is still mid-confirm and hasn\'t called us yet
        //     (in which case it will, and the sale row will be created normally)
        //   - The flow failed client-side but Stripe still succeeded
        //     (rare; the customer was charged but we have no sale)
        Log::warning('direct_payments_webhook.pi_succeeded_no_sale', [
            'tenant_id' => $tenant->id,
            'pi'        => $piId,
            'amount'    => $pi->amount,
            'metadata'  => $pi->metadata ?? null,
        ]);
    }

    /**
     * MARKER-PATCH-171 — charge.refunded fires for every refund, whether
     * initiated from Intake or from the Stripe dashboard.
     *
     * Intake-initiated refunds already have the refund row + stripe_refund_id
     * stored synchronously (storeRefund + fireStripeRefund). We just log.
     *
     * Stripe-dashboard refunds arrive here without a corresponding Intake
     * refund row. We update the ORIGINAL sale\'s payment_status to 'refunded'
     * so the sale doesn\'t show stale "paid" in Intake. We do NOT auto-create
     * a refund row — that\'s a real reverse-inventory + accounting operation
     * the staff should do explicitly via the register.
     */
    protected function onChargeRefunded(\Stripe\Event $event, Tenant $tenant): void
    {
        $charge = $event->data->object;
        $piId = $charge->payment_intent ?? null;
        if (! $piId) {
            Log::info('direct_payments_webhook.charge_refunded_no_pi', [
                'tenant_id' => $tenant->id,
                'charge_id' => $charge->id ?? null,
            ]);
            return;
        }

        $original = TenantSale::where('tenant_id', $tenant->id)
            ->where('stripe_payment_intent_id', $piId)
            ->whereNull('refund_of_sale_id') // only update the original, not refund rows
            ->first();

        if (! $original) {
            Log::info('direct_payments_webhook.charge_refunded_unknown_sale', [
                'tenant_id' => $tenant->id,
                'pi'        => $piId,
            ]);
            return;
        }

        // Check for an Intake-recorded refund covering this charge. If one
        // already exists, this event is a duplicate (we initiated it) and we
        // don\'t need to mutate state.
        $hasIntakeRefund = TenantSale::where('tenant_id', $tenant->id)
            ->where('refund_of_sale_id', $original->id)
            ->exists();

        if ($hasIntakeRefund) {
            return;
        }

        // Stripe-dashboard refund with no matching Intake row. Mark the
        // original as refunded so it doesn\'t look paid anymore.
        $refundedAmount = (int) ($charge->amount_refunded ?? 0);
        $totalAmount    = (int) ($charge->amount ?? 0);
        // MARKER-PATCH-172C — 'partial' is in the enum; 'partial_refund' is not.
        $original->payment_status = ($refundedAmount >= $totalAmount) ? 'refunded' : 'partial';
        $original->save();

        Log::warning('direct_payments_webhook.external_refund_detected', [
            'tenant_id'       => $tenant->id,
            'original_sale'   => $original->id,
            'pi'              => $piId,
            'refunded_amount' => $refundedAmount,
            'total_amount'    => $totalAmount,
        ]);

        // MARKER-PATCH-247 — money left via the Stripe dashboard with no
        // Intake action. Critical: staff must reconcile.
        app(\App\Services\Tenant\StaffAlertService::class)->emit($tenant, 'payment.refund_external', [
            'title' => 'Refund issued outside Intake — ' . $original->sale_number,
            'body'  => format_money($refundedAmount) . ' refunded from the Stripe dashboard. The sale is marked '
                . $original->payment_status . '; check the ledger.',
            'link'  => '/admin/register/reconciliation',
            'meta'  => ['sale_id' => $original->id, 'refunded_cents' => $refundedAmount],
        ]);
    }

    /**
     * MARKER-PATCH-172 — promote a draft sale to paid when its Checkout
     * Session completes.
     *
     * Idempotent: if the sale is already paid (duplicate webhook delivery,
     * polling-promoted, etc.) we no-op.
     */
    protected function onCheckoutSessionCompleted(\Stripe\Event $event, Tenant $tenant): void
    {
        $session = $event->data->object;
        $sessionId = $session->id ?? null;
        if (! $sessionId) return;

        // Resolve the PaymentIntent id early — used both as a fallback match key
        // and (below) for card details.
        $piId = is_string($session->payment_intent ?? null)
            ? $session->payment_intent
            : ($session->payment_intent?->id ?? null);

        // MARKER-PATCH-193 — match the sale by checkout_session_id first, then
        // FALL BACK to the PaymentIntent id. The session-only match stranded
        // payments when a sale's checkout_session_id was null/mismatched (e.g.
        // after a premature cancel) even though the money landed in Stripe.
        $sale = TenantSale::where('tenant_id', $tenant->id)
            ->where('checkout_session_id', $sessionId)
            ->first();

        if (! $sale && $piId) {
            $sale = TenantSale::where('tenant_id', $tenant->id)
                ->where('stripe_payment_intent_id', $piId)
                ->first();
        }

        if (! $sale) {
            // Genuinely unmatchable — the money is in Stripe with no home in
            // Intake. Log loudly so the reconciliation report can surface it.
            Log::warning('direct_payments_webhook.checkout_no_sale', [
                'tenant_id'  => $tenant->id,
                'session_id' => $sessionId,
                'payment_intent' => $piId,
            ]);
            return;
        }

        if ($sale->payment_status === 'paid') {
            // Already promoted — duplicate delivery.
            return;
        }

        // Pull card details + charge from the session ($piId resolved above).
        $brand = null; $last4 = null; $funding = null; $chargeId = null;

        if ($piId) {
            try {
                $direct = new \App\Services\Tenant\DirectPaymentsService($tenant);
                $pi = $direct->retrievePaymentIntent($piId);
                $details = $direct->extractCardDetails($pi);
                $brand    = $details['brand'];
                $last4    = $details['last4'];
                $funding  = $details['funding'];
                $chargeId = $details['charge_id'];
            } catch (\Throwable $e) {
                Log::warning('direct_payments_webhook.checkout_card_extract_failed', [
                    'tenant_id' => $tenant->id,
                    'pi'        => $piId,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        $sale->status                    = 'completed';
        $sale->payment_status            = 'paid';
        $sale->paid_at                   = now();
        $sale->stripe_payment_intent_id  = $piId;
        $sale->stripe_charge_id          = $chargeId;
        $sale->card_brand                = $brand;
        $sale->card_last4                = $last4;
        $sale->card_funding              = $funding;
        $sale->payment_reference         = ($brand && $last4) ? ($brand . ' ····' . $last4) : 'Paid via link';
        $sale->save();

        // MARKER-PATCH-178B — record the payment on the SALE ledger (this was
        // missing: the webhook flipped status=paid but never wrote a ledger
        // row, so link-paid sales never reconciled). Idempotent: skip if a
        // payment row for this charge already exists. Then refresh the linked
        // appointment's cache so it shows paid.
        try {
            $already = \App\Models\Tenant\TenantSalePayment::where('sale_id', $sale->id)
                ->where('external_reference', $piId)
                ->exists();
            if (! $already && $piId) {
                $hasPrior = $sale->payments()->count() > 0;
                app(\App\Services\Tenant\SalePaymentService::class)->record(
                    sale:               $sale,
                    amountCents:        (int) $sale->total_cents,
                    kind:               $hasPrior
                        ? \App\Models\Tenant\TenantSalePayment::KIND_BALANCE
                        : ($sale->appointment_id
                            ? \App\Models\Tenant\TenantSalePayment::KIND_DEPOSIT
                            : \App\Models\Tenant\TenantSalePayment::KIND_PAYMENT),
                    source:             \App\Models\Tenant\TenantSalePayment::SOURCE_DIRECT_PAYMENT_LINK,
                    method:             'card',
                    externalReference:  $piId,
                    notes:              'Paid via payment link',
                );
                // MARKER-PATCH-219C — appointment paid cache cascades
                // centrally in SalePaymentService::recalcStatus().
            }
        } catch (\Throwable $e) {
            Log::error('direct_payments_webhook.ledger_write_failed', [
                'tenant_id' => $tenant->id,
                'sale_id'   => $sale->id,
                'error'     => $e->getMessage(),
            ]);
        }

        Log::info('direct_payments_webhook.checkout_completed', [
            'tenant_id'  => $tenant->id,
            'sale_id'    => $sale->id,
            'session_id' => $sessionId,
        ]);

        // MARKER-PATCH-247 — the register has long since moved on; the bell
        // is how staff find out the money landed and fulfillment can happen.
        app(\App\Services\Tenant\StaffAlertService::class)->emit($tenant, 'payment.link_completed', [
            'title' => 'Payment link completed — ' . $sale->sale_number,
            'body'  => format_money((int) $sale->total_cents) . ' paid by card via link.',
            'link'  => '/admin/register/history',
            'meta'  => ['sale_id' => $sale->id, 'amount_cents' => (int) $sale->total_cents],
        ]);
    }

    /**
     * MARKER-PATCH-193 — checkout.session.expired. Stripe fires this when a
     * Checkout Session lapses (default 24h) without completing. Mark the
     * matching sale expired ONLY if it's still unpaid — never touch a sale that
     * was already paid (a completed event may race ahead of expiry).
     */
    protected function onCheckoutSessionExpired(\Stripe\Event $event, Tenant $tenant): void
    {
        $session = $event->data->object;
        $sessionId = $session->id ?? null;
        if (! $sessionId) return;

        $sale = TenantSale::where('tenant_id', $tenant->id)
            ->where('checkout_session_id', $sessionId)
            ->first();

        if (! $sale) {
            return; // nothing to expire
        }

        // Already paid (e.g. completed event won the race) — leave it alone.
        if ($sale->payment_status === 'paid' || $sale->payments()->count() > 0) {
            return;
        }

        // Mark the sale expired. payment_status stays unpaid (enum has no
        // 'expired'); status carries the lifecycle state.
        if ($sale->status !== 'cancelled') {
            $sale->status = 'cancelled';
            $sale->payment_reference = 'Payment link expired';
            $sale->save();
        }

        Log::info('direct_payments_webhook.checkout_expired', [
            'tenant_id'  => $tenant->id,
            'sale_id'    => $sale->id,
            'session_id' => $sessionId,
        ]);

        // MARKER-PATCH-247 — the customer never paid; staff should follow up.
        app(\App\Services\Tenant\StaffAlertService::class)->emit($tenant, 'payment.link_expired', [
            'title' => 'Payment link expired — ' . $sale->sale_number,
            'body'  => format_money((int) $sale->total_cents) . ' was never paid; the sale auto-cancelled.',
            'link'  => '/admin/register/history',
            'meta'  => ['sale_id' => $sale->id],
        ]);
    }
}
