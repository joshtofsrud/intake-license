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

            // 2B will handle: checkout.session.completed

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
        $original->payment_status = ($refundedAmount >= $totalAmount) ? 'refunded' : 'partial_refund';
        $original->save();

        Log::warning('direct_payments_webhook.external_refund_detected', [
            'tenant_id'       => $tenant->id,
            'original_sale'   => $original->id,
            'pi'              => $piId,
            'refunded_amount' => $refundedAmount,
            'total_amount'    => $totalAmount,
        ]);
    }
}
