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

            // 2B will handle: checkout.session.completed
            // 2C will handle: charge.refunded

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
}
